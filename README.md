# jsonSQLDB

A SQL database engine, HTTP API and web admin panel written in plain PHP, storing
data in JSON files. No database server, no Composer, no extensions beyond the
standard ones. You copy a folder and it works.

**Version 2.3.0** · [Apache License 2.0](LICENSE) · PHP 8.0+ (CI runs 8.0 to 8.5)

---

## What this is for, and what it is not

**This is not trying to compete with MySQL, PostgreSQL, SQLite or anything
else.** Those are better at being databases than this will ever be, and if you
can use one, use one.

jsonSQLDB exists to cover a specific, annoying gap: **you need to store
structured data and your hosting gives you no database, or a limited number of
them, or one so small it does not fit what you need.** Plenty of shared hosting
plans include a single MySQL database, or cap you at two, or none at all on the
cheapest tier. Meanwhile you have plenty of disk space and PHP.

That is the hole this fills. If you have that problem, this gives you real SQL —
joins, aggregates, foreign keys and triggers — over files you already have room
for. If you do not have that problem, you probably do not need this.

**There are no transactions.** There is no `BEGIN`, `COMMIT` or `ROLLBACK`. Each
statement is atomic on its own — it either completes or leaves the data as it
was — but you cannot group several statements into one unit of work that rolls
back together. If your data needs that, this is not the right tool.

**If a query does not fit in memory**, the engine stops it at 85 % of PHP's
`memory_limit` with an ordinary error explaining what happened, instead of dying
with PHP's uncatchable fatal. The query still fails, but the process survives and
the API answers properly. Data is never corrupted by it: reads write nothing,
writes are buffered and flushed at the end, every file is written atomically, and
multi-table operations are undone by the journal.

Rough numbers on 20,000 customers and 30,000 orders, one core: a primary key
lookup 3.4 ms, a filtered scan 22 ms, an aggregate join 95 ms, a single-row
`INSERT` 39 ms. On 50,000 rows, `ORDER BY … LIMIT 20` is 169 ms and a single-row
`INSERT` 105 ms; on 100,000 rows that `INSERT` is 221 ms. Writes scale with the
table because each one rewrites the parts it touches and rebuilds the table's
indexes, so these numbers get worse as the table grows and batching matters more.
Measure on your own hardware rather than trusting these — a shared host with a
network disk will be slower than any of this:

```
php tests/benchmark.php            # 20,000 rows
php tests/benchmark.php 50000      # any size
php tests/benchmark.php 20000 csv  # CSV, to compare two versions
```

It reports the median of several runs, not the mean, and uses a fixed seed so two
runs compare the same data. SQLite is between 40 and 300 times faster at all of it, which is
what you would expect from a B-tree over binary pages against JSON decoded into
PHP arrays. The point of this engine is that it runs where neither MySQL nor the
SQLite extension is available.

**Writing rows one at a time is the slowest thing you can do**, because every
statement rewrites the table's file. Two thousand rows inserted as one statement
with many `VALUES` take about 30 ms; the same rows as two thousand separate
statements take about 5 seconds. Batch your inserts.

**Indexes speed up reads only.** Equality and `IN` on an indexed column read
only the parts of the table where the matching rows live. On a 50,000-row table,
looking up a primary key goes from 107 ms and 50 MB to 17 ms and 19 MB. Ranges,
`LIKE`, `ORDER BY` and aggregates still read everything. Writes get slower, not
faster: a table with indexes rewrites their files too, and the index is rebuilt
from scratch on every write because row positions shift. Primary keys and unique
constraints get an index automatically; anything else you create by hand with
`CREATE INDEX`. `JSONSQLDB_INDICES` turns the whole thing off if the write cost
is not worth it for your workload.

**A `LIMIT` without `WHERE` no longer loads the whole table.** `SELECT * FROM t
LIMIT 50` over 50,000 rows went from 56 ms and 50 MB to 3.6 ms and 3.6 MB,
because rows are read one at a time and the read stops when it has enough.
`SELECT COUNT(*)` and `SHOW TABLES` no longer materialise the rows at all.

**Be realistic about the limits.** A query result is held in memory, so this is
built for tables in the thousands to low hundreds of thousands of rows, not
millions. There is no network protocol and no connection pool: concurrency is
handled with file locks, which is fine for a handful of simultaneous writers and
not for hundreds. A write locks only its own table when it cannot affect any
other; anything involving foreign keys, triggers or schema changes locks the
whole database.

**Every write that touches more than one file is crash-safe.** That is most of
them, and counting tables was not enough: a table past
`JSONSQLDB_FILAS_POR_PARTE` rows lives in several files, a table with indexes has
one file per index, and an `INSERT` into a table with `AUTOINCREMENT` also
rewrites the schema file. Any of those runs under a journal, so an operation
interrupted by a power cut is undone the next time the database is opened —
whole or not at all, never half. The journal is scoped to whatever lock the write
holds, so two writes to different tables still run at the same time. What is
still not covered is grouping several statements into one unit of work — there is
no `BEGIN`/`COMMIT`.

---

## Requirements

| | |
|---|---|
| **PHP** | 8.0 or later. Developed on 8.3; CI runs every version from **8.0 to 8.5** |
| **PHP extensions** | Only the standard ones (`json`, `pcre`, `hash`, `filter`). **No** mbstring, **no** intl, **no** PDO |
| **cURL** | Required by **jsonSQLDBadmin** and by the test suite, because the panel talks to the API over HTTP. Not needed by the engine itself |
| **zip** | Optional. Only for the panel's "ZIP backup" button |
| **Web server** | Apache, IIS or nginx — see below |
| **Composer** | Optional. Only to install this project; it pulls in nothing else |

### Web server compatibility

The project keeps its private folders (`data/`, `logs/`, `engine/`, the panel's
internals) out of reach of the browser. **How that is enforced depends on your
server:**

| Server | Status | What you have to do |
|---|---|---|
| **Apache** 2.2 / 2.4 | Works out of the box | Nothing. Each folder ships an `.htaccess`. Requires `AllowOverride` to be enabled, which it is on virtually every shared host |
| **IIS** 7+ | Works out of the box | Nothing. Each folder ships a `web.config` |
| **nginx** | **Needs manual setup** | nginx does not read `.htaccess` or `web.config`. **You must install the rules in [`nginx/`](nginx/)** |

> **nginx users, read this.** Without the rules from the [`nginx/`](nginx/)
> folder, anyone can request
> `https://yourserver/jsonsqldb/data/mydb/customers.json` and download your
> entire table, unauthenticated. This is not a flaw in nginx or in the project —
> nginx simply centralises configuration in one file instead of spreading it
> across directories. The folder contains `jsonsqldb.conf` (ready to include) and
> `LEEME.md` explaining the three things you need to adjust and how to verify it
> is working.

### Folders that need write permission

Only these three. Everything else can stay read-only.

```
data/                    the databases themselves
logs/                    query log and API state (rate limiting, nonces)
jsonsqldbadmin/datos/    panel users, failed-login counters, audit trail
```

On Linux with Apache or nginx:

```bash
sudo chown -R www-data:www-data data logs jsonsqldbadmin/datos
sudo chmod -R 750 data logs jsonsqldbadmin/datos
```

On Windows with XAMPP the default permissions usually work as they are.

---

## Installation

```bash
# 1. Copy the project into your web root (or clone it)
git clone https://github.com/miguelenred/jsonsqldb.git
cd jsonsqldb

# 2. Create both configuration files, with random keys already in place
php configurar.php

#    Testing on your own machine over plain HTTP? Use this instead, and put
#    HTTPS back to true in both files before you publish anything:
php configurar.php --local
```

`configurar.php` copies the two `.dist` templates and replaces every
`CHANGE_ME_` placeholder with a random value, keeping the panel's key and secret
matching its account in the API — they have to be identical or the panel cannot
talk to the engine. It never overwrites an existing configuration file.

You can still do it by hand if you prefer: copy
`api/jsonsqldb_api_config.dist.php` and `jsonsqldbadmin/config.dist.php` to the
same names without `.dist`, and replace every `CHANGE_ME_` in both, generating
each value with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`.

**Neither the API nor the panel will start while a `CHANGE_ME_` value is left**,
and that is deliberate: the templates carry the same placeholders on both sides,
so forgetting to change them used to leave everything working — with a key and a
secret that are published in this repository.

Two more things that catch people out on a first install:

- **Both refuse plain HTTP by default.** On a real server that is what you want;
  get a certificate. On your own machine use `php configurar.php --local`, or set
  `EXIGIR_HTTPS` and `ADMIN_EXIGIR_HTTPS` to `false` yourself. The error message
  tells you this too, so you do not have to come back here.
- **The `data/` folder must be writable by the web server**, and is best kept
  outside the web root. See `JSONSQLDB_DATA_PATH` in `config.php`.

Then open `jsonsqldbadmin/` in a browser. **The first time the panel is opened —
and only the first time — it asks you to create the administrator account**: you
choose the username and password right there. There is no default password and
no factory user, so there is nothing to change afterwards and no chance of
leaving an `admin/admin` behind. The password is stored with bcrypt and must be
at least 10 characters.

Once that user exists the setup screen disappears and the panel asks for
credentials like any other. If you ever lose access, delete
`jsonsqldbadmin/datos/usuarios.json` and the panel will ask you to create the
administrator again.

If you are on nginx, do [`nginx/`](nginx/) **before** exposing anything.

---

## No external dependencies

This matters enough to be explicit about it: **jsonSQLDB uses no third-party
libraries at all.** Not one. The engine, the API and the admin panel are written
against the PHP standard library, and the only bundled third-party code is
Bootstrap and Bootstrap Icons for the panel's appearance, served from local files
with no CDN.

There is a `composer.json`, and it might look like a contradiction. It is not:
it exists so you can install jsonSQLDB *with* Composer if that is how you manage
your project. Its `require` section contains PHP itself and nothing else, so
`composer install` downloads no dependencies — because there are none to
download.

```bash
composer require miguelenred/jsonsqldb
```

That gives you PSR-4 autoloading for the `JsonSQLDB\` namespace, so you do not
even need to require `engine/bootstrap.php`. You can equally ignore Composer
entirely, copy the folder to your server, and require `engine/bootstrap.php`
yourself. Both routes are supported and neither is preferred.

## How you connect

By default, **the only way in is the API**: a single signed HTTP endpoint. There
is no driver and no socket. Direct engine access exists but is switched off until
you turn it on — see [Direct connection](#direct-connection-no-api) below.

Every read and every write goes through:

```
POST /jsonsqldb/api/jsonsqldb_api.php
```

That is a deliberate design choice, not a limitation. It means the storage layer
is never exposed, permissions are enforced in one place, every query is logged,
and your application can live on a different machine from the data.

### Request and response

Every request carries an API key, the target database, the SQL, its bound
parameters, a timestamp and an HMAC-SHA256 signature over all of it:

```php
$token = hash_hmac('sha256',
    "+" . $apiKey . "|" . $db . "|" . $timestamp . "|" . $sql . $params . "¿", $secreto);
```

Each API key signs with its own `hmac_secret`, so a compromised application
cannot sign as another key. `$db` is the database name as sent, or the empty
string for statements that target none (`SHOW DATABASES`, `CREATE DATABASE`);
`$params` is the JSON as sent, or empty when there are none.

> **The database name entered the formula in 2.0, and this breaks old clients.**
> Before, `db` was outside the signature, so a legitimate signed request could be
> captured, have its `db` changed and be replayed against a different database —
> the signature stayed valid because it did not cover the field. Anything signing
> with the old formula is rejected and has to be updated. The bundled PHP, Python
> and PowerShell clients and the admin panel already use the new one. Full
> details in [docs/04-api.md](docs/04-api.md).

**All responses are JSON.** Three shapes, and that is all:

```jsonc
// SELECT and SHOW: an array of rows
[ {"id": 1, "name": "Ana", "balance": 10.55}, {"id": 2, "name": "Luis", "balance": 0} ]

// INSERT / UPDATE / DELETE / DDL
{"success": true, "filas": 3, "mensaje": "3 fila(s) insertada(s)"}

// Anything that went wrong
{"error": "Error en la consulta: SYNTAX: ..."}
```

Numbers come back as JSON numbers, not strings. `NULL` comes back as `null`.

### Example clients

Two ready-to-use clients are included. Copy the one you need into your
application; neither has dependencies.

| File | For |
|---|---|
| [`api/cliente_ejemplo.php`](api/cliente_ejemplo.php) | PHP applications |
| [`api/cliente_ejemplo.ps1`](api/cliente_ejemplo.ps1) | PowerShell scripts |
| [`api/cliente_ejemplo.py`](api/cliente_ejemplo.py) | Python 3.7+, standard library only |

They handle the signature, the bound parameters and the TLS certificate
(including self-signed ones) for you.

```php
require 'cliente_ejemplo.php';

$db = new JsonSqlDbCliente(
    'https://yourserver/jsonsqldb/api/jsonsqldb_api.php',
    'YOUR_API_KEY', 'YOUR_HMAC_SECRET', 'mydatabase'
);

$rows = $db->consultar('SELECT * FROM customers WHERE city = ?', ['Madrid']);
$db->consultar('INSERT INTO customers (name, balance) VALUES (?, ?)', ["O'Donnell", 10.55]);
```

```python
from cliente_ejemplo import JsonSqlDbCliente

db = JsonSqlDbCliente("https://yourserver/jsonsqldb/api/jsonsqldb_api.php",
                      "YOUR_API_KEY", "THAT_KEY_S_HMAC_SECRET", "mydatabase")

rows = db.consultar("SELECT * FROM customers WHERE city = ?", ["Madrid"])
```

```powershell
. .\cliente_ejemplo.ps1

Set-JsonSqlDbConexion -Url 'https://yourserver/jsonsqldb/api/jsonsqldb_api.php' `
                      -ApiKey 'YOUR_API_KEY' -HmacSecret 'YOUR_HMAC_SECRET' -Base 'mydatabase'

API-SQL-JSON "SELECT * FROM customers WHERE city = ?" @('Madrid')
```

### Values never go into the SQL string

Put `?` where a value belongs and pass the values separately. The server parses
the statement first and places each value into the syntax tree as a literal, so
a value can never become SQL no matter what it contains:

```php
$name = "x'); DROP TABLE customers; --";
$db->consultar('SELECT * FROM customers WHERE name = ?', [$name]);
// Looks for a customer literally called that. Returns 0 rows. Table untouched.
```

---

## Direct connection (no API)

PHP code running on the same server can use the engine without going through
HTTP. It is **disabled by default**. To enable it, in `config.php`:

```php
defined('JSONSQLDB_CONEXION_DIRECTA') || define('JSONSQLDB_CONEXION_DIRECTA', true);
```

> **For experienced developers only.** With direct access, security is entirely
> your responsibility. Read what follows before turning it on.

- **There are no permissions.** A direct connection is always equivalent to an
  `admin` API key: it can read, write, alter the schema and drop whole databases.
  There is no way to restrict it to one database or to read-only.
- **There is no API key and no signature.** It bypasses HMAC authentication, the
  rate limit, replay protection and the IP allow-list. Nothing stands between an
  unvalidated variable in your code and the data.
- **It is still logged.** Every query goes to the log exactly as it would through
  the API, with the `ip` field set to `"local"`, since there is no HTTP request
  to take an address from.
- **Bound parameters still work, and you should still use them.** They are the
  only thing protecting you from injection here, and there is no API forcing you
  to get it right.

When it makes sense: a maintenance script, a migration, a cron job, or your own
application on the same server where the HTTP hop only adds latency. For
anything exposed to third parties, use the API.

### Examples

With Composer's autoloader:

```php
require 'vendor/autoload.php';

define('JSONSQLDB_CONEXION_DIRECTA', true);
define('JSONSQLDB_DATA_PATH', __DIR__ . '/data');

$db = new JsonSQLDB\Database('mydatabase');

$rows = $db->consultar('SELECT * FROM customers WHERE city = ?', ['Madrid']);
$db->consultar('INSERT INTO customers (name, balance) VALUES (?, ?)', ["O'Donnell", 10.55]);
```

Without Composer, requiring the project's own bootstrap:

```php
require 'config.php';                    // your settings, with direct access on
require 'engine/bootstrap.php';

$db = new JsonSQLDB\Database('mydatabase');

// Reads
$rows  = $db->consultar('SELECT id, name FROM customers WHERE balance > ?', [100]);
$total = $db->consultar('SELECT COUNT(*) AS n FROM customers')[0]['n'];

// Writes: returns ['success' => true, 'filas' => n, 'mensaje' => '...']
$r = $db->consultar('UPDATE customers SET balance = balance + ? WHERE id = ?', [25.40, 7]);
echo $r['filas'], " row(s) updated\n";

// Schema and maintenance — a direct connection is always admin
$db->consultar('ALTER TABLE customers ADD COLUMN notes VARCHAR(200)');
$db->consultar('CHECK KEYS');

// Databases: these are static, they do not belong to one database
JsonSQLDB\Database::crear('another');
print_r(JsonSQLDB\Database::bases());
```

A migration script, which is the case this is really for:

```php
require 'config.php';
require 'engine/bootstrap.php';

$db = new JsonSQLDB\Database('mydatabase');

foreach ($db->consultar('SELECT id, email FROM customers WHERE email IS NOT NULL') as $row) {
    $db->consultar('UPDATE customers SET email = ? WHERE id = ?',
                   [strtolower(trim($row['email'])), $row['id']]);
}
```

Errors arrive as `JsonSQLDB\JsonSqlDbError`, which carries a `sqlState` telling
you what kind of problem it was:

```php
try {
    $db->consultar('INSERT INTO customers (code) VALUES (?)', ['A1']);
} catch (JsonSQLDB\JsonSqlDbError $e) {
    echo $e->sqlState, ': ', $e->getMessage();   // CONSTRAINT: ... already exists
}
```

## jsonSQLDBadmin

A web panel for managing everything, bundled in [`jsonsqldbadmin/`](jsonsqldbadmin/).

**It requires cURL to be enabled**, because the panel is just another API client:
it talks to `jsonsqldb_api.php` over HTTP exactly like your application does, and
never touches the engine or the data files directly. On XAMPP, uncomment
`extension=curl` in `php.ini`.

What it does:

- Create, list, back up and drop databases
- Create, rename, empty and drop tables; full column editing (type, length,
  decimals, `NOT NULL`, `UNIQUE`, `DEFAULT`)
- Primary keys (including composite ones, added after the fact), unique keys and
  foreign keys with `ON DELETE` / `ON UPDATE`
- Views: create, list and drop, with a jump to the SQL editor
- Referential integrity check and repair
- A trigger wizard with a live preview of the generated statement
- Browse, filter, sort, insert, edit and delete rows
- A SQL editor for anything else
- Export a table or a query result to **CSV** or to **INSERT statements**;
  export a whole database as a **SQL dump** or a **ZIP** of its files, and
  restore that ZIP back (both read and write the engine's files from disk, so
  they are only offered when the panel and the API share a host)
- An optional read-only API key, so the engine itself refuses writes from
  read-only users rather than trusting the panel's own check
- Its own users with `admin` / `read-only` roles, bcrypt passwords, session
  expiry, per-IP lockout after failed logins, CSRF tokens on every form, and a
  daily audit trail

Bootstrap 5.3.3 and Bootstrap Icons are bundled locally. The panel makes **zero**
external requests.

---

## Supported SQL

### Statements

| | |
|---|---|
| **Query** | `SELECT` |
| **Write** | `INSERT`, `UPDATE`, `DELETE` |
| **Schema** | `CREATE TABLE`, `DROP TABLE`, `ALTER TABLE`, `CREATE TRIGGER`, `DROP TRIGGER`, `CREATE VIEW`, `DROP VIEW`, `CREATE INDEX`, `DROP INDEX` |
| **Database** | `CREATE DATABASE`, `DROP DATABASE`, `SHOW DATABASES` |
| **Introspection** | `SHOW TABLES`, `SHOW VIEWS`, `SHOW SCHEMA`, `SHOW COLUMNS`, `SHOW KEYS`, `SHOW TRIGGERS`, `SHOW INDEXES` |
| **Maintenance** | `CHECK KEYS`, `REPAIR KEYS` |

`SELECT` supports `DISTINCT`, `WITH` (CTEs), `UNION` / `UNION ALL` /
`INTERSECT` / `EXCEPT`, correlated subqueries, `INNER`/`LEFT`/`RIGHT`/`FULL`/`CROSS JOIN`, `WHERE`, `GROUP BY`,
`HAVING`, `ORDER BY` (`ASC`/`DESC`), `LIMIT`/`OFFSET`, table and column aliases,
subqueries in `WHERE` and in `FROM`, and `CASE WHEN`.

`ALTER TABLE` supports `ADD COLUMN`, `MODIFY COLUMN`, `DROP COLUMN`,
`RENAME COLUMN`, `RENAME TO`, `ADD CONSTRAINT` (unique / foreign key),
`DROP CONSTRAINT`, `ADD PRIMARY KEY` and `DROP PRIMARY KEY`.

### Operators

`=` `<>` `!=` `<` `<=` `>` `>=` · `AND` `OR` `NOT` · `IS NULL` `IS NOT NULL` ·
`IN` `NOT IN` · `BETWEEN` `NOT BETWEEN` · `LIKE` `NOT LIKE` (with `%` and `_`,
case-insensitive) · `EXISTS` `NOT EXISTS` · `REGEXP` `RLIKE` (and `NOT`) · `||` (string concatenation) ·
`+` `-` `*` `/` `%`

### Functions

**Aggregate** — `COUNT`, `SUM`, `AVG`, `MIN`, `MAX`, `GROUP_CONCAT`

**Text** — `CONCAT`, `UPPER`, `LOWER`, `LENGTH`, `TRIM`, `LTRIM`, `RTRIM`, `REPLACE`,
`SUBSTR`, `SUBSTRING`, `INSTR`

**Numeric** — `ABS`, `ROUND`, `RANDOM`

**Date and time** — `DATE`, `TIME`, `DATETIME`, `STRFTIME`

**Null handling** — `COALESCE`, `IFNULL`, `NULLIF`

**Conversion** — `CAST(expr AS type)`, accepting the same type names as
`CREATE TABLE`

`CONCAT()` is available for people coming from MySQL, but `||` is the native
operator, as in SQLite:

```sql
SELECT first_name || ' ' || IFNULL(last_name, '') AS full_name FROM customers;
```

### Dialect

**It resembles SQLite's but is not compatible with it.** There are SQLite
constructs missing here, others borrowed from MySQL, and concrete behavioural
differences — the largest being that a text compared against a number is
converted (`'12abc'` is 12) rather than following the declared column's affinity.
Do not assume a query that runs in SQLite runs here, or the other way round.

Borrowings, where they are what people expect:
`CONCAT`, `REGEXP`/`RLIKE` and `LIMIT n, m` come from MySQL, `CAST` and
`FULL JOIN` are standard SQL, and `AUTO_INCREMENT` is accepted alongside
`AUTOINCREMENT`. Anything not mentioned behaves as in SQLite. See
[`docs/02-consultas.md`](docs/02-consultas.md) for the full table.

### What is not supported

The rule: **if a statement is accepted, it does exactly what it promises;
otherwise it is rejected with a clear error.** Nothing is accepted and silently
ignored.

These exist in SQLite and raise an error here: `INSERT OR IGNORE` / `OR REPLACE`
(no upsert — do a `SELECT` and pick), `CREATE TEMP`/`TEMPORARY TABLE` (no
temporary tables), `WITHOUT ROWID` (there is no rowid), `BEGIN`/`COMMIT`/
`ROLLBACK` (no multi-statement transactions), `CHECK` constraints (use a `BEFORE`
trigger with `RAISE(ABORT, …)`), `CREATE UNIQUE INDEX` (an index here only speeds
up lookups; use `ALTER TABLE … ADD UNIQUE`, which creates its own index), `GLOB` (use `LIKE`
or `REGEXP`), window functions (`OVER`), and `WITH RECURSIVE` — plain CTEs do
work, but a query cannot refer to itself.

Behavioural differences worth knowing: `DECIMAL` is a rounded float, `ORDER BY`
uses a configurable collation rather than binary order, and `LIKE` is
case-insensitive but accent-sensitive.

### Data types

| Type | Aliases | Notes |
|---|---|---|
| `INTEGER` | `INT`, `BIGINT`, `SMALLINT`, `TINYINT` | The only type that can be `AUTOINCREMENT` |
| `DECIMAL(p,s)` | `NUMERIC`, `NUMBER`, `MONEY` | Float rounded to `s` decimals. `p` is accepted and ignored |
| `DOUBLE` | `REAL`, `FLOAT`, `DOUBLE PRECISION` | Not rounded |
| `TEXT` | `VARCHAR(n)`, `CHAR`, `STRING` | `n` is enforced as a maximum length |
| `DATETIME` | `DATE`, `TIMESTAMP` | See the format note below |
| `BOOLEAN` | `BOOL` | Stored as 1 / 0 |

> **`DATETIME` format.** Values must be **`YYYY-MM-DD`**, with the time part
> **optional**: `YYYY-MM-DD HH:MM:SS`. So `2026-08-20` and
> `2026-08-20 14:30:00` are both valid; `20/08/2026` and `08-20-2026` are
> rejected with a type error. jsonSQLDBadmin shows this reminder in the column
> forms.

> **`DECIMAL` is a rounded float, not an exact decimal.** Rounding is applied on
> write, but arithmetic carries the usual binary floating-point error. For money
> that must reconcile to the cent across many rows, store integer cents in an
> `INTEGER` column and divide when displaying.

### Constraints and behaviour

`PRIMARY KEY` (simple or composite) · `AUTOINCREMENT` · `NOT NULL` · `UNIQUE`
(single or multi-column) · `DEFAULT` · `FOREIGN KEY` with `ON DELETE` /
`ON UPDATE` `NO ACTION` `CASCADE` `RESTRICT` `SET NULL` `SET DEFAULT` ·
`BEFORE`/`AFTER` triggers on `INSERT`/`UPDATE`/`DELETE` with `WHEN`, `NEW.`,
`OLD.` and `RAISE(ABORT, '…')`.

`CHECK KEYS` reports rows whose foreign key points at a value that no longer
exists in the parent table, and `REPAIR KEYS` sets those keys to `NULL` where the
column allows it — it never deletes rows. The engine enforces foreign keys on
every write, so this only happens when someone edits a `.json` by hand or
restores one table's backup without the other. The check reads straight from
disk, bypassing the cache, which is the only way a hand edit would show up.

**Views** are stored `SELECT` statements you query like a table. They hold no
data, are resolved on every query, and are read-only. They can nest up to 8
levels. They do not make anything faster — with no indexes, a view over a
three-table join scans all three every time.

Alphabetical ordering in `ORDER BY` is configurable: by default it ignores case
and accents and puts `ñ` after `n`, and a per-language map handles alphabets
where accented letters sort separately (Swedish `å ä ö` after `z`).

---

## How it works

A four-layer stack. Each layer only talks to the one below it.

```
Your application  ──HTTP──►  api/jsonsqldb_api.php
jsonSQLDBadmin    ──HTTP──►         │
                                    ▼
                              engine/  (the SQL engine)
                                    ▼
                              data/<database>/<table>.json
```

### More than one installation on the same machine

Nothing special is needed: **everything the engine shares between processes lives
inside the database folder** — the lock files, the journal, the temporaries and
the on-disk cache — so two installations with different data folders never see
each other. The one genuinely global resource is APCu, and its keys are prefixed
with a digest of the data path so two databases with the same name in different
installations cannot collide.

What is worth keeping separate, because it is not the engine's doing:
`JSONSQLDB_DATA_PATH`, `API_ESTADO_PATH` (the anti-replay and per-IP quota),
`ADMIN_SESION_NOMBRE` (two panels on one domain would share the cookie) and
`ADMIN_DATA_PATH`. All four default to paths inside the project folder, so two
copies of the project are already separate without touching anything.

Several processes against the *same* database is a different thing — that is
ordinary concurrency, handled by the locks above.

### Upgrading from 1.x

Replace the folder and keep your two configuration files
(`api/jsonsqldb_api_config.php` and `jsonsqldbadmin/config.php`, both gitignored).
The data needs no conversion: an existing database is read as it is, and each
table moves to the new layout on the first write to it — a `<table>.rev.json`
replacing its entry in the shared `_revs.json`, and an index file for its primary
key and unique constraints. Revision numbers carry on from where the old file
left off rather than restarting, so a stale cache entry cannot be mistaken for a
current one. The old `_revs.json` is left alone and stops being read; you can
delete it once every table has been written to.

**Update your API clients before or at the same time**, because the HMAC formula
changed and old signatures are rejected. The bundled PHP, Python and PowerShell
clients and the admin panel are already updated.

One thing worth doing first: **open each database with the old version once**
before swapping the folder, so anything left half-finished gets rolled back while
the version that wrote it is still in place. It is not required — a journal left
pending by 1.x is recognised and undone by 2.0 as well — but it is the tidier
order.

`tests/f9_journal.php` covers all four cases: reading an old-format database
without writing to it, the first write producing the new files without reusing
revision numbers, a journal left pending by the previous version being undone,
and the database still working afterwards.

### Storage

Each database is a directory. A table is `table.json` with the rows,
`table.meta.json` with the structure (columns, types, keys, indexes, triggers,
autoincrement counter), `table.rev.json` with a counter that invalidates the
cache, plus `table.partN.json` once it grows past `JSONSQLDB_FILAS_POR_PARTE`
rows (1,000 by default) and one `table.idx.<name>.json` per index. A `_database.json` holds
database-level metadata.

Rows are stored one JSON object per line inside the rows array, so the file stays
readable and diffable, a corrupt line does not destroy the rest, and a query that
only needs a few rows can read them one at a time instead of materialising the
whole file.

The revision counter is per table and not one shared file, because two writes to
different tables run at the same time: a single shared counter meant whichever
finished last erased the other's bump and left its cache serving stale rows.

### Indexes

An index maps a value to the positions of the rows that hold it, which tells the
engine which part files it has to decode. Primary keys and unique constraints get
one automatically, named `auto_<columns>`; `CREATE INDEX name ON t (a, b)` adds
your own. A composite index is used left to right: `(a, b)` serves a lookup on
`a`, or on `a` and `b`, but not on `b` alone.

Only `=` and `IN` against literals, and only in the top-level `AND` chain of a
`WHERE`. Anything under a `NOT`, a top-level `OR`, `IS NULL` and `NOT IN` are
left alone, because using an index there would change the result rather than just
speed it up. Index keys follow the engine's own equality, not PHP's: `5`, `'5'`
and `'5.0'` share a key, so looking up a number still finds the row that stored
it as text.

The whole index is rebuilt on every write to the table. Positions are not stable
— saving re-packs the rows from zero, so one `DELETE` shifts every row after it
into a different part — and keeping positions up to date incrementally would be
an endless source of quiet, hard-to-see wrong answers. Rebuilding costs one pass
over rows that are already in memory, which is small next to the `json_encode` of
the table that the write does anyway. Each index file records the revision it
belongs to; if it does not match, the engine ignores the file and scans, so a
stale or hand-edited index can make a query slower but never wrong.

### Concurrency

Locking has two levels, always taken in this order — first the database, then the
table — which is what makes a deadlock impossible:

| Operation | Database | Table |
|---|---|---|
| Reads (`SELECT`, `SHOW`, `CHECK KEYS`) | shared | **shared**, per table read |
| A write to **one** table with no foreign keys or triggers | shared | **exclusive** |
| Cascades and triggers, when the set of tables is knowable | shared | **exclusive on every table** it can reach |
| Schema changes, views, `REPAIR KEYS`, `INSERT ... SELECT` | **exclusive** | — |

So two writes to different tables run at the same time, and a write no longer
blocks reads of other tables.

A write that can propagate does not lock the database. Writing to a table does
not always stay in it — a foreign key with `ON DELETE CASCADE` drags child rows,
a trigger can write anywhere — so the engine works out the set of tables it could
reach first, following foreign keys in both directions and transitively, plus
wherever the triggers write, by parsing their SQL. Only those are locked, so an
unrelated group of tables carries on writing at the same time.

Two things make that safe: **all the locks are taken up front**, because asking
for one more halfway through a write is how deadlocks happen; and they are taken
**in alphabetical order**, so two processes that need the same tables ask in the
same sequence and one waits for the other instead of both waiting forever.

It falls back to the exclusive database lock the moment the set cannot be stated:
a trigger whose SQL will not parse, an `INSERT ... SELECT` (which reads from other
tables), any schema change, or more than eight tables, where taking that many
locks costs more than taking one.

Reads take the shared lock of each table they touch. Shared locks do not block
each other, so reads still run together; a read only waits when that same table
is being written. This is needed as soon as a table spans more than one file: the
writer replaces them one at a time, and without it a reader could pick up the
first part already new and the second still old — rows from two different
versions, with no power cut involved.

Writes are **atomic and durable**: the new content goes to a temporary file, is
forced to disk with `fsync()`, and is then renamed over the original. A crash
mid-write leaves the previous version intact, never a half-written file — and
never a file whose contents were still sitting in the operating system's cache.
`fsync()` exists from PHP 8.1; on 8.0 the buffer is flushed, which is as far as
that version goes.

That covers one file. For the rest there is a journal: before touching anything,
the files about to change are copied into `.tx/<scope>/` and a manifest is written
last. If the process dies, the copies are still there and the next time the
database is opened they go back. The manifest being written last is what makes it
safe — no manifest means the copying never finished, which means nothing was
modified yet, so the leftovers are discarded rather than restored. The scope is
whichever lock the write holds: `.tx/_base/` for anything holding the database
lock, `.tx/<table>/` for a write scoped to one table, so journalling does not cost
the concurrency that the table lock buys. Checking whether anything is pending is
a single `stat` on `.tx/`.

### Memory

On cheap shared hosting memory is the binding constraint, so it gets its own
section. **How much you need is set by the largest table a query touches, not by
the size of the database.**

#### The number that matters

A 1.9 MB JSON file becomes about 26 MB of PHP arrays — roughly **14×**. That is
not overhead you can tune away: every row is a hash table with its own copy of
the column names as keys, and short values inflate more than long ones. A table
of a hundred wide-but-short columns expands more than one of five long text
fields.

So the working rule is:

```
memory_limit  ≥  20 × (the largest table a single query has to read in full)
```

A 10 MB table wants 256 MB. If two tables are joined, add both. If APCu is
enabled, its memory is separate and does not count against `memory_limit`.

**Lowering `JSONSQLDB_FILAS_POR_PARTE` does not reduce this.** Splitting a table
into more part files only bounds the size of each individual decode; a query that
needs every row still ends up with every row in memory. What the split does buy
is the ability to *skip* parts, which is where indexes come in.

#### Why compression is not the answer

The peak is not the JSON text, it is the decoded PHP array, and an array has to
be decompressed to be filtered, joined or sorted. Compressing the cache on disk
or in APCu would save disk and shared memory, but not the process memory that
`memory_limit` actually governs. The only real lever is **not loading rows the
query does not need**, which is what everything below does.

#### What the engine does about it

| | Before | After |
|---|---|---|
| `SELECT * FROM t LIMIT 50` (50,000 rows) | 56 ms · 50 MB | **3.6 ms · 3.6 MB** |
| `SELECT * FROM t WHERE id = ?` (50,000 rows) | 107 ms · 50 MB | **17 ms · 19 MB** |
| `SELECT COUNT(*) FROM t` (50,000 rows) | 49 ms · 50 MB | **52 ms · 32 MB** |

- **Rows are read one at a time when the query will discard most of them.** The
  files are one JSON object per line, so a `LIMIT` or an indexed lookup never
  holds the whole file text and the whole decoded array at the same time. When
  the query genuinely wants every row the file is decoded in one call instead,
  which is about 25 % faster and costs nothing extra, because those rows were
  going to be held either way.
- **Indexes decode only the parts where the matching rows live.** On a table
  spread over twenty parts, an equality lookup reads one.
- **`LIMIT` is pushed into the read** when there is no `WHERE` and no `JOIN` —
  with either of those the surviving rows are not the first ones, so the read
  cannot stop early.
- **`SELECT COUNT(*)` and `SHOW TABLES` never build the rows at all.**
  `SHOW TABLES` used to load every table in the database just to count them.
- **Rows are no longer held twice while being prepared for a query.** Loading a
  table copies each row with its columns prefixed by the table alias; the
  original is now released as it goes, instead of keeping both full copies until
  the loop ended. That is the 50 MB → 32 MB above.
- **The cache steps aside when memory is tight.** Storing a table means
  serialising it, which briefly doubles it. Past half the limit the engine skips
  the cache rather than risk the query for it.

#### When it still will not fit

A result that genuinely does not fit cannot be made to fit. What the engine
guarantees is *how* it fails: `JSONSQLDB_MEMORIA_VIGILAR` makes it stop and raise
a normal error with `sqlState` `MEMORIA` — catchable, with the connection intact
and the data untouched — instead of PHP's fatal, which cannot be caught, runs no
`finally`, and hands the client a broken response.

The guard checks both the memory in use and the memory PHP has **requested from
the system**. The limit applies to the latter, and the gap between them — blocks
already requested but too fragmented to reuse — is not negligible when
`memory_limit` is small: with 16 MB the process hit the fatal with only 13 MB in
use. It also never reserves less than a fraction of what is already allocated,
because when a PHP array fills up it asks for double and copies, and that jump
costs about as much as the array already occupies — something no amount of
watching the previous rows can predict.

Data is never at risk from running out of memory, whichever way it ends: a read
writes nothing, a write accumulates in memory and flushes at the end so it has
not touched the disk yet, and the locks are released by the operating system when
the process dies.

### Query execution

1. **Lexer** turns the SQL text into tokens. String literals only accept `''` as
   an escape — there is no backslash escaping, which removes a whole class of
   injection tricks.
2. **Parser** builds a syntax tree. Only one statement per request is accepted,
   so `; DROP TABLE …` never gets a chance to run. Bound parameters are inserted
   into the tree **here**, as literal nodes — they are never concatenated into
   SQL text.
3. **Executor** resolves sources and joins, applies `WHERE`, groups, applies
   `HAVING`, sorts, and slices with `LIMIT`/`OFFSET`.
4. **Writer** applies changes, checking types, `NOT NULL`, uniqueness and foreign
   keys, and firing triggers before and after.
5. **Logger** records the query, its duration, the row count and any error.

### The API layer

The endpoint verifies, in this order: source IP against the allow-list, HTTPS if
required, the request size, the API key, the HMAC signature over
`api_key | timestamp | sql | params`, the timestamp window, the rate limit, and
finally the key's permission level against the *parsed* statement type — so
permissions are checked against what the statement actually is, not against a
string match on its text.

Three permission levels: `lectura` (SELECT/SHOW), `escritura` (adds
INSERT/UPDATE/DELETE), `admin` (adds DDL). Each key is also restricted to a list
of databases.

Optional, all off by default so nothing breaks on first install: IP allow-list
(single IPs and CIDR ranges, IPv4 and IPv6), HTTPS enforcement, HSTS, replay
protection, per-IP rate limiting, and suppressing detailed error messages.

---

## Tests

Eleven suites, no dependencies, all using temporary directories — they never touch
your data.

```
php tests/f1_nucleo.php       → OK: 65    storage, types, locking, direct access
php tests/f2_parser.php       → OK: 70    parser and bound parameters
php tests/f2_select.php       → OK: 138   SELECT execution and collation
php tests/f3_escrituras.php   → OK: 59    writes, DDL, keys and triggers
php tests/f4_api.php          → OK: 52    real requests against the API
php tests/f5_esquema.php      → OK: 89    SHOW, ALTER, constraints, views, integrity
php tests/f5_admin.php        → OK: 119   the panel, driven like a user
php tests/f6_cortes.php       → OK: 31    crash recovery, killing real processes
php tests/f7_concurrencia.php → OK: 23    real simultaneous processes and locking
php tests/f8_indices.php      → OK: 57    indexes, against a full scan every time
php tests/f9_journal.php      → OK: 38    every partial state a crash can leave
php tests/f10_indices_incrementales.php → OK: 16   indexes extended instead of rebuilt
```

Two of these are worth knowing what they actually do.

`f6_cortes.php` kills real processes with `SIGKILL`, which cannot be caught — no
destructors, no shutdown functions, no chance to clean up. It does it mid-cascade
across two tables, mid-write to a table spread over part files with indexes, and
once for each kind of operation in turn: `UPDATE` of every row, `UPDATE` of an
indexed column, an `INSERT` that adds a part, a `DELETE` that removes one,
`CREATE INDEX`, `DROP INDEX`, four kinds of `ALTER TABLE`, `CREATE TABLE`,
`DROP TABLE`. After each kill it reopens the database and demands that every row
be either the old value or the new one and never a mix, that the indexes agree
with the data, that the cache agrees with the files, and that nothing is left
behind.

Killing a process does not reproduce everything a power cut does, so the last
part of that suite builds the damaged journals by hand: a copy truncated under a
valid manifest, a journal with no manifest, a `COMMITTED` one, two table journals
at once, a revision file ahead of its data, a missing one. Many kills land
outside the critical window, and the suite reports how many landed inside so
nobody assumes it proved more than it saw.

`f9_journal.php` exists because that reporting kept being uncomfortable. Killing
processes is realistic but it samples: where the kill lands is luck, and a run
can easily miss the window entirely. So this suite does the opposite — it is
exhaustive and deterministic. It opens a real journal on a table spread across
ten files and then builds by hand **every** intermediate state the write could
have been interrupted in: the first file already replaced and the rest not, the
first two, the first three, all of them; the same again with files truncated
instead of replaced, and again with them deleted; each of those under both a
database-scoped and a table-scoped journal. Sixty-six states, and for each one it
demands that every file come back to its exact original bytes.

It also checks the two invariants the whole scheme rests on. First, that the
journal copies everything a write touches — if a write modified a file the
journal had not copied, recovery could not give it back, and that is data loss
with nothing to warn you. Second, that writes which skip the journal really do
touch a single file. To know whether a write journalled without guessing, the
test drops a *file* named `.tx` where the directory would go: any attempt to
journal then fails, so a write that succeeds is one that never tried.

`f8_indices.php` almost never asserts a literal result. It runs the indexed query
and the same condition rewritten so the index cannot be used, and requires the
two to be identical. An index that returns too much is obvious; one that returns
too little is not, and that is the failure worth catching. The oracle has to be
the engine scanning the table rather than a comparison in PHP, because the
engine's equality is its own: `'0'`, `'00'` and `'-0'` are the same value to it.

The engine, the API and the panel pass PHPStan at level 5 with no warnings. The
configuration is not committed — it is a development tool and the project needs
nothing beyond PHP itself — but the source carries the `@phpstan-type` and
`@phpstan-impure` annotations that make such an analysis meaningful.

`f5_admin.php` needs cURL and starts two PHP built-in servers — one for the panel
and one for the API, because the built-in server handles one request at a time
and the panel calls the API from inside its own request.

---

## Documentation

Full documentation lives in [`docs/`](docs/), **written in Spanish**:

| | |
|---|---|
| [`docs/00-indice.md`](docs/00-indice.md) | Index and starting points |
| [`docs/01-nucleo.md`](docs/01-nucleo.md) | Storage, types, locking, catalogue, logging |
| [`docs/02-consultas.md`](docs/02-consultas.md) | `SELECT`: syntax, functions, ordering |
| [`docs/03-escrituras.md`](docs/03-escrituras.md) | Writes, DDL, keys, triggers |
| [`docs/04-api.md`](docs/04-api.md) | The HTTP API, signing, bound parameters, clients |
| [`docs/05-admin.md`](docs/05-admin.md) | jsonSQLDBadmin |
| [`nginx/LEEME.md`](nginx/LEEME.md) | nginx setup — **required reading if you use nginx** |

Source code comments are in Spanish as well.

---

## Authorship

Copyright 2026 Miguel Sanchez.

The concept, architecture, functional and technical specification, design
decisions and review of this project are the work of its author.

The implementation — the PHP source code, the test suites and the documentation —
was written by **Claude Opus 5**, an AI model developed by Anthropic, working
from that specification and under the author's direction and review.

Every design decision, trade-off and acceptance criterion was made by a human.
Every line was produced by the model and reviewed before being kept.

See [AUTHORS](AUTHORS) and [NOTICE](NOTICE).

---

## Licence

Apache License 2.0 — see [LICENSE](LICENSE) and [NOTICE](NOTICE).

You may use, modify and redistribute this project for any purpose, personal,
professional or commercial. The licence requires you to keep the copyright
notice, to reproduce the contents of the `NOTICE` file in any redistribution, and
to state any changes you make to the source files.

Beyond that, and **not** as a legal condition: if this project is useful to you,
a visible credit and a link back to the original repository is appreciated.
Something as simple as *"powered by jsonSQLDB"* with a link is enough.
