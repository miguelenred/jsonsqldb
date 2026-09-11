# jsonSQLDB

The version is in the [VERSION](../VERSION) file; what changed in each one, in
the [CHANGELOG](../CHANGELOG.md). The number is deliberately not repeated here:
written by hand it falls behind without anyone noticing, which is exactly what
happened to this document for three versions.

An SQL database on JSON files, in pure PHP. No Composer, no unusual extensions
and no database server: copy the folder and it works.

Three pieces, each on top of the previous one:

```
jsonsqldbadmin/   web panel          →  talks only to the API
api/              signed HTTP API    →  talks only to the engine
engine/           SQL engine         →  reads and writes data/
data/             one folder per database, one .json per table
```

## Documentation

> **Coming from an earlier version?** Read
> [Upgrading from an earlier version](01-core.md#10-upgrading-from-an-earlier-version)
> before replacing the folder. The data needs no conversion, but if you come
> from 1.x **the HMAC signature of the API changed** and your own clients stop
> working until they are updated.

| Document | What it covers |
|---|---|
| [01-core.md](01-core.md) | storage, types, locking, journal, indexes, memory, configuration, log, upgrading |
| [02-queries.md](02-queries.md) | `SELECT`: syntax, functions, alphabetical order, performance |
| [03-writes.md](03-writes.md) | `INSERT`/`UPDATE`/`DELETE`, DDL, keys, triggers, views, integrity |
| [04-api.md](04-api.md) | HTTP endpoint, HMAC signature, bound parameters, clients |
| [05-admin.md](05-admin.md) | jsonSQLDBadmin: installation, users, what it can do |
| [../nginx/README.md](../nginx/README.md) | **Required if you use nginx**: the equivalent of the `.htaccess` rules |
| [../litespeed/README.md](../litespeed/README.md) | LiteSpeed Enterprise (works as Apache) and OpenLiteSpeed (**needs the rules in the vhost**) |

## Where to start

**If your server is nginx or OpenLiteSpeed**, read `nginx/README.md` or
`litespeed/README.md` first. The project ships `.htaccess` and `web.config`,
which nginx does not read and OpenLiteSpeed only reads in part: without the
rules in those folders, `data/` is reachable from the browser and anyone can
download your tables. LiteSpeed Enterprise reads `.htaccess` like Apache and
needs nothing.

**Install**: upload the folder to the server, run `php configurar.php` to
create both configuration files with random keys (or `--local` for plain HTTP
on your own machine), and open `jsonsqldbadmin/`. The first screen asks you to
create the administrator. Details in [05-admin.md §1](05-admin.md).

**Create the first database**: from the panel (*Databases → New database*),
with `CREATE DATABASE mydb` through the API with an empty `db`, or with
`JsonSQLDB\Database::crear('mydb')` from PHP.

**Query from an application**: copy the client that suits you
(`api/cliente_ejemplo.php`, `.ps1` for PowerShell, `.py` for Python) and always
use bound parameters:

```php
$rows = $cli->consultar('SELECT * FROM customers WHERE city = ?', ['Torrevieja']);
```

## Worth knowing first

- **Values are never concatenated into the SQL.** Write `?` and send the values
  apart; the server inserts them already parsed. See [04-api.md §1.1](04-api.md).
- **The database goes in the `db` parameter**, not in the SQL. There is no
  `USE` and no `mydb.customers`, and a query does not span two databases.
- **`DECIMAL` is rounded floating point**, not exact decimal. For money that
  has to add up to the cent, store cents in an `INTEGER`. See
  [01-core.md](01-core.md).
- **Alphabetical order is configurable** (`JSONSQLDB_COLACION`): by default it
  ignores case and accents and puts `ñ` after `n`. It only affects `ORDER BY`.
  See [02-queries.md](02-queries.md).
- **`ALTER TABLE` cannot do everything**: it adds, modifies and drops columns,
  unique, foreign and primary keys, but `AUTOINCREMENT` can only be set when
  the table is created. See [03-writes.md](03-writes.md).
- **Batch your `INSERT`s.** One statement with many `VALUES` is far cheaper
  than many statements; see [02-queries.md](02-queries.md).

## Tests

Twelve files, no dependencies. They use temporary folders and never touch your
data.

```
php tests/f1_nucleo.php       → OK: 66    storage, types, locking, direct access
php tests/f2_parser.php       → OK: 70    parser and bound parameters
php tests/f2_select.php       → OK: 144   SELECT execution and collation
php tests/f3_escrituras.php   → OK: 59    writes, DDL, keys and triggers
php tests/f4_api.php          → OK: 52    real requests against the API
php tests/f5_esquema.php      → OK: 91    SHOW, ALTER, constraints, views, integrity, journal, result cache
php tests/f5_admin.php        → OK: 119   the panel, driven like a user
php tests/f6_cortes.php       → OK: 33    crash recovery, killing real processes
php tests/f7_concurrencia.php → OK: 23    real simultaneous processes and locking
php tests/f8_indices.php      → OK: 59    indexes, against a full scan every time
php tests/f9_journal.php      → OK: 31    every intermediate state a crash can leave
php tests/f10_indices_incrementales.php → OK: 16   indexes corrected instead of rebuilt
```

`f5_admin.php` needs the cURL extension and starts two PHP built-in servers,
one for the panel and one for the API, because the built-in server handles one
request at a time and the panel calls the API from inside its own request.

`php tests/benchmark.php [rows]` measures the engine on a generated dataset
(mean of several repetitions of each query) so you can repeat the numbers
quoted in the documentation on your own machine.
