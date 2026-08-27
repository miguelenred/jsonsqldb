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
Resultado esperado: `OK: 63   FALLOS: 0`.

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

### Conexión directa al motor

Por defecto el motor **solo** se puede usar a través de la API. Cualquier intento
de instanciar `Database` desde otro sitio se rechaza con un mensaje explícito.

Para permitirlo, en `config.php`:

```php
defined('JSONSQLDB_CONEXION_DIRECTA') || define('JSONSQLDB_CONEXION_DIRECTA', true);
```

**Solo para programadores con experiencia.** Con la conexión directa:

- **No hay permisos.** Equivale siempre a una clave `admin`: puede leer,
  escribir, alterar la estructura y borrar bases enteras. No se puede limitar a
  una base ni a solo lectura.
- **No hay API key ni firma.** Se salta el HMAC, el límite de peticiones, el
  anti-replay y la lista de IPs. Toda la seguridad depende de tu código.
- **Sí se registra.** Cada consulta va al log igual que por la API, con el campo
  `ip` a `"local"`, porque no hay petición HTTP de la que sacarla.
- **Siguen valiendo los parámetros ligados**, y son lo único que te protege de
  una inyección: `consultar($sql, $params)` con `?` en la SQL.

```php
require 'config.php';
require 'engine/bootstrap.php';

$bd    = new JsonSQLDB\Database('mibase');
$filas = $bd->consultar('SELECT * FROM clientes WHERE ciudad = ?', ['Torrevieja']);
```

Tiene sentido en un script de mantenimiento, una migración o un proceso por cron,
donde el salto por HTTP solo añade latencia. Para cualquier cosa expuesta a
terceros, la API.

### Qué pasa si se llena la memoria

El resultado de una consulta vive entero en memoria. Si se pide más de la que PHP
tiene asignada en `memory_limit`, PHP corta con un **error fatal**: no es una
excepción, no se puede capturar, no se ejecuta ningún `finally`, y el cliente
recibe una respuesta rota en vez de un mensaje.

Los datos **no se corrompen**, y eso es por cómo está construido el motor: una
lectura no escribe nada; una escritura acumula los cambios en memoria y los
vuelca al final, de modo que quedarse sin memoria mientras se calcula no ha
tocado el disco todavía; cada fichero se escribe con temporal más `rename`, que
es indivisible; y si la operación toca varias tablas, el journal la deshace al
volver a abrir la base. Los bloqueos también se sueltan solos, porque `flock`
está atado al proceso y el sistema operativo lo libera al matarlo.

Lo que sí se puede evitar es la **forma** de fallar. Con
`JSONSQLDB_MEMORIA_VIGILAR` activado —lo está por defecto— el motor mira cada 512
filas cuánta memoria lleva y corta él mismo al llegar al 85 % del límite
(`JSONSQLDB_MEMORIA_MARGEN`), lanzando un error normal con `sqlState` `MEMORIA`:

```
Se ha cortado el producto cartesiano: lleva 56 MB de los 64 MB que PHP tiene
asignados. Acota la consulta con WHERE o LIMIT, o sube memory_limit si de
verdad necesitas ese volumen de una vez.
```

El proceso sigue vivo, la API responde con su JSON de error y el cliente sabe qué
ha pasado. **La consulta falla igual**: lo que no cabe no cabe. Lo que cambia es
que falla de forma entendible en vez de reventar.

Se mide la memoria **en uso**, no la reservada. PHP conserva los bloques que ya
pidió al sistema aunque estén libres, así que después de una consulta grande ese
número se queda alto —28 MB reservados con 1,5 MB realmente ocupados— y la
siguiente consulta se cortaría nada más empezar aunque quepa de sobra.

Y por debajo de todo eso hay una **red que no depende de acertar**. Predecir el
consumo es una heurística: PHP reserva memoria a rachas y cuánto reserva cambia
entre versiones, así que ninguna estimación es infalible. Por eso el motor aparta
dos megas al empezar y registra una función de cierre. Si PHP llega a abortar por
falta de memoria, esa función suelta la reserva —con lo que vuelve a haber sitio
para trabajar— y avisa: la API devuelve entonces un JSON de error normal en vez
de un cuerpo vacío o a medias.

Las funciones de cierre se ejecutan **siempre**, incluso tras un error fatal. Esa
es la diferencia: la predicción corta antes y con un mensaje mejor, pero la red
funciona aunque la predicción falle.

Cerca del límite se mira mucho más a menudo: cada 512 filas mientras hay sitio de
sobra, y cada 8 a partir de la mitad del límite. Comprobar cuesta 0,01
microsegundos, así que apretar cuando importa no se nota en el rendimiento.

No basta con mirar el techo. PHP duplica la tabla hash de un array cuando crece,
así que entre dos comprobaciones el consumo puede pegar un salto mayor que el
margen que queda y llegar al fatal sin pasar por el vigilante. Por eso también se
corta cuando **otro salto como el último no cabría**: se reserva el doble de lo
que acaba de crecer. Sin esto, el corte funcionaba con unos límites de memoria y
no con otros, según la versión de PHP y el tamaño de la tabla.

**Lo que el vigilante no puede cubrir del todo.** Un fichero se lee entero de
golpe: `file_get_contents()` y `json_decode()` materializan todo en una sola
instrucción, y el pico ocurre antes de que nadie pueda mirar nada. Por eso, antes
de abrir un fichero se estima si su contenido cabrá, a partir de su tamaño. La
estimación es una heurística —cuánto se expande depende de la forma de los datos,
y muchas columnas cortas inflan más que pocas largas—, así que **reduce la
ventana pero no la cierra**. Cerrarla exigiría leer por trozos en vez de entero,
que es un cambio de fondo del almacenamiento.

Sobre subir el límite automáticamente: se puede leer con `ini_get('memory_limit')`
y en muchos servidores se puede cambiar con `ini_set()`, pero **el motor no lo
hace**, a propósito. Ese límite existe para que una petición no se lleve por
delante a las demás; subirlo sola sería quitarle al administrador una decisión
que es suya, y en hosting compartido suele estar bloqueado de todas formas. Si de
verdad necesitas más, súbelo tú en `php.ini`.

### Bloqueos: dos niveles

Hay un bloqueo por base y otro por tabla, y **siempre se piden en ese orden**,
nunca al revés. Ese orden fijo es lo que hace imposible un interbloqueo.

| Operación | Base | Tabla |
|---|---|---|
| Lecturas (`SELECT`, `SHOW`, `CHECK KEYS`) | compartido | — |
| Escritura en **una** tabla sin claves foráneas ni triggers | compartido | **exclusivo** |
| Cascadas, triggers hacia otras tablas, DDL, `REPAIR KEYS` | **exclusivo** | — |

Con esto, dos escrituras en tablas distintas van a la vez, y una escritura ya no
bloquea las lecturas de las demás tablas. En cuanto la operación puede tocar más
de una tabla se pide el exclusivo de la base, que espera a que terminen **todas**
las escrituras pendientes de todas ellas.

La decisión se toma en `Database::tablaUnica()`, y es deliberadamente
desconfiada: basta con que la tabla tenga una clave foránea, con que otra tabla
la referencie, con que exista un trigger o con que sea un `INSERT ... SELECT`
para pedir el bloqueo de la base. Ante la duda, la base: un bloqueo de más solo
cuesta paralelismo; uno de menos cuesta datos.

Un detalle que costó encontrar: decidir el alcance obliga a leer la estructura, y
eso ocurre **antes** de tener el bloqueo. Lo leído entonces puede quedar obsoleto
en cuanto otro proceso escriba, así que el catálogo se olvida nada más bloquear.
Sin eso, dos procesos podían reutilizar el mismo autoincremento.

`tests/f7_concurrencia.php` lo comprueba con procesos de verdad, midiendo qué se
solapa y qué espera.

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

`tests/f6_cortes.php` lo comprueba matando procesos de verdad: lanza un `DELETE`
en cascada, lo mata con `SIGKILL` a mitad, y exige que al reabrir la base esté
entera o sin tocar, nunca a medias. Informa de cuántas muertes cayeron dentro de
la ventana de escritura, para que una ejecución que no llegó a probarlo lo diga
en vez de pasar en silencio.

Un detalle que evita el caso raro: antes de borrar `.tx/` el manifiesto se marca
como `COMMITTED`. Si el corte ocurre justo entre marcarlo y borrarlo, al
recuperar se ve que la operación sí había terminado y no se deshace nada.

Comprobar si hay un journal pendiente cuesta un `stat` (medio microsegundo) y se
hace una sola vez por petición, al coger el bloqueo. Medido: un `SELECT` con
apertura de base incluida tarda 0,6 ms, así que el journal es el 0,1 % de eso.

El journal cubre también las **escrituras de datos que tocan más de una tabla**:
un `DELETE` con `ON DELETE CASCADE`, un `UPDATE` con `ON UPDATE SET NULL`, o un
trigger que escribe en otra tabla. El motor acumula los cambios en memoria y los
vuelca al final, así que en ese momento sabe exactamente qué tablas toca y abre
el journal solo si son dos o más.

Con **una sola tabla no se journaliza**: sería copiar el fichero de datos entero
en cada `INSERT`, y el coste no compensa. Ahí basta con el `rename` atómico de la
escritura. Se controla con `JSONSQLDB_JOURNAL_DATOS`, a `true` por defecto.

Medido: un `DELETE` en cascada que recorre 2.500 filas de dos tablas tarda 3,3 ms
con el journal puesto.

Lo que **no** cubre: agrupar varias sentencias en una unidad de trabajo. No hay
`BEGIN`/`COMMIT`: cada sentencia es atómica por su cuenta, con sus cascadas y sus
triggers dentro, pero dos sentencias seguidas no se deshacen juntas.

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
