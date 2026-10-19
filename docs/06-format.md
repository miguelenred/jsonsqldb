# On-disk format (reference)

The exact shape of every file the engine keeps, as written by 2.10, for anyone
who reads them with another tool, writes a backup script or checks them by
eye. Why each file is the way it is, and what it costs, is explained in
[01-core.md](01-core.md#1-layout-on-disk) (layout), [§6](01-core.md#6-durability-atomic-files-and-the-journal)
(journal) and [§5](01-core.md#5-indexes) (indexes); this page only states the
format. The examples are files the engine wrote, except the journal's, which
only lives for an instant. Their lists are shown on one line; on disk, in every
file but the rows and the index pieces, each list is indented, one value per
line.

---

## 1. The folder of a database

One folder per database inside the data root (`JSONSQLDB_DATA_PATH`):

| Entry | What it is | If it is missing |
|---|---|---|
| `_database.json` | marks the folder as a database: `SHOW DATABASES` lists only folders that have it | the folder is not a database |
| `_views.json` | the views | no views |
| `_revs.json` | the revisions of all tables, written before 2.0 | normal; only read for a table without `rev.json` |
| `<t>.meta.json` | the structure of table `<t>`: **a table exists if and only if this file exists** | no table |
| `<t>.rev.json` | the revision and state of its parts | worked out from the data, and written again on the next write |
| `<t>.json`, `<t>.part2.json`, `<t>.part3.json`, … | the rows, part 1, 2, 3… | an error that names it, if `rev.json` counts that part (2.9; before, the table ended there) |
| `<t>.idx.<i>.json`, `<t>.idx.<i>.part2.json`, … | index `<i>`, one piece per part | rebuilt on the next write; until then that index is not used |
| `.htaccess`, `web.config` | deny web access, written when the database is created | nothing, unless the folder is reachable from the web |
| `.lock`, `.<t>.lock`, `.turno`, `.<t>.turno` | files for `flock()`, empty; while a write to table `<t>` is under way, `.<t>.lock` holds one line that says so, `escribiendo: pid 1234 desde 2026-10-13 15:04:05`, removed when it ends (2.10, §1.1) | created again |
| `.tx/` | the journal of a write in progress (§8) | normal |
| `.lote/` | the originals kept by a transaction in progress (§8) | normal |
| `.reparados/` | originals of repaired files and rows set aside (§9) | normal |
| `.orden/` | temporary files of what does not fit in memory in a query (an `ORDER BY`, a `JOIN`, a `GROUP BY`, a subquery…), only while it runs (§1.1) | normal |
| `.cache/` | cache: not part of the format, it changes between versions (§1.1) | regenerated; safe to delete at any time |
| `*.<pid>.tmp` | a file being written; a process killed at the wrong moment can leave one | swept by a later write to its table (§1.1) |

**Names.** A database: `[A-Za-z0-9_-]{1,64}`. A table, view, index or trigger:
`[A-Za-z_][A-Za-z0-9_]{0,63}`; tables and views share one namespace. A column:
1 to 64 characters, not all blank, no control characters (2.8); it is never
part of a file name. Reserved for tables and views (2.9): `_database`,
`_views`, `_revs` and `_base`. The `auto_` prefix is reserved for automatic
indexes.

**Automatic indexes.** Every `PRIMARY KEY` and `UNIQUE` has an index named
`auto_` plus its columns joined by `_` (`auto_id`, `auto_a_b`). If that is not a
valid name (a column with spaces or signs, or too long), it is `auto_` plus the
MD5 of the lower-case column names joined by `,`. Two keys whose index would get
the same name are refused (2.9).

### 1.1. Control files, cache and temporary files

All of it is text a person can read, like the rest of the folder, except the
lengths in `.orden/`:

- **The line in `.<t>.lock`** (2.10). A write to the table puts it there
  before touching any file and empties the file when it ends, if the line is
  still its own. A write that finds a line left there knows that the process
  that wrote it died half-way, unless that process is still alive (or, when
  that cannot be asked, the line is less than a minute old), and then removes
  the temporary files of dead processes. The `flock()` that serializes the
  writers is the same as before; the line only says who is writing.
- **`*.<pid>.tmp`**: the name carries the process that writes it. Besides the
  case above, a write removes those of its table whose process no longer
  exists once every 32 revisions; an operation that takes the whole database
  (DDL, a transaction) removes them all.
- **`.cache/`**: each entry is a file with the PHP `serialize()` of its value.
  Those with a fixed name, which are written over in place instead of being
  replaced (2.10: replacing a file costs the disk far more), start with a line
  `jsonsqldb-cache <length> <crc32> <crc32c>` with the length of what follows
  and two checksums of it, so that one caught half-written is never read: it
  only counts as a miss.
- **`.orden/`**: each file is a run of values, each with its length in four
  bytes in front and its PHP `serialize()`; they live only while the query
  that needs them runs, and one a killed process left is removed by a later
  query after an hour.

---

## 2. Encoding

- UTF-8, without a byte order mark (one put there by an editor is removed when
  the file is read, 2.9).
- JSON as written by PHP's `json_encode()` with `JSON_UNESCAPED_UNICODE`,
  `JSON_UNESCAPED_SLASHES` and `JSON_PRESERVE_ZERO_FRACTION`: accents as they
  are, `/` without a backslash, and a decimal number always with its fraction
  (`2.0`, not `2`).
- `_database.json`, `_views.json`, `<t>.meta.json`, `<t>.rev.json` and the
  manifests are indented with 4 spaces; data parts have the layout of §4;
  index pieces are one line.
- Every JSON file ends with a line break.
- **A file of the database is not changed in place.** The engine writes
  `<file>.<pid>.tmp`, forces it to disk and renames it over the old one; when
  a write changes more than one file, a journal makes them change together
  (§8). The exceptions: the line in a `.lock` and the cache entries with a
  fixed name (§1.1), which are not data; and, inside a transaction (2.10), a
  file the transaction has already replaced once, whose original is kept in
  `.lote/` (§8): the next statements write it in its place, and committing
  forces it to disk.

---

## 3. `_database.json` and `_views.json`

```json
{
    "database": "mydb",
    "engine": "jsonSQLDB",
    "version": 1,
    "created_at": "2026-10-08 13:47:12",
    "esquema": 4
}
```

`version` is always `1` and is not read. `esquema` (2.10) counts the tables
created, dropped or renamed: the statement that does it rewrites this file,
and puts it in place after its other files and deletions, so that whoever
reads the new number finds the tables as they are.
A write that needs the list of tables (to see which ones point to the one it
changes) keeps it with the size, date and inode of this file and this number,
and only lists the folder again when they change, when a table on the list is
gone, or once every 32 writes of the table, for a table copied in by hand or
made by an earlier version. Missing, it counts as `0`.

```json
{
    "v": {
        "sql": "SELECT id, x FROM t",
        "created_at": "2026-10-08 13:47:12"
    }
}
```

One key per view: its name, and the `SELECT` as it was written.

---

## 4. Data parts (`<t>.json`, `<t>.partN.json`)

Byte for byte, a part is:

```
{
  "table": "h",
  "rows": [
    {"id":4,"x":"d"},
    null,
    {"id":6,"x":"f"}
  ],
  "offsets": [34,56,66],
  "offsets_at": 88
}
```

- Lines are separated by `\n`; the indents are 2 and 4 spaces as shown.
- **One row per line**: a JSON object with every column of the structure, in
  its order; or `null`, the hole of a deleted row (2.9). Deleting the last
  rows shortens the table instead of leaving holes at its end.
- With no rows, `"rows": [],` and `"offsets": [],` on one line each.
- `offsets` (2.7): for each line of `rows`, holes included, the byte, counted
  from the start of the file, where it starts (the `{` or the `n` of `null`).
  `offsets_at`: the byte where the `  "offsets"` line starts. Parts written
  before 2.7 have neither, and are decoded whole. If they do not match the
  text, the engine takes the file as edited by hand (§10).

**Positions.** Every line has a position, counted from 0 at the start of the
table: part *k* holds positions (*k* − 1) × `chunk` to *k* × `chunk` − 1, where
`chunk` is the one in `rev.json`, and **every part but the last has exactly
`chunk` lines**, holes included. Index pieces point to rows by this position.
Tables written before 2.2 have no `chunk`: their lines are counted one after
another across the parts.

**Values.** `NULL` is `null`. The rest, by the type in the structure:

| Type (and its aliases) | Stored as |
|---|---|
| `INTEGER` (`INT`, `BIGINT`, `BOOLEAN`, `BIT`, `YEAR`…) | JSON integer; `true`/`false` are stored as `1`/`0` |
| `DOUBLE` (`REAL`, `FLOAT`…) | JSON number with a fraction or exponent (`2.0`), with the shortest digits that give the same number back (`0.30000000000000004`, `0.1`), whatever `serialize_precision` says (2.10) |
| `DECIMAL` (`NUMERIC`, `MONEY`…) | JSON number rounded to the column's `scale` (`3.0`, `2.25`) |
| `TEXT` (`VARCHAR`, `CHAR`, `JSON`, `BLOB`, `UUID`…) | JSON string; a `JSON` column keeps its text (`"{\"a\":1}"`) |
| `DATETIME` (`DATE`, `TIMESTAMP`) | string `yyyy-MM-dd`, `yyyy-MM-dd HH:mm`, `yyyy-MM-dd HH:mm:ss` or `yyyy-MM-dd HH:mm:ss.fff`, with the precision it was given; a `T` separator is written as a space |

An `ENUM` column is `TEXT` and holds one of its values as written in the
list.

---

## 5. Structure (`<t>.meta.json`)

```json
{
    "table": "t",
    "columns": [
        {"name": "id", "type": "INTEGER", "notnull": true, "pk": true, "autoincrement": true},
        {"name": "n", "type": "INTEGER", "unsigned": true},
        {"name": "d", "type": "DECIMAL", "scale": 3},
        {"name": "h", "type": "DATETIME", "default_expr": "CURRENT_TIMESTAMP"},
        {"name": "nombre", "type": "TEXT", "length": 50, "collate": "NOCASE", "unique": true},
        {"name": "e", "type": "TEXT", "default": "a", "enum": ["a", "b"], "notnull": true}
    ],
    "unique": [{"name": "uq_t_a_b", "columns": ["a", "b"]}],
    "foreign_keys": [
        {"name": "fk_t_c_id", "columns": ["c_id"], "table": "c", "references": ["id"],
         "on_delete": "CASCADE", "on_update": "NO ACTION"}
    ],
    "triggers": [
        {"name": "tg", "timing": "AFTER", "event": "INSERT", "when": null,
         "body": ["UPDATE c SET nombre = nombre WHERE id = NEW.c_id"],
         "sql": "CREATE TRIGGER tg AFTER INSERT ON t BEGIN UPDATE c SET nombre = nombre WHERE id = NEW.c_id; END"}
    ],
    "indexes": [{"name": "ix_x", "columns": ["x", "n"]}],
    "checks": [{"name": null, "sql": "n >= 0"}, {"name": "ck_t", "sql": "r < 100"}],
    "autoincrement": {"column": "id", "next": 1},
    "created_at": "2026-10-08 13:47:12",
    "updated_at": "2026-10-08 13:47:12"
}
```

(Columns of two tables put together, and shown compact: on disk each object
and list is indented.) A column carries only the keys that have a value, and
the table only the lists that are not empty.

| Column key | Meaning |
|---|---|
| `name`, `type` | always; `type` is one of `INTEGER`, `DOUBLE`, `DECIMAL`, `TEXT`, `DATETIME` |
| `length` | `TEXT`: maximum length in characters |
| `scale` | `DECIMAL`: decimals (2 if not declared) |
| `notnull`, `pk`, `autoincrement`, `unique` | `true` when they apply; each column of a composite primary key has `pk` |
| `default` | a fixed default, already in the column's type |
| `default_expr` | a default worked out on each insert (2.8): `CURRENT_TIMESTAMP` or an expression |
| `unsigned` | numeric column that refuses negatives (2.9) |
| `collate` | `NOCASE` or `RTRIM` (2.9); without it, `BINARY` |
| `enum` | the allowed values (2.9) |

| Table key | Meaning |
|---|---|
| `unique` | `UNIQUE` keys declared at table level, named, or made with `CREATE UNIQUE INDEX` |
| `foreign_keys` | `on_delete` and `on_update`: `NO ACTION`, `RESTRICT`, `CASCADE`, `SET NULL` or `SET DEFAULT` |
| `triggers` | `timing` `BEFORE` or `AFTER`; `event` `INSERT`, `UPDATE` or `DELETE`; `when` an expression or `null`; `body` the statements in order, each a text, or an object for an `IF` block (`{"si": [[condition, [statements]], …], "sino": [statements] or null}`); `sql` the statement as written |
| `indexes` | indexes made with `CREATE INDEX` |
| `checks` | `CHECK` constraints (2.9); one without a name (written in a column, or at table level without `CONSTRAINT name`) has `name` `null` |
| `autoincrement` | the column, and the next value at creation or at the last version before 2.7 (since 2.7 the counter is `autoinc` in `rev.json`, and the larger of the two wins) |
| `created_at`, `updated_at` | dates of creation and of the last change of structure |

---

## 6. Table state (`<t>.rev.json`)

```json
{
    "rev": 3,
    "chunk": 3,
    "rows": 10,
    "creada": 1952441877,
    "parts": [2, 3, 2, 2],
    "indexes": {"auto_id": [2, 3, 2, 2], "ix_nombre": [2, 3, 2, 2]},
    "rangos": {"auto_id": [[1, 3], [4, 6], [7, 9], [10, 10]], "ix_nombre": [null, null, null, null]},
    "huecos": [0, 1, 0, 0],
    "textos": {"ix_nombre": 3},
    "gen": 1
}
```

| Key | Since | Meaning |
|---|---|---|
| `rev` | 2.0 | goes up by one on every write to the table |
| `chunk` | 2.2 | lines per part |
| `rows` | 2.5 | lines of the table, holes included; the rows are `rows` minus the holes |
| `creada` | 2.6 | random number fixed at the table's first write, so that a table dropped and made again with the same name is never taken for the old one |
| `parts` | 2.5 | the revision at which each part was last written. 2.8 could leave the revision of a last part that a `DELETE` emptied and removed: an entry beyond the parts that `rows` fills is ignored |
| `indexes` | 2.6 | for each index, the revision at which each piece was last written (2.5 wrote one number per index) |
| `rangos` | 2.7 | for each index and piece: `[min, max]` of the first indexed value when every key of the piece has a number there; `[]` for a piece with no keys; `null` when some key has a text there |
| `huecos` | 2.9 | holes of deleted rows in each part; only there when some part has any |
| `textos` | 2.10 | for each index with a `TEXT` or a decimal (`REAL`, `DOUBLE`, `DECIMAL`) column, the revision of the table at which its pieces were last written with the keys of 2.10 (§7): a text, also one that is a number, as a text key, and a whole decimal up to 2^63 as an integer. An index whose number is missing, is above `rev`, or is below the revision of one of its pieces (a piece rewritten by an earlier version) has keys of an earlier version: it is not used, and the engine rebuilds it once the query is over (logged as an automatic `VACUUM`) or on the next write to the table |
| `autoinc` | 2.7 | next `AUTOINCREMENT` value |
| `gen` | 2.9 | only after a transaction that touched the table was undone, or after the engine rebuilt a `rev.json` it could not read: it changes every cache key of the table |

Missing keys are read as absent (files of 2.2–2.4 have only `rev` and
`chunk`, those of 2.0–2.1 only `rev`) and filled in on the next write. A part
or piece whose revision is the one written here is current.

---

## 7. Index pieces (`<t>.idx.<i>.json`, `<t>.idx.<i>.partN.json`)

One piece per part of the table, each with the keys of the rows of that part,
on one line, and `keys` always last:

```json
{"index":"ix_x","table":"t","columns":["x","n"],"part":1,"rev":5,"chunk":3,"filtro":"iAhEBCICERE=","cubre":"texto","min":"Hola","max":"z","keys":{"t4:Holan1:5":0,"t1:zn1:8":2}}
```

- `part`, `rev`, `chunk`: the part it covers, the revision it was written at,
  and the table's `chunk`.
- `keys`: key → position (§4) as an integer, or a list of positions when
  several rows share the key (2.5). A piece with no keys has `"keys":{}`. A row
  with `NULL` in any indexed column is not in the index.
- **Key.** Each indexed value, in the order of `columns`, as a type letter, its
  length in bytes, `:` and the value, all joined: `n` for a number (`n1:5`,
  `n4:2.25`; an integral decimal is written as an integer, so `5` and `5.0` are
  one key, up to 2^63: 2.10, until 2.9 up to 9·10^18, and `9.1e18` was
  `n7:9.1E+18`) and `t` for a text (`t4:Hola`). A text is a text key also when it
  is a number (`t3:007`, 2.10; until 2.9 it was the key of that number, so
  `'7'`, `'007'` and `7` shared one), and `true`/`false` are `1`/`0`. What is
  looked up goes with the affinity of the column, as in SQLite: `7` in a
  `TEXT` column is `t1:7`, `'7'` in an `INTEGER` one `n1:7`. A value of a
  `NOCASE` or `RTRIM` column is folded first (`t3:ana` for `Ana` in a
  `NOCASE` column).
- `filtro` (2.9): Bloom filter of the keys the piece covers, in base64; `""`
  if there are none. It is the one value in these files that a person cannot
  read: a field of bits, written as text. With *n* covered keys it has *m* =
  max(64, ⌈10 *n* / 32⌉ × 32) bits when the piece is written whole; a piece
  that only gains keys (2.10) keeps its filter and sets the bits of the new
  ones while it has 10 bits per key, and otherwise gets a new one with room
  for a quarter more keys (*n* + ⌊*n* / 4⌋ in that formula). To look a key up,
  *m* is the length of the filter in bits. They are packed as 32-bit
  little-endian words (bit *b* is bit *b* & 7 of byte *b* >> 3). Each key sets
  7 bits: (*h1* + *i* × *h2*) mod *m* for *i* = 0…6, with *h1* = `crc32(key)`
  and *h2* = `crc32(reverse(key)) | 1`, unsigned 32-bit.
- `cubre` (2.9): what the filter covers, right after it: `"texto"`, only the
  keys whose first value is a text; `"todo"`, the numeric keys too. Numbers
  are covered when the piece's numeric range spans at least 8 times its part
  size (values spread over the table, which the range does not narrow: the
  customer of each order). Absent when `filtro` is `""`.
- `min`, `max` (2.9): only when the first value of every key is a text: the
  smallest and largest of those values (after folding), compared byte by byte.
- A write that only removes keys from a piece (a `DELETE` that leaves holes)
  keeps its `filtro`, `cubre`, `min` and `max` as they were: they may still
  cover keys that are gone (a piece with `"keys":{}` can carry a filter), but
  never miss one. The next write that adds or changes keys in that piece works
  them out again.
- Pieces written before 2.9 have no `filtro`, `min` or `max`, and are read
  whole; `VACUUM` rebuilds the indexes that have any such piece. Indexes written before 2.6 were one file with every key, and before
  2.5 always with lists; they are read as they are.

---

## 8. Journal (`.tx/`) and transactions (`.lote/`)

**`.tx/<scope>.json`**: a write that changes more than one file, while it
puts them in place. `<scope>` is the table whose lock it holds, or `_base` with
the database's exclusive lock:

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

If one is there when the database is opened, the write is finished: each
temporary is renamed to its file and each file in `borrar` deleted (redoing it
twice is the same as once); a missing temporary listed in `regenerables` (an
index piece) is skipped. A manifest with `estado` instead of `tipo` is the
undo journal of 2.4 and earlier, and `.tx/<scope>/manifiesto.json` that of 2.5
and 2.6: both are still applied. A statement inside a transaction writes none
(2.10): `.lote/`, below, already undoes it.

**`.lote/`** (2.9): while a transaction runs, the original of every file it
replaces or deletes, under the same name (a hard link, or a copy forced to disk
where links are not allowed), and `manifiesto.lote` (not `.json`: a table may
be called `manifiesto`, and its saved copy would have the same name):

```json
{
    "guardados": ["h.part2.json", "h.rev.json", "h.idx.auto_id.part2.json"],
    "nuevos": ["h.part3.json", "h.idx.auto_id.part3.json"],
    "ts": "2026-10-08 13:51:49"
}
```

If the manifest is there when the database is opened, the transaction is
undone: each file in `guardados` goes back to its place, each one in `nuevos`
is deleted, and each table touched gets a new `gen`. A `.lote/` without a
manifest was committed and is only deleted. Once a statement has put a file
in place (its rename succeeded), the file under that name is no longer the
original, so from the next statement on the transaction writes it in its place
instead of renaming a temporary over it (2.10, on PHP 8.1 and later, up to
1 MB per statement, never `_database.json`). A file whose rename failed is
still the original, linked from `.lote/`, and is replaced as always. Whatever
a file written in place holds if the process dies, the original comes back.

---

## 9. Repairs (`.reparados/`, 2.9)

- `<file>.<yyyyMMdd-HHmmss>`: the original of a file the engine repaired, as
  it was.
- `apartadas.json`: the rows `REPAIR TABLES` set aside, until they are put
  back or forgotten (`REPAIR TABLES FORGET n`):

```json
{
    "siguiente": 2,
    "filas": [
        {"id": 1, "tabla": "t", "fichero": "t.json", "linea": 7, "texto": "{\"id\":4,\"nombre\":\"N4,\"ciudad\":\"C4\"},", "fecha": "2026-10-08 13:51:28"}
    ]
}
```

`linea` counts from 1 at the start of the file. `siguiente` is the number the
next one will get: numbers are never reused.

---

## 10. Reading and changing the files from outside

- **Reading** needs only §4 and §5: decode each part in order, skip the
  `null` lines, and take each value by the column's type. `rev.json`, the index
  pieces and `.cache/` can be ignored.
- **Editing a data part by hand** is allowed (2.9): every cache entry carries
  the size, date and inode of its file, and a write leaves for each part it
  rewrites those and a fingerprint of its text, so the next query that reads
  the part sees the change, even one that keeps every line's length, and the
  engine rewrites the table so that its offsets, index pieces and revisions
  match it. Small breakages of the JSON are repaired on their own;
  see [Editing the files by hand](01-core.md#editing-the-files-by-hand-29) for
  what is and what is not, and for the edits that cannot be told apart (same
  second as a write of the engine, same size, same inode; or one that keeps
  every line's length once the cache has lost that part's entry).
- **Do not write `rev.json` or index pieces**: they are derived from the data.
  If one is damaged, the engine rebuilds it.
- **Copying a database**: copy its whole folder while nothing writes to it;
  `.cache/` can be left out. A copy taken in the middle of a write can hold
  half of it. The panel's ZIP backup and SQL dump do this for you.
- **Going back from 2.10 to 2.9**: delete first the index files of every index
  with a `TEXT` column, whose keys 2.9 reads with its own rule; see [Going back
  to an earlier version](01-core.md#going-back-to-an-earlier-version).
- **Going back to a version before 2.9**: run `VACUUM` first, which removes the
  holes; see [Going back to an earlier version](01-core.md#going-back-to-an-earlier-version).
