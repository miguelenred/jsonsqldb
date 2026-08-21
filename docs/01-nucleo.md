# jsonSQLDB — Fase 1: núcleo de almacenamiento

Motor de base de datos escrito **íntegramente en PHP 8.0+**, sin extensiones
obligatorias y sin ninguna dependencia de SQLite ni MySQL.

Extensiones opcionales que se usan **si existen** (nunca son obligatorias):

| Extensión | Uso | Si falta |
|---|---|---|
| `apcu` | caché en memoria compartida | caché en disco (`.cache/`) |
| `mbstring` | longitud de textos UTF-8 | cálculo propio equivalente |

---

## 1. Estructura en disco

Una **carpeta por base de datos** dentro de la raíz de datos:

```
<raiz_datos>/
└── mibase/                      ← una carpeta = una base de datos
    ├── _database.json           metadatos de la base
    ├── _revs.json               nº de revisión por tabla (invalida la caché)
    ├── .lock                    fichero de bloqueo (vacío)
    ├── .htaccess / web.config   deniegan acceso si la carpeta cae en el webroot
    ├── .cache/                  caché serializada (regenerable: se puede borrar)
    ├── usuarios.meta.json       estructura de la tabla
    ├── usuarios.json            datos (parte 1)
    ├── usuarios.part2.json      datos (parte 2, a partir de 5.000 filas)
    └── pedidos.meta.json / pedidos.json
```

Nombres permitidos: base `[A-Za-z0-9_-]{1,64}`, tabla/columna
`[A-Za-z_][A-Za-z0-9_]{0,63}`. No se acepta nada más, así que no se puede salir
de la carpeta con `../` ni con rutas absolutas.

### Fichero de datos (`usuarios.json`)

Pensado para poder abrirlo y leerlo a mano: **una fila por línea**.

```json
{
  "table": "usuarios",
  "rows": [
    {"id":1,"nombre":"Ana","email":"ana@x.es","saldo":10.56,"alta":"2026-01-15 08:30"},
    {"id":2,"nombre":"Luis","email":"luis@x.es","saldo":0,"alta":null}
  ]
}
```

Es JSON válido: se puede editar con cualquier editor y el motor lo leerá.
Si al editar a mano se rompe el JSON, el motor devuelve el error
`IO: Datos ilegibles en usuarios.json` en vez de corromper la tabla.

**Paginación**: al superar 5.000 filas (`Storage::FILAS_POR_PARTE`) los datos se
reparten en `usuarios.json`, `usuarios.part2.json`, … Al reducir el número de
filas, las partes sobrantes se eliminan solas. Leer una tabla siempre concatena
todas sus partes.

### Fichero de estructura (`usuarios.meta.json`)

Solo se escriben las claves que tienen valor, para que se lea bien:

```json
{
    "table": "usuarios",
    "columns": [
        {"name": "id", "type": "INTEGER", "notnull": true, "pk": true, "autoincrement": true},
        {"name": "nombre", "type": "TEXT", "length": 50, "notnull": true},
        {"name": "email", "type": "TEXT", "length": 120, "unique": true},
        {"name": "saldo", "type": "DECIMAL", "scale": 2, "default": 0},
        {"name": "alta", "type": "DATETIME"}
    ],
    "unique": [{"name": "uq_usuarios_nif", "columns": ["nif"]}],
    "foreign_keys": [
        {"name": "fk_pedidos_usuario_id", "columns": ["usuario_id"],
         "table": "usuarios", "references": ["id"],
         "on_delete": "CASCADE", "on_update": "NO ACTION"}
    ],
    "triggers": [
        {"name": "trg_pedidos_ins", "timing": "AFTER", "event": "INSERT",
         "when": "NEW.total > 0",
         "body": ["UPDATE usuarios SET saldo = saldo + NEW.total WHERE id = NEW.usuario_id"],
         "sql": "CREATE TRIGGER trg_pedidos_ins ..."}
    ],
    "autoincrement": {"column": "id", "next": 3},
    "created_at": "2026-08-19 10:00:00",
    "updated_at": "2026-08-19 10:04:12"
}
```

---

## 2. Tipos de datos

| Tipo interno | Alias aceptados en el `CREATE TABLE` | Se guarda como |
|---|---|---|
| `INTEGER` | INT, INTEGER, TINYINT, SMALLINT, MEDIUMINT, BIGINT, BOOL, BOOLEAN | número entero |
| `DOUBLE` | REAL, FLOAT, DOUBLE, DOUBLE PRECISION | número |
| `DECIMAL` | DECIMAL(p,s), NUMERIC, NUMBER, MONEY | número redondeado a `s` decimales (por defecto 2) |
| `TEXT` | TEXT, VARCHAR(n), NVARCHAR, CHAR, NCHAR, CLOB, STRING, BLOB | cadena |
| `DATETIME` | DATE, DATETIME, TIMESTAMP | cadena `yyyy-MM-dd[ HH:mm[:ss[.fff]]]` |

Los alias son los mismos que usa SQLite, así que un `CREATE TABLE` escrito para
SQLite se acepta tal cual.

**Fechas**: la hora, los segundos y los milisegundos son **opcionales** y se
conserva la precisión que se haya escrito. Se admite `T` o espacio como
separador en la entrada, y siempre se guarda con espacio. Al guardarse con
formato fijo `yyyy-MM-dd...`, el orden alfabético coincide con el cronológico,
por lo que `ORDER BY` y `BETWEEN` sobre fechas funcionan sin conversión.

Ejemplos válidos: `2026-02-28`, `2026-02-28 10:05`, `2026-02-28 10:05:09`,
`2026-02-28 10:05:09.700`.

---

## 3. Concurrencia (bloqueo)

Un único fichero de bloqueo por base de datos (`.lock`) con `flock`:

| Operación | Bloqueo | Efecto |
|---|---|---|
| `SELECT` | `LOCK_SH` compartido | varias lecturas a la vez |
| `INSERT/UPDATE/DELETE/DDL` | `LOCK_EX` exclusivo | una sola escritura; el resto espera |

Como el bloqueo es de toda la base, un `SELECT` lanzado mientras hay una
escritura pendiente **espera a que termine** y devuelve ya los datos nuevos, que
es el comportamiento pedido. Las escrituras se serializan solas: el sistema
operativo hace de cola.

El bloqueo es **reentrante** (un trigger que escribe dentro de un `INSERT` no
vuelve a pedir el bloqueo) y se prohíbe escalar de lectura a escritura, para que
no haya interbloqueos: cada consulta decide su modo antes de empezar.

> En Windows `flock` funciona igual (bloqueo obligatorio del sistema).
> En NFS `flock` puede no ser fiable; no usar la raíz de datos en NFS.

---

## 4. Caché e invalidación

`_revs.json` guarda un contador por tabla:

```json
{"usuarios": 7, "pedidos": 2}
```

Cada escritura de datos o de estructura **incrementa** el contador de esa tabla.
La clave de caché incluye ese número, así que:

- mientras nadie escriba, todas las peticiones reutilizan la caché;
- en cuanto alguien escribe, la clave cambia y **cualquier otro proceso lee los
  datos nuevos**, aunque su caché anterior siga en memoria;
- las entradas antiguas se borran al escribir (APCu y disco), no se acumulan.

Borrar la carpeta `.cache/` a mano es seguro en cualquier momento.

---

## 5. Ficheros del núcleo

| Fichero | Responsabilidad |
|---|---|
| `engine/bootstrap.php` | autoloader (único `require` necesario) |
| `engine/JsonSqlDbError.php` | excepción única, con tipo: CONFIG, SCHEMA, TYPE, CONSTRAINT, SYNTAX, IO, LOCK, PERMISSION |
| `engine/Types.php` | tipos, alias, validación y conversión de valores y fechas |
| `engine/Storage.php` | ficheros JSON, bloqueo, escritura atómica, paginación, caché |
| `engine/Catalog.php` | tablas, columnas, PK/UNIQUE/FK, triggers, autoincremento, ALTER TABLE |
| `tests/f1_nucleo.php` | 49 comprobaciones del núcleo (no deja nada en disco) |

Escritura **atómica**: todo se escribe en `fichero.<pid>.tmp` y se renombra al
final; si algo falla, el temporal se borra en el `finally`. Nunca queda un
fichero a medias ni un temporal huérfano.

---

## 6. Uso desde PHP (nivel núcleo)

Este nivel es el que usará el ejecutor SQL de la fase siguiente. Se puede usar
directamente para tareas de mantenimiento:

```php
require_once __DIR__ . '/engine/bootstrap.php';

use JsonSQLDB\Storage;
use JsonSQLDB\Catalog;

Storage::crearBase('E:/datos/jsonsqldb', 'mibase');

$st  = new Storage('E:/datos/jsonsqldb', 'mibase');
$cat = new Catalog($st);

$st->bloquear(true);                    // escritura
try {
    $cat->crearTabla('usuarios', [
        'columns' => [
            ['name' => 'id',     'type' => 'INTEGER',      'pk' => true, 'autoincrement' => true],
            ['name' => 'nombre', 'type' => 'VARCHAR(50)',  'notnull' => true],
            ['name' => 'email',  'type' => 'VARCHAR(120)', 'unique' => true],
        ],
    ]);
} finally {
    $st->desbloquear();                 // siempre en finally
}
```

---

## 7. Pruebas

```
php tests/f1_nucleo.php
```

Crea una base temporal, comprueba tipos, estructura, datos, ALTER TABLE,
triggers, paginación, caché, bloqueo y limpieza, y la borra al terminar.
Resultado esperado: `OK: 52   FALLOS: 0`.

---

## 8. Pendiente (siguientes fases)

- **F2** analizador y ejecutor de `SELECT`: WHERE, JOIN, GROUP BY, HAVING,
  ORDER BY, LIMIT, DISTINCT, alias, subconsultas en `FROM` e `IN`, funciones.
- **F3** `INSERT/UPDATE/DELETE` + DDL por SQL + validación de PK/UNIQUE/FK +
  ejecución de triggers.
- **F4** API HTTP con HMAC, permisos por API key y parámetro de base de datos.
- **F5** panel `jsonSQLDBDBadmin`.

---

## 9. Configuración y protección (añadido)

### `config.php`

| Constante | Para qué |
|---|---|
| `JSONSQLDB_DATA_PATH` | carpeta raíz con una subcarpeta por base de datos |
| `JSONSQLDB_FILAS_POR_PARTE` | filas por fichero antes de partir la tabla |
| `JSONSQLDB_CACHE_ACTIVA` | activar/desactivar la caché |
| `JSONSQLDB_LOG_ACTIVO` | activar el log de consultas |
| `JSONSQLDB_LOG_PATH` | carpeta de los ficheros de log |
| `JSONSQLDB_LOG_NIVEL` | `todo` / `escrituras` / `errores` |
| `JSONSQLDB_LOG_MAX_SQL` | longitud máxima de la SQL guardada |
| `JSONSQLDB_LOG_PARAMS` | ¿guardar también los valores de los `?`? Por defecto **no** |
| `JSONSQLDB_LOG_MAX_SIZE` | tamaño máximo por fichero antes de rotar |
| `JSONSQLDB_LOG_DIAS` | días que se conservan los logs (0 = siempre) |

### Operaciones de estructura a prueba de cortes

Cada escritura suelta es atómica: se escribe un temporal y se hace `rename`, que
el sistema de ficheros garantiza indivisible. Pero un `ALTER TABLE` o un
`DROP TABLE` tocan **varios** ficheros, y el conjunto no lo es: si el proceso
muere entre dos escrituras, la base queda a medias.

Para eso está el journal. Antes de empezar, la operación copia en
`data/<base>/.tx/` los ficheros que va a tocar, junto con un manifiesto que dice
qué va a hacer. Si todo va bien, `.tx/` se borra. Si el proceso muere, `.tx/` se
queda, y **su sola presencia es la señal** de que algo no terminó: la siguiente
vez que se abre la base se restauran las copias y todo vuelve a como estaba.

Un detalle que evita el caso raro: antes de borrar `.tx/` el manifiesto se marca
como `COMMITTED`. Si el corte ocurre justo entre marcarlo y borrarlo, al
recuperar se ve que la operación sí había terminado y no se deshace nada.

Comprobar si hay un journal pendiente cuesta un `stat` (medio microsegundo) y se
hace una sola vez por petición, al coger el bloqueo. Medido: un `SELECT` con
apertura de base incluida tarda 0,6 ms, así que el journal es el 0,1 % de eso.

Lo que **no** cubre: las escrituras de datos que tocan varias tablas, como un
`DELETE` con `ON DELETE CASCADE`. Ahí cada tabla se escribe de forma atómica,
pero un corte entre las dos puede dejar el borrado a medias.

### Log de consultas

Los **valores** de los parámetros ligados no se guardan salvo que actives
`JSONSQLDB_LOG_PARAMS`. Por ahí viajan contraseñas, tokens y datos personales, y
el log se conserva 90 días por defecto: no es sitio para ellos. La SQL sí se
guarda, con sus `?` sin sustituir.

Un fichero por día: `logs/consultas-2026-08-19.json`, y `-1.json`, `-2.json`… al
rotar por tamaño. Cada línea es un objeto JSON independiente:

```json
{"ts":"2026-08-19 12:00:00.123","ip":"10.0.0.5","db":"mibase","op":"SELECT","rows":42,"ms":3.15,"origen":"Mi aplicación","sql":"SELECT ...","error":null}
```

- `rows` → registros **mostrados** en un SELECT o **afectados** en INSERT/UPDATE/DELETE
- `ip` → IP de origen de la petición (`cli` si se ejecuta por consola)
- `origen` → etiqueta de la API key que lanzó la consulta (lo rellena la API en F4)
- `error` → `null` si fue bien, o el mensaje si falló

Es **JSON por líneas** en lugar de un único array: así se puede añadir al final
sin releer ni reescribir el fichero, que es lo que permite loguear sin frenar las
consultas. Se lee igual a simple vista y se procesa línea a línea con
`json_decode`. Si prefieres un array JSON único, avísame y lo cambio.

Si la carpeta de logs no se puede escribir, el motor **sigue funcionando**: el log
nunca interrumpe una consulta.

### Protección desde el navegador

| Fichero | Qué bloquea |
|---|---|
| `.htaccess` + `web.config` (raíz) | listados de directorio, `config.php`, ficheros ocultos, cualquier `.json`, `.md`, `.log`, `.lock`, `.cache`, `.tmp`, y las carpetas `engine/ data/ logs/ docs/ tests/` |
| `engine/`, `data/`, `logs/`, `docs/`, `tests/` | cada una con su propio `.htaccess` y `web.config` que deniegan todo |
| carpeta de cada base de datos | `.htaccess` y `web.config` creados automáticamente al crear la base |

Doble capa a propósito: si el hosting ignora el `.htaccess` de la raíz o no tiene
`mod_rewrite`, los de cada carpeta siguen protegiendo. Sirve tanto para Apache 2.2
como 2.4 y para IIS.

Aun así, **lo recomendable es poner `data/` y `logs/` fuera del webroot** y dejar
solo `api/` accesible.
