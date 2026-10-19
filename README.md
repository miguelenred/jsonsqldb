# jsonSQLDB

A SQL database engine, HTTP API and web admin panel written in plain PHP, with
the data stored as JSON files you can read. No database server, no Composer, no
extensions beyond the standard ones. You copy a folder and it works.

**Version 2.10.0** · [Apache License 2.0](LICENSE) · PHP 8.0+ (CI runs 8.0 to 8.5;
8.1 or later recommended, [see why](#php-80-works-but-81-or-later-is-recommended))

---

## What it gives you

Real SQL for the hosting that has no database, only one, or one too small for
what you need — which is common on shared hosting, while disk space and PHP are
not scarce. Joins of every kind, subqueries, aggregates, `GROUP BY`/`HAVING`,
foreign keys with cascades, triggers, views and indexes, over files you already
have room for. You reach it through an HTTP API signed with HMAC, or directly
from PHP, and you manage it from a web panel.

## Why you can rely on it

- **It gives the same answers as SQLite.** Every CI run compares 231
  hand-written queries, 32 writes and 6,000 randomly generated queries against
  SQLite on the same data, with and without indexes
  (`tests/f12_contra_sqlite.php`, `tests/f13_fuzz_contra_sqlite.php`). The few
  places where it behaves differently on purpose are listed in
  [`docs/02-queries.md`](docs/02-queries.md).
- **A crash does not leave it half-written.** Every statement is atomic: writes
  go through a redo journal (inside a transaction, the transaction keeps the
  files they replace until it ends), and the tests kill real PHP processes in
  the middle of writes and check what they leave (`tests/f6_cortes.php`,
  `tests/f9_journal.php`).
- **Concurrent writes do not lose updates.** Four processes adding 1 fifty times
  to the same row end at exactly 200, every run (`tests/f7_concurrencia.php`).
- **You can arrive and leave whenever you want.** The data is JSON, one row
  per line, readable in any editor. The panel exports each database as an SQL
  dump for SQLite, MySQL / MariaDB, PostgreSQL or SQL Server, and imports the
  dumps those make (`sqlite3 .dump`, `mysqldump`, `pg_dump`, Management
  Studio's scripts), saying what did not translate — checked with real dumps
  and real servers, except loading into SQL Server, which the tests could not
  reach.
- **The on-disk format is stable.** Any 2.x version reads data written by an
  earlier 2.x; see the [compatibility policy](docs/01-core.md#compatibility-policy).
- **Every figure is measured.** The numbers in this documentation come from
  benchmarks you can run yourself (`php tests/benchmark.php`), and the
  [changelog](CHANGELOG.md) records what failed, how it was found and what was
  tried and dropped.

## When to use something else

If your hosting gives you MySQL, PostgreSQL or SQLite and they fit what you
need, use them: a database server is faster, and the difference grows with the
size of the tables and the number of simultaneous writers. And know the limits
before you choose this one:

- **A transaction holds the whole database.** Each statement is atomic on its
  own, and since 2.9 several can run as one transaction (`$db->transaccion()`
  or `$db->lote()` from PHP, the API's `lote` field, several statements in the
  panel's SQL console): all of them or none. But while one runs, everyone else
  waits for the whole database, and a transaction never spans requests. If
  your application needs long transactions among many users at once, this is
  not the right tool.
- **Writes to the same table commit one at a time.** Reads never wait for most
  of a write, and writes to different tables run in parallel, but two writes to
  the same table take turns to commit.
- **Everything runs inside PHP**, one request at a time. It is built for the
  size of data a small business or a web application keeps, not for millions of
  rows under constant load.

**If a query does not fit in memory**, the engine stops it by 85 % of PHP's
`memory_limit` at the latest — earlier if its next step would not fit — with an
ordinary error explaining what happened, instead of dying
with PHP's uncatchable fatal. The query still fails, but the process survives and
the API answers properly. Data is never corrupted by it: reads write nothing,
writes are buffered and flushed at the end, every file is written atomically, and
multi-file writes are finished or discarded whole by the journal (one too large
for memory goes in stretches inside a transaction, 2.9).

Rough numbers from `php tests/benchmark.php` with 2.10 (20,000 customers and
30,000 orders, one core, PHP 8.3 without OPcache, on-disk cache, the median of
fifteen runs): a primary key lookup 1.0 ms and 4.7 MB (about 0.2 ms once the
process has made one: the figure includes the first, which loads the code), a
lookup by a `UNIQUE` text column 0.24 ms, a scan with a numeric filter and no
index 12 ms and 5.8 MB, a `GROUP BY` 19 ms and 6.0 MB, an aggregate join of
both tables 104 ms and 18 MB, a single-row `INSERT` 6.2 ms and 5.3 MB, an
`UPDATE` by key 4.7 ms and 5.9 MB, and any of those repeated on unchanged data
under half a millisecond. On 100,000 customers (seven runs): primary key lookup
1.2 ms and 5.0 MB, `UNIQUE` text lookup 0.35 ms, `GROUP BY` 103 ms and 6.5 MB,
single-row `INSERT` 5.3 ms. The writes are measured
on a disk where an `fsync` costs 0.1 ms; on a shared host's disk, where it
costs a few milliseconds, add that five times. A write reads and rewrites only the parts
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
on 100,000 rows a primary key lookup takes about 0.2 ms (1.2 ms and 5.1 MB with the
first of the process, which loads the code) instead of scanning the table,
reading one line of one part by its byte offset. A `BETWEEN` on
a numeric indexed column reads only the parts whose values can fall in the
range. The last index pieces read stay in the process, so a loop of lookups
does not decode them again.
Since 2.9 a range on an indexed column (texts and dates too) reads only the
index pieces and parts that can hold it, `COUNT(*)` of a range, or of an
equality on a text that is not a number, counts in the index (since 2.10 also
of an equality on an `INTEGER`, `DOUBLE` or `DECIMAL` column, and on a `TEXT`
column of any text, since each text has its own key), and on a numeric indexed column so do
`ORDER BY … LIMIT n` and `MIN` / `MAX` without a `WHERE` (since 2.10 `ORDER BY
… LIMIT n` also on a `TEXT` column without a `COLLATE` of its own); `LIKE` and
the other aggregates still read everything. An index on several columns serves
a lookup by its first ones fully only when the columns after them are `NOT
NULL`: a row with `NULL` in an indexed column is not in the index, so it serves
the parts of the table that have none of those rows and the others are read
whole (2.10; before, such a lookup missed those rows: in `UPDATE` and `DELETE`
from 2.5.0, in `SELECT` from 2.7.4). Writes use
indexes too: an `INSERT` checks uniqueness against the index on disk and appends to the
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
Only a query that genuinely needs every row at once holds them all: the inner
side of a `JOIN` holds that table, an `ORDER BY` without `LIMIT` only the
columns it returns and its sort keys (2.10; before, the whole table),
`DISTINCT` only the distinct rows (2.9); and since 2.9 what does not fit in memory — an `ORDER
BY`, a `JOIN`, a `GROUP BY` of many groups, a subquery, a view or a `WITH`, a
`UNION`, window functions — goes to files on disk and gives the same rows.
With a million rows and 256 MB, every query measured answers: see [A million
rows](docs/01-core.md#a-million-rows-29).

**Files and space, and how to tune them for your hosting.** A table is one
file per 1,000 rows, plus one per part for each index, plus — without APCu —
a serialised copy of each in `.cache/`. The benchmark with 100,000 customers,
150,000 orders and 100,000 notes ends with 911 files and 36.8 MB of data and
indexes, and 869 files and 42 MB of cache. Space is never the problem on a cheap plan; the
number of files can be, if the account counts inodes. Three knobs, in order:
APCu (used automatically when present; since 2.9 `JSONSQLDB_CACHE_APCU = false`
leaves it aside): the cache goes to shared memory instead of `.cache/`, except
the `.php` copies of the table parts that OPcache keeps compiled, while OPcache
is active; `JSONSQLDB_CACHE_ACTIVA = 'apcu'` (nothing in `.cache/` at all, at the
cost of those copies: loading a whole 60,000-row table from the cache goes from
well under a millisecond to about 20 ms) or `false` (no cache: reads decode JSON
every time; measured with 2.9, on 100,000 rows a scan with a filter 159 ms
instead of 116, a lookup by key about the same, since it reads one line);
`JSONSQLDB_FILAS_POR_PARTE = 5000` (four times fewer files of every kind; on
20,000 rows a lookup by key the same, an `UPDATE` by key 12 ms instead of 10,
and scans 1.4 to 1.9 times slower). The full table of trade-offs, measured, is in
[`docs/01-core.md` §9](docs/01-core.md#9-files-space-and-how-to-tune-them).
Deleting `.cache/` is always safe.

**With OPcache, table parts stay compiled in shared memory between requests
(2.9).** If PHP has OPcache active, each table part that has not changed for a
couple of seconds is also kept as a small `.php` file that OPcache holds in its
memory: measured between web requests on 60,000 rows, loading the whole table
went from about 22 ms to about 0.15 ms and full scans became 33–56 % faster.
Writes are not slowed, it never takes more than OPcache can spare (it stops at
a quarter of free memory), and it is used automatically:
`JSONSQLDB_CACHE_OPCACHE = false` turns it off. Without OPcache the engine works
the same. What each cache does, and how to switch it, is in
[`docs/01-core.md`](docs/01-core.md#the-caches-one-by-one).

**PHP 8's JIT helps the heavy queries; turn it on if your hosting lets you.**
With OPcache's JIT (`opcache.jit=tracing` and `opcache.jit_buffer_size=64M` in
`php.ini`), counted with `valgrind` on 2.10 with OPcache, on 30,000 customers
and 60,000 orders, in a process that has already run the query once: a `GROUP
BY` does 14 % less work, `MIN`/`MAX` over the table 24 % less, `TOTAL` and
`AVG` 21 % less, a `LIKE` over the table 13 % less, an aggregated join 19 %
less and window functions 14 to 29 % less. A short query gains too once the
JIT has compiled what it runs (after 500 runs, a lookup by key 29 % less and
an `IN` of 100 keys 35 % less), but until then it costs more
than it saves, since each process compiles its own: in a process of 21 lookups
by key, 0.53 → 2.3 million instructions each, and an `IN` of 100 keys 4 %
more. A web server keeps what it compiled in OPcache's shared memory for the
next requests. Since 2.10, if
`php.ini` gives the JIT its memory but leaves it as `opcache.jit="off"` (with
the quotes) or `opcache.jit=0`, the engine turns it on for each request where
it is used (also for the rest of your application): `JSONSQLDB_JIT = false` in
`config.php` leaves it as it is. With `opcache.jit=disable` (the default from
PHP 8.4), with `off` without quotes (`php.ini` reads it as empty, which PHP
takes for `disable`) or without the memory (the default up to 8.3), PHP does
not allow it from code: set it in `php.ini`.
It is a recommendation, not a requirement: nothing depends on it.

**A `JOIN` behind a selective `WHERE` looks rows up instead of hashing a
table.** The parts of the `WHERE` that concern the left side run before the
join, and when few rows are left and the right-hand table has an index on the
`ON` columns, each one is looked up by key: one order and its customer in
0.44 ms and 5.3 MB instead of about 20 ms and 25 MB.

**A repeated `SELECT` on unchanged data is not run again.** Its result is
cached under the SQL, the parameters and the revision of every table it
touches; any write to one of them changes the revision and the cached result
stops matching. Queries that depend on the moment (`RANDOM()`, `DATE('now')`),
results over `JSONSQLDB_CACHE_RESULTADOS` rows and, since 2.9, queries that took
less than a millisecond (cheaper to run than to store) are never cached.

**Be realistic about the limits.** It is built for tables in the thousands to
low hundreds of thousands of rows. A million works, with the same results, but
a query that has to go through the whole table takes seconds, and tens of
seconds if it does not fit in memory and goes to disk. There is no network protocol and no connection pool: concurrency is
handled with file locks, which is fine for a handful of simultaneous writers and
not for hundreds. A write locks only its own table when it cannot affect any
other, and every table its foreign keys and triggers can reach when it can;
schema changes, writes that read other tables, transactions and a set of tables
that cannot be worked out lock the whole database.

**Every write that touches more than one file is crash-safe.** That is nearly
all of them: a table past `JSONSQLDB_FILAS_POR_PARTE` rows lives in several
files, a table with indexes has one file per index and part, every write rewrites the
revision file (which also keeps the `AUTOINCREMENT` counter, so an `INSERT`
does not rewrite the structure). Every file is first written to a temporary and forced to disk;
then a manifest lists the renames and they are applied together. A power cut
before the manifest leaves the data untouched; one after it is finished the
next time the database is opened — whole or not at all, never half. The journal
is scoped to whatever lock the write holds, so two writes to different tables
still run at the same time. Several statements grouped into one transaction
(2.9) are kept or undone together: the files they replace are kept until it
ends, and one left unfinished by a process that died is undone the next time
the database is opened. Inside a transaction a statement writes no journal of
its own, and its files are forced to disk once, when the transaction commits
(2.10): the files the transaction keeps already undo it.

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
   the data itself. Two things are not readable: the Bloom filter of the keys
   in each index piece (2.9, base64), a summary of the readable keys next to
   it, which a piece can do without (it is then read whole); and the four-byte
   length in front of each value in the temporary files of `.orden/`, which
   exist only while a query too large for memory runs.

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
| **PHP extensions** | Only the standard ones, on in every usual build: `json`, `pcre`, `hash`, `filter` and `ctype`; the panel also uses `session` and `tokenizer`. **No** mbstring, **no** intl, **no** PDO |
| **cURL** | Optional. **jsonSQLDBadmin** uses it for the API when it is there, and PHP's own streams when it is not; with the direct connection it makes no HTTP calls at all. The panel tests (`f5_admin.php`, `f11_asistente.php`, `f20_memoria.php`) and the PHP example client (`api/cliente_ejemplo.php`) do need it. Not needed by the engine |
| **zip** | Optional. Only for the panel's ZIP backup and restore and for Excel (.xlsx) files: importing (with `XMLReader`, standard), exporting and the import template |
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
manifest (inside a transaction, the list of the files it keeps) to disk
before it reports success (see
[Durability](docs/01-core.md#6-durability-atomic-files-and-the-journal)). On
8.0 that call does not exist, and **there is no reliable substitute in plain
PHP**: `fflush()` only empties PHP's own buffer into the operating system; the
`dio` extension, which has one, is not installed on shared hosting; `posix` has
no `fsync`; and running `sync` through `exec()` needs a shell that shared
hosting does not give, and flushes the whole machine.

**What that means in practice on PHP 8.0:**

| Event | PHP 8.0 | PHP 8.1 or later |
|---|---|---|
| The PHP process dies, is killed or runs out of memory mid-write | nothing is lost: the journal finishes or discards the write, and a transaction is undone | nothing is lost |
| Power cut, or the operating system crashes | writes the system had not yet put on disk are lost — on Linux with default settings, roughly the **last 30 seconds** — even though they were reported as done | nothing that was reported as done is lost |
| Could a table be left damaged? | on ext4 with default options (the usual on hosting), no: the kernel writes a replaced file's data before its rename. On other filesystems a replaced file could be left empty; `CHECK TABLES` would report it and a backup is the way back | no |

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
jsonsqldbadmin/datos/    panel users, failed-login counters, audit trail, scheduled backups (copias/), files to import (importar/)
```

On Linux with Apache, LiteSpeed or nginx:

```bash
sudo chown -R www-data:www-data data logs jsonsqldbadmin/datos
sudo chmod -R 750 data logs jsonsqldbadmin/datos
```

On Windows with XAMPP the default permissions usually work as they are.

### Keeping your project in git

The bundled `.gitignore` already leaves out what must not go into a
repository: the two real configuration files (`api/jsonsqldb_api_config.php` and
`jsonsqldbadmin/config.php`, which hold the keys and secrets); everything inside
`data/` (the databases, with their `.cache/`, `.tx/`, `.lote/`, `.reparados/`
and `.orden/` folders); everything inside `logs/` (the query log and the API
state in `logs/api/`); and everything inside `jsonsqldbadmin/datos/` (panel
users, audit trail, backups, files to import, the installation code). It keeps
only the `.htaccess`, `web.config` and `.gitkeep` that protect those folders.
Commit everything else, `config.php` at the root included: it holds settings,
not secrets. If you point `JSONSQLDB_DATA_PATH`, `JSONSQLDB_LOG_PATH`,
`API_ESTADO_PATH` or `ADMIN_DATA_PATH` to another folder inside the project,
add that folder to `.gitignore` yourself. A database is better kept out of
git: copy it with the panel's ZIP or SQL dump instead.

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
choose the username and password right there, after typing the **installation
code** that `php configurar.php` prints (it is also in
`jsonsqldbadmin/datos/codigo-instalacion.txt`): only someone with access to the
server can finish the installation. There is no default password and
no factory user, so there is nothing to change afterwards and no chance of
leaving an `admin/admin` behind. The password is stored with bcrypt and must be
at least 10 characters.

Once that user exists the setup screen disappears and the panel asks for
credentials like any other. If you ever lose access, delete
`jsonsqldbadmin/datos/usuarios.json` and the panel will ask you to create the
administrator again, with a new installation code it writes to
`jsonsqldbadmin/datos/codigo-instalacion.txt`.

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
is never exposed, permissions are enforced in one place, every write and every
error is logged (every read too, if you set `JSONSQLDB_LOG_NIVEL` to `todo`),
and your application can live on a different machine from the data.

### Request and response

Every request carries an API key, the target database, the SQL, its bound
parameters, a timestamp, a random nonce (2.9) and an HMAC-SHA256 signature over
all of it:

```php
$token = hash_hmac('sha256',
    "+" . $apiKey . "|" . $db . "|" . $timestamp . "|" . $nonce . "|" . $sql . $params . "¿", $secreto);
```

Each API key signs with its own `hmac_secret`, so a compromised application
cannot sign as another key. `$db` is the database name as sent, or the empty
string for statements that target none (`SHOW DATABASES`, `CREATE DATABASE`);
`$params` is the JSON as sent, or empty when there are none. A request without
a nonce is still accepted with the formula of before 2.9. Several statements
can go as one transaction in a `lote` field instead of `sql` and `params`
(2.9): see [docs/04-api.md](docs/04-api.md).

> **The database name entered the formula in 2.0, and this breaks old clients.**
> Before, `db` was outside the signature, so a legitimate signed request could be
> captured, have its `db` changed and be replayed against a different database —
> the signature stayed valid because it did not cover the field. Anything signing
> with the old formula is rejected and has to be updated. The bundled PHP, Python
> and PowerShell clients and the admin panel already use the new one. Full
> details in [docs/04-api.md](docs/04-api.md).

**All responses are JSON.** Four shapes, and that is all:

```jsonc
// SELECT and SHOW: an array of rows
[ {"id": 1, "name": "Ana", "balance": 10.55}, {"id": 2, "name": "Luis", "balance": 0} ]

// INSERT / UPDATE / DELETE / DDL
{"success": true, "filas": 3, "mensaje": "3 fila(s) insertada(s)"}

// A lote (2.9): the result of each statement, in order
[ {"success": true, "filas": 1, "mensaje": "..."}, [ {"id": 1} ] ]

// Anything that went wrong ("Error en la consulta" alone unless DEVOLVER_ERRORES is true)
{"error": "Error en la consulta: SYNTAX: ..."}
```

Numbers come back as JSON numbers, not strings. `NULL` comes back as `null`.

### Example clients

Three ready-to-use clients are included. Copy the one you need into your
application; none has dependencies.

| File | For |
|---|---|
| [`api/cliente_ejemplo.php`](api/cliente_ejemplo.php) | PHP applications |
| [`api/cliente_ejemplo.ps1`](api/cliente_ejemplo.ps1) | PowerShell scripts |
| [`api/cliente_ejemplo.py`](api/cliente_ejemplo.py) | Python 3.7+, standard library only |

They handle the signature, the bound parameters and the TLS certificate (your
own CA's, or a self-signed one if you accept it explicitly) for you.

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
HTTP. It is **disabled by default**, and the API **enabled**; each has its
switch in `config.php` (`JSONSQLDB_API_ACTIVA = false` turns the API off, 2.9).
jsonSQLDBadmin, when you choose its direct connection, turns it on for itself
only, without touching `config.php`: your own scripts still need the setting.
To enable the direct connection:

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
- **It is still logged.** What goes to the log (by default, since 2.9, writes
  and errors: `JSONSQLDB_LOG_NIVEL`) goes there exactly as through the API, with
  the `ip` field set to `"local"`, since there is no HTTP request to take an
  address from.
- **Bound parameters still work, and you should still use them.** They are the
  only thing protecting you from injection here, and there is no API forcing you
  to get it right.

When it makes sense: a maintenance script, a migration, a cron job, or your own
application on the same server where the HTTP hop only adds latency. For
anything exposed to third parties, use the API. The functions below stay
compatible from one version to the next, as the API does (see the
[compatibility policy](docs/01-core.md#compatibility-policy)).

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

// A transaction (2.9): all of it or nothing. Inside, each statement sees the
// previous ones; an exception that leaves the function undoes everything (a
// statement that fails leaves nothing of its own). The whole database waits
// for it, so keep it short
$db->transaccion(function (JsonSQLDB\Database $db) {
    $db->consultar('UPDATE accounts SET balance = balance - ? WHERE id = ?', [50, 1]);
    $db->consultar('UPDATE accounts SET balance = balance + ? WHERE id = ?', [50, 2]);
});
// The same as a list of statements, which is what the API's lote runs
$db->lote([['INSERT INTO log (what) VALUES (?)', ['x']], 'DELETE FROM queue WHERE done = 1']);

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
    echo $e->sqlState, ': ', $e->getMessage();   // CONSTRAINT: Valor duplicado en restricción UNIQUE …
}
```

## jsonSQLDBadmin

A web panel for managing everything, bundled in [`jsonsqldbadmin/`](jsonsqldbadmin/).

It talks to the engine in one of two ways, chosen in a **setup wizard** the
first time it is opened: **through the API**, over HTTP and signed exactly like
your application (it uses cURL if it is there and PHP's own streams if not), or
by **direct connection** (2.7), loading the engine without HTTP when the panel
and the data are on the same machine (it allows that for itself only:
`JSONSQLDB_CONEXION_DIRECTA` stays as it is for everything else). With the direct connection the engine
itself applies the role of each panel user; through the API it does when a
read-only key is configured (`ADMIN_API_KEY_LECTURA`), and otherwise the
panel's own check is the only barrier. Apart from the ZIP backup and restore, the panel
never reads or writes the data files: an import on the same machine runs in a
transaction of the engine, which the panel loads for it.

It manages databases, tables, columns, keys, views, triggers and rows; checks
and repairs referential integrity and hand-broken JSON files; runs several
statements as one transaction from its SQL console; exports to CSV, `INSERT`
statements, JSON, Excel (.xlsx), ZIP, the whole database as one JSON document
or an SQL dump for SQLite, MySQL / MariaDB, PostgreSQL, SQL Server or Microsoft
Access — views and triggers translated to each one's SQL — and imports the dumps
those engines make, with their views and triggers, CSV, JSON and Excel files
and its own ZIP (for Access and for SQL Server, with PowerShell scripts it
offers). Only administrators export (2.9). Exports and
imports have no size limit: what does not fit in PHP's memory goes in batches
worked out from the free memory, a file larger than the upload limit is sent in
pieces (and resumes if the connection drops), and a file can also be left by FTP
in its import folder (2.8). It makes **scheduled backups** (ZIP or SQL dump,
keeping the last N) from cron or, without cron, when someone opens it. It has
its own users
with `admin` / read-only roles, bcrypt passwords, per-IP lockout, CSRF tokens
and a daily audit trail. Its configuration can be changed from its own page,
which also warns if the data folder can be downloaded from outside, as its
home page does for administrators (2.10). It is in
**Spanish and English**: it follows the browser's language, and a language
chosen with its selector is saved with the user. The full tour is in
[`docs/05-admin.md`](docs/05-admin.md).

Bootstrap 5.3.3 is bundled locally and the icons are inline SVG; light and dark
theme. The panel makes **zero** external requests.

---

## Supported SQL

### Statements

| | |
|---|---|
| **Query** | `SELECT`; `EXPLAIN SELECT …` (2.9: runs it and says how each table was read) |
| **Write** | `INSERT` (with `OR IGNORE`, `OR REPLACE`, `ON CONFLICT … DO UPDATE` and `RETURNING`, 2.8), `UPDATE` (with `OR IGNORE` and `OR REPLACE`, 2.10), `DELETE`; also with other tables (2.9): `UPDATE … FROM`, `UPDATE … JOIN … SET`, `DELETE … USING`, `DELETE t FROM t JOIN …` |
| **Schema** | `CREATE TABLE`, `CREATE TABLE … AS SELECT`, `CREATE TABLE … LIKE` (2.9), `DROP TABLE`, `ALTER TABLE`, `CREATE TRIGGER`, `DROP TRIGGER`, `CREATE VIEW`, `DROP VIEW`, `CREATE [UNIQUE] INDEX`, `DROP INDEX` |
| **Database** | `CREATE DATABASE`, `DROP DATABASE`, `SHOW DATABASES` |
| **Introspection** | `SHOW TABLES`, `SHOW VIEWS`, `SHOW SCHEMA`, `SHOW COLUMNS`, `SHOW KEYS`, `SHOW TRIGGERS`, `SHOW INDEXES`, `SHOW CREATE TABLE`, `SHOW CREATE VIEW` (2.9) |
| **Maintenance** | `CHECK KEYS`, `REPAIR KEYS`; `CHECK TABLES`, `REPAIR TABLES`, `VACUUM` (2.9); `CHECK INDEXES`, `REPAIR INDEXES` (2.10) |
| **Transactions** | a batch of statements (2.9), which may start with `BEGIN` and end with `COMMIT` or `ROLLBACK` |

`SELECT` supports `DISTINCT`, `WITH` (CTEs, also `WITH RECURSIVE`, 2.9), `UNION` / `UNION ALL` /
`INTERSECT` / `EXCEPT`, correlated subqueries (at any depth, 2.10), `INNER`/`LEFT`/`RIGHT`/`FULL`/`CROSS JOIN`, `JOIN … USING` and `NATURAL JOIN` (2.9), a comma with `ON` or `USING` (2.10), `WHERE`, `GROUP BY`,
`HAVING`, window functions (`OVER`, 2.9), `ORDER BY` (`ASC`/`DESC`), `LIMIT`/`OFFSET`, table and column aliases,
subqueries in `WHERE` and in `FROM`, and `CASE WHEN`.

`ALTER TABLE` supports `ADD COLUMN`, `MODIFY COLUMN`, `CHANGE` (2.9),
`DROP COLUMN`, `RENAME COLUMN`, `RENAME TO`, `ADD CONSTRAINT` (unique, foreign
key, `CHECK`), `DROP CONSTRAINT`, `DROP CHECK` (2.9), `ADD PRIMARY KEY`,
`DROP PRIMARY KEY` and `ADD` / `DROP INDEX` (2.9).

### Operators

`=` `==` `<>` `!=` `<` `<=` `>` `>=` · `AND` `OR` `NOT` `XOR` · `IS NULL` `IS NOT NULL` ·
`IS` `IS NOT` `IS [NOT] DISTINCT FROM` `<=>` · `IN` `NOT IN` · `BETWEEN` `NOT BETWEEN` ·
`LIKE` `NOT LIKE` (with `%` and `_`, case-insensitive for `A` to `Z`) · `GLOB` (case-sensitive) ·
`EXISTS` `NOT EXISTS` · `REGEXP` `RLIKE` (and `NOT`) · `||` (string concatenation) ·
`+` `-` `*` `/` `%` `DIV` `MOD` · `&` `|` `~` `<<` `>>` · `->` `->>` (JSON) · `COLLATE`
— the ones of SQLite and MySQL added in 2.9 behave as there.

### Functions

Most of SQLite's and MySQL's (2.9), each behaving as in the engine it comes
from (a value with only a time, `'12:00:00'`, is read as on 2000-01-01, as in
SQLite: `TIME('12:00:00')` gives the time since 2.10); the full list, and what wins where the two differ, is in
[`docs/02-queries.md` §3](docs/02-queries.md#3-functions).

**Aggregate** — `COUNT`, `SUM`, `AVG`, `MIN`, `MAX`, `TOTAL`, `GROUP_CONCAT` /
`STRING_AGG`, `ANY_VALUE`, `JSON_GROUP_ARRAY`, `JSON_GROUP_OBJECT`, `STDDEV`,
`VARIANCE`, `BIT_AND` / `OR` / `XOR`…, with `FILTER (WHERE …)`, and as window
functions with `OVER (…)` together with `ROW_NUMBER`, `RANK`, `LAG`, `LEAD`…

**Text** — `CONCAT`, `CONCAT_WS`, `UPPER`, `LOWER`, `LENGTH`, `TRIM`, `REPLACE`,
`SUBSTR`, `INSTR`, `LPAD`, `REVERSE`, `REPEAT`, `SUBSTRING_INDEX`,
`FORMAT` / `PRINTF`…

**Numeric** — `ABS`, `ROUND`, `CEIL`, `FLOOR`, `POWER`, `SQRT`, `LN`, `LOG10`,
the trigonometric ones, `GREATEST`, `LEAST`, `RANDOM`…

**Date and time** — SQLite's `DATE`, `TIME`, `DATETIME`, `STRFTIME`,
`JULIANDAY` with their modifiers, and MySQL's `NOW`, `DATE_ADD`, `DATEDIFF`,
`DATE_FORMAT`, `STR_TO_DATE`, `+ INTERVAL n unit`…

**JSON** — `json_extract`, `->`, `->>`, `json_set`, `json_array`, `json_object`…

**Hashes** — `MD5`, `SHA1`, `SHA2`, `CRC32`, `UUID`…

**Null handling** — `COALESCE`, `IFNULL`, `NULLIF`, `IF`, `IIF`

**Conversion** — `CAST(expr AS type)`, accepting the same type names as
`CREATE TABLE`, and `CONVERT`

`CONCAT()` is available for people coming from MySQL, but `||` is the native
operator, as in SQLite:

```sql
SELECT first_name || ' ' || IFNULL(last_name, '') AS full_name FROM customers;
```

### Dialect

**It resembles SQLite's but is not compatible with it.** There are SQLite
constructs missing here, others borrowed from MySQL, and concrete behavioural
differences. Texts and numbers compare by the affinity of the column, as in
SQLite (2.10: until 2.9 a text that was a number compared as that number
everywhere, `'9' < '10'`), with two differences kept on purpose: with no
column on either side `'12' = 12` is true here, and in `x IN (…)` each value of
the list is compared as in its own `x = value`.
Do not assume a query that runs in SQLite runs here, or the other way round.

Borrowings, where they are what people expect:
`CONCAT`, `REGEXP`/`RLIKE` and `LIMIT n, m` come from MySQL, `CAST` and
`FULL JOIN` are standard SQL, and `AUTO_INCREMENT` is accepted alongside
`AUTOINCREMENT`; since 2.9 also most of MySQL's syntax and functions that
people bring with them (`ON DUPLICATE KEY UPDATE`, `INSERT … SET`, `UNSIGNED`,
`ENUM`, `TRUNCATE TABLE`, `DESCRIBE`…). Anything not mentioned behaves as in
SQLite. See [`docs/02-queries.md`](docs/02-queries.md) for the full table.

### What is not supported

The rule: **if a statement is accepted, it does exactly what it promises;
otherwise it is rejected with a clear error.** Nothing is accepted and silently
ignored; the few statements accepted without doing anything (2.9: `ANALYZE`,
`SELECT … FOR UPDATE`, MySQL's table options…) are those whose promise already
holds here.

These exist in SQLite and raise an error here: `INSERT` and `UPDATE` `OR FAIL` / `OR ROLLBACK`
(use `OR IGNORE`, `OR REPLACE` or `ON CONFLICT`, supported since 2.8, and in an `UPDATE` since 2.10), `CREATE TEMP`/`TEMPORARY TABLE` (no
temporary tables), `WITHOUT ROWID` (there is no rowid), and `BEGIN`/`COMMIT`/
`ROLLBACK` anywhere but at the start and the end of a batch, which is already
a transaction (each request is independent). `CHECK` constraints, `CREATE
UNIQUE INDEX`, `GLOB`, window functions (`OVER`), `WITH RECURSIVE` and
`COLLATE NOCASE` / `RTRIM` in a column work since 2.9.

Behavioural differences worth knowing: `DECIMAL` is a rounded float, and
`ORDER BY` uses a configurable collation rather than binary order. `LIKE`
ignores the case of `A` to `Z` only, as SQLite does (2.10).

### Data types

| Type | Aliases | Notes |
|---|---|---|
| `INTEGER` | `INT`, `BIGINT`, `SMALLINT`, `MEDIUMINT`, `TINYINT`, `BIT`, `YEAR` | The only type that can be `AUTOINCREMENT`; `UNSIGNED` rejects negatives (2.9) |
| `DECIMAL(p,s)` | `NUMERIC`, `NUMBER`, `MONEY` | Float rounded to `s` decimals. `p` is accepted and ignored |
| `DOUBLE` | `REAL`, `FLOAT`, `DOUBLE PRECISION` | Not rounded |
| `TEXT` | `VARCHAR(n)`, `CHAR`, `NVARCHAR`, `NCHAR`, `CLOB`, `STRING`, MySQL's `TINYTEXT` / `MEDIUMTEXT` / `LONGTEXT`, `JSON` and `UUID`; `BLOB`, `BINARY` and `VARBINARY` (text only) | `n` is enforced as a maximum length; `COLLATE NOCASE` / `RTRIM` (2.9) |
| `ENUM('a', 'b')` | | A text that only takes those values (2.9) |
| `DATETIME` | `DATE`, `TIMESTAMP` | See the format note below |
| `BOOLEAN` | `BOOL` | An `INTEGER`, as in SQLite: `true` and `false` are stored as 1 and 0 |

> **`DATETIME` format.** Values must be **`YYYY-MM-DD`**, with an **optional**
> time `HH:MM`, `HH:MM:SS` or `HH:MM:SS.fff` after a space or a `T` (stored
> with a space). So `2026-08-20`, `2026-08-20 14:30` and `2026-08-20 14:30:00`
> are all valid; `20/08/2026` and `08-20-2026` are
> rejected with a type error. jsonSQLDBadmin shows this reminder in the column
> forms.

> **`DECIMAL` is a rounded float, not an exact decimal.** Rounding is applied on
> write, but arithmetic carries the usual binary floating-point error. For money
> that must reconcile to the cent across many rows, store integer cents in an
> `INTEGER` column and divide when displaying.

### Constraints and behaviour

`PRIMARY KEY` (simple or composite) · `AUTOINCREMENT` · `NOT NULL` · `UNIQUE`
(single or multi-column) · `DEFAULT` · `CHECK` · `COLLATE NOCASE` / `RTRIM` on a
text column (2.9, as in SQLite: see [Part 2](docs/02-queries.md)) ·
`FOREIGN KEY` with `ON DELETE` /
`ON UPDATE` `NO ACTION` `CASCADE` `RESTRICT` `SET NULL` `SET DEFAULT` ·
`BEFORE`/`AFTER` triggers on `INSERT`/`UPDATE`/`DELETE` with `WHEN`, `NEW.`,
`OLD.` and `RAISE(ABORT, '…')`.

`CHECK KEYS` reports rows whose foreign key points at a value that no longer
exists in the parent table, and `REPAIR KEYS` sets those keys to `NULL` where the
column allows it — it never deletes rows. The engine enforces foreign keys on
every write, so this only happens when someone edits a `.json` by hand or
restores one table's backup without the other. The check reads straight from
disk.

**A file edited or broken by hand is noticed and, when it is safe, repaired
(2.9).** Each cached part, structure and index piece carries the seal of its
file (size, date, inode), so an edit is seen by the next query instead of
staying hidden behind the cache. A JSON broken by an extra or missing comma,
bracket or brace is repaired on its own when it is read, keeping the original
in `.reparados/`; a row that cannot be fixed without guessing makes the query
fail with the file and the line, until it is fixed by hand or `REPAIR TABLES`
sets it aside. `CHECK TABLES` lists what is damaged. Details in
[`docs/01-core.md`](docs/01-core.md#editing-the-files-by-hand-29).
`CHECK INDEXES` (2.10) compares every index with the rows of its table, part
by part, and lists any key missing or left over, which a hand edit of an index
file could cause; `REPAIR INDEXES` rebuilds those indexes from the rows.

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
`ADMIN_DATA_PATH` separate. Three of them already default to paths inside the
project folder; the session name is `jsonsqldbadmin` in every copy, so two
panels on the same domain need a different `ADMIN_SESION_NOMBRE` in one of them.

### Upgrading from an earlier version

Replace the folder and keep your two configuration files
(`api/jsonsqldb_api_config.php` and `jsonsqldbadmin/config.php`, both gitignored),
and whatever lives inside the folder (`data/`, `logs/` and `jsonsqldbadmin/datos/`
with the default paths); carry over any setting you changed in the root
`config.php`, which ships with its defaults.
**The data needs no conversion**: an existing database is read as it is, and each
table moves to the current layout on the first write to it — a `<table>.rev.json`
replacing its entry in the old shared `_revs.json` (pre-2.0), index files for its
primary key and unique constraints (pre-2.0), and the row count and per-part and
per-index revisions added to `rev.json` (2.5), and the index split into one
piece per part plus a `creada` marker in `rev.json` (2.6). Revision numbers carry on from
where they were rather than restarting, so a stale cache entry cannot be mistaken
for a current one.

**This is checked against every published version**, from 1.0.0 to 2.9.0:
`tests/f24_versiones_anteriores.php` takes each one from its git tag, builds a
database with that version's own engine and settings (tables spread over
several parts — 5,000 rows each in 1.x, 1,000 since 2.0 — with autoincrement,
`UNIQUE`, an index, a foreign key, a view and a trigger, as far as that version
supported them, plus deletes and updates), and opens it with the current
engine: it must return what the old engine returned, write correctly, carry
the autoincrement on without reusing deleted ids, keep enforcing the
constraints and the trigger, and leave indexes, files and keys consistent. The
reverse is not supported: a database written by a newer version is not meant to
be opened by an older engine.

**Coming from 1.x, update your API clients before or at the same time**, because
the HMAC formula changed in 2.0 and old signatures are rejected. The bundled PHP,
Python and PowerShell clients and the admin panel are already updated.

One thing worth doing first: **open each database with the old version once**
before swapping the folder, so anything left half-finished is settled by the
version that wrote it. It is not required — a journal left pending by any earlier
version is recognised and settled by this one too (an undo journal of 2.4 or
earlier is undone, a redo journal is finished) — but it is the tidier order.

Two configuration constants were removed in 2.5 and are ignored if still
defined: `JSONSQLDB_JOURNAL_DATOS` and `JSONSQLDB_CACHE_MAX_FILAS`. One was
added in 2.6, `JSONSQLDB_CACHE_RESULTADOS`, and one in 2.7,
`JSONSQLDB_ESCRITURA_POR_PARTES`, both with working defaults. Three were added
in 2.9, `JSONSQLDB_CACHE_APCU`, `JSONSQLDB_CACHE_OPCACHE` and
`JSONSQLDB_API_ACTIVA`, and one in 2.10, `JSONSQLDB_JIT`, all on by default; an
existing `config.php` without them gets the defaults. And without `JSONSQLDB_LOG_NIVEL` the log now records
writes and errors only (`escrituras`; before, `todo`).

**From 2.8 or earlier to 2.9, the cache starts over.** Its entries now carry
the seal of the file they came from, so 2.9 uses keys of its own and ignores
the old ones (and an earlier version, should you go back, ignores those of
2.9). The old entries in `.cache/` are just unused files: deleting the folder
after upgrading frees the space, and is always safe. A table touched by a
transaction may also get a `gen` field in its `rev.json`; earlier versions
ignore it.

**Going back from 2.9 to an earlier version: run `VACUUM` first.** Since 2.9 a
deleted row leaves `null` in its place instead of moving the rows behind it
(see [Storage](#storage)), and 2.8 or earlier cannot read a table with such
holes: they stop with an error on the first scan rather than return wrong rows.
`VACUUM` rewrites every table that has holes without them, after which the
files are exactly the kind earlier versions write.

A table with `CHECK` constraints or `ENUM` columns (2.9) is read by earlier
versions without them: its data comes out the same, but they would let a row
that breaks them be written. A `COLLATE NOCASE` or `RTRIM` column (2.9) has to
lose it before going back (`ALTER TABLE … MODIFY` without it): earlier versions
compare it as binary, and its indexes, built with the collation, would miss
rows there. Views and triggers are stored as their SQL, so one that uses SQL
only 2.9 accepts (a window function, `UPDATE … FROM`) is an error there when it
runs.

**From 2.9 or earlier to 2.10, some results change**: texts that are numbers
compare as texts, as in SQLite (`'7' = '007'` is false, `'10'` sorts before
`'9'`, and a `UNIQUE` text column takes both `'7'` and `'007'`), comparisons
have SQLite's precedence, `INSERT OR IGNORE` skips rows that break a `NOT
NULL` or a `CHECK`, `OR REPLACE` puts the default in a `NOT NULL` column, a row
that does not go in no longer uses up an id without `AUTOINCREMENT`, table
names are not case-sensitive, `LIKE` ignores the case of `A` to `Z` only
(`'É' LIKE 'é'` is 0), `SUM` of integers that does not fit in 64 bits is an
error, as in SQLite, sums of decimals give SQLite's figures (`TOTAL` of 1e100,
3 and -1e100 is 3), integers past 2^53 compare exactly with decimals, a
decimal turned into text has 15 digits (`'123456789.123457'`; it was
`'123456789.12346'`) and an infinite one is `'Inf'`, an operation without a
numeric result (`INF - INF`) is `NULL`, a `RANGE` frame with a distance goes
with SQLite's arithmetic and takes the `NULL`s it reaches, and
`CREATE VIEW` is an error if a table or view it uses does not exist (as in MySQL and PostgreSQL;
a script that creates a view before its table stops there). Read the
[changelog](CHANGELOG.md) before upgrading. The indexes with a `TEXT` or a
decimal column get new keys: one written by an earlier version is not used (the query reads
the table, with the right rows) and is rebuilt once, right after that query or
on the next write to the table (on 30,000 rows with three such indexes, that
first query took 0.3 s instead of 0.6 ms); `VACUUM` rebuilds them all at a
time of your choosing. **Going back
from 2.10 to 2.9: delete first the index files of every index with a `TEXT`
column**, and of a decimal one that holds whole numbers past 9·10^18
(`<table>.idx.<index>.json` and its `.partN.json`), which 2.9 would
read with its own keys and miss rows; it rebuilds them on its next write (see
[`docs/01-core.md`](docs/01-core.md#going-back-to-an-earlier-version)).

### Storage

Each database is a directory. A table is `table.json` with the rows,
`table.meta.json` with the structure (columns, types, keys, indexes, triggers),
`table.rev.json` with a revision counter, the autoincrement counter and the
state of the table's parts and indexes, plus `table.partN.json` once it grows past
`JSONSQLDB_FILAS_POR_PARTE` rows (1,000 by default) and one
`table.idx.<n>.json` / `table.idx.<n>.partN.json` per index and part. A
`_database.json` holds database-level metadata. Everything is readable JSON,
one row per line, so a file stays diffable and editable by hand; what the
engine leaves next to it — the line that says who is writing a table, the
cache — is text too (2.10, see [`docs/06-format.md`](docs/06-format.md)). A deleted row
leaves `null` on its line (2.9), so deleting does not move the rows behind it
(except in a table with triggers or a foreign key to itself, which a `DELETE`
loads whole and writes without them); when holes pass 25 % of a table it is
rewritten without them, and `VACUUM` does it on demand. `VACUUM` also rebuilds
the indexes written by 2.8 or earlier, so that lookups by a text key get the
filter of 2.9 (see [CHANGELOG](CHANGELOG.md)); and since 2.10, run it after
copying a table's files into a database by hand, so that the foreign keys of
the other tables see it at once.

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
`a`, or on `a` and `b`, but not on `b` alone; and on `a` alone fully only when
`b` is `NOT NULL` (or part of the primary key), because a row with `NULL` in any
column of an index is not in it: otherwise it serves the parts of the table
where every row is in the index, and the others are read whole (2.10; before,
such a lookup missed those rows: in `UPDATE` and `DELETE` from 2.5.0, in
`SELECT` from 2.7.4). Declare those columns `NOT NULL`, or index `a` on its own.

`=`, `IN` and, since 2.9, `<`, `<=`, `>`, `>=` and `BETWEEN` against literals
use an index, only in the top-level `AND` chain of a `WHERE`.
Anything under a `NOT`, a top-level `OR`, `IS NULL` and `NOT IN` are left alone,
because using an index there would change the result rather than just speed it
up. Index keys follow the engine's own equality, not PHP's, with the affinity
of the column (2.10, as in SQLite): in a numeric column `5`, `5.0` and `'5'`
share a key (a whole decimal and its integer, up to 2^63); in a `TEXT` column each text has its own (`'5'` and `'5.0'` are
two), and a number looked up there is taken as its text.

An index is stored in **one piece per part of the table** and **corrected
rather than rebuilt** whenever the engine can prove what changed: rows appended
at the end, rows replaced in place, or a deleted row left as a hole (2.9). Only the pieces that cover the positions concerned are read and
rewritten; every other piece is checked — with one `stat()` when its revision
and size are those the last write left in the cache, by its header and tail
otherwise — so a damaged file is rebuilt at the next write. Any doubt rebuilds the index from the rows. The
revision file says which revision each piece belongs to; on any mismatch the
engine ignores the index and scans, so a stale index can make a query slower
but not wrong. A hand edit of an index file that keeps its header can make a
lookup miss rows: `CHECK INDEXES` finds it and `REPAIR INDEXES` rebuilds the
index (2.10).

### Concurrency

Locking has two levels, always taken in this order — first the database, then the
table — which is what makes a deadlock impossible:

| Operation | Database | Table |
|---|---|---|
| Reads (`SELECT`, `SHOW`, `CHECK KEYS`, `CHECK TABLES`, `CHECK INDEXES`), also each statement of a `lote` that only reads (2.9) | shared | **shared**, per table read |
| A write to **one** table with no foreign keys or triggers, that no other table references (since 2.10, a plain `INSERT` even if one does) | shared | **exclusive** |
| An `UPDATE` or `DELETE` that depends only on each row (2.7) | shared | **shared** while it works, **exclusive** only to commit (writes by part) |
| Cascades and triggers, when the set of tables is knowable | shared | **exclusive on every table** it can reach |
| Schema changes, views, `REPAIR KEYS`, `REPAIR TABLES`, `REPAIR INDEXES`, `VACUUM`, and writes that read other tables (`INSERT ... SELECT`, a subquery, an `UPDATE` or `DELETE` with other tables, also in a trigger they fire) | **exclusive** | — |
| A transaction (2.9), from start to end, also a `lote` that writes | **exclusive** | — |

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

That covers one file. Multi-file writes are protected by a **redo journal**,
`.tx/<scope>.json`: every file is written to its temporary first, then a manifest
listing the renames is written in one piece, then the renames happen. A crash
before the manifest leaves the data untouched (the temporaries are swept); a
crash after it is finished on the next open, as many times as it takes; so is
a write whose rename failed without a crash (on Windows, a file another program
holds open). It replaced the copy-everything-first undo journal of earlier versions, which is
where most of the write cost used to go; pending journals of the old format are
still recognised and undone. Inside a transaction (2.9) the files a statement
replaces are first kept in `.lote/`, so since 2.10 the statement writes no
journal, and its files are forced to disk once, at commit: if it is cut short,
the whole transaction is undone from those files.

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

A query holds a table in full only when it needs every row at once: the inner
side of a `JOIN`, and an `ORDER BY` without `LIMIT`, which since 2.10 keeps
of each row only the columns it returns and its sort keys (until they do not
fit: since 2.9 both then go to files on disk). `GROUP BY` holds one accumulator per group
and `DISTINCT` the distinct rows; a `WHERE` scan holds only what passes, and a
write only what it changes. If APCu is enabled, its memory is
separate and does not count against `memory_limit`.

**Lowering `JSONSQLDB_FILAS_POR_PARTE` does not reduce the full-table case.**
Splitting a table into more part files only bounds the size of each individual
decode; a query that needs every row still ends up with every row in memory.
What the split does buy is the ability to *skip* parts, which is what indexes
and streaming do.

#### What the engine does about it

Measured with `php tests/benchmark.php` and 2.10 (PHP 8.3 without OPcache,
on-disk cache, one core): time and peak memory of the process, on 20,000
customers and 30,000 orders the median of fifteen runs, on 100,000 customers
and 150,000 orders of seven, each taking turns with 2.9.0. A lookup's time
includes the first one of the process, which loads the code; two runs of the
same version differ by up to 10–15 %, and two sets of rounds on this machine
by up to 20 %:

| | 20,000 customers | 100,000 customers |
|---|---|---|
| Lookup by primary key | 1.0 ms · 4.7 MB | 1.2 ms · 5.0 MB |
| `IN` of ten primary keys | 0.45 ms · 4.8 MB | 0.55 ms · 5.1 MB |
| Lookup by a `UNIQUE` text column | 0.24 ms · 4.7 MB | 0.35 ms · 5.1 MB |
| `BETWEEN` on the primary key (1,000 rows) | 1.6 ms · 5.9 MB | 1.7 ms · 6.4 MB |
| Numeric range, no index | 12 ms · 5.8 MB | 61 ms · 6.3 MB |
| `JOIN` of one order with its customer | 0.43 ms · 5.2 MB | 0.48 ms · 5.6 MB |
| `LEFT JOIN` of twenty orders | 2.7 ms · 5.8 MB | 3.5 ms · 6.1 MB |
| `GROUP BY` with `SUM` | 19 ms · 6.0 MB | 103 ms · 6.5 MB |
| `ORDER BY … LIMIT 20` | 12 ms · 6.0 MB | 68 ms · 6.5 MB |
| `ORDER BY`, whole table | 22 ms · 15 MB | 143 ms · 52 MB |
| `JOIN` of both tables, aggregated | 104 ms · 18 MB | 764 ms · 66 MB |
| `INSERT` / `UPDATE` one row | 6.2 / 4.7 ms · 5.9 MB | 5.3 / 5.3 ms · 6.4 MB |
| A repeated query on unchanged data (result cache) | 0.21 ms | 0.24 ms |

The writes depend on the disk more than on anything else: in those same
rounds 2.9.0 took 7.8 / 5.8 ms on 20,000 customers and 7.9 / 6.6 ms on
100,000. Measured on their own (one-row writes in a process, the two versions
taking turns), 2.10 takes on 200,000 rows 4.68 ms for an `INSERT`, 4.35 for an
`UPDATE` and 4.40 for a `DELETE`, against 6.50, 5.33 and 6.39 in 2.9.0, with
the same five `fsync` calls (see the [changelog](CHANGELOG.md)).

The writes are measured on a disk where an `fsync` costs 0.1 ms; on a shared
host's disk, where it costs 3 ms, each of the five a one-row `INSERT` makes
adds that (about 28 ms in all, measured in 2.7 by making the kernel delay each
one). The memory is that of the whole process: about 3.8 MB of it is
the engine's code and what the process keeps between statements (four index
pieces, the row offsets of 64 parts, the table structures), which with OPcache
(the normal case on a web server) is partly in shared memory, and which is the
first thing let go when memory runs short. How each version changed these is
in the [changelog](CHANGELOG.md).

Rows are read one part at a time and filtered as they arrive, and used as they
come out of the cache without copying; aggregates are accumulated instead of
collecting the rows of each group; `ORDER BY … LIMIT` keeps only the leading
rows, and a whole `ORDER BY` only the columns it returns (2.10); a `JOIN`
streams and loads only the columns the query names; `WHERE`
conditions are compiled once; indexes decode only the parts where the matching
rows live and are stored in pieces so a write rewrites one; `LIMIT` is pushed
into the read when nothing filters after it; `SELECT COUNT(*)` counts lines
without decoding a single row; and the cache steps aside when memory is tight.

#### When it still will not fit

A result that genuinely does not fit cannot be made to fit (a sort can, since
2.9: it goes to files on disk). What the engine
guarantees is *how* it fails: `JSONSQLDB_MEMORIA_VIGILAR` makes it stop and raise
a normal error with `sqlState` `MEMORIA` — catchable, with the connection intact
and the data untouched — instead of PHP's fatal, which cannot be caught, runs no
`finally`, and hands the client a broken response.

Data is never at risk from running out of memory, whichever way it ends: a read
writes nothing, a write either flushes at the end or, when it does not fit in
memory (2.9), writes in stretches inside a transaction that is undone if it
does not finish, and the locks are released by the operating system when
the process dies.

### Query execution

1. **Lexer** turns the SQL text into tokens. String literals only accept `''` as
   an escape — there is no backslash escaping, which removes a whole class of
   injection tricks.
2. **Parser** builds a syntax tree. Only one statement per text is accepted,
   so `; DROP TABLE …` never gets a chance to run; a batch (2.9) is a list of
   statements, each parsed on its own. Bound parameters are inserted
   into the tree **here**, as literal nodes — they are never concatenated into
   SQL text.
3. **Executor** resolves sources and joins, applies `WHERE`, groups, applies
   `HAVING`, sorts, and slices with `LIMIT`/`OFFSET`.
4. **Writer** applies changes, checking types, `NOT NULL`, uniqueness and foreign
   keys, and firing triggers before and after.
5. **Logger** records the query, its duration, the row count and any error.

### The API layer

The endpoint checks, in this order: that the API is on (`JSONSQLDB_API_ACTIVA`,
2.9), the source IP against the allow-list, that
the configuration is not the published template, HTTPS if required, that the
request is a `POST` of an accepted size, its fields, the lockout of an IP after
failed authentications, the token's format and the timestamp window, the API key
and its HMAC signature over `api_key | db | timestamp | nonce | sql + params`
(or the batch in place of the SQL and parameters) — an unknown key gets the
same answer as a wrong signature —, replay protection and the rate limits (per
IP and per key), the databases the key may use, and finally the key's
permission level against the *parsed* statement type — so permissions are
checked against what the statement actually is, not against a string match on
its text.

Three permission levels: `lectura` (`SELECT`, `SHOW`, `EXPLAIN`, `CHECK`),
`escritura` (adds `INSERT`/`UPDATE`/`DELETE`/`TRUNCATE`/`REPAIR`), `admin` (adds DDL and
`VACUUM`). Each key is also restricted to a list of databases.

On by default: HTTPS enforcement (`php configurar.php --local` turns it off
for a development machine), replay protection, the per-IP rate limit and
lockout, and generic error messages instead of detailed ones
(`DEVOLVER_ERRORES = false`). Off by default: the per-key rate limit, the IP
allow-list (single IPs and CIDR ranges, IPv4 and IPv6) and
HSTS.

---

## Tests

Twenty-seven suites, no dependencies, all using temporary directories — they
never touch your data. The ones that load dumps into other systems or run
PowerShell scripts do more where those are installed (CI has them).

```
php tests/f1_nucleo.php       → OK: 76    storage, types, locking, direct access
php tests/f2_parser.php       → OK: 86    parser and bound parameters
php tests/f2_select.php       → OK: 165   SELECT execution and collation
php tests/f3_escrituras.php   → OK: 122   writes, DDL, keys, triggers, transactions and holes
php tests/f4_api.php          → OK: 84    real requests against the API
php tests/f5_esquema.php      → OK: 125   SHOW, ALTER, CREATE TABLE … AS, constraints, views, integrity, journal, result cache
php tests/f5_admin.php        → OK: 189   the panel, driven like a user
php tests/f6_cortes.php       → OK: 36    crash recovery, killing real processes
php tests/f7_concurrencia.php → OK: 35    real simultaneous processes and locking
php tests/f8_indices.php      → OK: 93    indexes, against a full scan every time
php tests/f9_journal.php      → OK: 36    every intermediate state a crash can leave
php tests/f10_indices_incrementales.php → OK: 22   indexes corrected instead of rebuilt
php tests/f11_asistente.php    → OK: 45    panel setup wizard and direct connection
php tests/f12_contra_sqlite.php → OK: 16   238 queries, 32 writes and 8 triggers that touch rows of their own statement, same results as SQLite
php tests/f13_fuzz_contra_sqlite.php → OK: 2000  random queries against SQLite (day's seed; --n, --semilla)
php tests/f14_volcados.php     → OK: 65    dumps to and from SQLite, MySQL, PostgreSQL, SQL Server (more with the servers)
php tests/f15_idiomas.php      → OK: 6     the panel's translations and the choice of language
php tests/f16_vistas_triggers.php → OK: 11  views and triggers exported to other systems (more with MySQL and PostgreSQL: they must do the same there)
php tests/f17_rutinas_importadas.php → OK: 9 views and triggers imported from MySQL, PostgreSQL and SQL Server (more with the servers)
php tests/f18_access.php       → OK: 10    Microsoft Access: import and export (more with PowerShell: the script)
php tests/f19_escrituras_contra_sqlite.php → OK: 19  random writes and queries on tables of many parts, against SQLite
php tests/f20_memoria.php      → OK: 32    the panel with 32 MB: export, import and backups of a larger database (also --directa)
php tests/f21_compatibilidad.php → OK: 132 SQLite and MySQL syntax and functions, windows, recursion, COLLATE, against SQLite
php tests/f22_sqlserver.php    → OK: 4     SQL Server's script and import (more with PowerShell)
php tests/f23_reparar.php      → OK: 29    hand-broken JSON files repaired, or set aside; indexes checked against the rows
php tests/f24_versiones_anteriores.php → OK: 28  every published version's database opened by this one (needs the git tags)
php tests/f25_ficheros_abiertos.php → OK: 5  files another process holds open while a write replaces them (Windows; the CI runs it there too)
```

The CI runs them all with PHP 8.0 to 8.5 on Linux; on Windows, `f25` and the
engine's suites (`f1`, `f2_parser`, `f2_select`, `f3`, `f8`, `f9`, `f10` and
`f23`, 2.10); and PHPStan.

`f6_cortes.php` kills real processes with `SIGKILL` mid-write and demands that
every row be either the old value or the new one, never a mix. `f9_journal.php`
is its deterministic counterpart: it rebuilds by hand every state a commit passes
through — each temporary already in place or still pending — and demands the
exact bytes of the finished write; it also blocks the journal folder to prove
that a write which cannot journal changes nothing. `f8_indices.php` never asserts
literal results: it compares every indexed query against the same condition
written so the index cannot be used, and the cheap write paths against a table
without indexes.

The engine, the API and the panel are checked with PHPStan at level 5 in the
CI (2.10, `phpstan.neon.dist`): anything new it finds fails the run. What it
reported when it was added is in `phpstan-baseline.neon`, and each entry was
looked at: places where it cannot follow a type (a flag set by a nested call, a
list built by a loop, a numeric string that has just been checked) or defensive
calls it considers redundant; none of them is a bug. The configuration constants
are declared dynamic, so it does not take their default values for the only
ones. PHPStan is downloaded by the CI, not a dependency: the project needs
nothing beyond PHP itself.

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
| [`docs/06-format.md`](docs/06-format.md) | Reference of the files on disk |
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
the benchmarks and the documentation — is produced with the help of several
AI models, working from that specification and under the author's direction,
and reviewed by him before being kept.

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
