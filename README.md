# jsonSQLDB

A SQL database engine, HTTP API and web admin panel written in plain PHP, storing
data in JSON files. No database server, no Composer, no extensions beyond the
standard ones. You copy a folder and it works.

**Version 1.0.0** · [Apache License 2.0](LICENSE) · PHP 8.0+ (tested on 8.3)

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
joins, aggregates, foreign keys, triggers, transactions — over files you already
have room for. If you do not have that problem, you probably do not need this.

**Be realistic about the limits.** A query result is held in memory, so this is
built for tables in the thousands to low hundreds of thousands of rows, not
millions. There is no network protocol and no connection pool: concurrency is
handled with file locks, which is fine for a handful of simultaneous writers and
not for hundreds.

---

## Requirements

| | |
|---|---|
| **PHP** | 8.0 or later. Developed and tested on **PHP 8.3** |
| **PHP extensions** | Only the standard ones (`json`, `pcre`, `hash`, `filter`). **No** mbstring, **no** intl, **no** PDO |
| **cURL** | Required by **jsonSQLDBadmin** and by the test suite, because the panel talks to the API over HTTP. Not needed by the engine itself |
| **zip** | Optional. Only for the panel's "ZIP backup" button |
| **Web server** | Apache, IIS or nginx — see below |
| **Composer** | Not used |

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
# 1. Copy the project into your web root
#    (or clone it)
git clone https://github.com/miguelenred/jsonsqldb.git

# 2. Create the two configuration files from their templates
cp api/jsonsqldb_api_config.dist.php api/jsonsqldb_api_config.php
cp jsonsqldbadmin/config.dist.php    jsonsqldbadmin/config.php

# 3. Generate a secret and some API keys
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Replace every `CHANGE_ME_` placeholder in both files. The HMAC secret and the
admin API key must be **the same value** in both.

Then open `jsonsqldbadmin/` in a browser. The first screen asks you to create the
administrator account — there is no default password.

If you are on nginx, do [`nginx/`](nginx/) **before** exposing anything.

---

## How you connect

**The only way in is the API.** There is no driver, no socket, no direct file
access from your application. Every read and every write goes through a single
signed HTTP endpoint:

```
POST /jsonsqldb/api/jsonsqldb_api.php
```

That is a deliberate design choice, not a limitation. It means the storage layer
is never exposed, permissions are enforced in one place, every query is logged,
and your application can live on a different machine from the data.

### Request and response

Every request carries an API key, the target database, the SQL, its bound
parameters, a timestamp and an HMAC-SHA256 signature over all of it.

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
- A trigger wizard with a live preview of the generated statement
- Browse, filter, sort, insert, edit and delete rows
- A SQL editor for anything else
- Export a table or a query result to **CSV** or to **INSERT statements**;
  export a whole database as a **SQL dump** or a **ZIP** of its files
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
| **Schema** | `CREATE TABLE`, `DROP TABLE`, `ALTER TABLE`, `CREATE TRIGGER`, `DROP TRIGGER` |
| **Database** | `CREATE DATABASE`, `DROP DATABASE`, `SHOW DATABASES` |
| **Introspection** | `SHOW TABLES`, `SHOW SCHEMA`, `SHOW COLUMNS`, `SHOW KEYS`, `SHOW TRIGGERS` |

`SELECT` supports `DISTINCT`, `INNER`/`LEFT`/`CROSS JOIN`, `WHERE`, `GROUP BY`,
`HAVING`, `ORDER BY` (`ASC`/`DESC`), `LIMIT`/`OFFSET`, table and column aliases,
subqueries in `WHERE` and in `FROM`, and `CASE WHEN`.

`ALTER TABLE` supports `ADD COLUMN`, `MODIFY COLUMN`, `DROP COLUMN`,
`RENAME COLUMN`, `RENAME TO`, `ADD CONSTRAINT` (unique / foreign key),
`DROP CONSTRAINT`, `ADD PRIMARY KEY` and `DROP PRIMARY KEY`.

### Operators

`=` `<>` `!=` `<` `<=` `>` `>=` · `AND` `OR` `NOT` · `IS NULL` `IS NOT NULL` ·
`IN` `NOT IN` · `BETWEEN` `NOT BETWEEN` · `LIKE` `NOT LIKE` (with `%` and `_`,
case-insensitive) · `EXISTS` `NOT EXISTS` · `||` (string concatenation) ·
`+` `-` `*` `/` `%`

### Functions

**Aggregate** — `COUNT`, `SUM`, `AVG`, `MIN`, `MAX`

**Text** — `UPPER`, `LOWER`, `LENGTH`, `TRIM`, `LTRIM`, `RTRIM`, `REPLACE`,
`SUBSTR`, `SUBSTRING`, `INSTR`

**Numeric** — `ABS`, `ROUND`, `RANDOM`

**Date and time** — `DATE`, `TIME`, `DATETIME`, `STRFTIME`

**Null handling** — `COALESCE`, `IFNULL`, `NULLIF`

There is no `CONCAT()` — use the `||` operator, as in SQLite:

```sql
SELECT first_name || ' ' || IFNULL(last_name, '') AS full_name FROM customers;
```

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

### Storage

Each database is a directory. Each table is two files: `table.json` with the rows
and `table.meta.json` with the structure (columns, types, keys, triggers,
autoincrement counter). A `_database.json` holds database-level metadata.

Rows are stored one JSON object per line inside the rows array, so the file stays
readable and diffable, and a corrupt line does not destroy the rest.

### Concurrency

Every operation takes a file lock before touching anything: a **shared** lock for
reads, an **exclusive** lock for writes. Multiple readers run at once; a writer
waits for the readers to finish and blocks new ones while it works.

Writes are **atomic**: the new content is written to a temporary file and then
renamed over the original. A crash mid-write leaves the previous version intact,
never a half-written file.

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

Seven suites, no dependencies, all using temporary directories — they never touch
your data.

```
php tests/f1_nucleo.php       → OK: 52    storage, types, locking
php tests/f2_parser.php       → OK: 60    parser and bound parameters
php tests/f2_select.php       → OK: 77    SELECT execution and collation
php tests/f3_escrituras.php   → OK: 56    writes, DDL, keys and triggers
php tests/f4_api.php          → OK: 45    real requests against the API
php tests/f5_esquema.php      → OK: 54    SHOW, ALTER and constraints
php tests/f5_admin.php        → OK: 97    the panel, driven like a user
```

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
