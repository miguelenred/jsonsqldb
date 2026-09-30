# jsonSQLDB

A SQL database engine, HTTP API and web admin panel written in plain PHP, storing
data in JSON files. No database server, no Composer, no extensions beyond the
standard ones. You copy a folder and it works.

**Version 2.7.1** · [Apache License 2.0](LICENSE) · PHP 8.0+ (CI runs 8.0 to 8.5)

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

**If a query does not fit in memory**, the engine stops it by 85 % of PHP's
`memory_limit` at the latest — earlier if its next step would not fit — with an
ordinary error explaining what happened, instead of dying
with PHP's uncatchable fatal. The query still fails, but the process survives and
the API answers properly. Data is never corrupted by it: reads write nothing,
writes are buffered and flushed at the end, every file is written atomically, and
multi-file writes are finished or discarded whole by the journal.

Rough numbers on 20,000 customers and 30,000 orders, one core, PHP 8.3,
on-disk cache: a primary key lookup 0.6 ms and 6 MB, a lookup by a `UNIQUE` text
column 0.6 ms, a scan with a numeric filter and no index 12 ms and 6 MB, a
`GROUP BY` 26 ms and 6 MB, an aggregate join of both tables 134 ms and 22 MB, a
single-row `INSERT` 7 ms and 6 MB, an `UPDATE` by key 7 ms and 6 MB, and any
of those repeated on unchanged data under half a millisecond. On 100,000 rows:
primary key lookup 0.75 ms and 6 MB, `UNIQUE` text lookup 2 ms, `GROUP BY`
115 ms and 6 MB, single-row `INSERT` 14 ms. The writes are measured
on a disk where an `fsync` costs 0.1 ms; on a shared host's disk, where it
costs a few milliseconds, add that four times. A write reads and rewrites only the parts
it touches and the pieces of the indexes that cover them; a read holds the
whole table only when the query genuinely needs every row at once. Measure on
your own hardware rather than trusting these — a shared host with a network
disk will be slower than any of this:

```
php tests/benchmark.php            # 20,000 rows
php tests/benchmark.php 50000      # any size
php tests/benchmark.php 20000 csv  # CSV, to compare two versions
```

It reports the mean of several runs of each query and uses a fixed seed so two
runs compare the same data; the result cache is switched off for the run so
what it measures is the engine, and measured on its own at the end. SQLite is still many times faster at all of it,
which is what you would expect from a B-tree over binary pages against JSON
decoded into PHP arrays. The point of this engine is that it runs where neither
MySQL nor the SQLite extension is available.

**Batch your inserts.** Every statement rewrites the last part of the table, its
revision file and its indexes, so two thousand rows as one statement with many
`VALUES` cost about what one row costs; the same rows as two thousand separate
statements cost two thousand times that.

**Indexes speed up reads and writes.** Equality and `IN` on an indexed column
read only the parts of the table where the matching rows live, and on a
numeric column only the index pieces whose recorded range can hold the value:
on 100,000 rows a primary key lookup takes 0.75 ms and 5.5 MB instead of scanning
29 MB of JSON, reading one line of one part by its byte offset. A `BETWEEN` on
a numeric indexed column reads only the parts whose values can fall in the
range. The last index pieces read stay in the process, so a loop of lookups
does not decode them again.
Ranges, `LIKE`, `ORDER BY` and aggregates still read everything. Writes use them
too: an `INSERT` checks uniqueness against the index on disk and appends to the
last part without loading the table, and an `UPDATE` or `DELETE` by key reads
and rewrites only the parts that hold the affected rows. An index is stored in
one piece per part of the table, so a write rewrites the pieces it touched, not
the whole index. Primary keys and unique constraints get an index automatically;
anything else you create by hand with `CREATE INDEX`. `JSONSQLDB_INDICES` turns
the whole thing off.

**Reads stream one part at a time and hold as little as they can.** A `WHERE`
scan keeps only the rows that pass, `GROUP BY` and the aggregates keep one
accumulator per group rather than the rows, `ORDER BY … LIMIT n` keeps only the
n rows in the lead, `SELECT * FROM t LIMIT 50` stops reading as soon as it has
enough, and `SELECT COUNT(*)` and `SHOW TABLES` never build the rows at all.
Only a query that genuinely needs every row at once — `ORDER BY` without
`LIMIT`, the inner side of a `JOIN`, `DISTINCT` — holds the table.

**Files and space, and how to tune them for your hosting.** A table is one
file per 1,000 rows, plus one per part for each index, plus — without APCu —
a serialised copy of each in `.cache/`. The benchmark with 100,000 customers
and 150,000 orders ends with 709 files and 29 MB of data and indexes, and
707 files and 41 MB of cache. Space is never the problem on a cheap plan; the
number of files can be, if the account counts inodes. Three knobs, in order:
APCu (the cache leaves the disk entirely, no speed lost);
`JSONSQLDB_CACHE_ACTIVA = 'apcu'` or `false` (no `.cache/`, reads decode JSON
every time: a key lookup 13 ms instead of 8 on 100,000 rows);
`JSONSQLDB_FILAS_POR_PARTE = 5000` (four times fewer files of every kind; a
key lookup 6.7 ms instead of 2 and an `UPDATE` by key 25 ms instead of 11 on
20,000 rows; scans unchanged). The full table of trade-offs, measured, is in
[`docs/01-core.md` §9](docs/01-core.md#9-files-space-and-how-to-tune-them).
Deleting `.cache/` is always safe.

**A `JOIN` behind a selective `WHERE` looks rows up instead of hashing a
table.** The parts of the `WHERE` that concern the left side run before the
join, and when few rows are left and the right-hand table has an index on the
`ON` columns, each one is looked up by key: one order and its customer in
0.46 ms and 5.6 MB instead of about 20 ms and 25 MB.

**A repeated `SELECT` on unchanged data is not run again.** Its result is
cached under the SQL, the parameters and the revision of every table it
touches; any write to one of them changes the revision and the cached result
stops matching. Queries that depend on the moment (`RANDOM()`, `DATE('now')`)
and results over `JSONSQLDB_CACHE_RESULTADOS` rows are never cached.

**Be realistic about the limits.** A query result is held in memory, so this is
built for tables in the thousands to low hundreds of thousands of rows, not
millions. There is no network protocol and no connection pool: concurrency is
handled with file locks, which is fine for a handful of simultaneous writers and
not for hundreds. A write locks only its own table when it cannot affect any
other; anything involving foreign keys, triggers or schema changes locks the
whole database.

**Every write that touches more than one file is crash-safe.** That is nearly
all of them: a table past `JSONSQLDB_FILAS_POR_PARTE` rows lives in several
files, a table with indexes has one file per index, every write rewrites the
revision file, and an `INSERT` into a table with `AUTOINCREMENT` also rewrites
the schema file. Every file is first written to a temporary and forced to disk;
then a manifest lists the renames and they are applied together. A power cut
before the manifest leaves the data untouched; one after it is finished the
next time the database is opened — whole or not at all, never half. The journal
is scoped to whatever lock the write holds, so two writes to different tables
still run at the same time. What is still not covered is grouping several
statements into one unit of work — there is no `BEGIN`/`COMMIT`.

---

## Principles

Three rules that every change to this project has to respect. When a faster or
smaller design breaks one of them, it is not done — and the documentation says
so, with the numbers, where it happened.

1. **The data stays readable by a person.** Every table is JSON that you can
   open in a text editor, read, and understand: one row per line, the column
   names spelled out, no binary encoding, no compression, no format that needs
   a tool to decode. The indexes, the revision files and the journal follow the
   same rule. This is why the on-disk cache is a copy that can be deleted, not
   the data itself, and why a per-piece filter for text keys was not added.

2. **It runs on cheap hosting.** No database server, or one too small or too
   limited to use: that is the hosting this project is for. Plain PHP 8.0 or
   later, no Composer, no extensions beyond the standard ones, and nothing that
   has to stay running between requests. It must work within a small
   `memory_limit` (the engine stops a query by 85 % of it at the latest, with an
   ordinary error rather than dying) and without APCu.

3. **Few disk operations.** On a shared host the disk is shared, often slow, and
   every `fsync` costs milliseconds. Writes touch only the parts of a table they
   change, and force to disk only what must survive a power cut; reads use the
   indexes to open as few files as possible. This, and not raw speed, is what
   most of the design is organised around.

And one that follows from the others: **nothing is lost in a power cut.** A
statement either completes or leaves the data as it was. This one needs PHP 8.1
or later: on 8.0 there is no `fsync()`, and it holds for a crashed process but
not for a power cut (see
[PHP 8.0 works, but 8.1 or later is recommended](#php-80-works-but-81-or-later-is-recommended)).

---


## Requirements

| | |
|---|---|
| **PHP** | 8.0 or later. Developed on 8.3; CI runs every version from **8.0 to 8.5**. **8.1 or later recommended**: 8.0 has no `fsync()`, so a power cut can lose roughly the last 30 seconds of writes (a crashed or killed process loses nothing on any version). See [below](#php-80-works-but-81-or-later-is-recommended) |
| **PHP extensions** | Only the standard ones (`json`, `pcre`, `hash`, `filter`). **No** mbstring, **no** intl, **no** PDO |
| **cURL** | Optional. **jsonSQLDBadmin** uses it for the API when it is there, and PHP's own streams when it is not; with the direct connection it makes no HTTP calls at all. The panel tests (`f5_admin.php`, `f11_asistente.php`) do need it. Not needed by the engine |
| **zip** | Optional. Only for the panel's "ZIP backup" button |
| **Web server** | Apache, LiteSpeed, IIS or nginx — see below |
| **Composer** | Optional. Only to install this project; it pulls in nothing else |

### PHP 8.0 works, but 8.1 or later is recommended

> **Warning.** On PHP 8.0 jsonSQLDB cannot guarantee that a write reported as
> done survives a **power cut**. Use PHP 8.1 or later if your hosting offers it.

**Why.** When a program writes a file, the data does not go to the disk at once:
the operating system keeps it in memory and writes it back a little later,
because that is much faster. If the power fails in between, whatever was still
in memory is gone. The only way for a program to say "put this on the disk now,
and do not return until it is there" is the system call `fsync()`. PHP exposes
it as a function only since **PHP 8.1**.

jsonSQLDB relies on it for its guarantee that nothing is lost in a power cut:
every write forces the new data files, the revision file and the journal
manifest to disk before it reports success (see
[Durability](docs/01-core.md#6-durability-atomic-files-and-the-journal)). On
8.0 that call does not exist, and **there is no reliable substitute in plain
PHP**: `fflush()` only empties PHP's own buffer into the operating system; the
`dio` extension, which has one, is not installed on shared hosting; `posix` has
no `fsync`; and running `sync` through `exec()` needs a shell that shared
hosting does not give, and flushes the whole machine.

**What that means in practice on PHP 8.0:**

| Event | PHP 8.0 | PHP 8.1 or later |
|---|---|---|
| The PHP process dies, is killed or runs out of memory mid-write | nothing is lost: the journal finishes or discards the write | nothing is lost |
| Power cut, or the operating system crashes | writes the system had not yet put on disk are lost — on Linux with default settings, roughly the **last 30 seconds** — even though they were reported as done | nothing that was reported as done is lost |
| Could a table be left damaged? | on ext4 with default options (the usual on hosting), no: the kernel writes a replaced file's data before its rename. On other filesystems a replaced file could be left empty; `INTEGRITY CHECK` would report it and a backup is the way back | no |

Everything else — features, speed, memory — is the same on 8.0. The setup
wizard and the Configuration page of jsonSQLDBadmin show this warning when they
run on 8.0. PHP 8.0 itself has had no security fixes since November 2023, which
is a second reason to move if you can.

### Web server compatibility

The project keeps its private folders (`data/`, `logs/`, `engine/`, the panel's
internals) out of reach of the browser. **How that is enforced depends on your
server:**

| Server | Status | What you have to do |
|---|---|---|
| **Apache** 2.2 / 2.4 | Works out of the box | Nothing. Each folder ships an `.htaccess`. Requires `AllowOverride` to be enabled, which it is on virtually every shared host |
| **LiteSpeed Enterprise** | Works out of the box | Nothing. It reads the same `.htaccess` files Apache does. See [`litespeed/`](litespeed/) |
| **IIS** 7+ | Works out of the box | Nothing. Each folder ships a `web.config` |
| **nginx** | **Needs manual setup** | nginx does not read `.htaccess` or `web.config`. **You must install the rules in [`nginx/`](nginx/)** |
| **OpenLiteSpeed** | **Needs manual setup** | OpenLiteSpeed applies `.htaccess` only for rewrite rules, and only at startup. **Put the rules in the virtual host as described in [`litespeed/`](litespeed/)** |

> **nginx and OpenLiteSpeed users, read this.** Without the rules from the
> [`nginx/`](nginx/) or [`litespeed/`](litespeed/) folder, anyone can request
> `https://yourserver/jsonsqldb/data/mydb/customers.json` and download your
> entire table, unauthenticated. This is not a flaw in nginx or in the project —
> nginx simply centralises configuration in one file instead of spreading it
> across directories. The `nginx/` folder contains `jsonsqldb.conf` (ready to
> include) and a `README.md` explaining the three things you need to adjust and
> how to verify it is working; `litespeed/README.md` does the same for
> OpenLiteSpeed, and says what to check on LiteSpeed Enterprise.

### Folders that need write permission

Only these three. Everything else can stay read-only.

```
data/                    the databases themselves
logs/                    query log and API state (rate limiting, nonces)
jsonsqldbadmin/datos/    panel users, failed-login counters, audit trail
```

On Linux with Apache, LiteSpeed or nginx:

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

If you are on nginx or OpenLiteSpeed, do [`nginx/`](nginx/) or
[`litespeed/`](litespeed/) **before** exposing anything.

---

## No external dependencies

This matters enough to be explicit about it: **jsonSQLDB uses no third-party
libraries at all.** Not one. The engine, the API and the admin panel are written
against the PHP standard library, and the only bundled third-party code is
Bootstrap for the panel's appearance, served from local files with no CDN.

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

The Python and PowerShell clients work the same way; each file opens with a
usage example.

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

With Composer, `require 'vendor/autoload.php';` instead of the bootstrap.
Requiring the project's own bootstrap:

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

It talks to the engine in one of two ways, chosen in a **setup wizard** the
first time it is opened: **through the API**, over HTTP and signed exactly like
your application (it uses cURL if it is there and PHP's own streams if not), or
by **direct connection** (2.7), loading the engine without HTTP when the panel
and the data are on the same machine. With either, the engine itself applies
the role of each panel user. Apart from the ZIP backup and restore, the panel
never reads or writes the data files.

It manages databases, tables, columns, keys, views, triggers and rows; checks
and repairs referential integrity; exports to CSV, `INSERT` statements, SQL
dump or ZIP (and restores the ZIP); and has its own users with `admin` /
read-only roles, bcrypt passwords, per-IP lockout, CSRF tokens and a daily
audit trail. The full tour is in [`docs/05-admin.md`](docs/05-admin.md).

Bootstrap 5.3.3 is bundled locally and the icons are inline SVG; light and dark
theme. The panel makes **zero** external requests.

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
[`docs/02-queries.md`](docs/02-queries.md) for the full table.

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

Everything the engine shares between processes lives inside the database
folder, so two installations with different data folders never see each other;
APCu keys are prefixed with a digest of the data path for the same reason.
Keep `JSONSQLDB_DATA_PATH`, `API_ESTADO_PATH`, `ADMIN_SESION_NOMBRE` and
`ADMIN_DATA_PATH` separate — all four already default to paths inside the
project folder.

### Upgrading from an earlier version

Replace the folder and keep your two configuration files
(`api/jsonsqldb_api_config.php` and `jsonsqldbadmin/config.php`, both gitignored).
**The data needs no conversion**: an existing database is read as it is, and each
table moves to the current layout on the first write to it — a `<table>.rev.json`
replacing its entry in the old shared `_revs.json` (pre-2.0), index files for its
primary key and unique constraints (pre-2.0), and the row count and per-part and
per-index revisions added to `rev.json` (2.5), and the index split into one
piece per part plus a `creada` marker in `rev.json` (2.6). Revision numbers carry on from
where they were rather than restarting, so a stale cache entry cannot be mistaken
for a current one.

**Coming from 1.x, update your API clients before or at the same time**, because
the HMAC formula changed in 2.0 and old signatures are rejected. The bundled PHP,
Python and PowerShell clients and the admin panel are already updated.

One thing worth doing first: **open each database with the old version once**
before swapping the folder, so anything left half-finished is settled by the
version that wrote it. It is not required — a journal left pending by any earlier
version is recognised and undone by 2.5 as well — but it is the tidier order.

Two configuration constants were removed in 2.5 and are ignored if still
defined: `JSONSQLDB_JOURNAL_DATOS` and `JSONSQLDB_CACHE_MAX_FILAS`. One was
added in 2.6, `JSONSQLDB_CACHE_RESULTADOS`, with a working default; nothing to
change unless you want the result cache off.

### Storage

Each database is a directory. A table is `table.json` with the rows,
`table.meta.json` with the structure (columns, types, keys, indexes, triggers,
autoincrement counter), `table.rev.json` with a revision counter and the state of
the table's parts and indexes, plus `table.partN.json` once it grows past
`JSONSQLDB_FILAS_POR_PARTE` rows (1,000 by default) and one
`table.idx.<n>.json` / `table.idx.<n>.partN.json` per index and part. A
`_database.json` holds database-level metadata. Everything is readable JSON,
one row per line, so a file stays diffable and editable by hand.

The revision file is per table and not one shared file, because two writes to
different tables run at the same time: a single shared counter meant whichever
finished last erased the other's bump and left its cache serving stale rows. It
records at which revision each part and each piece of each index was last
written, so a write that touches one part of a hundred leaves the other
ninety-nine cached and leaves the untouched index pieces alone. It also carries
a random number fixed when the table was first written, so a table dropped and
recreated under the same name cannot be served the old table's cache.

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

An index is stored in **one piece per part of the table** and **corrected
rather than rebuilt** whenever the engine can prove what changed: rows appended
at the end, rows replaced in place, or rows shifted from a position on by a
`DELETE`. Only the pieces that cover the positions concerned are read and
rewritten; the header of every other piece is checked so a damaged file is
rebuilt at the next write. Any doubt rebuilds the index from the rows. The
revision file says which revision each piece belongs to; on any mismatch the
engine ignores the index and scans, so a stale or hand-edited index can make a
query slower but never wrong.

### Concurrency

Locking has two levels, always taken in this order — first the database, then the
table — which is what makes a deadlock impossible:

| Operation | Database | Table |
|---|---|---|
| Reads (`SELECT`, `SHOW`, `CHECK KEYS`) | shared | **shared**, per table read |
| A write to **one** table with no foreign keys or triggers | shared | **exclusive** |
| An `UPDATE` or `DELETE` that depends only on each row (2.7) | shared | **shared** while it works, **exclusive** only to commit (writes by part) |
| Cascades and triggers, when the set of tables is knowable | shared | **exclusive on every table** it can reach |
| Schema changes, views, `REPAIR KEYS`, `INSERT ... SELECT` | **exclusive** | — |

So two writes to different tables run at the same time, and a write does not
block reads of other tables.

A write that can propagate works out the set of tables it could reach first
(foreign keys both ways and transitively, plus wherever the triggers write),
takes every lock up front in alphabetical order — which is what makes deadlock
impossible — and falls back to the database lock when the set cannot be
stated. Reads take each table's shared lock, so reads run together and only
wait for a write to that same table. Every lock goes through a turnstile so a
writer waiting behind continuous readers gets in as soon as the readers
already inside finish, instead of never (2.6.1). And an `UPDATE` or `DELETE`
that depends only on each row does its work holding the table's shared lock and
locks it only to commit, checking then that the parts it rewrote did not change
meanwhile — if they did, it runs again with the table locked (2.7). Readers
stop waiting for it: on the benchmark, eleven times as many reads during
writes, at a fourteenth of the latency. The detail is in
[`docs/01-core.md`](docs/01-core.md); `php tests/benchmark_concurrencia.php`
measures readers and writers on one table in real processes.

Writes are **atomic and durable**: the new content goes to a temporary file, is
forced to disk with `fsync()`, and is then renamed over the original. A crash
mid-write leaves the previous version intact, never a half-written file — and
never a file whose contents were still sitting in the operating system's cache.
`fsync()` exists from PHP 8.1; on 8.0 the buffer is flushed, which is as far as
that version goes.

That covers one file. Multi-file writes are protected by a **redo journal** in
`.tx/<scope>/`: every file is written to its temporary first, then a manifest
listing the renames is written in one piece, then the renames happen. A crash
before the manifest leaves the data untouched (the temporaries are swept); a
crash after it is finished on the next open, as many times as it takes. It
replaced the copy-everything-first undo journal of earlier versions, which is
where most of the write cost used to go; pending journals of the old format are
still recognised and undone.

### Memory

On cheap shared hosting memory is the binding constraint, so it gets its own
section. **How much you need is set by the largest table a query has to hold in
full, not by the size of the database.**

#### The number that matters

A 1.9 MB JSON file becomes about 26 MB of PHP arrays — roughly **14×**. That is
not overhead you can tune away: every row is a hash table with its own copy of
the column names as keys, and short values inflate more than long ones. A table
of a hundred wide-but-short columns expands more than one of five long text
fields.

So the working rule is:

```
memory_limit  ≥  20 × (the largest table a single query has to hold in full)
```

A query holds a table in full only when it needs every row at once: `ORDER BY`
without `LIMIT`, `GROUP BY`, the inner side of a `JOIN`, `DISTINCT`. A `WHERE`
scan does not, and neither does a write. If APCu is enabled, its memory is
separate and does not count against `memory_limit`.

**Lowering `JSONSQLDB_FILAS_POR_PARTE` does not reduce the full-table case.**
Splitting a table into more part files only bounds the size of each individual
decode; a query that needs every row still ends up with every row in memory.
What the split does buy is the ability to *skip* parts, which is what indexes
and streaming do.

#### What the engine does about it

Measured with `php tests/benchmark.php` on 20,000 customers and 30,000 orders
(on-disk cache, one core, PHP 8.3), 2.6.1 against 2.7, each the mean of three
runs taken one after the other; differences under 10 % are within what two runs
of the same version differ by. The writes and the lookup by a `UNIQUE` text
column are 2.7.1, also the mean of three runs; the rest did not change in
2.7.1:

| | 2.6.1 | 2.7 |
|---|---|---|
| Lookup by primary key | 2.85 ms · 7.1 MB | **0.58 ms · 5.5 MB** |
| `IN` of ten primary keys | 3.45 ms · 7.1 MB | **0.41 ms · 5.5 MB** |
| Lookup by a `UNIQUE` text column | 2.40 ms · 7.3 MB | **0.57 ms · 5.3 MB** |
| `BETWEEN` on the primary key (1,000 rows) | 19.5 ms (scan) | **1.89 ms · 6.3 MB** |
| Numeric range, no index | 19.5 ms · 5.7 MB | **12.0 ms · 6.3 MB** |
| `JOIN` of one order with its customer | ~20 ms · 25 MB | **0.46 ms · 5.6 MB** |
| `LEFT JOIN` of twenty orders | ~60 ms · 23 MB | **3.7 ms · 6.1 MB** |
| `GROUP BY`, `ORDER BY`, `LIKE`, aggregated `JOIN`, subquery | — | same, ±8 % |
| `INSERT` / `UPDATE` / `DELETE` one row | 11.0 / 13.6 / 18.3 ms | **7.2 / 6.7 / 11.1 ms** |

On 100,000 rows: a primary key lookup from 9.3 ms and 13 MB to 0.75 ms and
5.5 MB, ten keys by `IN` from 9.1 ms to 0.56 ms, a range without index from
92 ms to 58 ms, and the rest the same. A lookup by a `UNIQUE` text column
goes from 8.6 ms to 1.9 ms: a text key can be in any piece of the index, so all
of them are read, but as text, searching for the key, without decoding them.
Writes of one row on 100,000 rows: `INSERT` 22.5 → 13.6 ms, `UPDATE` 26.1 →
13.9 ms, `DELETE` 22.5 → 9.9 ms. The
writes do not change here because this machine's disk makes an `fsync` cost
0.1 ms; on a shared host's disk, where it costs 3 ms, a one-row `INSERT` goes
from 50 ms to 28 ms because 2.7 makes four `fsync` calls where 2.6 made ten.

Memory: lookups use 1.5 MB less; everything else about 0.6 MB more on this
benchmark. Of that, 0.24 MB is the larger code of the engine, which with
OPcache (the normal case on a web server) lives in shared memory and does not
count; the rest is what the process keeps between statements (four index
pieces, the row offsets of sixteen parts, the table structures), which a
request of one or two queries does not accumulate and which is the first thing
let go when memory runs short.

Rows are read one part at a time and filtered as they arrive, and used as they
come out of the cache without copying; aggregates are accumulated instead of
collecting the rows of each group; `ORDER BY … LIMIT` keeps only the leading
rows; a `JOIN` streams and loads only the columns the query names; `WHERE`
conditions are compiled once; indexes decode only the parts where the matching
rows live and are stored in pieces so a write rewrites one; `LIMIT` is pushed
into the read when nothing filters after it; `SELECT COUNT(*)` counts lines
without decoding a single row; and the cache steps aside when memory is tight.

#### When it still will not fit

A result that genuinely does not fit cannot be made to fit. What the engine
guarantees is *how* it fails: `JSONSQLDB_MEMORIA_VIGILAR` makes it stop and raise
a normal error with `sqlState` `MEMORIA` — catchable, with the connection intact
and the data untouched — instead of PHP's fatal, which cannot be caught, runs no
`finally`, and hands the client a broken response.

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

Twelve suites, no dependencies, all using temporary directories — they never
touch your data.

```
php tests/f1_nucleo.php       → OK: 66    storage, types, locking, direct access
php tests/f2_parser.php       → OK: 70    parser and bound parameters
php tests/f2_select.php       → OK: 146   SELECT execution and collation
php tests/f3_escrituras.php   → OK: 64    writes, DDL, keys and triggers
php tests/f4_api.php          → OK: 54    real requests against the API
php tests/f5_esquema.php      → OK: 91    SHOW, ALTER, constraints, views, integrity, journal, result cache
php tests/f5_admin.php        → OK: 126   the panel, driven like a user
php tests/f6_cortes.php       → OK: 33    crash recovery, killing real processes
php tests/f7_concurrencia.php → OK: 28    real simultaneous processes and locking
php tests/f8_indices.php      → OK: 60    indexes, against a full scan every time
php tests/f9_journal.php      → OK: 32    every intermediate state a crash can leave
php tests/f10_indices_incrementales.php → OK: 16   indexes corrected instead of rebuilt
php tests/f11_asistente.php    → OK: 31    panel setup wizard and direct connection
php tests/f12_contra_sqlite.php → OK: 4    139 queries and 16 writes, same results as SQLite
```

`f6_cortes.php` kills real processes with `SIGKILL` mid-write and demands that
every row be either the old value or the new one, never a mix. `f9_journal.php`
is its deterministic counterpart: it rebuilds by hand every state a commit passes
through — each temporary already in place or still pending — and demands the
exact bytes of the finished write; it also blocks the journal folder to prove
that a write which cannot journal changes nothing. `f8_indices.php` never asserts
literal results: it compares every indexed query against the same condition
written so the index cannot be used, and the cheap write paths against a table
without indexes.

The engine, the API and the panel are checked with PHPStan at level 5 before
each release. It does not come back clean, and this is what it reports: most
warnings are about the configuration constants, which it evaluates with their
default values and so calls every condition on them "always true" or "always
false"; the rest are places where it cannot follow a type (a flag set by a nested
call, a list built by a loop, a numeric string that has just been checked) or
defensive calls it considers redundant. None of them is a bug; each was looked
at. The PHPStan configuration is not committed — it is a development tool and
the project needs nothing beyond PHP itself — but the source carries the
`@phpstan-type` and `@phpstan-impure` annotations that make the analysis
meaningful.

`f5_admin.php` needs cURL and starts two PHP built-in servers — one for the panel
and one for the API, because the built-in server handles one request at a time
and the panel calls the API from inside its own request.

---

## Documentation

Full documentation lives in [`docs/`](docs/):

| | |
|---|---|
| [`docs/00-index.md`](docs/00-index.md) | Index and starting points |
| [`docs/01-core.md`](docs/01-core.md) | Storage, types, locking, journal, indexes, memory, configuration, upgrading |
| [`docs/02-queries.md`](docs/02-queries.md) | `SELECT`: syntax, functions, ordering, performance |
| [`docs/03-writes.md`](docs/03-writes.md) | Writes, DDL, keys, triggers, views, integrity |
| [`docs/04-api.md`](docs/04-api.md) | The HTTP API, signing, bound parameters, clients |
| [`docs/05-admin.md`](docs/05-admin.md) | jsonSQLDBadmin |
| [`nginx/README.md`](nginx/README.md) | nginx setup — **required reading if you use nginx** |
| [`litespeed/README.md`](litespeed/README.md) | LiteSpeed Enterprise and OpenLiteSpeed — what applies and what to configure |

Source code comments and engine messages are in Spanish.

---

## Authorship

Copyright 2026 Miguel Sanchez.

This project is **directed by Miguel Sanchez** and **assisted by artificial
intelligence**. The concept, architecture, functional and technical
specification, design decisions, priorities, acceptance criteria and review
are the author's. The implementation — the PHP source code, the test suites,
the benchmarks and the documentation — is produced with the help of AI models
(Anthropic's Claude among others), working from that specification and under
the author's direction, and reviewed by him before being kept.

Every design decision and every trade-off was made by a human. No line ships
without his review.

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
