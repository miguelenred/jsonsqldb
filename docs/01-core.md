# jsonSQLDB — Part 1: the storage core

A database engine written **entirely in PHP 8.0+**, with no mandatory
extensions and no dependency on SQLite or MySQL.

Optional extensions that are used **if present** (never required):

| Extension | Used for | If missing |
|---|---|---|
| `apcu` | shared-memory cache | on-disk cache (`.cache/`) |
| `mbstring` | length of UTF-8 text | an equivalent built-in calculation |

`fsync()` exists from PHP 8.1. On 8.0 the engine flushes PHP's buffer, which is
as far as that version can go; the rest of the durability design is the same.
See [Durability](#6-durability-atomic-files-and-the-journal).

---

## 1. Layout on disk

One **folder per database** inside the data root:

```
<data_root>/
└── mydb/                        ← one folder = one database
    ├── _database.json           database metadata
    ├── .lock                    database lock file (empty)
    ├── .users.lock              lock file of one table (empty)
    ├── .htaccess / web.config   deny access if the folder ends up in the web root
    ├── .cache/                  serialised cache (regenerable: safe to delete)
    ├── .tx/                     journal; only exists while a write is in progress
    ├── users.meta.json          table structure
    ├── users.rev.json           revision of the table and state of its parts
    ├── users.json               data (part 1)
    ├── users.part2.json         data (part 2, from 1,000 rows on)
    ├── users.idx.auto_id.json   one file per index
    └── orders.meta.json / orders.json
```

Databases created before 2.0 have a `_revs.json` with the revisions of all
tables together and no index files; see
[Upgrading from an earlier version](#10-upgrading-from-an-earlier-version).

Allowed names: database `[A-Za-z0-9_-]{1,64}`, table/column
`[A-Za-z_][A-Za-z0-9_]{0,63}`. Nothing else is accepted, so there is no way to
escape the folder with `../` or an absolute path.

### Data file (`users.json`)

Meant to be opened and read by a person: **one row per line**.

```json
{
  "table": "users",
  "rows": [
    {"id":1,"name":"Ana","email":"ana@x.es","balance":10.56,"joined":"2026-01-15 08:30"},
    {"id":2,"name":"Luis","email":"luis@x.es","balance":0,"joined":null}
  ]
}
```

It is valid JSON: any editor can change it and the engine will read it. If a
hand edit breaks the JSON, the engine returns `IO: Datos ilegibles en
users.json` rather than corrupting the table. After editing a data file by
hand, delete `.cache/` and run `INTEGRITY CHECK`: the cache and the indexes are
invalidated by a revision counter that only moves when the engine writes.

**Parts**: past 1,000 rows (`JSONSQLDB_FILAS_POR_PARTE`) the data is spread over
`users.json`, `users.part2.json`, … Parts that become empty are removed. Reading
a table always concatenates its parts, but the engine reads them **one at a
time**: a query never needs the whole table in memory unless it genuinely uses
every row (see [Memory](#8-memory)).

### Structure file (`users.meta.json`)

Only keys with a value are written, so it stays readable:

```json
{
    "table": "users",
    "columns": [
        {"name": "id", "type": "INTEGER", "notnull": true, "pk": true, "autoincrement": true},
        {"name": "name", "type": "TEXT", "length": 50, "notnull": true},
        {"name": "email", "type": "TEXT", "length": 120, "unique": true},
        {"name": "balance", "type": "DECIMAL", "scale": 2, "default": 0},
        {"name": "joined", "type": "DATETIME"}
    ],
    "unique": [{"name": "uq_users_nif", "columns": ["nif"]}],
    "foreign_keys": [
        {"name": "fk_orders_user_id", "columns": ["user_id"],
         "table": "users", "references": ["id"],
         "on_delete": "CASCADE", "on_update": "NO ACTION"}
    ],
    "triggers": [
        {"name": "trg_orders_ins", "timing": "AFTER", "event": "INSERT",
         "when": "NEW.total > 0",
         "body": ["UPDATE users SET balance = balance + NEW.total WHERE id = NEW.user_id"],
         "sql": "CREATE TRIGGER trg_orders_ins ..."}
    ],
    "autoincrement": {"column": "id", "next": 3},
    "created_at": "2026-08-19 10:00:00",
    "updated_at": "2026-08-19 10:04:12"
}
```

### Revision file (`users.rev.json`)

Written on every write to the table. It is what invalidates the cache and what
lets a write know which files it can leave alone:

```json
{
    "rev": 7,
    "chunk": 1000,
    "rows": 2340,
    "parts": [3, 3, 7],
    "indexes": {"auto_id": 7, "idx_city": 5}
}
```

| Key | Meaning |
|---|---|
| `rev` | goes up by one on every write to this table |
| `chunk` | rows per part the table was written with |
| `rows` | how many rows the table has (2.5) |
| `parts` | the revision at which each part was last written (2.5) |
| `indexes` | the revision at which each index was last written (2.5) |

A part or an index whose revision equals the one recorded here is current. The
cache key of a part carries *its* revision, not the table's, so a write that
touches one part of a hundred leaves the other ninety-nine cached. An index
whose content does not change (an `UPDATE` of a column it does not cover, an
`ALTER TABLE` that only touches the structure) is not rewritten either.

Files written by 2.2–2.4 have only `rev` and `chunk`; files written by 2.0–2.1
only `rev`. Both are read as they are; the missing keys are filled in on the
first write.

### Index file (`users.idx.auto_id.json`)

```json
{"index":"auto_id","table":"users","columns":["id"],"rev":7,"rows":2340,"chunk":1000,
 "keys":{"n1:1":0,"n1:2":1,"t6:Madrid":[4,19,57]}}
```

`keys` maps a key (type, length and value of each indexed column, so composite
keys are unambiguous and prefix lookups work) to the positions of the rows that
hold it. A single position is stored as an integer and several as a list
(2.5); indexes written before 2.5 always stored lists and are read the same
way. Details in [Indexes](#5-indexes).

---

## 2. Data types

| Internal type | Aliases accepted in `CREATE TABLE` | Stored as |
|---|---|---|
| `INTEGER` | INT, INTEGER, TINYINT, SMALLINT, MEDIUMINT, BIGINT, BOOL, BOOLEAN | integer |
| `DOUBLE` | REAL, FLOAT, DOUBLE, DOUBLE PRECISION | number |
| `DECIMAL` | DECIMAL(p,s), NUMERIC, NUMBER, MONEY | number rounded to `s` decimals (2 by default) |
| `TEXT` | TEXT, VARCHAR(n), NVARCHAR, CHAR, NCHAR, CLOB, STRING, BLOB | string |
| `DATETIME` | DATE, DATETIME, TIMESTAMP | string `yyyy-MM-dd[ HH:mm[:ss[.fff]]]` |

The aliases are the ones SQLite uses, so a `CREATE TABLE` written for SQLite is
accepted as it is.

**Dates**: the time, seconds and milliseconds are **optional** and the
precision you wrote is kept. `T` or a space is accepted as separator on input;
the value is always stored with a space. Because the format is fixed, alphabetical
order is chronological order, so `ORDER BY` and `BETWEEN` on dates work without
any conversion.

Valid examples: `2026-02-28`, `2026-02-28 10:05`, `2026-02-28 10:05:09`,
`2026-02-28 10:05:09.700`.

`DECIMAL` is rounded floating point, not exact decimal. For money that has to
add up to the cent, store cents in an `INTEGER`.

---

## 3. Concurrency: two lock levels

`flock` on two files: one for the database (`.lock`) and one per table
(`.<table>.lock`). They are **always taken in that order** — database first,
then table — and that fixed order is what makes a deadlock impossible.

| Operation | Database | Table |
|---|---|---|
| `SELECT` and other reads | shared | shared, on each table read |
| A write to **one** table with no foreign keys or triggers | shared | exclusive |
| Cascades and triggers, when the set of tables is knowable | shared | exclusive on **every** table it can reach |
| DDL, views, `REPAIR KEYS`, `INSERT ... SELECT` | exclusive | — |

So two writes to different tables run at the same time, and a write does not
block reads of the other tables.

### What gets locked when a write can propagate

A write does not always stay in its table: a foreign key with `ON DELETE
CASCADE` drags child rows along, and a trigger can write anywhere. The engine
works out the **set of reachable tables** first — foreign keys in both
directions and transitively, plus the targets of the triggers, read from their
SQL — and locks only those.

Two details make it safe:

- **All locks are taken up front, before anything is written.** Asking for one
  more lock halfway through a write is the recipe for a deadlock.
- **They are taken in alphabetical order.** Two processes that need the same
  tables ask for them in the same sequence, so one waits for the other instead
  of both waiting for each other.

It falls back to the exclusive database lock as soon as the set cannot be
stated: a trigger whose SQL cannot be analysed, an `INSERT ... SELECT` (reads
other tables), any structure change, or more than eight tables, where taking
that many locks costs more than one. The decision lives in
`Database::tablasAfectadas()` and is deliberately suspicious: when in doubt,
the database — one lock too many costs parallelism, one too few costs data.

A `SELECT` on a table with a write in progress **waits for it** and then
returns the new data. Reads take the shared lock of each table the first time
they read from it; two shared locks do not obstruct each other. That per-table
lock is needed because a table can span several files: the write puts them in
place one after another, and without it a concurrent read could take the first
part already new and the second still old.

The lock is **re-entrant** (a trigger writing inside an `INSERT` does not ask
again) and upgrading from read to write is forbidden, so each statement decides
its mode before starting.

One detail that was hard to find: deciding the scope means reading the
structure, and that happens **before** the lock is held. What was read can go
stale as soon as another process writes, so the catalogue is forgotten right
after locking. Without that, two processes could reuse the same autoincrement
value.

> On Windows `flock` works the same (mandatory system lock). On NFS `flock` may
> be unreliable; do not put the data root on NFS.

`tests/f7_concurrencia.php` checks this with real processes, measuring what
overlaps and what waits.

---

## 4. Cache and invalidation

Every read of a part goes through a cache entry keyed by the table name, the
part number and **the revision at which that part was written** (from
`rev.json`). While nobody writes, every request reuses the cache; as soon as
someone writes, the revision of the parts they touched changes and any other
process reads the new data even if its old cache is still there. The structure
and each index have an entry of their own, keyed the same way. Entries that a
write leaves behind are deleted (APCu and disk); they do not accumulate.

The revision is per table and not one shared file, because two writes to
different tables run at the same time and a shared file would be rewritten in
full by both: whichever finished last erased the other's bump and left its cache
serving stale rows.

The cache steps aside when memory is tight (see [Memory](#8-memory)), and
`INTEGRITY CHECK` and `COUNT(*)` bypass it on purpose: they need to see what is
really in the files.

Deleting `.cache/` by hand is safe at any time.

---

## 5. Indexes

An index maps the value of one or more columns to the **positions** of the rows
that hold it, and from a position the engine knows which part the row lives in:
only those parts are decoded. The primary key and every `UNIQUE` get one
automatically, named `auto_<columns>`; the rest are created with
`CREATE INDEX`.

Indexes serve reads and writes:

- A `SELECT` with an equality or `IN` on an indexed column decodes only the
  parts that hold the matching rows. On a table of twenty parts, one instead
  of twenty.
- An `INSERT` checks the primary key and the `UNIQUE` constraints against the
  index on disk instead of loading the table, and appends to the last part.
- An `UPDATE` or `DELETE` whose `WHERE` an index can answer reads only the parts
  that hold the candidate rows and rewrites only those (a delete shifts every
  row after it, so from the first deleted position on the parts are redone).

Only equalities and `IN` against literals in the top-level `AND` chain of the
`WHERE` use an index. Ranges, `LIKE`, `ORDER BY`, aggregates, `IS NULL`,
`NOT IN`, a top-level `OR` and anything under a `NOT` scan the table.

### How an index is kept up to date

Positions are not stable: a table is stored by position, so one `DELETE` moves
every row after it. Rebuilding the index from scratch on every write was most
of the cost of writing one row into a large table, and almost always repeated
work. Since 2.5 the previous index is **corrected** whenever the engine can
prove what changed:

- rows **appended** at the end: their keys are added;
- rows **replaced in place** (an `UPDATE` that does not move anything): the old
  key is removed and the new one added, using the old row read from the part
  that has not been replaced yet;
- rows **shifted from a position on** (a `DELETE`): every entry from that
  position is cut and the rows behind it are re-added.

Any doubt — a revision file that does not say how many rows there were, a part
size that changed, an index file of a different revision — rebuilds the index
from the rows. The check is strict on purpose, because the two errors do not
cost the same: an entry too many only makes a query slower (the `WHERE` is
re-applied to the rows read), an entry too few returns incomplete results with
nothing to show for it.

The revision file records at which revision each index was written; if the
index file says otherwise, or its columns are not the expected ones, the engine
ignores it and scans. A stale or hand-edited index can cost speed, never a wrong
answer. `JSONSQLDB_INDICES` set to `false` disables indexes altogether.

---

## 6. Durability: atomic files and the journal

### One file

Every file is written **atomically**: the content goes to `file.<pid>.tmp`, is
forced to disk with `fsync()`, and is then renamed into place. `rename` is
indivisible on every file system that matters, so a crash leaves either the old
file or the new one, never a half-written one. Without the `fsync` there would
be name atomicity but not durability: the operating system could still have the
data in its cache and a power cut would leave the new file empty even though
the rename had happened.

After a rename the **directory entry** is forced to disk as well (`fsync` on a
descriptor of the folder): the content may be on disk and the name still only
in the system cache, and POSIX does not guarantee the order. On Windows a
directory cannot be opened for that, and `rename` does not replace an existing
file either (the engine deletes and renames); both are covered by the journal.

`fsync()` exists from PHP 8.1. On 8.0 PHP's buffer is flushed, which is as far
as that version can go.

### Several files: the redo journal

One file is atomic, but **a set of files is not**, and almost no write touches
just one: a table with more than `JSONSQLDB_FILAS_POR_PARTE` rows lives in
several parts, a table with indexes rewrites each of them, every write rewrites
`rev.json`, and an `INSERT` into a table with `AUTOINCREMENT` rewrites the
structure file too. A crash between two renames would leave half the table new
and half old — and because parts are split **by position**, that does not lose
"some rows", it misaligns the whole table from the cut onward.

Since 2.5 the journal is a **redo log**:

1. Every file the write produces goes to its temporary, forced to disk.
   **Nothing is renamed yet.**
2. When all of them are written, a manifest is written to
   `.tx/<scope>/manifiesto.json` — in one piece, forced to disk — saying which
   temporary goes to which file and which files are to be deleted.
3. The renames and deletions are applied and the folder is removed.

If the process dies before step 2, the temporaries are junk and the data is
intact: nothing was ever renamed. If it dies after, the manifest is found the
next time the database is opened and **the write is finished**: the
temporaries are on disk, so redoing always completes, and it can be repeated as
many times as it takes (a rename already done is skipped). Every intermediate
state is exercised by `tests/f9_journal.php`, one by one.

```json
{
    "tipo": "redo",
    "operacion": "ESCRITURA",
    "ambito": "orders",
    "tablas": ["orders"],
    "renombrar": {
        "orders.part3.json.4121.tmp": "orders.part3.json",
        "orders.rev.json.4121.tmp": "orders.rev.json",
        "orders.idx.auto_id.json.4121.tmp": "orders.idx.auto_id.json"
    },
    "borrar": ["orders.part4.json"],
    "ts": "2026-09-04 17:20:11"
}
```

The **scope** is the lock the write holds, and it says which lock recovery
needs: `.tx/_base/` when the write held the exclusive database lock,
`.tx/<table>/` when it was confined to a table (the manifest lists every table
it touched). Recovery of a table journal only needs those tables' locks, which
is what lets writes to other tables carry on meanwhile. Checking whether a
journal is pending costs one `stat` on `.tx/`, done once per request when the
lock is taken.

Versions up to 2.4 used an undo journal: copies of the files about to change,
restored on recovery. Those journals are still recognised — by their manifest
having an `estado` instead of a `tipo` — and undone the same way, so a database
left with a pending journal by an earlier version recovers correctly. The
pre-2.0 layout (copies loose in `.tx/`) is recognised too.

What the redo journal costs: one manifest write and a couple of directory
`fsync`s per write, instead of copying every file of the table. On a 20,000-row
table an `INSERT` went from 96 ms to 18 ms, and the difference grows with the
table.

A process killed with `SIGKILL` runs no `finally` and can leave its temporary
on disk; that cannot be avoided from inside. What is avoided is accumulation:
every write sweeps the stray temporaries of its table, which it can do safely
because it holds the table's exclusive lock, and a process holding the
exclusive database lock sweeps them all.

What the journal does **not** cover: grouping several statements into one unit
of work. There is no `BEGIN`/`COMMIT`: each statement is atomic on its own,
cascades and triggers included, but two consecutive statements are not undone
together.

`tests/f6_cortes.php` kills real processes with `SIGKILL` — mid cascade, mid
write of a multi-part indexed table, and once per kind of operation — and
demands that every row be either the old value or the new one, that the indexes
agree with the table, that the cache agrees with the files, and that nothing is
left over. `tests/f9_journal.php` is the deterministic counterpart: it rebuilds
by hand every state the commit passes through and demands the exact bytes of
the finished write, checks that a write which cannot journal changes nothing,
and that a pending journal of the previous format is undone.

---

## 7. Writes

Every write accumulates its changes in memory and flushes them at the end, in
one journalled commit: if a constraint fails on the third row of a three-row
`INSERT`, nothing is written. What gets written is the minimum:

- **Only the parts that changed.** Inserting a row into a table of a hundred
  parts rewrites the last one. The revision file remembers the part size the
  table was written with; if `JSONSQLDB_FILAS_POR_PARTE` changed since, the
  part boundaries moved and every part is rewritten.
- **Only the indexes that changed**, corrected rather than rebuilt when
  possible (see [Indexes](#5-indexes)).
- **Without reading the table** when nothing forces it: an `INSERT` into a
  table with no triggers, whose unique constraints have their index on disk,
  reads only the last part; an `UPDATE` or `DELETE` by key reads only the
  parts of the candidate rows. A trigger, a self-referencing foreign key, an
  index that cannot be trusted or a `WHERE` no index can answer fall back to
  loading the table, which is what every write did before 2.5.

The revision file goes up by one on every write, and its new version is
renamed together with everything else, so a reader never sees new data under an
old revision or the other way round.

---

## 8. Memory

On cheap shared hosting memory is the binding constraint, so it gets its own
section.

### The number that matters

A 1.9 MB JSON file becomes about 26 MB of PHP arrays — roughly **14×**. That is
not overhead you can tune away: every row is a hash table with its own copy of
the column names as keys, and short values inflate more than long ones.

So the working rule is:

```
memory_limit  ≥  20 × (the largest table a single query has to hold in full)
```

A query holds a table in full only when it needs every row at once: `ORDER BY`
without `LIMIT`, `GROUP BY`, the inner side of a `JOIN`, `DISTINCT`. A `WHERE`
scan does not — rows are read one part at a time and only the survivors are
kept — and neither does a write. If APCu is enabled its memory is separate and
does not count against `memory_limit`.

**Lowering `JSONSQLDB_FILAS_POR_PARTE` does not reduce the full-table case.**
Splitting a table into more parts only bounds the size of each decode; a query
that needs every row still ends with every row in memory. What the split buys
is being able to *skip* parts, which is what indexes and streaming do.

**Compression is not the way out.** The peak is not the JSON text, it is the
decoded array, and an array has to be decoded to be filtered, joined or sorted.

### What the engine does about it

Measured on the bundled benchmark (PHP 8.3, 20,000 customers and 30,000 orders;
`php tests/benchmark.php`), 2.4.0 against 2.5.0:

| Operation | 2.4.0 | 2.5.0 |
|---|---|---|
| Lookup by primary key | 4.2 ms · 14 MB | **1.9 ms · 7 MB** |
| Numeric range, no index | 35 ms · 22 MB | **22 ms · 7 MB** |
| `LIMIT 50`, no filter | 12 ms · 22 MB | **0.5 ms · 5 MB** |
| `GROUP BY` with `SUM` | 39 ms · 22 MB | **34 ms · 15 MB** |
| `JOIN` aggregated by city | 174 ms · 53 MB | **128 ms · 43 MB** |
| `INSERT` one row | 96 ms · 32 MB | **18 ms · 13 MB** |
| `UPDATE` one row by key | 132 ms · 31 MB | **13 ms · 11 MB** |
| `DELETE` one row by key | 215 ms · 32 MB | **32 ms · 13 MB** |

And on 100,000 customers: a one-row `INSERT` went from 742 ms and 140 MB to
104 ms and 43 MB, an `UPDATE` by key from 936 ms and 135 MB to 47 ms and 33 MB,
and loading the table in batches of 2,000 from 62 s to 4 s. Writes now scale
with the size of the indexes, not with the size of the table.

What makes the difference:

- **Rows are read one part at a time and filtered as they arrive.** A `WHERE`
  scan keeps only the rows that pass; the decoded part and the flattened rows
  never coexist in full.
- **Indexes decode only the parts that hold the wanted rows**, for reads and
  for writes.
- **`LIMIT` is pushed into the read** when there is no `WHERE` and no `JOIN`.
- **`SELECT COUNT(*)` and `SHOW TABLES` never build the rows**: they count
  lines.
- **Index entries are integers when a key has one position** — half the memory
  of the one-element lists used before.
- **The cache steps aside when memory is tight.** Storing an entry means
  serialising it, which holds it twice for an instant; past half the limit the
  engine gives up the cache rather than risk the query.

The memory limit is never raised automatically: it exists so one request does
not take the others down, and raising it is the administrator's decision.

### When it still will not fit

A result that genuinely does not fit cannot be made to fit. What the engine
guarantees is *how* it fails. With `JSONSQLDB_MEMORIA_VIGILAR` on (the default)
the engine checks its consumption every 512 rows — every 8 past half the
limit — and stops itself at 85 % of `memory_limit`
(`JSONSQLDB_MEMORIA_MARGEN`), raising a normal error with `sqlState`
`MEMORIA`:

```
Se ha cortado el producto cartesiano: lleva 56 MB de los 64 MB que PHP tiene
asignados. Acota la consulta con WHERE o LIMIT, o sube memory_limit si de
verdad necesitas ese volumen de una vez.
```

The process stays alive, the API returns its JSON error and the client knows
what happened, instead of PHP's fatal error, which cannot be caught, runs no
`finally` and hands the client a broken response.

Some detail on how it decides, because the naive version did not work:

- It measures memory **in use** first, not reserved: PHP keeps blocks it already
  asked for even when free, so after a large query the reserved figure stays
  high and would cut the next query before it started.
- It watches the reserved figure too, because **the limit applies to it**.
  Between the two there is a gap of fragmented blocks that is not negligible
  with a small limit; with 16 MB the process hit the fatal with only 13 MB in
  use.
- It also stops when **another jump like the last one would not fit**. PHP
  doubles an array's hash table when it grows, so between two checks
  consumption can jump by more than the remaining margin. And the reserve never
  drops below a fraction of what is already used, because the jump that can
  really kill the process is a reallocation of the order of the array's own
  size.
- Underneath there is a **safety net that does not depend on guessing right**:
  the engine sets aside two megabytes at start and registers a shutdown
  function. If PHP does abort for lack of memory, that function releases the
  reserve and the API still returns a normal JSON error rather than an empty or
  truncated body. Shutdown functions always run, even after a fatal error.
- **What it cannot fully cover**: a file is decoded in one instruction, and the
  peak happens before anyone can look. Before opening a file the engine
  estimates from its size whether the decoded content will fit. The estimate is
  a heuristic, so this reduces the window without closing it.

Data is never at risk from running out of memory: a read writes nothing, a
write flushes at the end so it has not touched the disk yet, and the locks are
released by the operating system when the process dies.

---

## 9. Configuration and protection

### `config.php`

| Constant | Purpose | Default |
|---|---|---|
| `JSONSQLDB_DATA_PATH` | root folder with one subfolder per database | `data/` |
| `JSONSQLDB_FILAS_POR_PARTE` | rows per file before a table is split | `1000` |
| `JSONSQLDB_CACHE_ACTIVA` | enable/disable the cache | `true` |
| `JSONSQLDB_INDICES` | maintain and use indexes | `true` |
| `JSONSQLDB_LOG_ACTIVO` | enable the query log | |
| `JSONSQLDB_LOG_PATH` | folder of the log files | `logs/` |
| `JSONSQLDB_LOG_NIVEL` | `todo` / `escrituras` / `errores` | `todo` |
| `JSONSQLDB_LOG_MAX_SQL` | maximum length of the SQL stored | `2000` |
| `JSONSQLDB_LOG_PARAMS` | also store the values of the `?`. Default **no** | `false` |
| `JSONSQLDB_LOG_MAX_SIZE` | maximum size per file before rotating | 5 MB |
| `JSONSQLDB_LOG_DIAS` | days to keep the logs (0 = forever) | `90` |
| `JSONSQLDB_CONEXION_DIRECTA` | use the engine without the API | `false` |
| `JSONSQLDB_MEMORIA_VIGILAR` | stop the query before memory runs out | `true` |
| `JSONSQLDB_MEMORIA_MARGEN` | fraction of `memory_limit` at which to stop | `0.85` |
| `JSONSQLDB_COLACION` | alphabetical order of `ORDER BY`: `general` or `binaria` | `general` |
| `JSONSQLDB_COLACION_MAPA` | per-language ordering corrections | `[]` |

Removed in 2.5 and ignored if still defined: `JSONSQLDB_JOURNAL_DATOS` (the
journal is always on; it is now cheap) and `JSONSQLDB_CACHE_MAX_FILAS` (the
cache is per part, so there is no whole-table entry to cap).

### Direct connection to the engine

By default the engine can **only** be used through the API. Any attempt to
instantiate `Database` from elsewhere is rejected with an explicit message. To
allow it, in `config.php`:

```php
defined('JSONSQLDB_CONEXION_DIRECTA') || define('JSONSQLDB_CONEXION_DIRECTA', true);
```

**For experienced programmers only.** With a direct connection:

- **There are no permissions.** It is always equivalent to an `admin` key: it
  can read, write, alter the structure and drop whole databases.
- **There is no API key and no signature.** HMAC, rate limiting, anti-replay and
  the IP list are all skipped. Security is entirely your code's business.
- **It is still logged.** Every query goes to the log as through the API, with
  `ip` set to `"local"`.
- **Bound parameters still apply**, and they are the only thing protecting you
  from injection: `consultar($sql, $params)` with `?` in the SQL.

```php
require 'config.php';
require 'engine/bootstrap.php';

$db   = new JsonSQLDB\Database('mydb');
$rows = $db->consultar('SELECT * FROM customers WHERE city = ?', ['Torrevieja']);
```

It makes sense in a maintenance script, a migration or a cron job, where the
HTTP hop only adds latency. For anything exposed to third parties, the API.

Using the storage layer directly, for maintenance:

```php
require_once __DIR__ . '/engine/bootstrap.php';

use JsonSQLDB\Storage;
use JsonSQLDB\Catalog;

Storage::crearBase('E:/data/jsonsqldb', 'mydb');

$st  = new Storage('E:/data/jsonsqldb', 'mydb');
$cat = new Catalog($st);

$st->bloquear(true);                    // write lock
try {
    $cat->crearTabla('users', [
        'columns' => [
            ['name' => 'id',    'type' => 'INTEGER',      'pk' => true, 'autoincrement' => true],
            ['name' => 'name',  'type' => 'VARCHAR(50)',  'notnull' => true],
            ['name' => 'email', 'type' => 'VARCHAR(120)', 'unique' => true],
        ],
    ]);
} finally {
    $st->desbloquear();                 // always in a finally
}
```

### Query log

The **values** of bound parameters are not stored unless you enable
`JSONSQLDB_LOG_PARAMS`: passwords, tokens and personal data travel through
them, and the log is kept 90 days by default. The SQL is stored with its `?`
unsubstituted.

One file per day, `logs/consultas-2026-08-19.json`, and `-1.json`, `-2.json`… when
rotating by size. Each line is an independent JSON object:

```json
{"ts":"2026-08-19 12:00:00.123","ip":"10.0.0.5","db":"mydb","op":"SELECT","rows":42,"ms":3.15,"origen":"My app","sql":"SELECT ...","error":null}
```

- `rows` → rows **returned** by a SELECT or **affected** by INSERT/UPDATE/DELETE
- `ip` → source IP of the request (`cli` from the console, `local` on a direct connection)
- `origen` → label of the API key that ran the query
- `error` → `null` if it went well, or the message if it failed

Line-delimited JSON rather than a single array: appending never rereads or
rewrites the file, which is what lets the log keep up with the queries. If the
log folder cannot be written, the engine **keeps working**: the log never
interrupts a query.

### Protection from the browser

| File | What it blocks |
|---|---|
| `.htaccess` + `web.config` (root) | directory listings, `config.php`, hidden files, any `.json`, `.md`, `.log`, `.lock`, `.cache`, `.tmp`, and the folders `engine/ data/ logs/ docs/ tests/` |
| `engine/`, `data/`, `logs/`, `docs/`, `tests/` | each with its own `.htaccess` and `web.config` denying everything |
| each database folder | `.htaccess` and `web.config` created automatically with the database |

Two layers on purpose: if the host ignores the root `.htaccess` or has no
`mod_rewrite`, the per-folder ones still protect. Works for Apache 2.2 and 2.4
and for IIS. **nginx reads neither**: see [`../nginx/README.md`](../nginx/README.md).

Even so, **the recommended setup keeps `data/` and `logs/` outside the web root**
and exposes only `api/`.

### Several installations on one machine

Everything the engine shares between processes lives inside the database
folder — lock files, journal, temporaries and the disk cache — so two
installations with different data folders never see each other. The only truly
global resource is APCu, and there the keys carry a prefix derived from the
database path.

What is worth keeping separate, because it does not depend on the engine:

| | Constant | Why |
|---|---|---|
| Data folder | `JSONSQLDB_DATA_PATH` | what makes the two installations independent |
| API state | `API_ESTADO_PATH` | anti-replay and per-IP quota; sharing it would mix the limits |
| Panel session | `ADMIN_SESION_NOMBRE` | two panels on the same domain with the same session name overwrite each other's cookie |
| Panel data | `ADMIN_DATA_PATH` | users and audit of each panel |

All four default to paths inside the project folder, so two copies of the
project in different folders come out separated without changing anything.

What you **cannot** do is point two different data folders at the same files
(through symbolic links, for instance). Locks are taken by path, so two paths to
the same file are two locks that do not exclude each other.

---

## 10. Upgrading from an earlier version

Replace the folder and keep your two configuration files
(`api/jsonsqldb_api_config.php` and `jsonsqldbadmin/config.php`, both
gitignored and not shipped).

**The data needs no conversion.** An existing database is read as it is, and
each table moves to the current layout on the first write it receives:

| Before | After |
|---|---|
| `_revs.json` with every table's revision (pre-2.0) | one `<table>.rev.json` per table |
| no index files (pre-2.0) | `<table>.idx.auto_*.json` for the primary key and the `UNIQUE`s |
| `rev.json` without `rows`, `parts`, `indexes` (2.0–2.4) | the three keys added; the first write of each table rebuilds its indexes once |
| index entries always as lists (pre-2.5) | read as they are; rewritten as integers when the index is next written |

Revision numbers **carry on from where they were** rather than restarting, so a
cache entry from before the upgrade cannot be mistaken for a current one. The
old `_revs.json` is left alone and stops being read.

### The one case to watch

A database left **with a pending journal** by the earlier version, because that
version died mid-operation and the database was never reopened. Journals up to
2.4 are undo journals; 2.5 recognises them and undoes them, and the pre-2.0
layout (copies loose in `.tx/`) is recognised too. Still, **the clean thing is to
open each database once with the previous version before replacing the
folder**, if only to run a query: that way anything left half-done is undone by
the version that wrote it. `tests/f9_journal.php` covers reading an old-format
database without writing, the first write producing the new files without
reusing revisions, a pending journal of the previous format being undone, and
the database working afterwards.

### What does stop working

**The HMAC signature of the API changed in 2.0** and requests signed with the
old formula are rejected. Any client of your own must be updated; the four that
ship with the project (PHP, Python, PowerShell and the panel) already are. See
[04-api.md](04-api.md).

---

## 11. Files of this part

| File | Responsibility |
|---|---|
| `engine/bootstrap.php` | autoloader (the only `require` you need) |
| `engine/JsonSqlDbError.php` | single exception, typed: CONFIG, SCHEMA, TYPE, CONSTRAINT, SYNTAX, IO, LOCK, PERMISSION, MEMORIA |
| `engine/Types.php` | types, aliases, validation and conversion of values and dates |
| `engine/Storage.php` | JSON files, locking, atomic writes, redo journal, parts, cache, index files |
| `engine/Catalog.php` | tables, columns, PK/UNIQUE/FK, indexes, triggers, autoincrement, ALTER TABLE |
| `engine/Indexes.php` | index keys, construction and correction, choice of index for a query |
| `engine/Memoria.php` | the watchdog that stops a query before PHP's fatal error |
| `tests/f1_nucleo.php` | 66 core checks (leaves nothing on disk) |

Everything else in the project is built on what this document describes:

- the parser and the `SELECT` executor — [02-queries.md](02-queries.md)
- writes, DDL, keys and triggers — [03-writes.md](03-writes.md)
- the signed HTTP API — [04-api.md](04-api.md)
- the `jsonSQLDBadmin` panel — [05-admin.md](05-admin.md)
