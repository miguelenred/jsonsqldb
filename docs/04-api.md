# jsonSQLDB — Part 4: the API

An HTTP endpoint to run SQL against a jsonSQLDB database from any application,
with HMAC signing and per-key permissions.

File: `api/jsonsqldb_api.php` — configuration: `api/jsonsqldb_api_config.php`

## 1. Request

`POST` with these parameters:

| Parameter | Content |
|---|---|
| `api_key` | the application's key |
| `db` | name of the database the statement runs against |
| `sql` | statement to run (may be multi-line and carry comments) |
| `params` | optional: JSON list with the values of the `?` in the SQL |
| `timestamp` | current UNIX time, 10 digits |
| `token` | HMAC-SHA256 signature of the request |

The signature is computed as:

```php
$token = hash_hmac('sha256',
    "+" . $apiKey . "|" . $db . "|" . $timestamp . "|" . $sql . $params . "¿", $secret);
```

where `$secret` is the `hmac_secret` field of that account in
`api/jsonsqldb_api_config.php`. **Every account has its own**, different from the
others.

`$db` is the database name exactly as sent in the `db` field, or an empty
string for the statements that target none (`SHOW DATABASES`,
`CREATE DATABASE`). `$params` is the JSON exactly as sent, or an empty string
if there are no parameters.

The signature covers the key, the database, the time, the SQL and the
parameters, so none of them can be changed in transit without knowing the
secret.

> **Breaking change in 2.0.** Until then the database was outside the
> signature, which allowed taking a legitimate request, changing its `db` field
> and replaying it against another database: the signature stayed valid because
> it did not cover it. For a key with access to several databases that was
> enough to run on the wrong one. **Any client signing with the old formula
> stops working** and must be updated. The four example clients (PHP, Python,
> PowerShell and the panel) already use the new one.

### The database goes in `db`, not in the SQL

There is no `USE` and no `mydb.customers` prefix: every request says in `db`
which database it works on, and the API checks that the key has access to that
database before running anything. With the client, the database is the fourth
argument:

```php
$shop      = new JsonSqlDbCliente($url, $apiKey, $secret, 'shop');
$warehouse = new JsonSqlDbCliente($url, $apiKey, $secret, 'warehouse');
```

A query cannot span two databases: they are separate folders.

`db` may only be **empty** for `SHOW DATABASES`, `CREATE DATABASE` and
`DROP DATABASE`, and only if the API key has `'bases' => ['*']`.

### The secret a client uses

The clients take the secret as a parameter: the third constructor argument in
PHP and Python, `-HmacSecret` in PowerShell. That value is the **`hmac_secret`
of that same account** in `api/jsonsqldb_api_config.php`. There is no global
secret: every key has its own.

```php
$cli = new JsonSqlDbCliente($url, 'MY_API_KEY', 'THE_SECRET_OF_THAT_KEY', 'mydb');
```

Signing with the wrong secret returns `Token inválido`, with no further detail:
the API does not distinguish a miscalculated signature from a key that does not
exist.

### The example clients' key

`cliente_ejemplo.php`, `cliente_ejemplo.ps1` and `cliente_ejemplo.py` come with an
API key of their own, **the same in all three**, with `escritura` permission on
the `pruebas` database and no other. It is registered in
`api/jsonsqldb_api_config.php` as «Clientes de ejemplo».

`escritura` allows `SELECT`, `INSERT`, `UPDATE` and `DELETE`, plus the `SHOW`
statements. It does not allow `CREATE`, `ALTER`, `DROP` or triggers: those need
an `admin` key.

In PHP the shortcut is `JsonSqlDbCliente::pruebas()`; in PowerShell it is preset
in `$Global:JsonSqlDb`. For your application, create a key of its own limited to
its databases instead of reusing this one.

### From Python

`api/cliente_ejemplo.py` does the same from Python, with the standard library
only: no `pip`, no `requests`. Requires Python 3.7 or later.

```python
from cliente_ejemplo import JsonSqlDbCliente

cli = JsonSqlDbCliente("https://myserver/jsonsqldb/api/jsonsqldb_api.php",
                       "MY_API_KEY", "THE_HMAC_SECRET_OF_THAT_KEY", "mydb")

rows = cli.consultar("SELECT * FROM customers WHERE city = ?", ["Madrid"])
cli.consultar("INSERT INTO customers (name, balance) VALUES (?, ?)", ["O'Donnell", 10.55])
print(cli.valor("SELECT COUNT(*) FROM customers"))
```

It returns lists of dictionaries for `SELECT` and `SHOW`, and a dictionary
`{'success': True, ...}` for writes. Errors arrive as a `JsonSqlDbError`
exception, not as a return value.

Own or self-signed certificate, as with the others:

```python
cli.certificado("C:/xampp/apache/conf/ssl.crt/server.crt")
cli.aceptar_autofirmado()
```

An implementation detail: the parameters' JSON is generated with
`separators=(",", ":")`, without spaces. The server signs the **exact** text it
receives, so what is signed and what is sent must be identical byte for byte.

### From PowerShell

`api/cliente_ejemplo.ps1` does the same from PowerShell, with bound parameters
and support for a certificate of your own:

```powershell
Set-JsonSqlDbConexion -Url 'https://shirka:44311/jsonsqldb/api/jsonsqldb_api.php' `
                      -ApiKey '...' -HmacSecret '...' -Base 'pruebas'

API-SQL-JSON "SELECT * FROM customers WHERE city = ?" @('Torrevieja')
API-SQL-JSON "SHOW DATABASES" -Base ''
```

Two PowerShell-specific details: decimals are converted with `InvariantCulture`,
so `10.55` comes out and not `10,55`; and the `¿` of the signature is built
from its code `[char]0x00BF`, so the token matches even if the `.ps1` file is
saved in ANSI instead of UTF-8.

## 1.1. Bound parameters

**Never build the SQL by concatenating values.** Put a `?` wherever a value goes
and send the values in `params`, in the same order.

The server **does not substitute text**: it parses the SQL with the `?` and
places each converted value inside the statement tree. A value cannot turn into
SQL, however much it looks like it. Quotes, semicolons or comments make no
difference: it is always treated as data.

```php
// WRONG — the value is part of the statement
$sql = "SELECT * FROM customers WHERE name = '$name'";

// RIGHT — the value travels apart
$rows = $cli->consultar('SELECT * FROM customers WHERE name = ?', [$name]);
```

With `$name = "x' OR 1=1; DROP TABLE customers; --"` the second form literally
looks for a customer with that name: it returns 0 rows and the table is not
touched.

Rules:

- One `?` = one value. If the count does not match, the request is rejected.
- Allowed values: `null`, boolean (stored as 1/0), integer, decimal and text.
  No lists or objects.
- The `?` go where a **value** goes: `WHERE`, `VALUES`, `SET`, `HAVING`,
  function arguments, `LIMIT` and `OFFSET`. They cannot stand for table or
  column names: that is structure, not data.
- `IN (?, ?, ?)` needs one `?` per element; build the list from how many values
  you have.
- A `?` inside a string (`'really?'`) is text, not a placeholder.
- The values are only logged if `JSONSQLDB_LOG_PARAMS` is on.

```php
// Several values and an IN list of variable size
$cities = ['Madrid', 'Valencia', 'Bilbao'];
$holes  = implode(',', array_fill(0, count($cities), '?'));

$rows = $cli->consultar(
    "SELECT name, balance FROM customers
      WHERE city IN ($holes) AND balance > ? AND joined >= ?
      ORDER BY balance DESC
      LIMIT ?",
    array_merge($cities, [100.50, '2026-01-01', 20])
);
```

From PHP, without the API, it is the same:

```php
$db = new JsonSQLDB\Database('mydb');
$db->consultar('UPDATE customers SET balance = balance + ? WHERE id = ?', [25.40, 7]);
```

## 2. Response

```json
// SELECT
[ {"id":1,"name":"Ana","city":"Madrid"}, {"id":2,"name":"Luis","city":"Valencia"} ]

// INSERT / UPDATE / DELETE / DDL
{"success":true,"filas":2,"mensaje":"2 fila(s) insertada(s)"}

// Error
{"error":"Error en la consulta: CONSTRAINT: La columna 'customers.name' no admite NULL"}
```

Types come already normalised by the engine: numbers as numbers, dates as
`yyyy-MM-dd[ HH:mm[:ss[.fff]]]` and nulls as `null`. Nothing needs converting in
the client.

> **Leave it at `false` in production.** With `DEVOLVER_ERRORES = true` the
> response includes the internal message, which may name tables and columns:
> convenient while developing and free information for whoever probes your API.
> The template ships with `false`.

With `DEVOLVER_ERRORES = false` errors are reduced to a generic message; the
detail is still in the log.

## 3. Permissions per API key

```php
$API_KEYS = [
    'jsonSQLDBadmin' => [
        'key'         => '...',
        'permiso'     => 'admin',
        'bases'       => ['*'],
        'hmac_secret' => '...',
    ],
];
```

| Permission | Allowed statements |
|---|---|
| `lectura` | `SELECT`, `SHOW`, `CHECK KEYS` |
| `escritura` | those plus `INSERT`, `UPDATE`, `DELETE`, `REPAIR KEYS` |
| `admin` | everything, including `CREATE`, `ALTER`, `DROP` and triggers |

The permission is checked **after parsing the SQL and before running it**, so
it looks at what the statement really does, not at how it is written. `bases`
limits which databases the key can access; `['*']` means all.

What each permission can do, without ambiguity:

| Statement | `lectura` | `escritura` | `admin` |
|---|:---:|:---:|:---:|
| `SELECT`, `UNION` | yes | yes | yes |
| `SHOW TABLES`, `SHOW VIEWS`, `SHOW SCHEMA` / `COLUMNS` | yes | yes | yes |
| `SHOW KEYS`, `SHOW TRIGGERS`, `SHOW INDEXES` | yes | yes | yes |
| `SHOW DATABASES` | yes | yes | yes |
| `CHECK KEYS` | yes | yes | yes |
| `INSERT`, `UPDATE`, `DELETE`, `REPAIR KEYS` | no | yes | yes |
| `CREATE` / `ALTER` / `DROP` of table, index, view or trigger | no | no | yes |
| `CREATE DATABASE`, `DROP DATABASE` | no | no | yes |

The list is maintained by hand in `api/jsonsqldb_api.php`, so adding a statement
to the parser and forgetting it here leaves it out. `tests/f4_api.php` runs
every `SHOW` with a read key so that it shows.

Keys and secrets **are deliberately not listed here**: duplicating a secret in
the documentation is a fine way for it to end up where it should not. They are
in `api/jsonsqldb_api_config.php`, which is their only place. `php configurar.php`
creates both configuration files with random keys already in place.

To generate a new key or secret, a different one each time:

```
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

The admin key and its `hmac_secret` must match `ADMIN_API_KEY` and
`ADMIN_HMAC_SECRET` in `jsonsqldbadmin/config.php`.

## 4. Protections

| Control | Configuration | Default |
|---|---|---|
| POST only | — | always |
| Maximum request size | `MAX_POST_SIZE` | 200 KB |
| Maximum SQL length | `MAX_SQL_LENGTH` | 100,000 characters |
| Bound parameters per request | `MAX_PARAMS`, `MAX_PARAMS_LENGTH` | 1,000 values, 100 KB |
| Maximum clock skew | `RATE_TIMESTAMP_DIFF` | 300 s |
| Anti-replay (single-use token) | `ANTI_REPLAY_ACTIVO` | enabled |
| Per-IP request limit | `RATE_LIMIT_ACTIVO`, `RATE_LIMIT_MAX`, `RATE_LIMIT_SECONDS` | **enabled**, 150 / 24 h |
| Cut-off on authentication failures | `RATE_LIMIT_GLOBAL_MAX` | 30 |
| IP allow-list (single IP or CIDR) | `IPS_PERMITIDAS` | empty (no filter) |
| Require HTTPS | `EXIGIR_HTTPS` | **`true`** |
| HSTS header | `HSTS_ACTIVO` | `false` |
| Trust X-Forwarded-For / -Proto | `CONFIAR_EN_PROXY` | `false` |

All state (per-IP counters, failures and used tokens) is kept in **JSON**, in
`logs/api/estado.json`, under an exclusive lock and pruning only expired
entries. There is no external database underneath.

Token comparison uses `hash_equals`, so the secret cannot be guessed by timing
responses.

### The defaults are the safe ones

`EXIGIR_HTTPS`, `ANTI_REPLAY_ACTIVO` and `RATE_LIMIT_ACTIVO` ship **enabled** and
`DEVOLVER_ERRORES` **disabled**. An installation that is not configured is
protected, not exposed.

The flip side: if you develop locally over `http://localhost`, the API rejects
your requests until you set `EXIGIR_HTTPS` to `false` (`php configurar.php
--local` does it for you). It is one line of configuration and a warning you
would rather get on your machine than discover in production.

`TIME_LIMIT` is **60 seconds** and `MEMORY_LIMIT` **256 MB**. An expensive query
occupies a PHP worker for all of that, and with a handful of workers that is a
denial of service made of legitimate requests. Raise them only if you have
queries or exports that really need it.

### One secret per key

Every entry of `$API_KEYS` is **indexed by the account name** and carries the
key in `key` and its secret in `hmac_secret`, both mandatory:

```php
'My application' => [
    'key'         => 'MY_API_KEY',
    'permiso'     => 'escritura',
    'bases'       => ['mydb'],
    'hmac_secret' => '...',        // a different one per account
],
```

There is no global secret. If several keys shared a secret, **any application
holding it could sign requests as another key, the admin one included**, and
per-key permissions would be worth nothing.

With one secret per key, a compromised application only compromises its own,
and is revoked by changing its key and secret without touching the others.

An account without `hmac_secret` cannot sign anything: the API answers
*«Configuración incompleta»* saying which key it is and what is missing.

### What to enable in production

In order of effectiveness:

1. **`IPS_PERMITIDAS`**. If the API's consumers are servers of yours with fixed
   IPs, this is the strongest protection: whoever is not on the list gets a 403
   before the signature is even looked at. Accepts single IPs (`10.0.0.7`) and
   CIDR ranges (`10.0.0.0/24`, `2001:db8::/32`).
2. **One `hmac_secret` per account**, as explained above.
3. **`HSTS_ACTIVO`**, only with a certificate from a recognised CA. With a
   self-signed one you would make the domain unreachable for a year.

`EXIGIR_HTTPS`, `RATE_LIMIT_ACTIVO`, `ANTI_REPLAY_ACTIVO` and `DEVOLVER_ERRORES`
already ship at their safe value: nothing to enable, only not to disable.

`CONFIAR_EN_PROXY` deserves a warning of its own: enable it **only** if a trusted
proxy or load balancer sits in front. With it on, the API believes the
`X-Forwarded-For` and `X-Forwarded-Proto` headers; if nobody is setting them,
anyone can fake their IP and bypass `IPS_PERMITIDAS` and the rate limit.

## 5. Logging

Every request leaves two traces:

- `logs/api/peticiones-YYYY-MM-DD.json` — the request: date, IP, user agent,
  database, API key label, operation, rows, milliseconds and error.
- `logs/consultas-YYYY-MM-DD.json` — the query run by the engine, with the full
  SQL.

Both are one file per day, rotate by size and are purged according to
`JSONSQLDB_LOG_DIAS` (0 = keep forever).

## 6. Client

`api/cliente_ejemplo.php` is a client ready to copy into the application that
will consume the API. It signs the request, sends it and returns the decoded
result:

```php
require 'cliente_ejemplo.php';

$cli = new JsonSqlDbCliente(
    'https://myserver/jsonsqldb/api/jsonsqldb_api.php',
    'MY_API_KEY',
    'THE_SECRET_OF_THAT_KEY',
    'mydb'
);

$rows = $cli->consultar('SELECT * FROM customers WHERE city = ?', ['Madrid']);
$cli->consultar('INSERT INTO customers (name, city) VALUES (?, ?)', ["O'Donnell", 'Logroño']);
```

Values travel in `params` and the server binds them to the `?` (see 1.1). The
client escapes nothing and does not touch the SQL.

With a self-signed certificate use `$cli->certificado('/path/to/server.crt')`
or `$cli->aceptarAutofirmado()`.

## 7. Installation

1. Upload the folder to the server. Ideally only `api/` is reachable from the
   web.
2. In `config.php` adjust `JSONSQLDB_DATA_PATH` and `JSONSQLDB_LOG_PATH`; if you
   can, point them to folders **outside the web root**.
3. In `api/jsonsqldb_api_config.php` set the API keys and each one's
   `hmac_secret` (`php configurar.php` generates them).
4. Check that the data and log folders are writable.
5. Run the tests to validate the environment: `php tests/f4_api.php`.

If you cannot move `data/` and `logs/` out of the web root, the bundled
`.htaccess` and `web.config` already block browser access on Apache, LiteSpeed
Enterprise and IIS (nginx: see [`../nginx/README.md`](../nginx/README.md);
OpenLiteSpeed: [`../litespeed/README.md`](../litespeed/README.md)).

You can also move both configuration files out of the web root and point at
them with the constants `JSONSQLDB_CONFIG` and `JSONSQLDB_API_CONFIG`.

## 8. Files

| File | Responsibility |
|---|---|
| `api/jsonsqldb_api.php` | endpoint: validates, authorises, runs and logs |
| `api/jsonsqldb_api_config.php` | API keys with permissions, secrets and limits |
| `api/cliente_ejemplo.php` | PHP client for applications |
| `api/cliente_ejemplo.ps1` | PowerShell client, with the same bound parameters |
| `api/cliente_ejemplo.py` | Python client, standard library only |
| `engine/ApiStore.php` | API state in JSON: rate limit, failures, nonces, history |
| `tests/f4_api.php` | 52 checks sending real requests |

## 9. Tests

```
php tests/f1_nucleo.php       → OK: 66
php tests/f2_parser.php       → OK: 70
php tests/f2_select.php       → OK: 144
php tests/f3_escrituras.php   → OK: 59
php tests/f4_api.php          → OK: 52
php tests/f5_esquema.php      → OK: 91
php tests/f5_admin.php        → OK: 119
```
