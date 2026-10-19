# jsonSQLDB — Part 1: the storage core

A database engine written **entirely in PHP 8.0+**, with no mandatory
extensions and no dependency on SQLite or MySQL.

Optional extensions that are used **if present** (never required):

| Extension | Used for | If missing |
|---|---|---|
| `apcu` | shared-memory cache; must be enabled for the SAPI in use (`apc.enable_cli=1` for scripts and cron) | on-disk cache (`.cache/`) |
| `mbstring` | length, substrings and upper/lower case of UTF-8 text | an equivalent built-in calculation |
| OPcache | keeping each unchanged table part compiled in shared memory, from a `.php` copy in `.cache/` (2.9) | the part comes from APCu or `.cache/` |

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
empty; `CHECK TABLES` and the engine's `Datos ilegibles` error would
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

The exact shape of each file, field by field, is in the reference
[06-format.md](06-format.md); this section explains it.

One **folder per database** inside the data root:

```
<data_root>/
└── mydb/                        ← one folder = one database
    ├── _database.json           database metadata
    ├── _views.json              views: name => saved SELECT (once one has been created)
    ├── .lock                    database lock file (empty)
    ├── .users.lock              lock file of one table (empty; one readable line while a write is under way, 2.10)
    ├── .htaccess / web.config   deny access if the folder ends up in the web root
    ├── .cache/                  serialised cache (regenerable: safe to delete)
    ├── .turno / .users.turno    turnstiles crossed before asking for a lock (empty)
    ├── .tx/                     journal; its files only exist while a write is in progress
    ├── .lote/                   originals kept by a transaction (2.9); only while it runs
    ├── .orden/                  temporary files of what does not fit in memory in a query (2.9); its files only while it runs
    ├── .reparados/              originals of repaired files and rows set aside (2.9); only after a repair
    ├── users.meta.json          table structure
    ├── users.rev.json           revision of the table and state of its parts
    ├── users.json               data (part 1)
    ├── users.part2.json         data (part 2, from 1,000 rows on)
    ├── users.idx.auto_id.json   index, piece for part 1
    ├── users.idx.auto_id.part2.json  index, piece for part 2
    └── orders.meta.json / orders.json
```

Since 2.9 a table cannot be named `_database`, `_views`, `_revs` or `_base`:
its files would be the database's own (before, `CREATE TABLE _views` was
accepted and the views were lost).

Databases created before 2.0 have a `_revs.json` with the revisions of all
tables together and no index files; see
[Upgrading from an earlier version](#12-upgrading-from-an-earlier-version).

Allowed names: database `[A-Za-z0-9_-]{1,64}`; table, index and trigger
`[A-Za-z_][A-Za-z0-9_]{0,63}`. Nothing else is accepted, so there is no way to
escape the folder with `../` or an absolute path. A column name (2.8) can have
spaces or signs, from 1 to 64 characters and no control characters: it is
never part of a file name.

### Data file (`users.json`)

Meant to be opened and read by a person: **one row per line**.

```json
{
  "table": "users",
  "rows": [
    {"id":1,"name":"Ana","email":"ana@example.com","balance":10.56,"joined":"2026-01-15 08:30"},
    {"id":2,"name":"Luis","email":"luis@example.com","balance":0.0,"joined":null}
  ],
  "offsets": [38,135],
  "offsets_at": 218
}
```

`offsets` (2.7) is the byte at which each row's line starts, and `offsets_at`
the byte at which that list starts; with them a lookup by key reads the one
line it needs — three small reads, about 30 µs — instead of decoding the
thousand rows of the part. The engine writes them while it writes the rows;
files from earlier versions do not have them and are decoded whole, as before.

It is valid JSON: any editor can change it and the engine will read it. See
[Editing the files by hand](#editing-the-files-by-hand-29) for what the engine
does when someone does, and when an edit breaks the JSON.

**A deleted row leaves `null` in its place (2.9).** Deleting no longer moves the
rows behind it: the row's line becomes `null`, and nothing else in the table
changes place. The exception is a table with triggers or with a foreign key to
itself, which a `DELETE` loads whole and writes without the deleted rows, as
before. In a larger table, deleting row 2 leaves:

```json
  "rows": [
    {"id":1,"name":"Ana","email":"ana@example.com","balance":10.56,"joined":"2026-01-15 08:30"},
    null,
    {"id":3,"name":"Eva","email":"eva@example.com","balance":3.0,"joined":null}
  ],
```

Every read skips these holes, `COUNT(*)` does not count them, and an `INSERT`
still goes at the end (holes are not reused, so the insertion order stays).
Deleting the last rows of a table leaves no holes: there is nothing behind
them to keep in place, so the table is shortened instead, as before 2.9, and a
table used as a queue does not pile them up.
When holes would pass 25 % of a table, the delete that gets there rewrites the
table without them; `VACUUM` does the same for every table that has any. Before
2.9, deleting a row moved all the rows behind it one place, which rewrote every
part and every index piece behind it: half a second for one row of 60,000.

A table with holes cannot be read by 2.8 or earlier (they stop with an error
on the first scan; they do not return wrong rows). To go back to an earlier
version, run `VACUUM` first: afterwards the data files are again of the kind
those versions read (see [Going back to an earlier version](#going-back-to-an-earlier-version)).

### Editing the files by hand (2.9)

The files are meant to be readable, and a person will sometimes open one and
change it. The engine notices, keeps its answers true to the files, and
repairs on its own what can be repaired without guessing.

**An edit is seen at once.** Every cache entry of a table part, of its
structure and of its index pieces carries the seal of the file it came from:
its size, date and inode. The engine always writes a new file and moves it
into place, so a different seal without a new revision means someone touched
the file. Then the cached copy is not used: the file is read again, and if its
content really changed (copying a file or restoring a backup with the same
content does not count), the table is rewritten through the normal write path,
so that its indexes, offsets and revisions match what the person wrote. A part
read from disk whose offsets no longer match its text is treated the same way,
which catches an edit made while the cache was empty. And a write, which does
not keep in the cache the rows of the parts it rewrites, leaves for them a
small entry instead: for each, the seal of the new file and a fingerprint
(CRC-32) of its text. So a part edited after the engine wrote it is seen the
first time it is read, even if nobody had read it before and every line kept
its length (2.9; before, such an edit went unnoticed and the indexes went on
answering with the old values). It costs each write, per part it rewrites, a
CRC-32 of its text (a few microseconds) and one `stat()`, and that one entry:
in APCu, about a microsecond; on disk, one small file (one per part, as it
was first written, made a cascade that rewrote forty parts slower than in
2.8.0). A cached query result
does not look at the files again: as in 2.8, it is used until a write to one
of its tables. Checking the seal of every file the query read cost a `stat()`
per file on every hit (52 for a join of two tables of 20,000 and 30,000 rows),
which made a hit slower than in 2.8.0, and twice as slow with `open_basedir`.
The edit is seen by the next query that reads that file, which rewrites the
table and with it retires the table's cached results.

One limit, inherent to file dates having one-second resolution: an edit made
in the same second as a write by the engine, that leaves the file the same size
and keeps its inode, is not told apart. A person editing a file does not hit
it; a script that rewrites a file in place right after a write could. And one
that comes from the cache being optional: with its entry for that part gone (the
cache emptied, or APCu short of memory), an edit that keeps the length of every
line is not told apart either.

Another limit comes from the indexes: a query answered through one (`WHERE
city = 'Paris'` with an index on `city`) reads only the parts the index points
to, so it does not see a value of that column edited in another part until
some query reads that part — one that reads the whole table, or a lookup that
lands there. Then the table is rewritten and its indexes match the files
again. `CHECK TABLES` looks for damaged files, not for valid edits, so it does
not do it; after editing files with a script, run a query that reads the whole
table (`SELECT COUNT(*) FROM t WHERE col <> ''` on a column without an index).

**What is repaired on its own.** When a file does not parse, the engine
repairs it only if the repair is certain:

| File | Repaired on its own |
|---|---|
| a data part (`users.json`, `users.part2.json`) | a missing or extra comma between rows, the bracket or brace at the end, the `offsets` list (rewritten), a row missing or with one extra closing brace, and a file reformatted by an editor that is still valid JSON |
| the table state (`users.rev.json`) | rebuilt from the data; meanwhile the table gets a new cache generation, so no old cache entry is ever used |
| an index piece (`users.idx.*.json`) | rebuilt from the data |
| the structure (`users.meta.json`) | a trailing comma, or the closing brace of the file if that is all that is missing |
| the views (`_views.json`) | the same fixes as the structure, applied each time it is read; the file is rewritten fixed by the next `CREATE VIEW` or `DROP VIEW` (no copy goes to `.reparados/`, and `CHECK TABLES` does not list it). If it cannot be repaired, the error says so and nothing writes over it (before 2.9 it was taken as empty and the next `CREATE VIEW` replaced it) |
| any of them | the byte order mark (`EF BB BF`) an editor may put at the start |

A data part missing between others (`users.part2.json` gone while
`users.part3.json` is there) is an error that names it, and `CHECK TABLES`
lists it; nothing can rebuild its rows, so it has to come from a copy. Before
2.9 the table ended at the part before it, without warning.

The query that found the problem uses the repaired rows in memory; when it
ends, holding no lock, the engine takes the exclusive lock as any write does,
keeps the original file in `.reparados/` with the date, and rewrites the table:
one part at a time when the table does not fit comfortably in memory (2.9;
before, the repair gathered the whole table first, and one larger than
`memory_limit` failed with a fatal error on every read that tried to repair it).
The repair goes to the query log as a `REPAIR` entry.

**What needs a person.** A row that cannot be fixed with certainty — a quote
deleted inside a text, a comma removed between two fields, a missing opening
brace — is not guessed: the query fails with the file and the line, for
example `Datos ilegibles en users.part2.json: una fila no se puede arreglar
sola (línea 203: {"id":1200,"nombre":"N1200,"ciudad":"C3"},)`, and nothing is
touched. Fix it by hand, or run `REPAIR TABLES`, which sets the row aside: the
table works again without it, and the row is still in the original kept in
`.reparados/`. A structure file cut short is never completed by guessing:
the structure of a table cannot be inferred.

```sql
CHECK TABLES;               -- lists the damaged files of every table, changing nothing
CHECK TABLES FROM users;    -- only that table
REPAIR TABLES;              -- repairs them, setting aside the rows that need a person
REPAIR TABLES FORGET 3;     -- set-aside row 3 is no longer pending (put back, or discarded)
```

`CHECK TABLES` returns one row per problem with the table, the file, the
problem and how it would be fixed; none if all is well. `REPAIR TABLES` does
not guess either: what it does that the automatic repair does not is take the
doubtful rows out of the table, which is the decision a person makes by running
it. Its result adds `apartadas`, the rows it set aside with their table, file,
line and text, so you know exactly what to fix and put back with an `INSERT`;
they also stay in the original kept in `.reparados/`.

**Rows set aside stay pending until someone deals with them.** `REPAIR TABLES`
records each one, with a number, in `.reparados/apartadas.json`; `CHECK TABLES`
keeps listing them, with that number in `apartada`, until they are put back or
discarded. To put one back, insert it fixed and forget it in the same
transaction, so that either it goes back and stops being pending, or nothing
happens: `$db->lote([['INSERT INTO users (…) VALUES (…)', […]], 'REPAIR TABLES
FORGET 3'])`, or the API's `lote`. `REPAIR TABLES FORGET n` alone discards it (it
stays in the copy in `.reparados/`). Numbers are never reused, so one kept from
an earlier list cannot forget a different row.

In the panel it is the first section of the **Integrity** tab. Each pending row
shows how it was, an example of a correct row of its table — a real row of the
table if it has any, made up from its columns if it is empty — and a box to fix
it. Saving checks that it is a JSON object with columns of the table, and the
engine checks the rest as it inserts it (types, required values, unique and
foreign keys); if something is wrong, it says what and the row stays pending.
`CHECK TABLES` needs read permission; `REPAIR TABLES`, write.

**The indexes can be checked against the rows (2.10).** An index is worked out
from the rows and rewritten by every write, but a hand edit of an index file,
or a table's data files copied without their indexes, can leave keys that do
not match, and a lookup through such an index could return fewer rows.

```sql
CHECK INDEXES;              -- every index against the rows of its table, changing nothing
CHECK INDEXES FROM users;   -- only that table
REPAIR INDEXES;             -- rebuilds from the rows the indexes of every table with a problem
```

`CHECK INDEXES` reads each index piece by piece against the rows of its part,
one part in memory at a time, and returns one row per problem with the table
(`tabla`), the index (`indice`), the problem (`problema`) and what fixes it
(`arreglo`); none if all is well. It finds keys a piece has and its rows do
not, or the other way round; a filter that hides some of a piece's keys, or a
range in the table's state that does not cover them (both would make a lookup
skip the piece); a piece missing or from another revision; and keys written
before 2.10 (see [How to upgrade](#how-to-upgrade)). The last two only make
queries read the table, since such an index is not used. `REPAIR INDEXES`
rebuilds them and answers like a write, with how many problems it fixed in
how many tables (`2 problema(s): los índices de 1 tabla(s), rehechos con sus
filas`). `CHECK INDEXES` needs read
permission; `REPAIR INDEXES`, write, and holds the whole database while it
works, as `REPAIR KEYS` does.

**Parts**: past 1,000 rows (`JSONSQLDB_FILAS_POR_PARTE`) the data is spread over
`users.json`, `users.part2.json`, … Parts that become empty are removed. Reading
a table always concatenates its parts, but the engine reads them **one at a
time**: a query never needs the whole table in memory unless it genuinely uses
every row (see [Memory](#8-memory)).

### Structure file (`users.meta.json`)

A column only carries the keys that have a value, so it stays readable (shown
condensed; the engine writes one key per line):

```json
{
    "table": "users",
    "columns": [
        {"name": "id", "type": "INTEGER", "notnull": true, "pk": true, "autoincrement": true},
        {"name": "name", "type": "TEXT", "length": 50, "notnull": true},
        {"name": "email", "type": "TEXT", "length": 120, "unique": true},
        {"name": "balance", "type": "DECIMAL", "scale": 2, "default": 0.0},
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
| `rows` | how many places the table has (2.5): its rows, plus the holes of deleted ones (2.9) |
| `creada` | a random number fixed at the table's first write (2.6); see below |
| `parts` | the revision at which each part was last written (2.5) |
| `indexes` | the revision at which each piece of each index was last written (2.6) |
| `rangos` | the smallest and largest numeric value in each piece of each index, `[]` for an empty piece, `null` when the first indexed column is text (2.7) |
| `huecos` | how many holes of deleted rows each part has (2.9); only there when some part has any |
| `gen` | only after a transaction that touched the table was undone, or after the engine rebuilt a `rev.json` it could not read (2.9): it changes every cache key of the table |
| `textos` | for each index with a `TEXT` or a decimal column, the revision of the table at which its pieces were last written with the keys of 2.10 (2.10); without it, above `rev` or below the revision of one of its pieces (one rewritten by an earlier version), the index is not used and is rebuilt (see [How to upgrade](#how-to-upgrade)) |
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
{"index":"ix_city","table":"users","columns":["city"],"part":2,"rev":7,"chunk":1000,
 "filtro":"AAEA…","cubre":"texto","min":"Elche","max":"Murcia",
 "keys":{"t5:Elche":1000,"t6:Murcia":1001,"t6:Madrid":[1004,1019,1057]}}
```

`keys` maps a key (type, length and value of each indexed column, so composite
keys are unambiguous and prefix lookups work) to the positions of the rows that
hold it, counted from the start of the table. A single position is stored as
an integer and several as a list (2.5). Indexes written before 2.6 were one
file with every key (and before 2.5 always with lists); they are read as they
are and split into pieces the next time the table is written. Details in
[Indexes](#5-indexes).

`filtro` (2.9) is a Bloom filter of the piece's keys, in base64, and `cubre`,
right after it, says which keys it covers: `"texto"`, the text keys, or
`"todo"`, the numeric keys too, when the piece's range is wide (at least 8
times the part size: an index on the customer of each order, where every piece
spans the whole table and the range narrows nothing). It says, without reading
the keys, that a key it covers is **not** in the piece (if it says it may be,
it is a false positive about 1 % of the time, and the keys are read). A piece
with no key to cover has `"filtro":""` and no `cubre`. When all the keys of a piece are texts, `min` and `max`
(2.9) hold the smallest and largest value of its first column, compared as the
`WHERE` compares two texts: a range on a text or a date reads only the pieces
that can hold it. They all go before `keys`, so a lookup reads the first bytes
of each piece and opens the keys of only the pieces where the value can be.
Pieces written before 2.9 have none of them and are read whole, as before;
they get them the next time the table rewrites them. Older versions read
pieces with them as usual and ignore them.

---

## 2. Data types

| Internal type | Aliases accepted in `CREATE TABLE` | Stored as |
|---|---|---|
| `INTEGER` | INT, INTEGER, TINYINT, SMALLINT, MEDIUMINT, BIGINT, BOOL, BOOLEAN; BIT and YEAR (2.9) | integer |
| `DOUBLE` | REAL, FLOAT, DOUBLE, DOUBLE PRECISION | number |
| `DECIMAL` | DECIMAL(p,s), NUMERIC, NUMBER, MONEY | number rounded to `s` decimals (2 by default) |
| `TEXT` | TEXT, VARCHAR(n), NVARCHAR, CHAR, NCHAR, CLOB, STRING, BLOB; TINYTEXT, MEDIUMTEXT, LONGTEXT, JSON, TINYBLOB, MEDIUMBLOB, LONGBLOB, BINARY, VARBINARY and UUID (2.9) | string |
| `DATETIME` | DATE, DATETIME, TIMESTAMP | string `yyyy-MM-dd[ HH:mm[:ss[.fff]]]` |

The aliases are the ones SQLite uses, and since 2.9 MySQL's too, so a `CREATE
TABLE` written for either is accepted as it is. `ENUM('a', 'b', …)` (2.9) is a
`TEXT` that only takes those values, and `UNSIGNED` (2.9), on a numeric column,
rejects negatives.

**Dates**: the time, seconds and milliseconds are **optional** and the
precision you wrote is kept. `T` or a space is accepted as separator on input;
the value is always stored with a space. Because the format is fixed, alphabetical
order is chronological order, so `ORDER BY` and `BETWEEN` on dates work without
any conversion.

Valid examples: `2026-02-28`, `2026-02-28 10:05`, `2026-02-28 10:05:09`,
`2026-02-28 10:05:09.700`.

`DECIMAL` is rounded floating point, not exact decimal. For money that has to
add up to the cent, store cents in an `INTEGER`.

**Decimals on disk** are written with the shortest digits that give the same
number back (0.30000000000000004, 0.1), whatever `serialize_precision` says in
`php.ini` or the application sets: the engine sets it to -1 while it works and
puts back the one there was when it finishes (2.10; with 10, 0.30000000000000004
was stored as 0.3). The application's code that the engine runs (inside a
`transaccion()`, the function that receives the rows of `consultarPorFilas()`
and the one that authorizes a statement) keeps its own. If your hosting
disables `ini_set()`, put `serialize_precision = -1` in `php.ini` (the default
of PHP).

---

## 3. Concurrency: two lock levels

`flock` on two files: one for the database (`.lock`) and one per table
(`.<table>.lock`). They are **always taken in that order** — database first,
then table — and that fixed order is what makes a deadlock impossible.

| Operation | Database | Table |
|---|---|---|
| `SELECT` and other reads, also each statement of a `lote` that only reads (2.9: one lock for each, released between them) | shared | shared, on each table read |
| A write to **one** table with no foreign keys or triggers, that no other table references (2.10: or, if one does, an `INSERT` without `OR REPLACE` or `DO UPDATE`, which changes no row they point to) | shared | exclusive (a write by part only while it commits, see below) |
| Cascades and triggers, when the set of tables is knowable | shared | exclusive on **every** table it can reach |
| DDL, views, `REPAIR KEYS`, `REPAIR TABLES` and `VACUUM` (2.9), `REPAIR INDEXES` (2.10), a whole transaction (2.9), and writes that read other tables: `INSERT ... SELECT`, a subquery (2.9), an `UPDATE` or `DELETE` with other tables (2.9), also in a trigger they fire (2.9) | exclusive | — |

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
stated: a trigger whose SQL cannot be analysed, a write that reads other
tables (`INSERT ... SELECT`, a subquery, or since 2.9 an `UPDATE` or `DELETE`
with other tables: holding the exclusive lock of some tables, the others are
read without a lock), a trigger it can fire that reads other tables the same
way, or with a `SELECT … FROM` (2.9; before, only its `INSERT ... SELECT`
counted), any structure change, or more than eight tables, where taking
that many locks costs more than one. The decision lives in
`Database::tablasAfectadas()` and is deliberately suspicious: when in doubt,
the database — one lock too many costs parallelism, one too few costs data.

The tables it looks through, and those an `UPDATE` or `DELETE` asks which
point to the one it changes, come from a list kept between writes while
`_database.json` does not change (2.10): every `CREATE`, `DROP` or rename of a
table counts in it (`esquema`, see [06-format.md](06-format.md)), so the next
write lists the folder again. Before, an `UPDATE` listed it up to three times,
800 files with 200,000 rows. A table that appears without the engine knowing
— its files copied in by hand, or created by an earlier version — is seen
within 32 writes of a table, or at once after `VACUUM`, which counts as such a
change: **run `VACUUM` after copying a table into the folder**, or its foreign
keys may miss a cascade until then (`CHECK KEYS` would find the orphans).

If a lock cannot be taken — a table's lock file that cannot be opened — or
the recovery done when a database is opened fails, the error releases every
lock already taken (2.9; before, the process kept the shared lock of the
database, so other writers waited for it forever, and its own later writes,
DDL included, ran believing they held an exclusive lock they did not have).

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
order of locking changes. What other writes did meanwhile in other parts stays
in the revision file it commits, also a part an `INSERT` added at the end (2.9;
before, the revision file was cut to the parts this write had seen, and every
lookup through an index became a scan until the next write), and the numeric
range of every index piece stays in its place, also in a revision file from
before 2.7, which has none (2.9; the one it wrote went to the first place, and
a lookup by number could skip the piece that held it).

What counts as depending only on each row: the table has no foreign keys and no
triggers and no other table references it; the statement reads no other table
(no subqueries, no `UPDATE … FROM` or `DELETE … USING`); an `UPDATE` does not
touch a column of the primary key or of a `UNIQUE` constraint, because
uniqueness is a property of the whole table; and it is not a `TRUNCATE TABLE`
(2.9), which empties the whole table. Everything
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
same table do 30–31 updates a second either way here, because four of the five
`fsync` calls of a write (revision file, manifest, journal folder, database
folder) have to happen inside the commit, and the fifth, the part's, is the
only one that now overlaps.

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

What each cache keeps, where it lives and how to switch it is in
[The caches, one by one](#the-caches-one-by-one); this section is about how
they stay right.

Every read of a part goes through a cache entry keyed by the table name, the
part number and **the revision at which that part was written** (from
`rev.json`). While nobody writes, every request reuses the cache; as soon as
someone writes, the revision of the parts they touched changes and any other
process reads the new data even if its old cache is still there. The structure
and each index piece have an entry of their own, keyed the same way. Entries
that a write leaves behind are deleted (APCu and disk); they do not accumulate.
On disk they are deleted by their exact name (2.9; listing the cache folder was
12 % of a one-row `UPDATE`), and every 32 revisions of the table, and when it
is dropped, all of its entries are swept: that also takes what no write leaves
behind by name, such as an entry stored by a read that crossed a write, or a
file half written by a process that died. While the table exists, the sweep
keeps the entries whose key carries no revision — the row count of each part
and, for each index, the verdict on its pieces, their filters and the bounds
of their text keys —, because they hold the revision of every part or piece
inside and an outdated one is never used (2.9; they were swept too, and then
the first text lookup opened the head of every index piece again, the next
write checked every piece again and `COUNT(*)` counted every part again).
The fingerprints a write leaves (see [Editing the files by hand](#editing-the-files-by-hand-29))
stay while a part it wrote is still there. What a write learns about what it leaves — that verdict, those filters and the
fingerprint of each part it rewrites — goes to the cache only once the write is
committed (2.9): a write by part that was discarded left its filters behind
with a revision that the next write would give to other contents, and a lookup
through that index could miss rows that were there. Inside a transaction
(2.10) nothing goes to the shared cache (APCu or `.cache/`), only to the
process's own memory: each statement gives the table another revision, so
what one stored was stale for the next, and nobody else can read until the
commit; the first reads afterwards fill it again. A table's results (below) are only
looked for when it has any on disk: storing one leaves a mark, and the next
write removes the mark and the results.

### A `JOIN` with few rows on the left looks them up

A `JOIN` builds a hash of the right-hand table and streams the left through
it, which is right for two large tables and wrong for the most common `JOIN`
on a web page: `FROM orders o JOIN customers c ON c.id = o.customer_id WHERE
o.id = ?`, one order and its customer. Since 2.7 the conditions of the
`WHERE` that only concern tables already joined are applied before the join
(for `INNER` and `LEFT`; a `RIGHT` or `FULL` join would change its result),
and if what is left on the left is small compared with the right-hand table
— one row for every 150 the table has, up to a thousand — and the right-hand
table has an index on the columns the `ON` equates, each left row is looked
up by key instead: 0.44 ms and 5.3 MB for that query on 30,000 orders and
20,000 customers (2.10), where the hash join took about 20 ms and 25 MB;
2.6 ms for twenty orders with a `LEFT JOIN`, where it took about 60. Above the threshold, or without
an index, the hash join runs as before, on the rows the `WHERE` left.

### The last entries stay in the process

Whatever the cache is, disk or APCu, reading an entry means decoding it. The
last entries read or written are kept as they are in the PHP process (2.7) —
four index pieces, 64 lists of row offsets (2.9; 16 before, which a query
touching more parts threw away on every pass), and sixteen shared by table
structures and the other small entries (verdicts, filters, limits, row
counts); never parts, which are large and which a lookup by key
no longer needs — so a script that runs thousands of lookups, the panel, or a
page that looks up the customer of each order in a loop does not decode the
same piece again on every query. The key carries the revision, so an entry
that another process has made stale is simply never asked for again; and when
memory runs short this is the first thing let go. In a request that runs one
query it changes nothing. Five thousand lookups by key on 20,000 rows: 9.9 s
in 2.6.1, 2.7 s in 2.7.0 with random keys and 0.7 s when nearby rows are
looked up in sequence.

Since 2.9 the process also keeps each table's state (its `rev.json`, decoded)
and its structure already normalised, for up to 32 tables. Before using them
it checks the seal of the file (size, date and inode) with one `stat()`, and
it only keeps a file that has not changed for 2 seconds: two writes in the same
second can leave the same seal, and the file system reuses the inode a write
frees. In one process, a lookup by key on 20,000 rows went from 0.183 to
0.144 ms.

### Query results

Since 2.6 the result of a `SELECT` is cached too, keyed by the SQL text, the
bound parameters and the revision (and `creada`) of every table the query
touches, views included. A repeated query on unchanged data is served without
running it: a `JOIN` that takes 100 ms the first time takes a fraction of a
millisecond the second. A write to any of those tables changes their revision
and the cached result simply stops matching; on disk the file is deleted at
that moment, in APCu it expires after an hour.

The key also carries the definition of every view the query reads (2.9) and
what the configuration changes in a result: the collation, its map and the
engine version. Redefining a view with the same name, switching the collation
or updating the engine is another key, so an older result is never served; the
old file stays until the next write to its tables deletes it.

Not cached: queries that depend on the moment (`RANDOM()`, `UUID()`,
`DATE('now')` and the other date functions with no argument), results over
`JSONSQLDB_CACHE_RESULTADOS` rows (5,000 by default; `0` turns the result
cache off), and (2.9) queries that took less than 1 ms to run: a lookup by key
costs less than storing it and is seldom repeated as it is. Storing every one
filled the cache with results nobody asked for again; with OPcache, a lookup
by key went from 691 to 657 µs between requests (612 µs with no result cache),
while a repeated 9 ms query is still served in under 0.5 ms. Also not cached
(2.9): a query that reads no table, which no write would ever delete, and on
disk a result of more than 1 MB: before, a key that can only read could fill
the disk with one large result per different query. In APCu there is no such
limit: its memory has a size of its own and the oldest entries leave it.

The results of the same tables go to 4,096 places at most (2.9; in APCu, 4,096
for the whole database): before, every different query left a file until the
next write, so a table that is only read, queried row by row, piled them up
without end, and every write had to list them. Two queries that land on the
same place overwrite each other; each result carries its whole key and is only
served for it. Queries run inside a trigger never use it: they see the changes of
the statement being run, which are not on disk yet.

The revision is per table and not one shared file, because two writes to
different tables run at the same time and a shared file would be rewritten in
full by both: whichever finished last erased the other's bump and left its cache
serving stale rows.

The cache steps aside when memory is tight (see [Memory](#8-memory)), and
`CHECK KEYS` and `CHECK TABLES` bypass it on purpose: they need to see what is
really in the files. `COUNT(*)` without `WHERE` does not read the cached rows
either: it counts the lines of each part, and since 2.9 keeps only that count in
the cache, together with the part's revision, size and modification time; when
any of them changes (a write, or an edit by hand) that part is counted again.

### What the on-disk cache costs

Without APCu the cache lives in `.cache/` as PHP-serialised copies, and that
is not free: it takes a little more than the space of the data and index
files it mirrors (1.1–1.2 times in the benchmark), and about as many files. What that means for a hosting plan,
and what you can do about it, is in
[Files, space and how to tune them](#9-files-space-and-how-to-tune-them).

Deleting `.cache/` by hand is safe at any time: everything in it is
regenerated on the next read.

Most entries carry the revision of what they copy in their name, so a write
makes new ones instead of changing old ones. A few have a fixed name — for
each index, its filters, the check of its pieces, its smallest and largest
values and the parts where it serves whole; and the list of tables — and
since 2.10 a write overwrites them in place instead of replacing the file:
on ext4 or XFS, emptying or replacing a file that exists makes the system send
its data to the disk, 170–270 µs per file even for a small one, against 4–7
µs in place. Each such entry starts with a line `jsonsqldb-cache <length>
<crc32> <crc32c>`, so one caught half-written by a reader, or left so by a
killed process, does not match its sums and only counts as a miss
([06-format.md](06-format.md)).

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
but lose nothing either. On a **text** column the key can be in any piece; since
2.9 each piece carries a filter in its header that says, without reading its
keys, when a key cannot be there (a lookup by email: 1.65 → 1.15 ms between web
requests), and its smallest and largest text, so a range (`>`, `<`, `BETWEEN`)
only reads the pieces that can hold part of it. Those two values are also kept
in the cache with the revision of their piece, made when a query reads them, so
a range does not even open the headers of pieces that have not changed.

Indexes serve reads and writes:

- A `SELECT` with an equality or `IN` on an indexed column decodes only the
  parts that hold the matching rows. On a table of twenty parts, one instead
  of twenty.
- An `INSERT` checks the primary key and the `UNIQUE` constraints against the
  index on disk instead of loading the table, appends to the last part and
  rewrites only the last piece of each index.
- An `UPDATE` or `DELETE` whose `WHERE` an index can answer reads only the parts
  that hold the candidate rows and rewrites only those (since 2.9 a delete
  leaves a hole in its place instead of moving the rows behind it),
  and only the matching index pieces.

Equalities and `IN` against literals in the top-level `AND` chain of the
`WHERE` use an index, as long as reading the rows they point to costs no
more than reading half the table: up to 30 rows of a part are read by their
lines, and the engine counts 200 such lines as the cost of reading a part
(measured with and without OPcache and APCu: 190 to 280). Until 2.9.0 it
counted 30, so with
more than 15 matching rows per part it gave the index up and read the table,
nine to thirteen times slower and with more memory: on 30,000 rows, `WHERE age =
30` (453 rows) went from 21.9 to 1.8 ms in 2.10. An index on several columns
serves its first ones fully only when the columns after them are `NOT NULL`
(the primary key's are): a row with `NULL` in any column of an index is not in
it, so a lookup by `a` through an index on `(a, b)` alone would miss the rows
whose `b` is `NULL` (until 2.9 it did: in `UPDATE` and `DELETE` from 2.5.0, in
reads from 2.7.4). Since 2.10 such an index serves an equality or an
`IN` on its first columns in the parts where no row is missing from it — its
piece has one key for every line of the part, so no hole and no `NULL` —, and
the other parts are read whole and filtered as usual; when those are more
than half, or most of the first ones looked at, the table is read. Whether a
piece is whole is kept in the cache with its revision, so only the pieces
changed since the last query are counted again. On 30,000 rows with
`UNIQUE (grupo, codigo)`, `codigo` nullable and one `NULL`, `WHERE grupo = 7`
takes 5.5 ms instead of 11.5 (2.9.0, which missed the rows with `NULL`: 4.9);
with a `NULL` in every part it reads the table as before, in the same time.
The last part, not yet full, is always read whole. Since 2.9 also, with the same rule for the columns after the first:

- a range (`<`, `<=`, `>`, `>=`, `BETWEEN`) against a literal on the first
  column of an index, when the ranges of the pieces show it will not cost more
  than reading half the table (with numbers, fewer than half the parts to
  open);
- `COUNT(*)` whose `WHERE` is only comparisons on one indexed column, counted
  in the index without reading rows; with equalities, on texts that are not
  numbers and, since 2.10, on numbers in an `INTEGER`, `DOUBLE` or `DECIMAL`
  column (`COUNT(*) WHERE age = 30` on 30,000 rows, 21.3 → 0.36 ms) and on any
  text in a `TEXT` column, where each value has one key;
- `MIN(col)` and `MAX(col)` with no `WHERE` on a numeric column that is the
  first column of an index, taken from the ranges already in `rev.json`;
- `ORDER BY col [DESC] LIMIT n` on one table, with no `WHERE`, grouping or
  `DISTINCT`, on a numeric indexed column (ascending needs it `NOT NULL`);
  only the n rows that come out are read, also when many rows tie on the last
  value (2.10; `ORDER BY age DESC LIMIT 20` with 439 rows of the same age:
  11.5 → 3.6 ms). Since 2.10 also on a `TEXT` column without `COLLATE`:
  its keys are its texts as they are, so every piece of the index is read
  (there is no range in `rev.json` to start from the best one) and only what
  can still come out is kept, in the order of the query — the configured
  collation, or byte by byte with `ORDER BY col COLLATE BINARY` or
  `JSONSQLDB_COLACION` `'binaria'` —, the table order breaking ties. On 30,000
  customers, against 2.9.0: `ORDER BY ciudad DESC LIMIT 20` (10 cities)
  29.2 → 2.0 ms, `ORDER BY codigo DESC LIMIT 20` (30,000 different texts)
  36.2 → 25.8 ms, `ORDER BY codigo COLLATE BINARY DESC LIMIT 20` 102 → 10.5 ms.

`EXPLAIN SELECT …` says which of these a query used. `LIKE`, `IS NULL`,
`NOT IN`, other aggregates and orders, a top-level `OR` and anything under a
`NOT` scan the table.

### How an index is kept up to date

A table is stored by position, and the index points to positions. Rebuilding
the index from scratch on every write was most of the cost of writing one row
into a large table, and almost always repeated work. Since 2.5 the previous
index is **corrected** whenever the engine can prove what changed:

- rows **appended** at the end: their keys are added;
- rows **replaced in place** (an `UPDATE` that does not move anything, or since
  2.9 a `DELETE` that leaves a hole): the old key is removed and the new one, if
  any, added, using the old row read from the part that has not been replaced
  yet;
- rows **shifted from a position on**: every entry from that position is cut
  and the rows behind it are re-added.

Since 2.9 a `DELETE` leaves a hole, `null`, in the place of each deleted row,
so nothing behind it moves (see [Data file](#data-file-usersjson)). On a table
the statement has to load whole — one with triggers or with a foreign key to
itself — it still writes the table without the deleted rows, and the rows
behind the first one shift. When the holes would pass 25 % of the table, or on
`VACUUM`, the table is rewritten without them and its indexes rebuilt.

Only the pieces that hold the positions concerned are read and rewritten; the
others keep their revision. Before trusting them, the write checks the pieces
it leaves alone: the revision and the size of each one as the last write left
them are in the cache, and a piece in the same revision with the same size
(one `stat()`) is as it was; any other has its header read — index name,
columns, part number and revision, in the first bytes of the file — and its
tail checked. So a piece damaged or edited by hand is rebuilt at the next write
instead of staying broken, and a write to a table of a million rows does not
open its 3,000 pieces (it did until 2.9: half of a one-row `UPDATE`).

Any doubt — a revision file that does not say how many rows there were, a part
size that changed, a piece of a different revision — rebuilds the index from
the rows. The check is strict on purpose, because the two errors do not cost
the same: an entry too many only makes a query slower (the `WHERE` is
re-applied to the rows read), an entry too few returns incomplete results with
nothing to show for it.

The revision file records at which revision each piece was written; if a
piece says otherwise, or its columns are not the expected ones, the engine
ignores the index and scans: a stale index can cost speed, not a wrong
answer. A hand edit that keeps a piece's header can (an entry too few, as
above): `CHECK INDEXES` finds it and `REPAIR INDEXES` rebuilds the index (2.10,
see [Editing the files by hand](#editing-the-files-by-hand-29)).
`JSONSQLDB_INDICES` set to `false` disables indexes altogether.

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

A process killed between writing its `.tmp` and renaming it leaves the
`.tmp` behind; the name says which process wrote it. Since 2.10 a write puts
one line in the table's `.lock` while it works (`escribiendo: pid 1234 desde
2026-10-13 15:04:05`) and empties it when it ends. The next write that finds
the line of a process that no longer exists (or, where that cannot be asked,
a line more than a minute old) removes the temporary files of dead processes
of that table; and every 32 writes it looks anyway. Before, every write
listed the folder for them: 0.26 ms with 200,000 rows.

After a rename the **directory entry** is forced to disk as well (`fsync` on a
descriptor of the folder): the content may be on disk and the name still only
in the system cache, and POSIX does not guarantee the order. On Windows a
directory cannot be opened for that, which the journal covers; and renaming
over a file fails while another process has it open, even only to read it:
the engine tries again for up to a second, and never deletes the file it
replaces (2.10: it deleted it and renamed, so another process could find it
missing for an instant, and if it was still open the write failed).
`tests/f25_ficheros_abiertos.php` checks it, and the CI runs it on Windows.

`fsync()` exists from PHP 8.1. On 8.0 there is no reliable equivalent, so
everything in this section about surviving a **power cut** holds only on 8.1 and
later; surviving a killed or crashed **process** holds on 8.0 too (see the note
at the top of this document).

### Several files: the redo journal

One file is atomic, but **a set of files is not**, and almost no write touches
just one: a table with more than `JSONSQLDB_FILAS_POR_PARTE` rows lives in
several parts, a table with indexes rewrites each of them, and every write
rewrites `rev.json`. A crash between two renames would leave half the table new
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
state is exercised by `tests/f9_journal.php`, one by one. A rename that fails
in step 3 without a crash (on Windows, a file another program holds open)
leaves the temporaries and the manifest where they are, and the write is
finished the next time the database is opened (2.9; before, the temporaries
were deleted and the database no longer opened).

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
next write, and the next write to the table rebuilds it (before a write trusts a piece, it checks
its size and revision against the cache, or its header and tail; see [How an
index is kept up to date](#how-an-index-is-kept-up-to-date)). Not forcing them saves one
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
table with three indexes makes **five `fsync` calls** — the part, `rev.json`,
the manifest, the `.tx/` folder that holds it and the database folder; six
when it fills the last part and also opens a new one — down from ten in 2.6
and from twenty-odd in 2.4. The autoincrement counter moved from the
structure file into `rev.json` for exactly this reason: a file the write
touches anyway. On this benchmark machine an `fsync` takes 0.1 ms and the
difference is invisible; on a hosting disk it takes 3–8 ms, and there the
same `INSERT` went from 50 ms to 28 ms (3 ms per `fsync`) or from 102 ms to
about 50 ms (8 ms) when measured in 2.7, by making the kernel delay each
`fsync` by that much. Five is the floor for a write that keeps every data file durable on its
own: one per data file (the part and `rev.json`), the manifest, the folder
that holds it and the database folder.

A process killed with `SIGKILL` runs no `finally` and can leave its temporary
on disk; that cannot be avoided from inside. What is avoided is accumulation:
a write sweeps the stray temporaries of its table left by processes that no
longer exist (the pid is in the name) when the line in the table's `.lock`
says the previous write died, and every 32 writes (2.10; before, at every
write, see [One file](#one-file)) — holding the table's lock is not enough,
since a write by part keeps its temporaries while it waits to commit — and a
process holding the exclusive database lock sweeps them all.

Each statement is atomic on its own, cascades and triggers included. Grouping
several statements into one unit of work is a **transaction** (2.9:
`$db->transaccion(fn)`, `$db->lote([...])`, the API's `lote` field, several
statements in the panel's SQL console; see [Transactions](03-writes.md#transactions-29)).
It holds the database's exclusive lock from start to end, so nobody sees it
half done. Each statement writes as always, and the next one sees what it did;
before a write first replaces or deletes a file, the original is kept in
`.lote/` with a hard link (a copy forced to disk where links are not allowed),
which keeps the old content without copying it, because the engine does not
change that file in place: it puts a new one under its name. From then on
(2.10) the statements of the transaction write that new file in its place,
without a temporary or a rename, with what is under 1 MB per statement: the
original is safe in `.lote/`, in another inode (only once the new file is in
place: while a rename has failed, the file under the name is still the one
linked from `.lote/`, and it is replaced as always), nobody else reads the database meanwhile, and
committing forces it to disk with the rest; on PHP 8.0, which cannot force
it, they write as always. A transaction of 100 one-row `INSERT`s renames 6
files instead of 402 (502 in 2.9.0), and forces 2 more to disk when it
commits: its index pieces, which in their place could be left half written. The originals are kept, and listed in
`.lote/manifiesto.lote`, before anything is put in place, so a statement cut
short is undone with the rest of the transaction; that is why, since 2.10, a
statement inside a transaction writes no journal of its own (its manifest
and its `fsync`s were redundant), and does not force its files to disk: until
the transaction is committed, a cut is undone with the originals, whatever the
new files hold. Committing forces every
file the transaction wrote, once each, and only then removes the manifest
(the point of no return): a transaction of 100 one-row `INSERT`s makes 10
`fsync` calls, where 2.9.0 made 505. A statement that fails half-way through putting its files in place,
or while writing one in its place, leaves the transaction unable to commit:
it can only be undone. One whose only file cannot be renamed leaves it as it
was, and the transaction goes on. Committing
deletes `.lote/`; undoing puts the originals back and removes what is new. If the process dies halfway, the
transaction is undone when the database is next opened (`tests/f3_escrituras.php`
kills one with `SIGKILL` and checks it).

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
- **Deleting does not move rows** (2.9): a deleted row becomes a hole in its
  own part, so a `DELETE` rewrites the parts it deleted from and their index
  pieces, nothing behind them (see [Data file](#data-file-usersjson)).
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

A query holds a table in full only when it needs every row at once: the
inner side of a `JOIN`, and an `ORDER BY` without `LIMIT`, which since 2.10
keeps of each row only the columns it returns and its sort keys, projected as
the rows are read (`SELECT id, balance … ORDER BY balance DESC` on 30,000 rows:
33.8 → 13.5 MB, 104 → 47 ms). Since 2.9 what does not fit —
those two, a `GROUP BY` of many groups, a subquery, a `UNION`, window
functions — goes to files on disk instead (in `.orden/`, as soon as half of
`memory_limit` is in use, see [What does not fit goes to
disk](#what-does-not-fit-goes-to-disk-29)), and `DISTINCT` keeps only the
distinct rows, dropping a repeated one as soon as it is projected. A `WHERE` scan does not hold the
table — rows are read one part at a time and only the survivors are kept — and
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
  comparison, and since 2.10 keeps only the projected row and its keys.
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
  the engine registers a shutdown function. If PHP does abort for lack of
  memory, that function raises `memory_limit` at that moment to two megabytes
  above what PHP has taken, and the API still returns a normal JSON error rather than an empty or
  truncated body. Shutdown functions always run, even after a fatal error.
  Only where the hosting does not let `memory_limit` change (`ini_set`
  disabled, or the limit fixed by the server) are the two megabytes set aside
  at start and released instead, as before 2.9; that was about 1 ms on every
  request. The limit is only ever raised.
- **What it cannot fully cover**: a file is decoded in one instruction, and the
  peak happens before anyone can look. Before opening a file the engine
  estimates from its size whether the decoded content will fit. The estimate is
  a heuristic, so this reduces the window without closing it.

Data is never at risk from running out of memory: a read writes nothing, a
write flushes at the end so it has not touched the disk yet (one too large for
memory goes in stretches inside a transaction, which is undone when the
database is next opened), and the locks are released by the operating system when the process dies.

### What does not fit goes to disk (2.9)

When what a query has to gather does not fit, it is not stopped any more:
while it gathers in memory, as soon as half of `memory_limit` is in use,
`Memoria` warns and the query is run again by a path that writes what it
gathers to temporary files in the database's `.orden/` folder. An `ORDER BY`
is sorted in files and merged; a hash `JOIN` and a `GROUP BY` are split into
64 files by their key and done file by file; a subquery of the `FROM`, a view
or a query of the `WITH` is written to a file and read as it is used; a
`UNION` passes each row on as it is read; window functions keep in memory only
the values they use, a list per expression. The usual path is not touched:
the repetition is only paid for when it is needed, and before it the memory
PHP kept from the first attempt is given back. The rows are the same as in
memory, and so is their order when the query has an `ORDER BY`. The files are
closed and deleted at the end whatever happens, also when a `LIMIT` stops the
reading early (2.9; they were deleted while still open, which Windows does not
allow, and stayed); one left by a process that died is deleted by the next
query that uses the folder, after an hour. Details in
[02-queries.md](02-queries.md#5-performance).

What still has to fit: the values of a `GROUP_CONCAT` and, with `GROUP BY`,
the distinct values of a `COUNT(DISTINCT …)` in each group (without `GROUP
BY` they go to disk too), the rows already seen by a `DISTINCT` or a `UNION`,
the groups of one of the 64 files, and about 16 bytes per row for each value
a window function uses. A write of many rows goes in stretches (see
[03-writes.md](03-writes.md)), and looks its unique keys up in the indexes on
disk without loading the table: with the whole index in memory if it fits,
and if not, key by key, through the filter of each index piece.

### A million rows (2.9)

Measured with 2.9.0 (2.10 only made some of these faster or lighter: see
the CHANGELOG) with two tables of a million rows each — `clientes` (six columns,
`email` `UNIQUE`, an index on `ciudad`) and `pedidos` (four columns, an index
on `cid`) — with `memory_limit = 256M`, PHP 8.3, the on-disk cache, the result
cache off, each query in its own process; 300 MB of data and indexes. Every
result was checked against the same data loaded in SQLite: the same rows, with
one difference in the last digits of a sum of a million decimals (SQLite adds
them with compensation, here they are added in order).
Loading them, in `INSERT`s of 2,000 rows: 383 s and 55 MB for `clientes`
(each new email is looked up in the `UNIQUE` index, see below), 61 s and
19 MB for `pedidos`.

| Query | Time · peak memory |
|---|---|
| Lookup by primary key | 32 ms · 3 MB |
| The orders of one customer, by the index on `cid` (values spread over the table) | 51 ms · 5 MB (as a lookup by key once its filters are in the cache: the first one reads them from the 1,000 pieces) |
| 50 rows of one city by its index, `LIMIT 50` | 21 ms · 4 MB |
| Lookup by the `UNIQUE` text column | 42 ms · 5 MB (as a lookup by key once its filters are in the cache: the first one reads them from the 1,000 pieces) |
| Ten keys with `IN (…)` | 27 ms · 3 MB |
| `BETWEEN` on the primary key, 1,000 rows | 34 ms · 4 MB |
| `COUNT(*)` of the table | 298 ms · 3 MB the first time; 19 ms once the count of each part is in the cache |
| `COUNT(*)` with an equality on an index (100,000 rows) | 43 ms · 3 MB |
| `ORDER BY id DESC LIMIT 20` | 27 ms · 4 MB |
| `COUNT(*)` of a range, no index | 0.80 s · 4 MB |
| `LIKE` by prefix, no index | 1.3 s · 5 MB |
| `ORDER BY saldo DESC, id LIMIT 20`, no index | 1.7 s · 4 MB |
| `GROUP BY ciudad` with `COUNT`, `SUM` and `AVG` (10 groups) | 2.3 s · 5 MB |
| The million ids, through `consultarPorFilas()` (the API) | 2.9 s · 17 MB |
| The million ids `ORDER BY saldo DESC, id` | 14 s · 164 MB, on disk |
| `ORDER BY id LIMIT 100 OFFSET 500000` | 4.9 s · 146 MB; without the `ORDER BY`, 25 ms (the parts the `OFFSET` skips are not read) |
| `JOIN` of the million orders with their customers, `GROUP BY` city | 19 s · 128 MB, on disk |
| `COUNT(DISTINCT email)` | 1.9 s · 90 MB |
| `COUNT(DISTINCT email)` and `COUNT(DISTINCT nombre)` | 8.4 s · 152 MB, on disk |
| `GROUP BY cid` (631,796 groups) `ORDER BY` count `LIMIT 5` | 14 s · 163 MB, on disk |
| `COUNT(*) … WHERE cid IN (SELECT id FROM clientes)` | 4.7 s · 101 MB |
| `COUNT(*)` with a correlated `EXISTS` on the orders | 2.9 s · 5 MB |
| `COUNT(*)` of a `UNION` of the two million emails | 6.6 s · 128 MB, on disk |
| `ROW_NUMBER() OVER (ORDER BY saldo DESC, id)` | 33 s · 161 MB, on disk |
| `LAG(saldo) OVER (ORDER BY id)` | 12 s · 128 MB, on disk |

A query that goes to disk shows about half of `memory_limit` as its peak:
that is where its first attempt, in memory, stopped.

Writes, one after another on the same data (with `memory_limit = 256M`; the
sums of control of both tables, the same as SQLite's after each one):

| Statement | Time · peak memory |
|---|---|
| `CREATE TABLE … AS SELECT`, 323,268 rows | 3.1 s · 69 MB |
| `INSERT … SELECT`, 676,732 rows | 11 s · 122 MB |
| `UPDATE` of the million rows | 24 s · 140 MB |
| `UPDATE` of 300,097 rows chosen by an index | 17 s · 147 MB |
| `DELETE` of 100,140 rows chosen by an index | 8.3 s · 70 MB |
| `DELETE` of 300,000 orders by a range of the key | 8.2 s · 39 MB |
| `ALTER TABLE` `ADD COLUMN`, `DROP COLUMN`, `RENAME COLUMN` | 3.7, 4.4 and 4.7 s · 8–9 MB |
| `CREATE INDEX` on a number, `CREATE UNIQUE INDEX` on a text | 3.1 s · 12 MB, 4.6 s · 103 MB |
| `VACUUM` after deleting 100,140 rows | 12 s · 24 MB |
| `INSERT … SELECT … ON CONFLICT (id) DO UPDATE`, 179,774 rows | 13 s · 123 MB |
| `INSERT OR IGNORE … SELECT`, 89,925 new rows, two `UNIQUE` text columns | 125 s · 111 MB |
| `INSERT OR REPLACE … SELECT`, 44,976 rows | 71 s · 85 MB |
| `UPDATE` of the `UNIQUE` email of 99,563 rows | 80 s · 129 MB |
| An upsert of one row by its `UNIQUE` email | 80 ms · 10 MB |

The slow ones look up many text keys in a `UNIQUE` index too big for memory:
each key is looked for only in the index pieces whose filter allows it, about
a millisecond per key at this size. Holding the whole index in memory, the
`UPDATE` of the emails took 14 s, but the `INSERT OR IGNORE … SELECT` ran out
of memory; the whole index is still used whenever it fits in what is left up
to half of `memory_limit`.

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
| On-disk cache (`.cache/`, without APCu) | *P* + *P* × *I* + 2 (structure and row counts), up to three per index (its piece sizes, its filters and the bounds of its text keys, 2.9), one per write whose parts are still in place (their fingerprints, 2.9), plus one per cached query result | a little more than the file it mirrors (1.1–1.2 × in the benchmark) |

On the bundled benchmark (`php tests/benchmark.php 100000`, 100,000 customers,
150,000 orders and 100,000 notes, three indexes on customers and one on each
of the others, default settings, no APCu):

| | Files | Space |
|---|---|---|
| Data | 358 | 27.5 MB |
| Indexes | 553 | 9.3 MB |
| Cache | 869 | 42.0 MB |
| **Total** | **1,780** | **78.8 MB** |

The benchmark prints these three lines for whatever size you give it. For
20,000 customers, 30,000 orders and 20,000 notes it is 78 + 113 + 205 files
and 5.4 + 1.8 + 8.5 MB. Space is not the problem: a base has to hold millions of rows
before it fills a cheap plan, and by then this engine is the wrong tool. Files
can be, if the account is already busy with something like a WordPress
(30,000–60,000 files on its own).

### The three settings that move it

**1. `JSONSQLDB_CACHE_ACTIVA` — the cache.** The largest share of both files
and space is the cache, and it exists only for speed: a cached part decodes in
half the time of its JSON and a cached index piece in a third. Three values:

| Value | Files and space | Speed |
|---|---|---|
| `true` (default) | APCu if the host has it (then the table cache lives in shared memory and **costs nothing on disk**, except, with OPcache active, the `.php` copies of the parts and one state file per table); otherwise `.cache/` on disk, the figures above | full |
| `'apcu'` | shared memory only; `.cache/` is never written. **Without APCu this means no cache at all** | full with APCu; without it, reads decode JSON every time (measured with 2.9): on 100,000 rows a scan with a filter goes from 116 ms to 159 ms, a `GROUP BY` from 162 ms to 233 ms and a lookup by a `UNIQUE` text from 0.7 ms to 3.2 ms; a lookup by primary key stays about the same, since it reads one line |
| `false` | no cache | as above, without cache; only for debugging |

So the first thing to check on a shared host is whether APCu is available for
your PHP version (most control panels offer it as a tick box). With it, and
without OPcache, the disk question disappears: the table above becomes 911
files and 36.8 MB, all of them data and indexes.

**2. `JSONSQLDB_FILAS_POR_PARTE` — the part size** (1,000 by default). A
bigger part means fewer parts, and since index pieces and cache entries follow
the parts, fewer of everything, and the space stays the same: on 20,000
customers (measured with 2.9), 387 files at 1,000 rows per part and 99 at 5,000. What it costs,
measured there with 2.9, same machine, two runs of each one after the other
(the mean):

| | 1,000 rows per part | 5,000 rows per part |
|---|---|---|
| Lookup by primary key | 1.6 ms · 5 MB | 1.3 ms · 6 MB — one line of the part, read by its offset, either way |
| `IN` of a hundred keys spread over the table | 3.5 ms · 5 MB | **46 ms · 10 MB** — with that many keys in a part, the part is decoded, and it is five times bigger |
| `LIMIT 50` with no filter | 0.9 ms · 7 MB | 4.9 ms · 11 MB |
| `INSERT` one row | 15 ms · 6 MB | 13 ms · 7 MB |
| `UPDATE` one row by key | 9.7 ms · 6 MB | 11.8 ms · 8 MB — one part rewritten, five times bigger |
| `DELETE` one row by key, with its notes in cascade | 96 ms · 16 MB | 81 ms · 17 MB |
| Scan with a filter, `GROUP BY`, `ORDER BY … LIMIT` | 21–32 ms · 7 MB | **31–52 ms · 11 MB** |
| `JOIN` aggregated by city | 279 ms · 19 MB | 295 ms · 22 MB |

A lookup by key costs the same, since it reads one line; a single-row write
rewrites a bigger part; and what reads many rows — scans, and lookups of many
keys in the same part, which decode it — is slower and holds more memory. The
engine's defaults favour speed because on most plans a few hundred files are
nothing; if your account is near its quota, 5,000 cuts the files by four for
scans 1.4 to 1.9 times slower.

Changing it on an existing database is safe: tables are read with the part
size recorded in their `rev.json`, and the first write to each table splits it
with the new size and rebuilds its indexes, once. That first write is a full
rewrite of the table, so do it at a quiet moment.

**3. Indexes.** Each index adds *P* files and, without APCu, *P* cache files.
An index no query uses is pure cost: only equalities and `IN`, and since 2.9
the ranges, counts, `MIN`/`MAX` and `ORDER BY … LIMIT` listed in section 5, use
one; `LIKE` and anything else never does. `SHOW INDEXES FROM t` lists
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

### The caches, one by one

The engine keeps four caches, each with its own job. None of them is needed for
the results to be right: every entry is checked against the revision of its
table before it is used, a write leaves the old ones unreachable, and deleting
`.cache/` or emptying APCu or OPcache is always safe — the next read just
rebuilds what it needs.

| Cache | What it keeps | Where it lives | Setting |
|---|---|---|---|
| **Table cache** | each table part already decoded, its index pieces and its structure; since 2.9 also how many rows each part has, so `COUNT(*)` and `SHOW TABLES` do not read the parts, and per index the revision and size of each piece as the last write left them and all its filters together (see [Indexes](#5-indexes)) | APCu if PHP has it; otherwise `<base>/.cache/` | `JSONSQLDB_CACHE_ACTIVA`, `JSONSQLDB_CACHE_APCU` |
| **OPcache layer** (2.9) | the table parts again, as `.php` files that OPcache keeps compiled in its shared memory; and each table's state (`rev.json`) already decoded, one `e.*.php` per table | `<base>/.cache/*.php` and the memory of OPcache | `JSONSQLDB_CACHE_OPCACHE` |
| **Result cache** | the rows of a `SELECT` that took at least 1 ms (2.9), under its SQL, its parameters and the revision of every table and view it reads; at most 4,096 per set of tables | wherever the table cache is | `JSONSQLDB_CACHE_RESULTADOS` |
| **Per-request memory** | the structures and index pieces decoded during the current request, so the same request does not decode them twice | the memory of that PHP request | on whenever the table cache is (with `false`, only table states and structures) |

**`JSONSQLDB_CACHE_ACTIVA` is the general switch**, not only the disk one:

| Value | What it means |
|---|---|
| `true` (default) | the table cache goes to APCu if PHP has it (and `JSONSQLDB_CACHE_APCU` is not `false`), and to `.cache/` if not; the OPcache layer and the result cache work on top of it |
| `'apcu'` | shared memory only: nothing is ever written to `.cache/`. That leaves out the OPcache layer, which needs its files; and **without APCu this means no cache at all** |
| `false` | no cache of any kind (table, OPcache layer or results): every read decodes the JSON. Only for debugging |

**`JSONSQLDB_CACHE_APCU`** (2.9, `true` by default). If PHP has APCu, the
table cache goes to its shared memory instead of to files. It is worth having:
the cache takes no disk space (on disk, a little more than the data and indexes)
and no files, all PHP processes share it, and nothing is read from the disk to
use it. Measured here against the disk cache, with the system already holding
the files in memory: full scans 4–5 % faster and everything else the same; on a
host with a slow or network disk the difference is larger. It is not required:
without APCu the engine works the same, with the cache on disk. `false` leaves
APCu aside even if it is there.

**The table's state, decoded (2.9).** Every request reads each table's
`rev.json` to know which cache entries still hold; decoding it was the largest
single cost at the start of a request (about 0.08 ms for a table of 60 parts).
With OPcache or APCu, the decoded state is kept, one entry per table, together
with the seal of the file (size, date and inode): a `stat()` tells whether it
still holds. It is only kept once the file has not changed for 2 seconds, so two
writes in the same second can never share a seal. Between web requests: a lookup
by key 862 → 834 µs, a text range with `LIMIT` 813 → 719 µs. Without OPcache or
APCu nothing changes.

**`JSONSQLDB_JIT`** (2.10, `true` by default). OPcache's JIT is not a cache,
but it is where the time of a query goes that reads many rows: with it, a
`GROUP BY` over 30,000 rows does 14 % less work and `MIN`/`MAX` 24 % less
(counted with `valgrind`, see the README). It is set in `php.ini`
(`opcache.jit=tracing`, `opcache.jit_buffer_size=64M`). If `php.ini` gives it
its memory but leaves it as `opcache.jit="off"` (with the quotes) or `0` (off,
but allowed to be turned on), the engine turns it on with `ini_set()` the
first time it opens a database in a process; it applies to the rest of the
request, the application included. With `opcache.jit=disable`, with `off`
without quotes (`php.ini` reads it as empty, which PHP takes for `disable`),
or without its memory, PHP does not let code turn it on, and the engine
leaves it alone; also where `ini_set()` is disabled. `false` leaves it as it
is.

**`JSONSQLDB_CACHE_OPCACHE`** (2.9, `true` by default). If PHP has OPcache
active, every table part that has not changed for 2 seconds is also written to
`.cache/` as a `.php` file holding only its data, and OPcache keeps it compiled
in its shared memory: loading the part then costs practically nothing, and it
lasts from one request to the next. It is worth having — measured between web
requests on a table of 60,000 rows: loading the whole table from the cache went
from about 22 ms to about 0.15 ms, full scans became 33–56 % faster, and queries
answered by an index stay the same. It does not slow writes: the `.php` is made
when a part is read, a few per request, never when OPcache has less than a
quarter of its memory or keys free (so it can neither fill nor restart because
of the database), and a write only deletes the `.php` of the parts it rewrites.
Each `.php` is written to a temporary file of its own process and renamed into
place only once complete (2.9; two processes rebuilding the same one shared a
temporary name, and could leave it cut short, a parse error when loading it,
or empty).
It works with or without APCu (with both, the parts come from OPcache and the
rest from APCu), but not with `JSONSQLDB_CACHE_ACTIVA = 'apcu'`, because it
needs its files. It is not required: without OPcache, or with `false`, the
engine works exactly as before. The `.php` files contain only data written by
PHP's own `var_export()`, under names the engine makes; `.cache/` must not be
reachable from the web (the `.htaccess` and `web.config` that come with the
engine deny it), and restoring a ZIP backup in the panel never writes into it.

**Without OPcache** PHP compiles, in every request, every file of the engine
that the request loads; with OPcache, once. So since 2.9 the code that only
some statements need lives in classes of its own, which the autoloader loads
only when one of them is used: in the storage, what only reads do
(`StorageLectura`), writing rows (`StorageEscritura`), changing and removing
them (`StorageCambios`), reads by a comparison on an index (`StorageRangos`),
and the structure, transactions, recovery after a crash and repairs
(`StorageMantenimiento`, `CatalogDdl`, `WriterDdl`, `ParserSentencias`); in
the parser, the rest of `SELECT` (`ParserConsulta`), `INSERT`, `UPDATE` and
`DELETE` (`ParserEscritura`), windows and `WITH RECURSIVE` (`ParserAvanzado`);
in queries, sorting without an index (`SelectOrden`), `GROUP BY` and the
aggregates (`SelectAgrupada`), `UNION` and `WITH` (`SelectCompuesta`), the
hash `JOIN` (`SelectJoin`), windows and `EXPLAIN` (`SelectAvanzada`), the
choice of an index (`IndexesBusqueda`), compiled expressions
(`EvaluatorCompilado`), `LIKE`, `GLOB` and `REGEXP` (`EvaluatorPatrones`) and
the functions that are not SQLite's usual ones (`FunctionsAmpliadas`); in
writes, `UPDATE` and `DELETE` (`WriterCambios`), foreign keys
(`WriterForaneas`), a row that clashes with a key (`WriterConflictos`) and
the body of triggers (`WriterTriggers`); and batches, `SHOW DATABASES` and
`EXPLAIN` (`DatabaseAvanzada`). 2.10 adds to them, besides `CHECK INDEXES`:
reading in the order of an index (`StorageOrden`, and of a text index
`StorageOrdenTexto`), the largest values of an index, for the id of an
`INSERT` that leaves it out (`StorageMayores`), keeping the indexes up to
date when writing (`IndexesEscritura`), writing a whole table
(`StorageEntera`), compacting one
by blocks of text (`StorageCompactacion`), what is kept for other processes
in APCu or for OPcache (`StorageCompartida`), the lookups by the first
columns of a composite index or by prefix (`StorageBusquedas`), the temporary
files of a write that died (`StorageBarrido`), which tables to lock for a
write that can reach several (`CatalogBloqueos`), the join through an index
(`SelectJoinIndice`), sorting on disk (`SelectEnDisco`), `IN` row by row
(`EvaluatorIn`) and looking a value up in its set (`EvaluatorConjunto`, all
that a `WHERE` with `IN`, which is compiled, needs), `CAST` and the `ENUM`
and `UNSIGNED` columns
(`TypesCast`), the functions written with words, `TRIM(… FROM …)`, `EXTRACT`,
`CONVERT`… (`ParserFunciones`), the `INSERT` (`WriterInsercion`), which an
`UPDATE` or a `DELETE` does not compile, and the parser of `UPDATE` and
`DELETE` (`ParserCambios`), which an `INSERT` does not compile, the `SET NEW`
of triggers and the writes that join other tables (`ParserEscrituraAvanzada`,
`WriterCambiosEspeciales`, which also has `TRUNCATE`), `RETURNING`
(`WriterAvanzado`), and transactions and the automatic repairs after a query
(`DatabaseAvanzada`). The structure changes are called in `CatalogDdl`
directly, and the log (`Logger`) is loaded only when something is written to
it (with the `config.php` that ships, writes and errors: not for a read); the
API and the panel give it who runs the queries, and from where, with a
constant instead of calling it (see [Query log](#query-log)). An `INSERT` of
plain values (also negative numbers) takes them as they are, without loading
the expression evaluator (`Evaluator`). With 2.9.0 no kind of statement
compiled as much code as with 2.8.0 (counted without spaces or comments,
which is what costs): a one-row `INSERT` 24 % less, an `UPDATE` or a `DELETE`
16 % less, a lookup by key 13 % less; 2.10 is below. A whole request without OPcache,
against 2.8.0 (median of 60): a lookup by key 10.6 against 13.0 ms, a join
13.4 against 16.1 ms, `GROUP BY` 14.2 against 17.0 ms, a one-row `INSERT`
12.9 against 15.7 ms, `UPDATE` 20.0 against 23.6 ms and `DELETE` 15.7 against
18.1 ms. With OPcache nothing is compiled again, and the classes apart only
add finding a few more files (about 15 µs in a lookup by key).
`tests/f1_nucleo.php` checks what each kind of statement loads, and that none
compiles more than 40 pieces of code over what it compiles in 2.10.

Against 2.9.0, 2.10 compiles less for every kind of statement measured, and
the same for a one-row `INSERT` with its id: the comparison rules of SQLite,
the table names without case and the keys of the text indexes are in every
statement, and the writes carry the fixes of 2.10 (foreign keys checked when
the statement ends, the ids after an `OR REPLACE`, the line in the
`.lock`…), but what a statement does not use is in the classes above. Counted
the same way (each statement in a new process, 300 rows), a lookup by key
61,685 → 58,605 pieces of code (−5.0 %), a join by key 63,920 → 62,808
(−1.7 %), a one-row `INSERT` 52,252 → 52,254 (the same), one without its id
55,120 → 52,659 (−4.5 %: it no longer reads rows to find the next id) and an
`UPDATE` or a `DELETE` 68,882 → 67,165 (−2.5 %). A whole request without
OPcache does less work than with 2.9.0, every one measured (counted with
`valgrind` on 30,000 customers, the log off): a lookup by key −2.6 %, by a
unique column −2.6 %, by ten keys −2.3 %, by a range −2.4 %, a `COUNT(*)` by
an index −2.0 % and of the whole table −1.9 %, a `LIMIT` −2.5 % and with
`OFFSET` −2.9 %, `ORDER BY id DESC LIMIT 10` −2.3 %, `SELECT 1` −1.1 %, a
join by key −1.0 %, a one-row `INSERT` −1.6 % and one without its id
−10.0 %, `UPDATE` −2.0 %, `DELETE` −2.2 % and an `UPDATE` of 51 rows −0.9 %;
with the log of the `config.php` that ships, from −0.9 % (the `UPDATE` of 51
rows) to −2.6 % (a lookup by key), the one-row `INSERT` −1.0 % and −9.4 %
without its id; with OPcache (its file cache, so that each process finds the
code compiled), from −0.3 % (`SELECT 1` and the join) to −2.6 % (the
`INSERT`), and −13.1 % without its id. The statements that use one of the
classes apart load one more file, and still do less than with 2.9.0: on
3,000 rows, an `IN`, an `EXISTS`, `UPPER`, `CAST`, an `INSERT` and an
`UPDATE` whose trigger inserts a row, an `INSERT` into an `ENUM` column, a
`DELETE` that compacts a table of 12 rows, `RETURNING` and `CREATE TABLE …
AS`, −0.4 to −8.5 % without OPcache and −0.07 to −13 % with it. At its peak
a request without OPcache takes 2.34 MB for a lookup by key (2.51 in 2.9.0),
2.21 for a one-row `INSERT` (2.51), 2.95 for an `UPDATE` (3.03) and 3.03 for
a `DELETE` (3.11); with OPcache's file cache, 2.34, 2.33, 3.22 and 3.30 MB
against 2.35, 2.56, 3.39 and 3.48. In a PHP server with OPcache in shared
memory, as on a web server, where nothing is compiled again: 462 KB for a
lookup by key (461 in 2.9.0), 547 for a join (544), 553 for a one-row
`INSERT` (598) and for one without its id (842), 907 for an `UPDATE` (895)
and 987 for a `DELETE` (978); in these two, 8 KB are the buffer PHP gives the
table's `.lock` when it reads the line a write leaves there, which is new in
2.10 (reading it without one cost more instructions in every write). A query loads the expression evaluator before
it reads any row (2.10), so that without OPcache compiling it does not come
on top of what the rows take: it is needed from there on anyway.

**`JSONSQLDB_CACHE_RESULTADOS`** (5,000 rows by default): the largest result
that is cached; see [Query results](#query-results) in section 4 for what goes
into its key and what is never cached. `0` turns the result cache off on its
own, leaving the other caches as they are.

### `config.php`

| Constant | Purpose | Default |
|---|---|---|
| `JSONSQLDB_DATA_PATH` | root folder with one subfolder per database | `data/` |
| `JSONSQLDB_FILAS_POR_PARTE` | rows per file before a table is split | `1000` |
| `JSONSQLDB_CACHE_ACTIVA` | general switch of the caches: `true`: APCu or disk; `'apcu'`: shared memory only; `false`: no cache at all (see [the caches](#the-caches-one-by-one)) | `true` |
| `JSONSQLDB_CACHE_APCU` | use APCu for the cache if PHP has it (2.9) | `true` |
| `JSONSQLDB_CACHE_OPCACHE` | give the table parts to OPcache if it is active (2.9) | `true` |
| `JSONSQLDB_JIT` | turn on OPcache's JIT for the request when `php.ini` gives it memory and leaves it as `"off"` or `0` (2.10, see [the caches](#the-caches-one-by-one)) | `true` |
| `JSONSQLDB_ESCRITURA_POR_PARTES` | `UPDATE`/`DELETE` that depend only on each row lock the table only to commit (see [Writes by part](#writes-by-part-27)) | `true` |
| `JSONSQLDB_CACHE_RESULTADOS` | maximum rows of a `SELECT` result to cache; `0` disables the result cache | `5000` |
| `JSONSQLDB_INDICES` | maintain and use indexes | `true` |
| `JSONSQLDB_LOG_ACTIVO` | enable the query log | `true` |
| `JSONSQLDB_LOG_PATH` | folder of the log files | `logs/` |
| `JSONSQLDB_LOG_NIVEL` | `todo` / `escrituras` / `errores` | `escrituras` (2.9; before, `todo`) |
| `JSONSQLDB_LOG_MAX_SQL` | maximum length of the SQL stored | `2000` |
| `JSONSQLDB_LOG_PARAMS` | also store the values of the `?`. Default **no** | `false` |
| `JSONSQLDB_LOG_MAX_SIZE` | maximum size per file before rotating | 5 MB |
| `JSONSQLDB_LOG_DIAS` | days to keep the logs (0 = forever) | `90` |
| `JSONSQLDB_API_ACTIVA` | the API answers requests (2.9) | `true` |
| `JSONSQLDB_CONEXION_DIRECTA` | use the engine without the API | `false` |
| `JSONSQLDB_MEMORIA_VIGILAR` | stop the query before memory runs out | `true` |
| `JSONSQLDB_MEMORIA_MARGEN` | fraction of `memory_limit` at which to stop | `0.85` |
| `JSONSQLDB_COLACION` | alphabetical order of `ORDER BY`: `general` or `binaria` | `general` |
| `JSONSQLDB_COLACION_MAPA` | per-language ordering corrections | `[]` |

Removed in 2.5 and ignored if still defined: `JSONSQLDB_JOURNAL_DATOS` (the
journal is always on; it is now cheap) and `JSONSQLDB_CACHE_MAX_FILAS` (the
cache is per part, so there is no whole-table entry to cap).

### Direct connection to the engine

By default the engine can **only** be used through the API. On a direct
connection, the first query or transaction of a `Database` is rejected with an
explicit message. jsonSQLDBadmin, set to its direct connection, allows it for
itself only, without changing `config.php`
([05-admin.md](05-admin.md)).
The API itself can be turned off with `JSONSQLDB_API_ACTIVA = false` (2.9, see
docs/04-api.md). To allow the direct connection, in `config.php`:

```php
defined('JSONSQLDB_CONEXION_DIRECTA') || define('JSONSQLDB_CONEXION_DIRECTA', true);
```

**For experienced programmers only.** With a direct connection:

- **There are no permissions.** It is always equivalent to an `admin` key: it
  can read, write, alter the structure and drop whole databases.
- **There is no API key and no signature.** HMAC, rate limiting, anti-replay and
  the IP list are all skipped. Security is entirely your code's business.
- **It is still logged**, as through the API (by default, since 2.9, writes and
  errors; see [Query log](#query-log)), with `ip` set to `"local"`.
- **Bound parameters still apply**, and they are the only thing protecting you
  from injection: `consultar($sql, $params)` with `?` in the SQL.

```php
require 'config.php';
require 'engine/bootstrap.php';

$db   = new JsonSQLDB\Database('mydb');
$rows = $db->consultar('SELECT * FROM customers WHERE city = ?', ['Malaga']);
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

Storage::crearBase('/path/to/data', 'mydb');

$st  = new Storage('/path/to/data', 'mydb');
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

By default (2.9) it records writes and errors, not reads:
`JSONSQLDB_LOG_NIVEL = 'escrituras'`, also with a `config.php` from an earlier
version that does not define it (it fell back to `todo`). Writing a line for every read opens and
appends to the log file in every request: about 0.09 ms, which between web
requests was 16 % of a lookup by key (632 → 544 µs). Set it to `todo` to see
reads too (as before 2.9), or `errores` for failures only. At `escrituras`,
`SELECT`, `UNION`, `EXPLAIN`, `SHOW` and `CHECK` are left out; before 2.9 only `SELECT`
was.

The **values** of bound parameters are not stored unless you enable
`JSONSQLDB_LOG_PARAMS`: passwords, tokens and personal data travel through
them, and the log is kept 90 days by default. The SQL is stored with its `?`
unsubstituted.

The line is always written (2.9): the SQL is cut at `JSONSQLDB_LOG_MAX_SQL`
bytes without splitting a UTF-8 character, a byte that is not UTF-8 is stored
as U+FFFD, and a value JSON cannot hold (`INF`, `NAN`) as 0. Before, a cut
inside a character such as `ñ`, or such a byte in the SQL or the values, left
the statement out of the log, writes included.

One file per day, `logs/consultas-2026-08-19.json`, and `consultas-2026-08-19.1.json`,
`.2.json`… when rotating by size. Each line is an independent JSON object:

```json
{"ts":"2026-08-19 12:00:00.123","ip":"10.0.0.5","db":"mydb","op":"SELECT","rows":42,"ms":3.15,"origen":"My app","sql":"SELECT ...","error":null}
```

- `rows` → rows **returned** by a SELECT or **affected** by INSERT/UPDATE/DELETE
- `ip` → source IP of the request through the API, or the user's in the panel; `local`
  for a script on a direct connection
- `origen` → label of the API key that ran the query, or `jsonSQLDBadmin (user)` for
  the panel on a direct connection
- A script of your own can set both with `JsonSQLDB\Logger::contexto('label', 'ip')`.
  The API and the panel give them with the constant `JSONSQLDB_REGISTRO`
  (`[label, ip]`) instead (2.10), so that a request that writes no line does
  not load the log's code; `Logger::contexto()` takes precedence
- `error` → `null` if it went well, or the message if it failed

Line-delimited JSON rather than a single array: appending never rereads or
rewrites the file, which is what lets the log keep up with the queries. If the
log folder cannot be written, the engine **keeps working**: the log never
interrupts a query. Since 2.10 it says so once per file and process in PHP's
error log (`jsonSQLDB: no se puede escribir …`), and so do the API's request
log and its state files (anti-replay and limits; without them the API turns
requests away, as before) and the panel's audit trail.

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

Three of them default to paths inside the project folder, so two copies in
different folders keep their data, API state and panel data apart without
changing anything; the session name is `jsonsqldbadmin` in every copy, so two
panels on the same domain need a different `ADMIN_SESION_NOMBRE` in one of
them.

What you **cannot** do is point two different data folders at the same files
(through symbolic links, for instance). Locks are taken by path, so two paths to
the same file are two locks that do not exclude each other.

---

## 11. Where the floor is

Every release so far has found the next thing to speed up, and every
measurement finds one more. This section says, for each kind of operation,
what the engine cannot go below without ceasing to be what it is — data in
JSON a person can read, PHP interpreting every row, and writes that survive a
power cut — and how far from that floor 2.10 stands. When something here is
called the floor, the way past it is a binary format, a C extension or a
resident process, none of which this project will have.

Measured on the one-core benchmark machine with `php tests/benchmark.php`
(PHP 8.3, on-disk cache, 20,000 customers and 30,000 orders unless said
otherwise; the floors were measured with 2.7.1, by timing only the
unavoidable part on the same machine):

| Operation | 2.10 | Floor | What separates them |
|---|---|---|---|
| Scan with a numeric filter | 0.6 ms per part of 1,000 rows | ~0.45 ms: decoding the part | yielding each row through the pipeline and counting it, in PHP |
| Scan with other filters, `GROUP BY`, `ORDER BY … LIMIT` | 0.6–1.2 ms per part | ~0.45 ms | evaluating the `WHERE` and accumulating, in PHP, one row at a time |
| Lookup of one row by a numeric key | 0.16 ms in a process that has already made one (0.18 ms on 100,000 rows); 1.0 ms with the first, which loads the code | reading one piece of the index and three small reads of the part | the lock, the parse and the log |
| Lookup of one row by a text key (`UNIQUE` on a text column) | 0.18 ms (0.25 ms on 100,000 rows), measured the same way | same as a numeric key | in 2.7.1 every piece of the index had to be read, since a text key can be in any (read as text and searched for the key, not decoded), so the reading grew with the table. Since 2.9 the filter in each piece's head leaves only the one that can hold the key: see below |
| `JOIN` of two big tables | 109 ms for 30,000 × 20,000 | ~50 ms: decoding both sides | hashing one side and matching every row of the other, in PHP |
| `JOIN` after a selective `WHERE` (one order and its customer) | 0.44 ms | ~0.3 ms | one lookup by key per row on the left; nothing else |
| Full `ORDER BY` | 24 ms and 15 MB for 20,000 rows | ~20 ms: decoding plus `asort` | building the sort keys and the result rows |
| Write of one row | 5 `fsync` calls (6 when it fills the last part and also opens a new one); `INSERT` 6.3 ms, `UPDATE` 4.8 ms on 20,000 rows | 5 `fsync` calls plus rewriting one part and one piece per index | an `INSERT` or `UPDATE` edits the text of the part without decoding it; what remains is checking uniqueness, the index pieces and the journal |
| Writes to the same table from two processes | by part since 2.7 (see [Writes by part](#writes-by-part-27)); the commit, one at a time | the commit, one at a time | four of the five `fsync` calls belong to the commit, so two writers overlap only in the fifth (the part's) and in their computing |

Everything in the third column is the cost of PHP arrays and readable JSON.
SQLite on the same machine does the lookup in a few microseconds and the scan
in a few milliseconds, because it reads binary pages into C structures and
never builds a PHP array per row. This engine exists for the hosting where
SQLite is not available; on that hosting, these are the numbers.

What was **not** on the floor in 2.7.1: lookups by a text key, which read the
text of every piece of the index (about 2 ms on 100,000 rows) where a numeric
key reads one. 2.9 added to the head of each piece a Bloom filter of its text
keys (`filtro`, see [Index files](#index-files-usersidxauto_idjson-usersidxauto_idpart2json-)),
so a lookup reads the first bytes of every piece and the keys of only the one
where the key can be. Measured against 2.8.0: by email on 60,000 rows
1.10 → 0.58 ms, on 300,000 rows 9.1 → 4.9 ms; an `INSERT` with a unique email
on 300,000 rows 26.5 → 17.2 ms. The filters of an index are also kept together
in the cache, with the revision of each piece, by the first lookup that reads
them all and by every write: with them, a lookup by email or by a spread
number on a million rows opens one piece instead of the head of a thousand
(from 30 ms to about 3 ms more than a lookup by key). The filter is the one
part of the data, structure and index files that is not readable by eye (the
temporary files of `.orden/` aside, see
[06-format.md](06-format.md#11-control-files-cache-and-temporary-files)); it lives in the index, which is rebuilt from the rows,
never in the data. An index split by a hash of the key instead of by position
would also cut the reads, at the price of changing how indexes are stored and
rewriting more of them on a `DELETE`: not done.

---

## 12. Upgrading from an earlier version

### Compatibility policy

From 2.7.2 on, these are promises, not just what has happened so far:

- **The on-disk format of `data/` is stable for the whole 2.x series.** A later
  2.x version can *add* keys to the files (as 2.7 added `rangos`, `offsets` and
  `autoinc`), and fills them in as tables are written; it never stops reading
  data written by an earlier 2.x. Indexes are worked out from the rows: a
  version may change how it builds them, as 2.10 did for `TEXT` columns, and
  then rebuilds the older ones itself. The files stay JSON a person can read.
- **An incompatible change means 3.0**, announced in the changelog at least one
  minor version in advance, with a migration tool in the same release.
- **The SQL accepted, the API protocol (fields and HMAC signature) and the
  configuration files** follow the same rule: within 2.x, what works keeps
  working. A `config.php` from an earlier version keeps working with default
  values for the options added since.
- **The `Database` class of the direct connection** (stated since 2.10): its
  functions — `new Database()`, `consultar()`, `consultarPorFilas()`,
  `lote()`, `transaccion()`, `Database::crear()`, `Database::bases()` and the
  rest shown in this documentation — keep their names, what they take and what
  they return, and their errors stay `JsonSqlDbError` with its `sqlState`.
  Should a compelling reason force a change, it is documented and announced in
  the changelog before the version that brings it.
- **The way out does not depend on this project.** The panel's SQL dump loads
  unchanged into SQLite, and the data files can be read by anything that reads
  JSON.

What this does not promise: support for old versions. Only the latest release
receives fixes (see [SECURITY.md](../SECURITY.md)), so upgrading within 2.x is
always the way to get one.

### How to upgrade

Replace the folder and keep your two configuration files
(`api/jsonsqldb_api_config.php` and `jsonsqldbadmin/config.php`, both
gitignored and not shipped), and with them whatever lives inside the folder:
`data/`, `logs/` and `jsonsqldbadmin/datos/` when their paths are the
defaults. The root `config.php` ships with its defaults: carry over any
setting you changed (`JSONSQLDB_DATA_PATH`, `JSONSQLDB_CONEXION_DIRECTA`…).

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
| index pieces without `filtro`, `min`, `max` (pre-2.9) | read whole, as before; each piece gets them when it is next written, and `VACUUM` rebuilds every index that has such a piece, without touching the data: on 20,000 customers written by 2.8.0, a lookup by a unique email 0.49 → 0.31 ms |
| `rev.json` listing a part that is gone (2.8) | 2.8 kept the revision of a last part that a `DELETE` emptied and removed; that entry is ignored, and the next write drops it |
| cache entries under the old keys (pre-2.9) | ignored: 2.9 uses keys of its own (`jsq2:`), since its entries carry the seal of their file. Deleting `.cache/` after upgrading frees the space |
| API state in one `logs/api/estado.json` (pre-2.9) | spread over small files in `logs/api/estado/`; the old file's tokens are still refused while they could be valid, then it is deleted, and per-IP counters start from zero |
| `_database.json` without `esquema` (pre-2.10) | counts as 0; the first table created, dropped or renamed writes it |
| cache entries with a fixed name, without the line in front (pre-2.10) | a miss: they are written again with it |
| keys of indexes on a `TEXT` or a decimal column (pre-2.10), where a text that was a number went to that number's key, and a whole decimal from 9·10^18 to 2^63 to a decimal's | not used: the table is read instead, and the index is rebuilt once with 2.10 keys right after that query (logged as `VACUUM -- automático`), on the next write to the table, or with `VACUUM`; `rev.json` then says so in `textos` (see [06-format.md](06-format.md)). The other indexes (integers, dates) are kept as they are |

Revision numbers **carry on from where they were** rather than restarting, so a
cache entry from before the upgrade cannot be mistaken for a current one. The
old `_revs.json` is left alone and stops being read.

2.9 also adds to the files, on their own when they are written: `huecos` in
`rev.json` (the holes of deleted rows per part), and `gen` in the `rev.json`
of a table touched by a transaction that was undone, which changes its cache
keys. Earlier versions ignore both fields. 2.10 adds `textos` to `rev.json`,
`esquema` to `_database.json`, a line in a table's `.lock` while it is written
and a line in front of the cache entries with a fixed name, and earlier
versions ignore all of them (a cache entry they cannot read is a miss for
them); but they read the keys of a 2.10 index on a `TEXT` or a decimal
column with their own rule, and miss rows (see below).

### Going back to an earlier version

Not supported: a database written by a newer version is not meant to be opened
by an older engine, and no test checks it. If you must go back from 2.10:

- **Delete the index files of every index with a `TEXT` column**
  (`<table>.idx.<index>.json` and its `.partN.json`): 2.9 would look up
  `'007'` under the key of 7 and find nothing. The same with an index on a
  `REAL`, `DOUBLE` or `DECIMAL` column that holds whole numbers from 9·10^18
  to 2^63 (or as negative): 2.9 looks them up under a decimal's key. Without them it reads the
  table, and rebuilds them with its own keys on the next write (checked with
  2.9.0). Remember that 2.9 compares a text that is a number as that number
  (`'7' = '007'`), and that a `UNIQUE` text column may now hold both.
- **Coming back to 2.10 after creating tables with 2.9, run `VACUUM`**: 2.9
  does not count them in `_database.json`, and until then the foreign keys of
  the others may not see them (see [§3](#3-concurrency-two-lock-levels)).
  Checked with 2.9.0 and 2.10 taking turns on the same database: the same
  rows, `CHECK KEYS`, `CHECK TABLES` and `CHECK INDEXES` clean.

And from 2.9:

- **Run `VACUUM` first.** 2.8 or earlier cannot read a table with holes of
  deleted rows: they stop with an error on the first scan rather than return
  wrong rows. `VACUUM` rewrites every table that has any without them.
- **A `COLLATE NOCASE` or `RTRIM` column has to lose it** (`ALTER TABLE …
  MODIFY` without it): earlier versions compare it as binary, and its indexes,
  built with the collation, would miss rows there.
- **`CHECK` constraints and `ENUM` columns are not enforced** by earlier
  versions: the data reads the same, but they would let a row that breaks them
  be written.
- **Views and triggers are stored as their SQL**, so one written with SQL that
  only 2.9 accepts (a window function, `UPDATE … FROM`, a new function) is an
  error there when it runs.

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
| `engine/TypesCast.php` | the less usual conversions: `CAST(… AS type)` and the values of an `ENUM` or `UNSIGNED` column (2.10; in `Types` before) |
| `engine/Storage.php` | JSON files, locking, parts, cache, index files: what reads and writes share |
| `engine/StorageLectura.php` | what only reads do: counting rows, rows by an index, the whole parts an `OFFSET` skips, the result cache (2.9) |
| `engine/StorageEscritura.php` | writing rows: atomic writes, redo journal, parts and index pieces on disk, the cache a write leaves behind (2.9) |
| `engine/StorageEntera.php` | writing a whole table, from memory or from the disk: a write that loaded it, the structure changes and the repairs (2.10; in `StorageEscritura` before) |
| `engine/StorageCambios.php` | changing or removing rows already written (`UPDATE`, `DELETE`), part by part (2.9) |
| `engine/StorageCompactacion.php` | compacting a table by blocks of text, without decoding the rows that do not change: a `DELETE` or an `OR REPLACE` that leaves more than 25 % of holes, `VACUUM` and the structure changes that rewrite the table (2.10; in `StorageCambios` before) |
| `engine/StorageMantenimiento.php` | creating, renaming and dropping tables and databases, structure changes by blocks of text, transactions, recovery after a crash, repairs (2.9) |
| `engine/StorageRangos.php` | reads by a comparison on an index (`<`, `>`, `BETWEEN`…) that the range kept for each part cannot settle, and an index's smallest and largest value (2.9) |
| `engine/StorageOrden.php` | reads in the order of an index: the first rows of an `ORDER BY … LIMIT` (2.10) |
| `engine/StorageOrdenTexto.php` | the same by an index on a text column (2.10; in `StorageOrden` before) |
| `engine/StorageMayores.php` | the largest values of a numeric index without reading rows, from the range of each piece or from its keys: the next id of an integer primary key without `AUTOINCREMENT` (2.10) |
| `engine/StorageBusquedas.php` | lookups by the first columns of a composite index whose later columns admit `NULL`, and by prefix (2.10) |
| `engine/StorageCompartida.php` | what is kept for other processes: a table's state in APCu or in a `.php` for OPcache, and the parts as `.php` (2.10) |
| `engine/StorageBarrido.php` | the temporary files of a write that died (2.10) |
| `engine/Catalog.php` | tables, columns, PK/UNIQUE/FK, indexes, triggers, autoincrement: what queries need |
| `engine/CatalogDdl.php` | `CREATE`, `ALTER` and `DROP` of tables, indexes, views and triggers, and the autoincrement (2.9); called directly (2.10: `Catalog` had a method of the same name for each) |
| `engine/Indexes.php` | index keys and definitions, and what reads and writes share about them |
| `engine/IndexesBusqueda.php` | which conditions of a `WHERE` an index can resolve, and with which index (2.9) |
| `engine/IndexesEscritura.php` | what keeps an index up to date when writing: the summary of each piece (filter, range, extremes), its keys by position, the keys that come, change or go with the rows (2.10) |
| `engine/Memoria.php` | the watchdog that stops a query before PHP's fatal error |
| `engine/OrdenExterno.php` | sorting on disk for an `ORDER BY` that does not fit in memory (2.9) |
| `engine/Volcado.php` | rows in temporary files, for the rest of what does not fit in memory: `JOIN`, `GROUP BY`, subqueries, `WITH`, windows (2.9) |
| `engine/Reparador.php` | repairs a JSON file broken by a hand edit, only where the fix is certain (2.9); used by `Storage` |
| `engine/ConflictoPartes.php` | internal signal: a write by part found its parts changed and is retried with the table's lock; never reaches the user |
| `tests/f1_nucleo.php` | 76 core checks (leaves nothing on disk) |

Everything else in the project is built on what this document describes:

- the parser and the `SELECT` executor — [02-queries.md](02-queries.md)
- writes, DDL, keys and triggers — [03-writes.md](03-writes.md)
- the signed HTTP API — [04-api.md](04-api.md)
- the `jsonSQLDBadmin` panel — [05-admin.md](05-admin.md)
