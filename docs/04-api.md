# jsonSQLDB — Fase 4: la API

Endpoint HTTP para ejecutar SQL contra una base jsonSQLDB desde cualquier
aplicación, con firma HMAC y permisos por clave.

Fichero: `api/jsonsqldb_api.php` — Configuración: `api/jsonsqldb_api_config.php`

## 1. Petición

`POST` con estos parámetros:

| Parámetro | Contenido |
|---|---|
| `api_key` | clave de la aplicación |
| `db` | nombre de la base de datos sobre la que se ejecuta la sentencia |
| `sql` | sentencia a ejecutar (puede ser multilínea y llevar comentarios) |
| `params` | opcional: lista JSON con los valores de los `?` de la SQL |
| `timestamp` | hora UNIX actual, 10 dígitos |
| `token` | firma HMAC-SHA256 de la petición |

La firma se calcula así:

```php
$token = hash_hmac('sha256', "+" . $apiKey . "|" . $timestamp . "|" . $sql . $params . "¿", HMAC_SECRET);
```

`$params` es el JSON tal cual se envía, o cadena vacía si no hay parámetros (en
ese caso la fórmula es exactamente la de siempre y los clientes antiguos siguen
funcionando sin tocar nada).

Como la SQL y los parámetros entran en la firma, no se puede cambiar ni la
consulta ni los valores por el camino sin conocer el secreto.

### La base de datos va en `db`, no en la SQL

No hay `USE` ni prefijos tipo `mibase.clientes`: cada petición dice en `db`
sobre qué base trabaja, y la API comprueba que la API key tiene acceso a esa
base antes de ejecutar nada. Con el cliente, la base es el cuarto argumento:

```php
$tienda  = new JsonSqlDbCliente($url, $apiKey, $secreto, 'tienda');
$almacen = new JsonSqlDbCliente($url, $apiKey, $secreto, 'almacen');
```

Una consulta no puede cruzar dos bases: son carpetas separadas.

`db` solo puede ir **vacío** para `SHOW DATABASES`, `CREATE DATABASE` y
`DROP DATABASE`, y únicamente si la API key tiene `'bases' => ['*']`.

### Clave de los ejemplos

`cliente_ejemplo.php` y `cliente_ejemplo.ps1` vienen con una API key propia,
**la misma en los dos**, con permiso `escritura` sobre la base `pruebas` y sobre
ninguna más. Está dada de alta en `api/jsonsqldb_api_config.php` como
«Clientes de ejemplo».

`escritura` permite `SELECT`, `INSERT`, `UPDATE` y `DELETE`, más las sentencias
`SHOW`. No permite `CREATE`, `ALTER`, `DROP` ni triggers: para eso hace falta
una clave `admin`.

En PHP el atajo es `JsonSqlDbCliente::pruebas()`; en PowerShell ya viene puesta
en `$Global:JsonSqlDb`. Para tu aplicación, crea una clave propia limitada a sus
bases en lugar de reutilizar esta.

### Desde PowerShell

`api/cliente_ejemplo.ps1` hace lo mismo desde PowerShell, con parámetros
ligados y soporte de certificado propio:

```powershell
Set-JsonSqlDbConexion -Url 'https://example.com/jsonsqldb/api/jsonsqldb_api.php' `
                      -ApiKey '...' -HmacSecret '...' -Base 'pruebas'

API-SQL-JSON "SELECT * FROM clientes WHERE ciudad = ?" @('Torrevieja')
API-SQL-JSON "SHOW DATABASES" -Base ''
```

Dos detalles propios de PowerShell: los decimales se convierten con
`InvariantCulture`, para que salga `10.55` y no `10,55`; y el `¿` de la firma se
monta desde su código `[char]0x00BF`, para que el token cuadre aunque el
fichero .ps1 se guarde en ANSI en lugar de UTF-8.

## 1.1. Parámetros ligados

**Nunca montes la SQL concatenando valores.** Pon un `?` en cada sitio donde
vaya un valor y manda los valores en `params`, en el mismo orden.

El servidor **no sustituye texto**: analiza la SQL con los `?` y coloca cada
valor ya convertido dentro del árbol de la sentencia. Un valor no puede
convertirse en SQL, por muy SQL que parezca. Da igual que lleve comillas,
punto y coma o comentarios: siempre se trata como un dato.

```php
// MAL — el valor forma parte de la sentencia
$sql = "SELECT * FROM clientes WHERE nombre = '$nombre'";

// BIEN — el valor viaja aparte
$filas = $cli->consultar('SELECT * FROM clientes WHERE nombre = ?', [$nombre]);
```

Con `$nombre = "x' OR 1=1; DROP TABLE clientes; --"` la segunda forma busca
literalmente un cliente que se llame así: devuelve 0 filas y la tabla no se toca.

Reglas:

- Un `?` = un valor. Si el número no coincide, la petición se rechaza.
- Valores admitidos: `null`, booleano (se guarda como 1/0), entero, decimal y
  texto. Nada de listas ni objetos.
- Los `?` van donde va un **valor**: `WHERE`, `VALUES`, `SET`, `HAVING`,
  argumentos de funciones, `LIMIT` y `OFFSET`. No sirven para nombres de tabla
  ni de columna: eso es estructura, no dato.
- `IN (?, ?, ?)` necesita un `?` por elemento; monta la lista según cuántos
  valores tengas.
- Un `?` dentro de una cadena (`'¿de verdad?'`) es texto, no marcador.
- Los valores quedan también en el log, en el campo `params`.

```php
// Varios valores y una lista IN de tamaño variable
$ciudades = ['Madrid', 'Valencia', 'Bilbao'];
$huecos   = implode(',', array_fill(0, count($ciudades), '?'));

$filas = $cli->consultar(
    "SELECT nombre, saldo FROM clientes
      WHERE ciudad IN ($huecos) AND saldo > ? AND alta >= ?
      ORDER BY saldo DESC
      LIMIT ?",
    array_merge($ciudades, [100.50, '2026-01-01', 20])
);
```

Desde PHP, sin pasar por la API, es igual:

```php
$bd = new JsonSQLDB\Database('mibase');
$bd->consultar('UPDATE clientes SET saldo = saldo + ? WHERE id = ?', [25.40, 7]);
```

## 2. Respuesta

```json
// SELECT
[ {"id":1,"nombre":"Ana","ciudad":"Madrid"}, {"id":2,"nombre":"Luis","ciudad":"Valencia"} ]

// INSERT / UPDATE / DELETE / DDL
{"success":true,"filas":2,"mensaje":"2 fila(s) insertada(s)"}

// Error
{"error":"Error en la consulta: CONSTRAINT: La columna 'clientes.nombre' no admite NULL"}
```

Los tipos vienen ya normalizados por el motor: los números llegan como números,
las fechas como `yyyy-MM-dd[ HH:mm[:ss[.fff]]]` y los nulos como `null`. No hace
falta convertir nada en el cliente.

Con `DEVOLVER_ERRORES = false` los errores se reducen a un mensaje genérico
(recomendado en producción); el detalle sigue quedando en el log.

## 3. Permisos por API key

```php
$API_KEYS = [
    'clave...' => [
        'nombre'  => 'jsonSQLDBadmin',
        'permiso' => 'admin',
        'bases'   => ['*'],
    ],
];
```

| Permiso | Sentencias admitidas |
|---|---|
| `lectura` | solo `SELECT` |
| `escritura` | `SELECT`, `INSERT`, `UPDATE`, `DELETE` |
| `admin` | todas, incluidas `CREATE`, `ALTER`, `DROP` y los triggers |

El permiso se comprueba **después de analizar la SQL y antes de ejecutarla**, así
que se mira lo que la sentencia hace de verdad, no cómo esté escrita. `bases`
limita a qué bases de datos puede acceder esa clave; `['*']` son todas.

Las claves y el secreto **no vienen puestos**: `api/jsonsqldb_api_config.dist.php`
trae marcadores `CHANGE_ME_` que hay que sustituir al instalar. Una instalación
sin configurar falla ruidosamente en lugar de funcionar con un secreto conocido
por todo el mundo.

Genera cada valor así, uno distinto por clave:

```
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

El `HMAC_SECRET` es del servidor y lo comparten todas las claves; la clave admin
tiene que ser la misma en `api/jsonsqldb_api_config.php` y en
`jsonsqldbadmin/config.php`.

## 4. Protecciones

| Control | Configuración | Por defecto |
|---|---|---|
| Solo POST | — | siempre |
| Tamaño máximo de la petición | `MAX_POST_SIZE` | 200 KB |
| Longitud máxima de la SQL | `MAX_SQL_LENGTH` | 100.000 caracteres |
| Parámetros ligados por petición | `MAX_PARAMS`, `MAX_PARAMS_LENGTH` | 1.000 valores, 100 KB |
| Desfase máximo del reloj | `RATE_TIMESTAMP_DIFF` | 300 s |
| Anti-replay (token de un solo uso) | `ANTI_REPLAY_ACTIVO` | desactivado |
| Límite de peticiones por IP | `RATE_LIMIT_ACTIVO`, `RATE_LIMIT_MAX`, `RATE_LIMIT_SECONDS` | desactivado |
| Corte por fallos de autenticación | `RATE_LIMIT_GLOBAL_MAX` | 30 |
| Lista blanca de IPs (IP suelta o CIDR) | `IPS_PERMITIDAS` | vacía (sin filtro) |
| Exigir HTTPS | `EXIGIR_HTTPS` | `false` |
| Cabecera HSTS | `HSTS_ACTIVO` | `false` |
| Confiar en X-Forwarded-For / -Proto | `CONFIAR_EN_PROXY` | `false` |

Todo el estado (contadores por IP, fallos y tokens ya usados) se guarda en
**JSON**, en `logs/api/estado.json`, bajo bloqueo exclusivo y limpiándose solo de
entradas caducadas. No hay ninguna base de datos externa por debajo.

La comparación de tokens usa `hash_equals`, así que no se puede adivinar el
secreto midiendo tiempos de respuesta.

### Qué activar en producción

Por orden de eficacia:

1. **`IPS_PERMITIDAS`**. Si quien consume la API son servidores tuyos con IP
   fija, esta es la protección más fuerte: quien no esté en la lista recibe un
   403 antes de que se mire la firma. Admite IP suelta (`10.0.0.7`) y rango
   CIDR (`10.0.0.0/24`, `2001:db8::/32`).
2. **`EXIGIR_HTTPS`**. La firma HMAC impide manipular la consulta, pero no
   impide leerla. Sin HTTPS, la SQL y los datos viajan en claro.
3. **`RATE_LIMIT_ACTIVO`** y **`ANTI_REPLAY_ACTIVO`**. El segundo impide
   reutilizar un token capturado dentro de la ventana de `RATE_TIMESTAMP_DIFF`.
4. **`DEVOLVER_ERRORES` a `false`**. Con `true` los errores del motor llegan al
   cliente, cómodo mientras desarrollas y demasiado hablador después.
5. **`HSTS_ACTIVO`** solo con un certificado de una CA reconocida. Con uno
   autofirmado dejarías el dominio inaccesible durante un año.

`CONFIAR_EN_PROXY` merece un aviso aparte: actívalo **solo** si delante hay un
proxy o balanceador de confianza. Con él puesto, la API cree lo que digan las
cabeceras `X-Forwarded-For` y `X-Forwarded-Proto`; si nadie las está fijando,
cualquiera puede falsear su IP y saltarse `IPS_PERMITIDAS` y el rate limit.

## 5. Registro

Cada petición deja dos rastros:

- `logs/api/peticiones-AAAA-MM-DD.json` — la petición: fecha, IP, user-agent,
  base, etiqueta de la API key, operación, filas, milisegundos y error.
- `logs/consultas-AAAA-MM-DD.json` — la consulta ejecutada por el motor, con la
  SQL completa.

Los dos son un fichero por día, rotan por tamaño y se purgan según
`JSONSQLDB_LOG_DIAS` (0 = conservarlos siempre).

## 6. Cliente

`api/cliente_ejemplo.php` es un cliente listo para copiar en la aplicación que
vaya a consumir la API. Firma la petición, la envía y devuelve el resultado ya
decodificado:

```php
require 'cliente_ejemplo.php';

$cli = new JsonSqlDbCliente(
    'https://miservidor/jsonsqldb/api/jsonsqldb_api.php',
    'MI_API_KEY',
    'MI_HMAC_SECRET',
    'mibase'
);

$filas = $cli->consultar('SELECT * FROM clientes WHERE ciudad = ?', ['Madrid']);
$cli->consultar('INSERT INTO clientes (nombre, ciudad) VALUES (?, ?)', ["O'Donnell", 'Logroño']);
```

Los valores viajan en `params` y el servidor los liga a los `?` (ver 1.1). El
cliente no escapa nada ni toca la SQL.

Con certificado autofirmado hay que poner `CURLOPT_SSL_VERIFYPEER` y
`CURLOPT_SSL_VERIFYHOST` a `false` en el cliente.

## 7. Instalación

1. Sube la carpeta al servidor. Lo ideal es dejar accesible por web solo `api/`.
2. En `config.php` ajusta `JSONSQLDB_DATA_PATH` y `JSONSQLDB_LOG_PATH`; si puedes,
   apúntalos a carpetas **fuera del webroot**.
3. En `api/jsonsqldb_api_config.php` cambia `HMAC_SECRET` y las API keys.
4. Comprueba que la carpeta de datos y la de logs tienen permiso de escritura.
5. Lanza las pruebas para validar el entorno: `php tests/f4_api.php`.

Si no puedes mover `data/` y `logs/` fuera del webroot, los `.htaccess` y
`web.config` incluidos ya bloquean el acceso por navegador.

También puedes mover los dos ficheros de configuración fuera del webroot y
apuntarlos con las constantes `JSONSQLDB_CONFIG` y `JSONSQLDB_API_CONFIG`.

## 8. Ficheros

| Fichero | Responsabilidad |
|---|---|
| `api/jsonsqldb_api.php` | endpoint: valida, autoriza, ejecuta y registra |
| `api/jsonsqldb_api_config.php` | secreto HMAC, API keys con permisos y límites |
| `api/cliente_ejemplo.php` | cliente PHP para las aplicaciones |
| `api/cliente_ejemplo.ps1` | cliente PowerShell, con los mismos parámetros ligados |
| `engine/ApiStore.php` | estado de la API en JSON: rate limit, fallos, nonces, histórico |
| `tests/f4_api.php` | 44 comprobaciones lanzando peticiones reales |

## 9. Pruebas

```
php tests/f1_nucleo.php       → OK: 52
php tests/f2_parser.php       → OK: 60
php tests/f2_select.php       → OK: 77
php tests/f3_escrituras.php   → OK: 56
php tests/f4_api.php          → OK: 44
php tests/f5_esquema.php      → OK: 54
php tests/f5_admin.php        → OK: 97
```
