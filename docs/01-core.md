# jsonSQLDB — Part 1: the storage core

A database engine written **entirely in PHP 8.0+**, with no mandatory
extensions and no dependency on SQLite or MySQL.

Optional extensions that are used **if present** (never required):

| Extension | Used for | If missing |
|---|---|---|
| `apcu` | shared-memory cache; must be enabled for the SAPI in use (`apc.enable_cli=1` for scripts and cron) | on-disk cache (`.cache/`) |
| `mbstring` | length of UTF-8 text | an equivalent built-in calculation |

**On PHP 8.0 a power cut can lose recent writes.** `fsync()`, the call that
forces data from the operating system's memory onto the disk, exists in PHP only
from 8.1, and there is no reliable way to do the same on 8.0 without an
extension (`dio` is not standard, `posix` has no `fsync`, and `exec('sync')`
needs a shell that shared hosting does not give and flushes the whole machine).
On 8.0 the engine flushes PHP's buffer, which hands the data to the operating
system and no further. So on 8.0 nothing is lost if the **PHP process** dies or
is killed mid-write — the operating system still has the data and the journal
finishes or discards the write — but on a **power cut or an operating system
crash**, writes the operating system had not yet written back can be lost even
though they were reported as done. On Linux with default settings the kernel
writes dirty data back once it is 30 seconds old, checking every 5, so the
window is roughly the last half minute.

What is lost is recent writes, not the tables: every file is replaced by
writing a new one and renaming it over the old, and on ext4 with its default
options (`auto_da_alloc`, `data=ordered`) the kernel detects exactly that
pattern and writes the new file's data before the rename is committed. On
other filesystems that do not, a power cut on 8.0 could leave a replaced file
empty; `INTEGRITY CHECK` and the engine's `Datos ilegibles` error would
report it, and a backup is the way back.

Storage engines that overwrite data in place sometimes add a precaution on
systems without `fsync`: they wait until new data is older than that write-back
delay before overwriting the old copy. jsonSQLDB has no such step to protect —
it never overwrites a file in place; it writes a new one and renames it — so
the precaution would add files and disk operations for nothing.

On 8.1 and later the guarantee is the full one. PHP 8.0 has
had no security support since November 2023; if your hosting offers 8.1 or
later, use it. The panel's Configuration page and its setup wizard warn about
this when they run on 8.0. See [Durability](#6-durability-atomic-files-and-the-journal).

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
    ├── users.idx.auto_id.json   index, piece for part 1
    ├── users.idx.auto_id.part2.json  index, piece for part 2
    └── orders.meta.json / orders.json
```

Databases created before 2.0 have a `_revs.json` with the revisions of all
tables together and no index files; see
[Upgrading from an earlier version](#12-upgrading-from-an-earlier-version).

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
  ],
  "offsets": [39,122],
  "offsets_at": 210
}
```

`offsets` (2.7) is the byte at which each row's line starts, and `offsets_at`
the byte at which that list starts; with them a lookup by key reads the one
line it needs — three small reads, about 30 µs — instead of decoding the
thousand rows of the part. The engine writes them while it writes the rows;
files from earlier versions do not have them and are decoded whole, as before.

It is valid JSON: any editor can change it and the engine will read it. If a
hand edit breaks the JSON, the engine returns `IO: Datos ilegibles en
users.json` rather than corrupting the table. After editing a data file by
hand, delete `.cache/` and make a write to the table (or `REPAIR KEYS`): the
cache and the indexes are invalidated by a revision counter that only moves
when the engine writes, and both the indexes and the offsets refer to the
rows by position and byte, so a row added or removed by hand puts them out of
step until the table is next written.

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
    "autoincrement": {"column": "id", "next": 1},
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
    "creada": 1739451208,
    "parts": [3, 3, 7],
    "indexes": {"auto_id": [3, 3, 7], "idx_city": [3, 5, 7]},
    "rangos": {"auto_id": [[1, 1000], [1001, 2000], [2001, 2340]], "idx_city": [null, null, null]},
    "autoinc": 2341
}
```

| Key | Meaning |
|---|---|
| `rev` | goes up by one on every write to this table |
| `chunk` | rows per part the table was written with |
| `rows` | how many rows the table has (2.5) |
| `creada` | a random number fixed at the table's first write (2.6); see below |
| `parts` | the revision at which each part was last written (2.5) |
| `indexes` | the revision at which each piece of each index was last written (2.6) |
| `rangos` | the smallest and largest numeric value in each piece of each index, `[]` for an empty piece, `null` when the first indexed column is text (2.7) |
| `autoinc` | the next `AUTOINCREMENT` value (2.7). It used to live in the structure file, which an `INSERT` then had to rewrite and force to disk; here it rides on a file the write touches anyway. The structure file keeps the value it had at creation or at the last version before 2.7, and the larger of the two wins |

A part or an index piece whose revision equals the one recorded here is
current. The cache key of a part carries *its* revision, not the table's, so
a write that touches one part of a hundred leaves the other ninety-nine
cached; the same goes for the pieces of an index. An index piece whose content
does not change (an `UPDATE` of a column it does not cover, an `ALTER TABLE`
that only touches the structure) is not rewritten either.

`creada` tells this table apart from an earlier one with the same name that
was dropped: revisions start again from one, and a cache entry — in APCu,
where entries cannot be deleted by table — or a cached query result of the old
table would otherwise pass for the new one.

Files written by 2.5 record one revision per index instead of a list (the
index was one file); files written by 2.2–2.4 have only `rev` and `chunk`;
files written by 2.0–2.1 only `rev`. All are read as they are; the missing
keys are filled in on the first write.

### Index files (`users.idx.auto_id.json`, `users.idx.auto_id.part2.json`, …)

An index is stored in **one file per part of the table**, each holding the
keys of the rows in that part:

```json
{"index":"auto_id","table":"users","columns":["id"],"part":2,"rev":7,"chunk":1000,
 "keys":{"n4:1001":1000,"n4:1002":1001,"t6:Madrid":[1004,1019,1057]}}
```

`keys` maps a key (type, length and value of each indexed column, so composite
keys are unambiguous and prefix lookups work) to the positions of the rows that
hold it, counted from the start of the table. A single position is stored as
an integer and several as a list (2.5). Indexes written before 2.6 were one
file with every key (and before 2.5 always with lists); they are read as they
are and split into pieces the next time the table is written. Details in
[Indexes](#5-indexes).

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

### Writes by part (2.7)

A table is a series of parts of a thousand rows, and an ordinary `UPDATE` or
`DELETE` changes one or two of them. With the table lock held for the whole
statement, readers of the table wait for all of a write's work — reading,
computing, writing and forcing the new files to disk — and so does any other
write to the same table.

Since 2.7, an `UPDATE` or `DELETE` that depends only on each row does that work
holding the table's **shared** lock, and takes the exclusive lock only to
commit: it writes the revision file and the journal manifest and renames. At
that moment it checks that none of the parts it rewrote — nor the pieces of
index that go with them — changed since it read them. If one did, because
another write went to the same part in between, it discards everything without
having renamed a thing and runs again with the whole table locked: it joins the
queue, and neither write is lost. The data files keep their format; only the
order of locking changes.

What counts as depending only on each row: the table has no foreign keys and no
triggers and no other table references it; the statement has no subqueries; and
an `UPDATE` does not touch a column of the primary key or of a `UNIQUE`
constraint, because uniqueness is a property of the whole table. Everything
else — `INSERT`, DDL, anything with keys or triggers — takes the table lock as
before. `JSONSQLDB_ESCRITURA_POR_PARTES = false` turns it off.

Measured on the one-core benchmark machine, with the kernel delaying each
`fsync` by 3 ms as a shared host's disk does, on a 20,000-row table:

| One writer doing `UPDATE`s by key, two readers doing lookups by key, 6 s | Table lock | By part |
|---|---|---|
| Reads done | 334 | **3,837** |
| Read latency p50 / p95 / p99 | 35.7 / 40.5 / 46.2 ms | **2.5 / 4.8 / 19.4 ms** |
| Writes done | 166 | 88 |

Readers stop waiting for writers: eleven times as many reads, at a fourteenth of
the latency. The writes done drop on this machine because it has a single core:
the readers that used to be blocked now run, and share the processor with the
writer; on a server with more cores that competition is much smaller. Between
writers the gain is small: two or four processes updating random rows of the
same table do 30–31 updates a second either way here, because three of the four
`fsync` calls of a write (revision file, manifest, directory) have to happen
inside the commit, and the fourth is the only one that now overlaps.

`tests/f7_concurrencia.php` checks it with real processes: four processes adding
1 fifty times each to the same row must end at exactly 200 (the check that
catches a lost update: without the validation at commit, the same test ends
around 54); three processes writing in different parts while a fourth deletes
rows must leave every sum, every index and no temporary file behind; and two
processes setting the same value in a `UNIQUE` column of rows in different
parts must leave one success and one error.

### Writers do not starve

The obvious cost of the locks is that a read of a table waits for a write in
progress on it: 10 ms on a table of 20,000 rows, 30 ms on one of 100,000, and
only when the two coincide. Measuring it turned up the real problem, which is
the other way round: **the writer waits for the readers, and can wait
forever.** `flock` gives nobody preference: an exclusive lock is granted only
when no shared lock is held, and with readers that keep overlapping — a busy
page served by several PHP workers — that moment may never come.

`php tests/benchmark_concurrencia.php [readers] [writers] [seconds] [pause_ms]`
runs readers and writers on the same table in real processes: the readers
alternate a primary-key lookup and a `WHERE` scan, the writer inserts one row
at a time with an optional pause between rows. Measured on a one-core
machine, 20,000 rows and three indexes:

| Readers / writer / pause between inserts | Length | Writes done, 2.6.0 | Writes done, 2.6.1 (turnstile) |
|---|---|---|---|
| no readers, the writer alone | 5 s | 620 | — |
| 1 reader, no pause | 5 s | 275 | — |
| 2 readers, 100 ms pause | 6 s | 28 | **45** |
| 2 readers, 30 ms pause | 6 s | 39 | **104** |
| 2 readers, no pause | 6 s | 39 | **170** |
| 4 readers, no pause | 8 s | **2** | **123** |
| 4 readers, 50 ms pause | 8 s | **1** | — |

With two readers the writer manages a sixteenth of what it does alone; with
four it manages two rows in eight seconds. For a website that is a form's
`INSERT` taking seconds to get in while the page is being read.

Since 2.6.1 every lock is taken through a **turnstile**: a second `flock`
file (`.turno`, `.<table>.turno`) that everyone crosses before asking for
the lock and releases as soon as they have it. A writer that is waiting keeps
the turnstile, so new readers stop at it; the readers already inside finish,
the writer gets in, and when it releases the turnstile the waiting readers go
through. It costs two extra system calls per lock (measured: a read lock goes
from 0.018 ms to 0.024 ms, a write lock from 0.006 to 0.012) and changes
nothing else: the order in which locks are taken is the same for everyone,
so a deadlock is still impossible. What readers pay is that a read arriving
while a write waits queues behind it — it waits for that one write, 10 ms —
instead of the writer waiting for every reader:

| 2 readers, 1 writer; read latency, p95 | 2.6.0 | 2.6.1 (turnstile) |
|---|---|---|
| writer pausing 100 ms — key lookup | 9.6 ms | 11.1 ms |
| writer pausing 100 ms — `WHERE` scan | 44.0 ms | 49.0 ms |
| writer pausing 30 ms — key lookup | 10.3 ms | 16.7 ms |
| writer pausing 30 ms — `WHERE` scan | 44.2 ms | 53.3 ms |
| writer never pausing — key lookup | 10.5 ms | 26.5 ms |
| writer never pausing — `WHERE` scan | 45.6 ms | 59.9 ms |

(Alone, with no writer at all: 2.5 ms and 19.5 ms.) The read latencies are
inflated on both sides by the single core: five processes share one CPU, and
every write that now gets in takes CPU from the readers. On a server with
several cores the readers barely notice the writer, and the starvation
without the turnstile is if anything worse, because readers overlap more
perfectly. `tests/f7_concurrencia.php` checks it: three readers holding the
lock in a loop for two seconds, and a writer inserting in a loop, which must
keep at least 15 % of its rate alone, with one retry if a loaded CI runner
gives a bad round (without the turnstile it keeps 5–7 %; with it, 41–44 % on the
machine above and 29 % on a GitHub runner with PHP 8.0).

**What was considered and not done: reads without any lock.** Since every
file is replaced atomically, a read could skip the lock, read, and check at
the end that nothing changed, repeating if it did. It was built and
measured: it removes the starvation too, but a read that overlaps a write's
commit has to be thrown away and repeated, and long scans paid for it — in
the table above, the `WHERE` scan's p95 went from 44 ms to 72, 87 and 101 ms
in the three writer settings, while the writer got in less than with the
turnstile (38, 61 and 81 writes). The gain readers were supposed to get was
not there to begin with: a read that does not overlap a write does not wait
today either. The turnstile fixes the real problem at no cost, so that is
what shipped.

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
overlaps and what waits, and that a writer gets in against readers that never
stop.

---

## 4. Cache and invalidation

Every read of a part goes through a cache entry keyed by the table name, the
part number and **the revision at which that part was written** (from
`rev.json`). While nobody writes, every request reuses the cache; as soon as
someone writes, the revision of the parts they touched changes and any other
process reads the new data even if its old cache is still there. The structure
and each index piece have an entry of their own, keyed the same way. Entries
that a write leaves behind are deleted (APCu and disk); they do not accumulate.

### A `JOIN` with few rows on the left looks them up

A `JOIN` builds a hash of the right-hand table and streams the left through
it, which is right for two large tables and wrong for the most common `JOIN`
on a web page: `FROM orders o JOIN customers c ON c.id = o.customer_id WHERE
o.id = ?`, one order and its customer. Since 2.7 the conditions of the
`WHERE` that only concern tables already in the cruce are applied before it
(for `INNER` and `LEFT`; a `RIGHT` or `FULL` join would change its result),
and if what is left on the left is small compared with the right-hand table
— one row for every 150 the table has, up to a thousand — and the right-hand
table has an index on the columns the `ON` equates, each left row is looked
up by key instead: 0.46 ms and 5.6 MB for that query on 30,000 orders and
20,000 customers, where the hash join took about 20 ms and 25 MB; 3.7 ms for
twenty orders with a `LEFT JOIN`, where it took about 60. Above the threshold, or without
an index, the hash join runs as before, on the rows the `WHERE` left.

### The last entries stay in the process

Whatever the cache is, disk or APCu, reading an entry means decoding it. The
last entries read or written are kept as they are in the PHP process (2.7) —
sixteen index pieces, one part, a few table structures, a couple of megabytes
at most — so a script that runs thousands of lookups, the panel, or a page
that looks up the customer of each order in a loop does not decode the same
piece again on every query. The key carries the revision, so an entry that
another process has made stale is simply never asked for again; and when
memory runs short this is the first thing let go. In a request that runs one
query it changes nothing. Five thousand lookups by key on 20,000 rows: 9.9 s
in 2.6.1, 2.7 s in 2.7.0 with random keys and 0.7 s when nearby rows are
looked up in sequence.

### Query results

Since 2.6 the result of a `SELECT` is cached too, keyed by the SQL text, the
bound parameters and the revision (and `creada`) of every table the query
touches, views included. A repeated query on unchanged data is served without
running it: a `JOIN` that takes 100 ms the first time takes a fraction of a
millisecond the second. A write to any of those tables changes their revision
and the cached result simply stops matching; on disk the file is deleted at
that moment, in APCu it expires after an hour.

Not cached: queries that depend on the moment (`RANDOM()`, `DATE('now')` and
the other date functions with no argument) and results over
`JSONSQLDB_CACHE_RESULTADOS` rows (5,000 by default; `0` turns the result
cache off). Queries run inside a trigger never use it: they see the changes of
the statement being run, which are not on disk yet.

The revision is per table and not one shared file, because two writes to
different tables run at the same time and a shared file would be rewritten in
full by both: whichever finished last erased the other's bump and left its cache
serving stale rows.

The cache steps aside when memory is tight (see [Memory](#8-memory)), and
`INTEGRITY CHECK` and `COUNT(*)` bypass it on purpose: they need to see what is
really in the files.

### What the on-disk cache costs

Without APCu the cache lives in `.cache/` as PHP-serialised copies, and that
is not free: it takes about twice the space of the data and index files it
mirrors, and as many files as they have. What that means for a hosting plan,
and what you can do about it, is in
[Files, space and how to tune them](#9-files-space-and-how-to-tune-them).

Deleting `.cache/` by hand is safe at any time: everything in it is
regenerated on the next read.

---

## 5. Indexes

An index maps the value of one or more columns to the **positions** of the rows
that hold it, and from a position the engine knows which part the row lives in:
only those parts are decoded. The primary key and every `UNIQUE` get one
automatically, named `auto_<columns>`; the rest are created with
`CREATE INDEX`.

An index is stored in pieces, one per part of the table (2.6). A write
rewrites only the pieces of the parts it touched. A lookup on a **numeric**
column reads only the pieces whose range can hold the value: `rev.json`
records the smallest and largest value in each piece (2.7), and with an
auto-increment key each piece covers a stretch of ids, so a lookup by id
opens one piece instead of all of them — 0.58 ms instead of 2.85 on 20,000
rows, 0.75 ms instead of 9.3 on 100,000. A key outside every range (a new
id being inserted) is rejected without opening any. Numbers spread at random
over the table (an index on `age`) overlap on every piece and gain nothing,
but lose nothing either. A lookup on a **text** column reads every piece —
the key can be in any of them.

Indexes serve reads and writes:

- A `SELECT` with an equality or `IN` on an indexed column decodes only the
  parts that hold the matching rows. On a table of twenty parts, one instead
  of twenty.
- An `INSERT` checks the primary key and the `UNIQUE` constraints against the
  index on disk instead of loading the table, appends to the last part and
  rewrites only the last piece of each index.
- An `UPDATE` or `DELETE` whose `WHERE` an index can answer reads only the parts
  that hold the candidate rows and rewrites only those (a delete shifts every
  row after it, so from the first deleted position on the parts are redone),
  and only the matching index pieces.

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

Only the pieces that hold the positions concerned are read and rewritten; the
others keep their revision. Before trusting them, the write checks the header
of every piece it leaves alone — index name, columns, part number and
revision, read from the first bytes of the file — so a piece damaged or edited
by hand is rebuilt at the next write instead of staying broken.

Any doubt — a revision file that does not say how many rows there were, a part
size that changed, a piece of a different revision — rebuilds the index from
the rows. The check is strict on purpose, because the two errors do not cost
the same: an entry too many only makes a query slower (the `WHERE` is
re-applied to the rows read), an entry too few returns incomplete results with
nothing to show for it.

The revision file records at which revision each piece was written; if a
piece says otherwise, or its columns are not the expected ones, the engine
ignores the index and scans. A stale or hand-edited index can cost speed,
never a wrong answer. `JSONSQLDB_INDICES` set to `false` disables indexes
altogether.

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

`fsync()` exists from PHP 8.1. On 8.0 there is no reliable equivalent, so
everything in this section about surviving a **power cut** holds only on 8.1 and
later; surviving a killed or crashed **process** holds on 8.0 too (see the note
at the top of this document).

### Several files: the redo journal

One file is atomic, but **a set of files is not**, and almost no write touches
just one: a table with more than `JSONSQLDB_FILAS_POR_PARTE` rows lives in
several parts, a table with indexes rewrites each of them, every write rewrites
`rev.json`, and an `INSERT` into a table with `AUTOINCREMENT` rewrites the
structure file too. A crash between two renames would leave half the table new
and half old — and because parts are split **by position**, that does not lose
"some rows", it misaligns the whole table from the cut onward.

Since 2.5 the journal is a **redo log**:

1. Every file the write produces goes to its temporary. The data files — the
   parts, `rev.json`, the structure — are forced to disk. **Nothing is renamed
   yet.**
2. When all of them are written, a manifest is written to `.tx/<scope>.json`
   — in one piece, forced to disk together with its directory entry — saying
   which temporary goes to which file and which files are to be deleted.
3. The renames and deletions are applied and the directory is forced to disk
   once, so the new names are durable. The manifest is then removed.

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
        "orders.idx.auto_id.part3.json.4121.tmp": "orders.idx.auto_id.part3.json"
    },
    "borrar": ["orders.part4.json"],
    "regenerables": ["orders.idx.auto_id.part3.json"],
    "ts": "2026-09-14 10:20:11"
}
```

**Index pieces are not forced to disk** (2.7). They are listed as
`regenerables`: everything in them can be rebuilt from the rows, so if a
crash loses a piece's content, recovery carries on without it — the piece is
simply missing or unreadable, lookups on that index scan the table until the
next write, and the next write to the table rebuilds it (every piece's header
and tail are checked before a write trusts it). Not forcing them saves one
`fsync` per index per write, and an `fsync` is what a write costs on a real
disk.

The **scope** is the lock the write holds, and it says which lock recovery
needs: `.tx/_base.json` when the write held the exclusive database lock,
`.tx/<table>.json` when it was confined to a table (the manifest lists every
table it touched). Recovery of a table journal only needs those tables' locks,
which is what lets writes to other tables carry on meanwhile. Checking whether
a journal is pending costs one `stat` and one listing of `.tx/` — a folder
that stays, almost always empty, once the base has been written to — done
once per request when the lock is taken.

Versions 2.5 and 2.6 kept the manifest in a folder per scope
(`.tx/<scope>/manifiesto.json`); versions up to 2.4 used an undo journal:
copies of the files about to change, restored on recovery. Both are still
recognised — the undo one by its manifest having an `estado` instead of a
`tipo` — and applied or undone the same way, so a database left with a
pending journal by an earlier version recovers correctly. The pre-2.0 layout
(copies loose in `.tx/`) is recognised too.

What the journal costs, counted with `strace`: a one-row `INSERT` into a
table with three indexes makes **four `fsync` calls** — the part, `rev.json`,
the manifest and the directory; five when it opens a new part — down from ten
in 2.6 and from twenty-odd in 2.4. The autoincrement counter moved from the
structure file into `rev.json` for exactly this reason: a file the write
touches anyway. On this benchmark machine an `fsync` takes 0.1 ms and the
difference is invisible; on a hosting disk it takes 3–8 ms, and there the
same `INSERT` goes from 50 ms to 28 ms (3 ms per `fsync`) or from 102 ms to
about 50 ms (8 ms), measured by making the kernel delay each `fsync` by that
much. Four is the floor for a write that keeps every data file durable on its
own: one per data file plus the manifest and the directory.

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
- **Only the index pieces that changed**, corrected rather than rebuilt when
  possible (see [Indexes](#5-indexes)). Appending a row to a table of a hundred
  parts rewrites the last piece of each index, not the whole index.
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
without `LIMIT`, the inner side of a `JOIN`, `DISTINCT`. A `WHERE` scan does
not — rows are read one part at a time and only the survivors are kept — and
neither does a `GROUP BY` or an aggregate (accumulated as the rows go by),
an `ORDER BY … LIMIT n` (only the n rows in the lead are kept), or a write. If
APCu is enabled its memory is separate and does not count against
`memory_limit`.

**Lowering `JSONSQLDB_FILAS_POR_PARTE` does not reduce the full-table case.**
Splitting a table into more parts only bounds the size of each decode; a query
that needs every row still ends with every row in memory. What the split buys
is being able to *skip* parts, which is what indexes and streaming do.

**Compression is not the way out.** The peak is not the JSON text, it is the
decoded array, and an array has to be decoded to be filtered, joined or sorted.

### What the engine does about it

Measured on the bundled benchmark (PHP 8.3, 20,000 customers and 30,000 orders,
on-disk cache; `php tests/benchmark.php`), 2.5.0 against 2.6.0, run one after
the other on the same machine:

| Operation | 2.5.0 | 2.6.0 |
|---|---|---|
| Lookup by primary key | 2.3 ms · 7 MB | 2.3 ms · 7 MB (0.6 ms since 2.7) |
| Equality on an indexed column (2,000 rows) | 24 ms · 7 MB | **17 ms · 7 MB** |
| Numeric range, no index | 30 ms · 7 MB | **18 ms · 6 MB** |
| `LIKE` by prefix | 35 ms · 11 MB | **18 ms · 6 MB** |
| `GROUP BY` with `SUM` | 33 ms · 15 MB | **21 ms · 6 MB** |
| `ORDER BY … LIMIT 20` | 45 ms · 18 MB | **21 ms · 6 MB** |
| `ORDER BY`, whole table | 128 ms · 20 MB | **31 ms · 21 MB** |
| `JOIN` aggregated by city | 146 ms · 43 MB | **111 ms · 22 MB** |
| `IN (SELECT …)` subquery | 86 ms · 9 MB | **64 ms · 9 MB** |
| `INSERT` one row | 20 ms · 13 MB | **10 ms · 9 MB** |
| `UPDATE` one row by key | 12 ms · 11 MB | 12 ms · 10 MB |
| `DELETE` one row by key | 28 ms · 13 MB | **16 ms · 8 MB** |
| The `JOIN` again, unchanged data (result cache) | — | **0.2 ms · 4 MB** |

On 100,000 customers: `GROUP BY` from 211 ms and 58 MB to 105 ms and 6 MB,
`ORDER BY … LIMIT 20` from 307 ms and 69 MB to 100 ms and 6 MB, the whole
`ORDER BY` from 812 ms to 218 ms, the aggregated `JOIN` from 927 ms and 193 MB
to 676 ms and 85 MB, a one-row `INSERT` from 95 ms and 43 MB to 30 ms and
22 MB, and a `DELETE` by key from 153 ms and 45 MB to 53 ms and 21 MB. With
APCu the figures are the same or slightly better. Timings move by ±20 % from
one run to the next on the same machine; the memory figures do not.

What makes the difference:

- **Rows are read one part at a time and filtered as they arrive.** A `WHERE`
  scan keeps only the rows that pass. A single-table query uses the rows
  exactly as they come out of the cache, without copying them (2.6).
- **Aggregates are accumulated, not collected.** `GROUP BY`, `COUNT`, `SUM`,
  `AVG`, `MIN` and `MAX` keep one accumulator per group, not the rows of each
  group (2.6). `DISTINCT` inside an aggregate and `GROUP_CONCAT` keep only the
  values of that column.
- **`ORDER BY … LIMIT n` keeps only the n rows in the lead** (2.6); a full
  `ORDER BY` sorts with `array_multisort` when every key is all numbers or
  all text, with the collation key computed once per row instead of once per
  comparison.
- **A `JOIN` streams** its rows into the `WHERE` and the grouping, loads of
  each side only the columns the query names, and keeps its hash index as
  integers (2.6).
- **`WHERE` conditions are compiled** into PHP closures (2.6); the general
  evaluator is used only for what cannot be compiled (functions, subqueries).
- **Indexes decode only the parts that hold the wanted rows**, for reads and
  for writes, and are stored in one piece per part so a write rewrites one
  piece rather than the whole index (2.6).
- **`LIMIT` is pushed into the read** when there is no `WHERE` and no `JOIN`.
- **`SELECT COUNT(*)` and `SHOW TABLES` never build the rows**: they count
  lines.
- **A repeated `SELECT` on unchanged data is served from the result cache**
  (2.6; see [Query results](#query-results)).
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

## 9. Files, space and how to tune them

A database is a folder of files, and cheap hosting limits two things about
files: **how many** (the inode quota, typically 100,000–300,000 per account,
shown on the first page of the control panel) and **how much space** (tens of
gigabytes on entry plans). For this engine the number of files is the one that
can matter; the space almost never does. This section says where each file
comes from and which settings trade files for speed, with the numbers measured
on the bundled benchmark, so you can decide for your own plan.

### Where the files come from

For a table with *R* rows, *P* = ⌈*R* / `JSONSQLDB_FILAS_POR_PARTE`⌉ parts and
*I* indexes (the primary key and each `UNIQUE` count as one each, plus the ones
you create):

| Files | How many | Size |
|---|---|---|
| Data (`table.json`, `table.partN.json`) | *P* | the rows as readable JSON, one per line |
| Structure and revision (`table.meta.json`, `table.rev.json`) | 2 | a few KB |
| Index pieces (`table.idx.<name>.json`, `.partN.json`) | *P* × *I* | about 40 % of the data for a three-index table |
| On-disk cache (`.cache/`, without APCu) | *P* + *P* × *I* + 1, plus one per cached query result | about 1.4 × the file it mirrors |

On the bundled benchmark (`php tests/benchmark.php 100000`, 100,000 customers
and 150,000 orders, three indexes on customers and one on orders, default
settings, no APCu):

| | Files | Space |
|---|---|---|
| Data | 256 | 21 MB |
| Indexes | 453 | 8 MB |
| Cache | 707 | 41 MB |
| **Total** | **1,416** | **70 MB** |

The benchmark prints these three lines for whatever size you give it. For
20,000 customers and 30,000 orders it is 56 + 93 + 147 files and
4 + 1.4 + 8 MB. Space is not the problem: a base has to hold millions of rows
before it fills a cheap plan, and by then this engine is the wrong tool. Files
can be, if the account is already busy with something like a WordPress
(30,000–60,000 files on its own).

### The three settings that move it

**1. `JSONSQLDB_CACHE_ACTIVA` — the cache.** The largest share of both files
and space is the cache, and it exists only for speed: a cached part decodes in
half the time of its JSON and a cached index piece in a third. Three values:

| Value | Files and space | Speed |
|---|---|---|
| `true` (default) | APCu if the host has it (then the cache lives in shared memory and **costs nothing on disk**); otherwise `.cache/` on disk, the figures above | full |
| `'apcu'` | shared memory only; `.cache/` is never written. **Without APCu this means no cache at all** | full with APCu; without it, reads decode JSON every time: a full scan of 100,000 rows goes from ~65 ms to ~100 ms, a primary key lookup from 8 ms to 13 ms |
| `false` | no cache | as above, without cache; only for debugging |

So the first thing to check on a shared host is whether APCu is available for
your PHP version (most control panels offer it as a tick box). With it, the
disk question disappears: the table above becomes 709 files and 29 MB, all of
them data and indexes.

**2. `JSONSQLDB_FILAS_POR_PARTE` — the part size** (1,000 by default). A
bigger part means fewer parts, and since index pieces and cache entries follow
the parts, fewer of everything: at 5,000 rows per part the 1,416 files above
become about 285, and the space stays the same. What it costs, measured on
20,000 customers (296 files at 1,000 per part, 70 at 5,000), same machine,
one run after the other:

| | 1,000 rows per part | 5,000 rows per part |
|---|---|---|
| Lookup by primary key | 2.0 ms · 7 MB | **6.7 ms · 11 MB** — the whole part is decoded to get one row |
| `INSERT` one row | 7.8 ms · 9 MB | 9.1 ms · 13 MB — a bigger last part and a bigger last piece of each index |
| `UPDATE` one row by key | 10.7 ms · 10 MB | **24.6 ms · 17 MB** — one part read and rewritten, five times bigger |
| `DELETE` one row by key | 14.1 ms · 8 MB | 12.2 ms · 9 MB |
| Range scan, `GROUP BY`, `ORDER BY … LIMIT` | 17–20 ms · 6 MB | 17–22 ms · 9 MB — same bytes; the part in memory at any moment is five times bigger |
| `JOIN` aggregated by city | 99 ms · 22 MB | 106 ms · 24 MB |

Reads by key and single-row writes get slower in proportion to the part size
(they read and write one part, and the part is bigger); everything that reads
the table anyway costs the same time and about 3 MB more of memory. The
engine's defaults favour speed because on most plans a few hundred files are
nothing; if your account is near its quota, 5,000 cuts the files by four for
a lookup that takes 5 ms instead of 2, and 10,000 is where single-row work
starts to feel slow.

Changing it on an existing database is safe: tables are read with the part
size recorded in their `rev.json`, and the first write to each table splits it
with the new size and rebuilds its indexes, once. That first write is a full
rewrite of the table, so do it at a quiet moment.

**3. Indexes.** Each index adds *P* files and, without APCu, *P* cache files.
An index you do not use for equality lookups (`=`, `IN`) is pure cost: ranges,
`LIKE`, `ORDER BY` and aggregates never use one. `SHOW INDEXES FROM t` lists
them; `DROP INDEX` removes the ones you created. The automatic ones on the
primary key and `UNIQUE` constraints stay, because the writer uses them to
check uniqueness without loading the table.

### What not to do

Compressing the cache or storing it in a more compact format looks tempting
and was tried: the time to decompress or convert it back eats the gain over
decoding the JSON, so the cache stops paying for itself. If space is the
problem, the answer is APCu, not a smaller cache on disk.

### How to measure it on your own data

`php tests/benchmark.php <rows>` prints files and megabytes of data, indexes
and cache in its first line and times and memory below. To try a different
part size, add `define('JSONSQLDB_FILAS_POR_PARTE', 5000);` next to the other
two constants at the top of the file and run it again right after; to see the
effect of APCu, run it with `php -d apc.enable_cli=1`. The benchmark uses a
fixed seed, so two runs compare the same data.

---

## 10. Configuration and protection

### `config.php`

| Constant | Purpose | Default |
|---|---|---|
| `JSONSQLDB_DATA_PATH` | root folder with one subfolder per database | `data/` |
| `JSONSQLDB_FILAS_POR_PARTE` | rows per file before a table is split | `1000` |
| `JSONSQLDB_CACHE_ACTIVA` | `true`: APCu or disk; `'apcu'`: shared memory only; `false`: off | `true` |
| `JSONSQLDB_ESCRITURA_POR_PARTES` | `UPDATE`/`DELETE` that depend only on each row lock the table only to commit (see [Writes by part](#writes-by-part-27)) | `true` |
| `JSONSQLDB_CACHE_RESULTADOS` | maximum rows of a `SELECT` result to cache; `0` disables the result cache | `5000` |
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

For a large `SELECT` there is `consultarPorFilas()` (2.7.4): instead of
returning the rows together, it hands them one at a time to a function as they
come out of the engine, so the whole result is never a PHP array. It is what the
API uses to write its JSON. It returns how many rows went out that way; a query
that needs the whole result anyway (`ORDER BY`, `DISTINCT`, aggregates, one
served from the result cache) and any other statement return what `consultar()`
would. The function runs while the engine holds its read lock: keep it quick
(write to a file or a buffer), not a slow network call.

```php
$n = $db->consultarPorFilas('SELECT * FROM customers', [], null,
    static function (array $row) use ($fh): void {
        fwrite($fh, json_encode($row) . "\n");
    });
if (is_array($n)) {                    // came whole: write it out here
    foreach ($n as $row) { fwrite($fh, json_encode($row) . "\n"); }
}
```

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
| `engine/`, `data/`, `logs/`, `docs/`, `tests/`, `nginx/`, `litespeed/` | each with its own `.htaccess` and `web.config` denying everything |
| each database folder | `.htaccess` and `web.config` created automatically with the database |

Two layers on purpose: if the host ignores the root `.htaccess` or has no
`mod_rewrite`, the per-folder ones still protect. Works for Apache 2.2 and 2.4,
for LiteSpeed Enterprise (which reads `.htaccess` like Apache) and for IIS.
**nginx reads neither, and OpenLiteSpeed applies `.htaccess` only for rewrite
rules**: see [`../nginx/README.md`](../nginx/README.md) and
[`../litespeed/README.md`](../litespeed/README.md).

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

## 11. Where the floor is

Every release so far has found the next thing to speed up, and every
measurement finds one more. This section says, for each kind of operation,
what the engine cannot go below without ceasing to be what it is — data in
JSON a person can read, PHP interpreting every row, and writes that survive a
power cut — and how far from that floor 2.7 stands. When something here is
called the floor, the way past it is a binary format, a C extension or a
resident process, none of which this project will have.

Measured on the one-core benchmark machine (PHP 8.3, on-disk cache, 20,000
customers and 30,000 orders unless said otherwise); the floor is what the same
machine takes to do only the unavoidable part:

| Operation | 2.7.1 | Floor | What separates them |
|---|---|---|---|
| Scan with a numeric filter | 0.6 ms per part of 1,000 rows | ~0.45 ms: decoding the part | yielding each row through the pipeline and counting it, in PHP |
| Scan with other filters, `GROUP BY`, `ORDER BY … LIMIT` | 1.0–1.2 ms per part | ~0.45 ms | evaluating the `WHERE` and accumulating, in PHP, one row at a time |
| Lookup of one row by a numeric key | 0.58 ms (0.75 ms on 100,000 rows) | ~0.3 ms | reading one piece of the index and three small reads of the part; the lock, the parse and the log are the rest |
| Lookup of one row by a text key (`UNIQUE` on a text column) | 0.57 ms (1.9 ms on 100,000 rows) | same as a numeric key | every piece of the index has to be read, since a text key can be in any; since 2.7.1 they are read as text and searched for the key, not decoded. The reading grows with the table: see below |
| `JOIN` of two big tables | 134 ms for 30,000 × 20,000 | ~50 ms: decoding both sides | hashing one side and matching every row of the other, in PHP |
| `JOIN` after a selective `WHERE` (one order and its customer) | 0.46 ms | ~0.3 ms | one lookup by key per row on the left; nothing else |
| Full `ORDER BY` | 36 ms for 20,000 rows | ~20 ms: decoding plus `asort` | building the sort keys and the result rows |
| Write of one row | 4 `fsync` calls (5 when it opens a new part); `INSERT` 7.2 ms, `UPDATE` 6.7 ms on 20,000 rows | 4 `fsync` calls plus rewriting one part and one piece per index | an `INSERT` or `UPDATE` edits the text of the part without decoding it; what remains is checking uniqueness, the index pieces and the journal |
| Writes to the same table from two processes | by part since 2.7 (see [Writes by part](#writes-by-part-27)); the commit, one at a time | the commit, one at a time | three of the four `fsync` calls belong to the commit, so two writers overlap only in the fourth and in their computing |

Everything in the third column is the cost of PHP arrays and readable JSON.
SQLite on the same machine does the lookup in a few microseconds and the scan
in a few milliseconds, because it reads binary pages into C structures and
never builds a PHP array per row. This engine exists for the hosting where
SQLite is not available; on that hosting, these are the numbers.

What is **not** on the floor and could still move, if someone needs it:
lookups by a text key, which read the text of every piece of the index (about
2 ms on 100,000 rows) where a numeric key reads one. A per-piece filter saying which keys a piece cannot
contain, or an index split by a hash of the key instead of by position, would
cut the reads to one or two pieces — the first at the price of data that is not
readable by eye, the second at the price of changing how indexes are stored and
rewriting more of them on a `DELETE`. Neither is done: the first breaks a
principle and the second is not worth it until a real workload asks for it.
Looking rows up by their numeric id meanwhile costs 0.58 ms.

---

## 12. Upgrading from an earlier version

### Compatibility policy

From 2.7.2 on, these are promises, not just what has happened so far:

- **The on-disk format of `data/` is stable for the whole 2.x series.** A later
  2.x version can *add* keys to the files (as 2.7 added `rangos`, `offsets` and
  `autoinc`), and fills them in as tables are written; it never stops reading
  data written by an earlier 2.x. The files stay JSON a person can read.
- **An incompatible change means 3.0**, announced in the changelog at least one
  minor version in advance, with a migration tool in the same release.
- **The SQL accepted, the API protocol (fields and HMAC signature) and the
  configuration files** follow the same rule: within 2.x, what works keeps
  working. A `config.php` from an earlier version keeps working with default
  values for the options added since.
- **The way out does not depend on this project.** The panel's SQL dump loads
  unchanged into SQLite, and the data files can be read by anything that reads
  JSON.

What this does not promise: support for old versions. Only the latest release
receives fixes (see [SECURITY.md](../SECURITY.md)), so upgrading within 2.x is
always the way to get one.

### How to upgrade

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
| one file per index, one revision per index in `rev.json` (2.5) | read as they are; split into one piece per part on the next write |
| `rev.json` without `creada` (pre-2.6) | added on the first write; the cache entries of the table are regenerated once |
| `rev.json` without `rangos` (pre-2.7) | lookups read every piece, as before; each piece gets its range when it is next written, and the whole index at the next full rebuild |
| data files without `offsets` (pre-2.7) | lookups decode the part, as before; a part gets its offsets when it is next written |
| journal folders `.tx/<scope>/` (2.5–2.6) | recognised and applied; new journals are single files `.tx/<scope>.json` |

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

## 13. Files of this part

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
