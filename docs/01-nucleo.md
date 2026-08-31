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
    ├── .lock                    fichero de bloqueo de la base (vacío)
    ├── .usuarios.lock           fichero de bloqueo de una tabla (vacío)
    ├── .htaccess / web.config   deniegan acceso si la carpeta cae en el webroot
    ├── .cache/                  caché serializada (regenerable: se puede borrar)
    ├── .tx/                     journal; solo existe con una escritura en curso
    ├── usuarios.meta.json       estructura de la tabla
    ├── usuarios.rev.json        nº de revisión de la tabla (invalida su caché)
    ├── usuarios.json            datos (parte 1)
    ├── usuarios.part2.json      datos (parte 2, a partir de 1.000 filas)
    ├── usuarios.idx.auto_id.json   índice (uno por índice de la tabla)
    └── pedidos.meta.json / pedidos.json
```

Las bases creadas con versiones anteriores a la 2.0 tienen un `_revs.json` con
la revisión de todas las tablas juntas, y ningún fichero de índice. Ver
[«Actualizar desde una versión anterior»](#85-actualizar-desde-una-versión-anterior)
al final de este documento.

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

**Paginación**: al superar 1.000 filas (`JSONSQLDB_FILAS_POR_PARTE`) los datos se
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

Con `flock` sobre dos ficheros: uno de la base (`.lock`) y uno por tabla
(`.<tabla>.lock`). El detalle de qué operación pide cuál está en
[Bloqueos: dos niveles](#bloqueos-dos-niveles), más abajo.

| Operación | Base | Tabla | Efecto |
|---|---|---|---|
| `SELECT` y demás lecturas | compartido | compartido, por cada tabla leída | varias lecturas a la vez |
| Escritura en **una** tabla sin claves foráneas ni triggers | compartido | exclusivo | escrituras en tablas distintas van a la vez |
| Cascadas y triggers, con el conjunto de tablas calculable | compartido | exclusivo, en TODAS las que puede tocar | otros grupos de tablas siguen escribiendo |
| DDL, vistas, `REPAIR KEYS`, `INSERT ... SELECT` | exclusivo | — | espera a todo y todo la espera |

### Qué se bloquea cuando una escritura puede propagar

Escribir en una tabla no siempre se queda en ella: una clave foránea con
`ON DELETE CASCADE` arrastra filas hijas, y un trigger puede escribir donde
quiera. Hasta la 2.2 eso bastaba para tomar el exclusivo de la base, y cualquier
otra escritura esperaba aunque fuera a tablas sin ninguna relación.

Ahora se calcula antes el **conjunto de tablas alcanzables** —claves foráneas en
las dos direcciones y de forma transitiva, más los destinos de los triggers,
leyendo su SQL— y se bloquean solo esas.

Dos detalles que son lo que lo hace seguro:

- **Se piden todas de golpe, antes de escribir nada.** Pedir un bloqueo más a
  mitad de la escritura es la receta del interbloqueo.
- **Se piden en orden alfabético.** Dos procesos que necesiten las mismas tablas
  las piden en la misma secuencia, así que uno espera al otro en vez de
  esperarse mutuamente. Sin un orden fijo, uno que fuera de A a B y otro de B a A
  se bloquearían para siempre.

Se cae al exclusivo de la base en cuanto el conjunto no se puede afirmar: un
trigger cuya SQL no se deja analizar, un `INSERT ... SELECT` (que lee de otras
tablas), cualquier operación de estructura, o más de ocho tablas, donde pedir
tantos bloqueos cuesta más que uno solo.

Un `SELECT` sobre una tabla con una escritura pendiente **espera a que termine** y
devuelve ya los datos nuevos, que es el comportamiento pedido. Lo que ya no
ocurre es que una escritura en `pedidos` haga esperar a una lectura de
`usuarios`: para eso están los dos niveles.

La lectura pide el compartido de cada tabla que toca, sobre la marcha, la primera
vez que lee de ella. Dos compartidos no se estorban, así que las lecturas siguen
yendo a la vez; solo se espera si hay una escritura de esa misma tabla. Hace
falta desde que una tabla puede ocupar varios ficheros: la escritura los
reemplaza uno a uno, y sin ese bloqueo una lectura simultánea podía coger la
primera parte ya nueva y la segunda todavía vieja, es decir filas de dos
versiones distintas, sin ningún corte de luz de por medio.

El bloqueo es **reentrante** (un trigger que escribe dentro de un `INSERT` no
vuelve a pedir el bloqueo) y se prohíbe escalar de lectura a escritura, para que
no haya interbloqueos: cada consulta decide su modo antes de empezar.

> En Windows `flock` funciona igual (bloqueo obligatorio del sistema).
> En NFS `flock` puede no ser fiable; no usar la raíz de datos en NFS.

---

## 4. Caché e invalidación

Cada tabla guarda su contador de revisión en su propio fichero:

```json
{"rev": 7}
```

Uno por tabla y no uno compartido, porque dos escrituras en tablas distintas van
a la vez —para eso está el bloqueo por tabla— y un fichero común lo reescribían
las dos enteras: la última en terminar borraba la subida de la otra y dejaba su
caché sirviendo los datos de antes, sin ningún síntoma visible.

Cada escritura de datos o de estructura **incrementa** el contador de esa tabla.
La clave de caché incluye ese número, así que:

- mientras nadie escriba, todas las peticiones reutilizan la caché;
- en cuanto alguien escribe, la clave cambia y **cualquier otro proceso lee los
  datos nuevos**, aunque su caché anterior siga en memoria;
- las entradas antiguas se borran al escribir (APCu y disco), no se acumulan.

La revisión se sube **antes** de escribir los datos, y el orden importa. Si un
corte de corriente cae entre las dos cosas, queda una revisión nueva con los
datos viejos: nadie tiene nada cacheado bajo esa revisión, así que la siguiente
lectura va al fichero y ve lo correcto. Al revés —datos nuevos y revisión
vieja— la caché seguiría sirviendo lo de antes, y eso no se detecta nunca.

Borrar la carpeta `.cache/` a mano es seguro en cualquier momento.

---

## 5. Ficheros del núcleo

| Fichero | Responsabilidad |
|---|---|
| `engine/bootstrap.php` | autoloader (único `require` necesario) |
| `engine/JsonSqlDbError.php` | excepción única, con tipo: CONFIG, SCHEMA, TYPE, CONSTRAINT, SYNTAX, IO, LOCK, PERMISSION, MEMORIA |
| `engine/Types.php` | tipos, alias, validación y conversión de valores y fechas |
| `engine/Storage.php` | ficheros JSON, bloqueo, escritura atómica, journal, paginación, caché, índices |
| `engine/Catalog.php` | tablas, columnas, PK/UNIQUE/FK, índices, triggers, autoincremento, ALTER TABLE |
| `engine/Indexes.php` | claves de índice, construcción y elección para una consulta |
| `engine/Memoria.php` | vigilante que corta una consulta antes del fatal de PHP |
| `tests/f1_nucleo.php` | comprobaciones del núcleo (no deja nada en disco) |

Escritura **atómica**: todo se escribe en `fichero.<pid>.tmp`, se fuerza a disco
con `fsync()` y se renombra al final; si algo falla, el temporal se borra en el
`finally`. Nunca queda un fichero a medias.

Un fichero es atómico, pero **un conjunto de ficheros no**, y casi ninguna
escritura toca uno solo: una tabla de más de `JSONSQLDB_FILAS_POR_PARTE` filas
vive repartida en varias partes, una tabla con índices reescribe el fichero de
cada uno, y un `INSERT` en una tabla con `AUTOINCREMENT` reescribe también el de
estructura. Para eso está el journal:

1. Antes de tocar nada se copia lo que va a cambiar a `.tx/<ámbito>/`.
2. Se escribe el **manifiesto, al final**, y de una sola pieza.
3. Se hacen los cambios.
4. Se marca `COMMITTED` y se borra la carpeta.

Si el proceso muere, la carpeta se queda ahí y al abrir la base se deshace todo.
Que el manifiesto vaya el último es lo que lo hace seguro: **sin manifiesto no se
restaura nada**, porque significa que las copias no terminaron, y como se copia
antes de modificar, si no terminaron es que no se había modificado nada. Al
limpiar, el manifiesto se borra el primero, para que un corte a mitad de la
limpieza no deje un manifiesto apuntando a un juego de copias ya incompleto.

El **ámbito** es el bloqueo que tiene la escritura, y dice cuál hará falta para
deshacerla: `.tx/_base/` cuando se tiene el exclusivo de la base, `.tx/<tabla>/`
cuando la escritura está acotada a una tabla. Así journalizar no cuesta la
concurrencia que da el bloqueo por tabla. Comprobar si hay algo pendiente es un
solo `stat` sobre `.tx/`.

Un proceso matado con `SIGKILL` no ejecuta ningún `finally` y puede dejar su
temporal en disco; eso no se puede evitar desde dentro. Lo que sí se hace es que
no se acumulen: cada escritura barre los temporales ajenos de su tabla, cosa que
puede hacer sin riesgo porque tiene su bloqueo exclusivo.

---

## 5.1 Índices

Un índice asocia el valor de una o varias columnas con las **posiciones** de las
filas que lo tienen, y de ahí se deduce en qué partes viven: se decodifican solo
esas. La PK y los UNIQUE tienen el suyo automáticamente, con nombre
`auto_<columnas>`; el resto se crean con `CREATE INDEX`.

Se reconstruye **entero en cada escritura**. Las posiciones no son estables: al
guardar, las filas se reindexan desde cero y se reparten en partes, así que un
solo `DELETE` desplaza todas las siguientes. Mantenerlas al día de forma
incremental sería una fuente inagotable de errores callados; reconstruir cuesta
un recorrido sobre filas que ya están en memoria, poco al lado del `json_encode`
que la escritura hace de todos modos.

Cada fichero de índice guarda la revisión a la que corresponde. Si no es la de la
tabla, el motor lo ignora y recorre la tabla: un índice desfasado o tocado a mano
puede costar velocidad, nunca dar un resultado equivocado.

---

## 6. Uso desde PHP (nivel núcleo)

Este nivel es el que usa el ejecutor SQL. Se puede usar
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

## 8. Qué se apoya en este nivel

Todo lo demás del proyecto está construido encima de lo que describe este
documento:

- El analizador y el ejecutor de `SELECT` — ver [02-consultas.md](02-consultas.md)
- Las escrituras, el DDL, las claves y los triggers — ver [03-escrituras.md](03-escrituras.md)
- La API HTTP firmada — ver [04-api.md](04-api.md)
- El panel `jsonSQLDBadmin` — ver [05-admin.md](05-admin.md)

---

## 8.4 Varias instalaciones en la misma máquina

Sí se puede, y de hecho no hay que hacer nada especial: **todo lo que el motor
comparte entre procesos vive dentro de la carpeta de la base de datos**. Los
ficheros de bloqueo (`.lock`, `.<tabla>.lock`), el journal (`.tx/`), los
temporales y la caché en disco (`.cache/`) están todos ahí, así que dos
instalaciones con carpetas de datos distintas no se ven entre sí.

El único recurso de verdad global es **APCu**, que es memoria compartida de todo
el servidor PHP. Ahí las claves llevan delante un prefijo derivado de la ruta de
la base (`jsq:` más un resumen de la ruta), justamente para que dos bases que se
llamen igual en instalaciones distintas no se pisen.

Lo que sí conviene separar, porque no depende del motor:

| | Constante | Por qué |
|---|---|---|
| Carpeta de datos | `JSONSQLDB_DATA_PATH` | Es lo que hace independientes a las dos instalaciones |
| Estado de la API | `API_ESTADO_PATH` | Guarda el anti-replay y el cupo por IP; compartirlo mezclaría los límites de las dos |
| Sesión del panel | `ADMIN_SESION_NOMBRE` | Dos paneles en el mismo dominio con el mismo nombre de sesión se pisan la cookie |
| Datos del panel | `ADMIN_DATA_PATH` | Usuarios y auditoría de cada panel |

Los cuatro por defecto son relativos a la carpeta del proyecto, así que **dos
copias del proyecto en carpetas distintas ya salen separadas** sin tocar nada. Si
montas dos instalaciones que comparten el código y solo cambian la
configuración, tendrás que darles valores distintos a mano.

Un caso que no es «varias instalaciones» y conviene no confundir: **varios
procesos sobre la MISMA base** —que es lo normal con PHP-FPM— es concurrencia
corriente y la resuelven los bloqueos descritos arriba. Ahí no hay que separar
nada.

Lo que **no** se puede hacer es tener dos carpetas de datos distintas apuntando a
los mismos ficheros (por enlaces simbólicos, por ejemplo). Los bloqueos se piden
por ruta, así que dos rutas distintas para el mismo fichero son dos bloqueos
distintos y no se excluyen entre sí.

## 8.5 Actualizar desde una versión anterior

Se reemplaza la carpeta y se conservan los dos ficheros de configuración
(`api/jsonsqldb_api_config.php` y `jsonsqldbadmin/config.php`), que están en
`.gitignore` y no vienen en el paquete.

**Los datos no hay que convertirlos.** Una base existente se lee tal cual, y cada
tabla pasa al formato nuevo en la primera escritura que reciba:

| Antes | Después |
|---|---|
| `_revs.json` con la revisión de todas las tablas | un `<tabla>.rev.json` por tabla |
| sin índices | `<tabla>.idx.auto_*.json` para la PK y los UNIQUE |

La revisión **sigue por donde iba** el `_revs.json` en lugar de empezar de cero.
Importa: si volviera a empezar, una entrada de caché de antes de actualizar
podría corresponder por casualidad a una revisión nueva y darse por buena. El
`_revs.json` viejo se queda donde está y deja de leerse; se puede borrar cuando
todas las tablas hayan recibido una escritura.

### El caso que hay que tener en cuenta

Una base que se quedó **con un journal pendiente** de antes de actualizar, porque
la versión anterior murió a mitad de una operación y no se volvió a abrir.

Los journals de entonces guardaban las copias sueltas dentro de `.tx/`, sin
carpeta de ámbito, y la recuperación de ahora busca subcarpetas: un journal
antiguo le pasaba desapercibido, y la operación a medias no se deshacía nunca.
Ya se reconocen —por tener el manifiesto suelto en la raíz de `.tx/`— y se
deshacen igual que cualquier otro.

Aun así, **lo limpio es abrir cada base una vez con la versión anterior antes de
reemplazar la carpeta**, aunque solo sea para hacer una consulta: así, si quedó
algo a medias, lo deshace la misma versión que lo escribió.

### Lo que sí deja de funcionar

**La firma HMAC de la API cambió** y las peticiones firmadas con la fórmula
anterior se rechazan. Cualquier cliente propio hay que actualizarlo; los cuatro
que vienen con el proyecto (PHP, Python, PowerShell y el panel) ya lo están. El
detalle, en [04-api.md](04-api.md).

`tests/f9_journal.php` cubre los cuatro casos: leer una base del formato antiguo
sin modificarla, que la primera escritura genere el formato nuevo sin reutilizar
revisiones, que un journal pendiente de la versión anterior se deshaga, y que la
base siga siendo utilizable después.

## 9. Configuración y protección

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
| `JSONSQLDB_CONEXION_DIRECTA` | usar el motor sin pasar por la API. `false` por defecto |
| `JSONSQLDB_MEMORIA_VIGILAR` | cortar la consulta antes de agotar la memoria. `true` |
| `JSONSQLDB_MEMORIA_MARGEN` | fracción del `memory_limit` a la que se corta. `0.85` |
| `JSONSQLDB_JOURNAL_DATOS` | journal en las escrituras que tocan más de un fichero. `true` |
| `JSONSQLDB_INDICES` | mantener y usar los índices de búsqueda. `true` |
| `JSONSQLDB_CACHE_MAX_FILAS` | tope de filas para cachear la tabla entera. `20000` |
| `JSONSQLDB_FILAS_POR_PARTE` | filas por fichero antes de partir la tabla. `1000` |
| `JSONSQLDB_COLACION` | orden alfabético del `ORDER BY`: `general` o `binaria` |
| `JSONSQLDB_COLACION_MAPA` | correcciones de orden por idioma |

Cada una se explica en su apartado más abajo.

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

Se mide sobre todo la memoria **en uso**, no la reservada. PHP conserva los
bloques que ya pidió al sistema aunque estén libres, así que después de una
consulta grande ese número se queda alto —28 MB reservados con 1,5 MB realmente
ocupados— y usarlo como medida principal cortaría la siguiente consulta nada más
empezar aunque quepa de sobra.

Pero la reservada se mira también, porque **el límite se aplica a ella**. Entre
las dos hay un hueco —bloques ya pedidos pero troceados, que no sirven para una
petición nueva— que con un `memory_limit` pequeño no es despreciable: con 16 MB
el proceso llegaba al fatal con solo 13 MB en uso. Así que se corta si a
cualquiera de las dos le queda menos margen del reservado.

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
corta cuando **otro salto como el último no cabría**: se reserva varias veces lo
que acaba de crecer. Sin esto, el corte funcionaba con unos límites de memoria y
no con otros, según la versión de PHP y el tamaño de la tabla.

Y aun así la reserva nunca baja de una fracción de lo ya usado. El salto que de
verdad puede matar el proceso no es el de una fila: cuando un array se llena,
PHP pide el doble y copia, así que esa reasignación cuesta del orden de lo que el
array ya ocupaba. Eso no se deduce mirando cuánto crecieron las filas anteriores
—es un salto que no se ha visto nunca hasta que ocurre— y por eso hace falta un
suelo proporcional.

**Lo que el vigilante no puede cubrir del todo.** Un fichero se lee entero de
golpe: `file_get_contents()` y `json_decode()` materializan todo en una sola
instrucción, y el pico ocurre antes de que nadie pueda mirar nada. Por eso, antes
de abrir un fichero se estima si su contenido cabrá, a partir de su tamaño. La
estimación es una heurística —cuánto se expande depende de la forma de los datos,
y muchas columnas cortas inflan más que pocas largas—, así que **reduce la
ventana pero no la cierra**.

---

### Cuánta memoria hace falta

**La marca la tabla más grande que toque una consulta, no el tamaño de la base.**

Un fichero JSON de 1,9 MB se convierte en unos 26 MB de arrays de PHP: unas
**14 veces**. No es sobrecoste que se pueda ajustar; cada fila es una tabla hash
con su propia copia de los nombres de columna como claves, y los valores cortos
inflan más que los largos.

```
memory_limit  ≥  20 × (la tabla más grande que una consulta lea entera)
```

Una tabla de 10 MB pide 256 MB. Si se cruzan dos, se suman las dos. Si hay APCu,
su memoria es aparte y no cuenta contra `memory_limit`.

**Bajar `JSONSQLDB_FILAS_POR_PARTE` no reduce esto.** Repartir la tabla en más
ficheros solo acota el pico de cada decodificación suelta; una consulta que
necesite todas las filas acaba con todas en memoria igual. Lo que sí compra el
reparto es poder **saltarse partes**, que es para lo que están los índices.

**Comprimir no es la salida.** El pico no es el texto JSON, es el array ya
decodificado, y un array hay que descomprimirlo para filtrarlo, cruzarlo u
ordenarlo. Comprimir la caché en disco o en APCu ahorraría disco y memoria
compartida, pero no la del proceso, que es la que topa con `memory_limit`. La
única palanca real es **no cargar las filas que la consulta no necesita**.

Eso es lo que hacen estas cinco cosas:

| | Antes | Ahora |
|---|---|---|
| `SELECT * FROM t LIMIT 50` (50.000 filas) | 56 ms · 50 MB | **3,6 ms · 3,6 MB** |
| `SELECT * FROM t WHERE id = ?` (50.000 filas) | 107 ms · 50 MB | **17 ms · 19 MB** |
| `SELECT COUNT(*) FROM t` (50.000 filas) | 49 ms · 50 MB | **52 ms · 32 MB** |

- **Las filas se leen de una en una cuando la consulta va a descartar casi
  todas.** Los ficheros llevan una fila por línea, así que un `LIMIT` o una
  búsqueda por índice no tienen a la vez el texto completo y el array completo.
  Cuando la consulta sí quiere todas las filas, el fichero se decodifica de una
  vez, que es un 25 % más rápido y no cuesta memoria de más, porque esas filas se
  iban a guardar igualmente.
- **Los índices decodifican solo las partes donde están las filas buscadas.** En
  una tabla de veinte partes, una igualdad lee una.
- **El `LIMIT` se empuja a la lectura** cuando no hay `WHERE` ni `JOIN`; con
  cualquiera de los dos, las filas que sobreviven no son las primeras.
- **`SELECT COUNT(*)` y `SHOW TABLES` no llegan a construir las filas.**
  `SHOW TABLES` cargaba todas las tablas de la base solo para contarlas.
- **Las filas ya no se tienen dos veces al preparar la consulta.** Cargar una
  tabla copia cada fila con las columnas prefijadas por el alias de la tabla, y
  antes se guardaban las dos copias enteras hasta terminar el bucle; ahora la
  original se va soltando fila a fila. De ahí los 50 MB → 32 MB de la tabla.
- **La caché se aparta cuando va justo.** Guardar una tabla obliga a
  serializarla, lo que la tiene dos veces un instante; pasada la mitad del
  límite, el motor renuncia a la caché antes que arriesgar la consulta.

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
| Lecturas (`SELECT`, `SHOW`, `CHECK KEYS`) | compartido | compartido, por cada tabla leída |
| Escritura en **una** tabla sin claves foráneas ni triggers | compartido | **exclusivo** |
| Cascadas y triggers, con el conjunto calculable | compartido | **exclusivo en todas** las que puede tocar |
| DDL, vistas, `REPAIR KEYS`, `INSERT ... SELECT` | **exclusivo** | — |

Con esto, dos escrituras en tablas distintas van a la vez, y una escritura ya no
bloquea las lecturas de las demás tablas. Una escritura que puede propagar por
claves foráneas o triggers bloquea el grupo de tablas al que puede llegar, no la
base: otro grupo sin relación sigue escribiendo a la vez. Solo se toma el
exclusivo de la base cuando ese conjunto no se puede afirmar.

La decisión se toma en `Database::tablasAfectadas()`, y es deliberadamente
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

Cada escritura suelta es atómica y duradera: se escribe un temporal, se fuerza a
disco con `fsync()` y se hace `rename`, que
el sistema de ficheros garantiza indivisible. Sin ese `fsync` habría atomicidad
de nombre pero no durabilidad: el sistema operativo puede tener los datos todavía
en su caché, y un corte de corriente dejaría el fichero nuevo vacío pese a que el
`rename` ya hubiera ocurrido. `fsync()` existe desde PHP 8.1; en 8.0 se vacía el
buffer, que es hasta donde llega esa versión.

Pero un `ALTER TABLE` o un
`DROP TABLE` tocan **varios** ficheros, y el conjunto no lo es: si el proceso
muere entre dos escrituras, la base queda a medias.

Para eso está el journal. Antes de empezar, la operación copia en
`data/<base>/.tx/<ámbito>/` los ficheros que va a tocar, junto con un manifiesto
que dice qué va a hacer. Si todo va bien, la carpeta se borra. Si el proceso
muere, se queda, y **su sola presencia es la señal** de que algo no terminó: la
siguiente vez que se abre la base se restauran las copias y todo vuelve a como
estaba.

Tres detalles que son lo que hace que esto aguante un corte de corriente y no
solo la muerte de un proceso:

- **Las copias se fuerzan a disco con `fsync()` antes de escribir el
  manifiesto.** `copy()` a secas deja el contenido en la caché del sistema
  operativo, y eso basta para sobrevivir a que muera el proceso —la caché es del
  sistema, no suya— pero no a que se vaya la luz. Sin esto, un corte podía dejar
  un manifiesto perfectamente válido señalando copias vacías, y la recuperación
  las volcaba encima de unos datos que estaban bien.
- **El manifiesto va el último.** Si no está, es que las copias no terminaron; y
  como se copia antes de modificar nada, si no terminaron es que no se había
  tocado nada todavía. Así que sin manifiesto no se restaura: se tira la carpeta.
- **El manifiesto anota el tamaño de cada copia**, y al restaurar se comprueba.
  Si una no mide lo que debería, el motor se planta y lo dice, dejando la carpeta
  para mirarla a mano: restaurar a ciegas destruiría datos que quizá estaban
  intactos, y borrarla perdería la única copia que queda.

`tests/f6_cortes.php` lo comprueba matando procesos de verdad con `SIGKILL`, que
no se puede capturar. Lo hace a mitad de un `DELETE` en cascada, a mitad de una
escritura sobre una tabla repartida en partes y con índices, y una vez por cada
tipo de operación: `UPDATE` de todas las filas, `UPDATE` de una columna indexada,
`INSERT` que añade una parte, `DELETE` que quita otra, `CREATE INDEX`,
`DROP INDEX`, cuatro clases de `ALTER TABLE`, `CREATE TABLE` y `DROP TABLE`. Tras
cada muerte exige que ninguna fila quede mezclada entre el valor viejo y el
nuevo, que los índices digan lo mismo que la tabla, que la caché diga lo mismo
que los ficheros, y que no sobre nada.

Matar un proceso no reproduce todo lo que hace un corte de corriente, así que la
última parte monta a mano los journals dañados que sí produce: una copia truncada
bajo un manifiesto válido, un journal sin manifiesto, uno ya `COMMITTED`, dos
journals de tabla a la vez, un fichero de revisión por delante de sus datos y uno
que falta. Informa de cuántas muertes cayeron dentro de la ventana de escritura,
para que una ejecución que no llegó a probarlo lo diga en vez de pasar en
silencio.

Un detalle que evita el caso raro: antes de borrar `.tx/` el manifiesto se marca
como `COMMITTED`. Si el corte ocurre justo entre marcarlo y borrarlo, al
recuperar se ve que la operación sí había terminado y no se deshace nada.

Comprobar si hay un journal pendiente cuesta un `stat` (medio microsegundo) y se
hace una sola vez por petición, al coger el bloqueo. Medido: un `SELECT` con
apertura de base incluida tarda 0,6 ms, así que el journal es el 0,1 % de eso.

El journal cubre las **escrituras de datos en cuanto tocan más de un fichero**, y
eso es casi siempre. Hasta la 2.0 se contaban *tablas*, y era el criterio
equivocado: la unidad que tiene que ser indivisible son los *ficheros*, y una
tabla rara vez es uno solo. Pasa de uno si tiene más de
`JSONSQLDB_FILAS_POR_PARTE` filas, si tiene índices, o si el `INSERT` toca además
el fichero de estructura por el `AUTOINCREMENT`.

Con el criterio viejo, un corte de corriente entre el `rename` de la primera
parte y el de la segunda dejaba media tabla nueva y media vieja. Y como el
reparto en partes es **por posición**, eso no perdía «unas filas»: descuadraba la
tabla entera a partir del corte.

El **ámbito** del journal es el bloqueo que tiene la escritura. Con una sola
tabla se tiene su exclusivo y basta el suyo, que es lo que permite que dos
escrituras en tablas distintas sigan yendo a la vez; con varias se tiene el de la
base y el journal es de base. Se controla con `JSONSQLDB_JOURNAL_DATOS`, a `true`
por defecto.

Medido sobre una tabla de 20.000 filas: el journal añade alrededor de un 1 % al
tiempo de un `UPDATE`, porque copiar un fichero cuesta muy poco al lado del
`json_encode` de la tabla que la escritura hace de todos modos. Un `DELETE` en
cascada que recorre 2.500 filas de dos tablas tarda 3,3 ms con el journal puesto.

Queda un límite que ninguna base de datos en ficheros puede salvar del todo: tras
el `rename` haría falta un `fsync()` sobre el **directorio** para que la entrada
nueva sea duradera, y PHP no permite abrir un directorio para eso. En la práctica
los sistemas de ficheros con *journaling* (ext4 con `data=ordered`, NTFS, APFS)
ordenan la entrada después de los datos, así que un corte no deja un nombre
apuntando a basura; puede dejar el cambio sin aplicar, que es justo lo que el
journal sabe deshacer.

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
