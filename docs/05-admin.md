# 05 — jsonSQLDBadmin (administration panel)

A web panel to administer jsonSQLDB from the browser. Pure PHP, no Composer and
nothing from outside: Bootstrap, the stylesheet and the icons (inline SVG, no
icon font) are in `jsonsqldbadmin/`, and since 2.9 it does not need PHP's
`mbstring` either. Light and dark theme, remembered in each browser; in Spanish
and English (section 6).

What is new since 2.7.1, and where it is described:

| Since | What | Section |
|---|---|---|
| 2.7.1 | The configuration can be changed from the Configuration page | 7 |
| 2.7.1 | Sessions end when a user is deleted or their password changes | below |
| 2.7.2 | Spanish and English, following the browser, saved per user | 6 |
| 2.7.2 | SQL dumps for SQLite, MySQL / MariaDB, PostgreSQL and SQL Server | 3 |
| 2.7.2 | Import of dumps from those four engines | 3 |
| 2.7.2 | Warning when the data folder can be downloaded from outside | 7 |
| 2.7.3 | Guide to making the dump of each engine; UTF-16 SQL Server scripts | 3 |
| 2.7.3 | CSV cells that a spreadsheet would run as formulas are neutralised | 3 |
| 2.7.3 | Signing out is a POST with its token; failed sign-ins counted under a lock; `Secure` cookie behind a trusted proxy | 5 |
| 2.8 | Exports and imports with no size limit (batches, upload in pieces, import folder); `ADMIN_EXPORT_MAX` is gone | 3.1 |
| 2.8 | Excel (.xlsx) into a table | 3.1 |
| 2.8 | Scheduled backups, from cron or from the panel | 3.2 |
| 2.9 | Only administrators export and dump | 2, 3 |
| 2.9 | JSON and Excel exports, JSON import, a whole database as one JSON document, and a window with import examples and templates | 3 |
| 2.9 | The SQL console runs several statements as one transaction | 3 |
| 2.9 | The Integrity tab also checks and repairs the files, and keeps the rows set aside until they are fixed | 3 |
| 2.9 | `COLLATE`, `CHECK` and `ENUM` in the Structure tab, the imports and the dumps | 3 |
| 2.9 | A PowerShell script that dumps a SQL Server database and loads a dump into one | 3 |
| 2.9 | Imports sized to the API's limits per request; an all-or-nothing import runs in one transaction of the engine and no longer undoes what others wrote meanwhile | 3 |

The panel talks to the engine in one of two ways, chosen when it is installed:

```
API:     browser → jsonsqldbadmin/ → api/jsonsqldb_api.php (HMAC) → engine/ → data/
direct:  browser → jsonsqldbadmin/ → engine/ → data/
```

- **Through the API** (`ADMIN_CONEXION = 'api'`): signed requests with bound
  parameters, exactly like any other client. The API can be on another
  machine; the panel follows it by changing one constant.
- **Direct connection** (`ADMIN_CONEXION = 'directa'`, since 2.7): the panel
  loads the engine and calls it without HTTP. Only when the panel and the data
  are on the same machine. There are no keys to configure, and each query
  saves an HTTP request — which, on the same host, is most of what a small
  query costs. It works also with the API turned off
  (`JSONSQLDB_API_ACTIVA = false` in the engine's `config.php`, 2.9), and
  without `JSONSQLDB_CONEXION_DIRECTA`: the panel allows itself.

With either, **the engine applies the role of the panel user**: a read-only
user cannot write even if a page of the panel failed to check, because the
statement is refused before it runs — by the API key with read permission in
API mode (if you set one, see «Read-only key» in section 3), and by the same list of allowed
statements in direct mode. `tests/f11_asistente.php` checks the direct-mode
case by calling the engine the way the panel does, bypassing the pages, and
fails if that check is removed.

The panel does not read or write the data files itself, except for the ZIP
backup and restore (section 3), which need the files exactly as they are on
disk. An import on the same machine runs in a transaction of the engine, which
the panel loads for it (section 3). Before exporting a ZIP, the panel asks the engine about that
database with the credentials of whoever asks, so a key limited to other
databases cannot take it.

**Sessions follow the stored user** (2.7.1): on every request the panel checks
that the user still exists with the same password and takes their role from
what is stored. Deleting a user or changing their password ends their open
sessions; changing your own password keeps the session you are using, and
asks for the current one (2.9), with the same lockout as signing in.

**The panel uses the engine's time zone** (2.9) when the engine is on the same
machine: the one its `config.php` sets with `date_default_timezone_set('…')`.
Before, the audit trail and the scheduled backups used `php.ini`'s, except in
the requests that loaded the engine, and one day's audit could end up in two
files. With the engine on another machine, the panel uses `php.ini`'s.

**Before signing in**, the panel shows neither the exact PHP version nor full
paths of the server (2.9): the setup wizard, which anyone can see until it is
finished, shows its paths from the project folder.

## 1. Installation

1. Upload the project, `jsonsqldbadmin/` included.
2. Open `https://yourserver/jsonsqldb/jsonsqldbadmin/`. While the panel has no
   `config.php`, **what you get is the setup wizard**, and nothing else is
   served until it finishes:
   - **Connection**: direct (suggested when the engine is found next to the
     panel) or through the API. For the API you give its URL (empty = the one
     of this installation) and the key and HMAC secret of its administration
     account; if the API of this same installation is not configured yet, the
     wizard can create its configuration with new random keys, the
     administration one being the panel's.
   - **Security**: whether to accept plain HTTP. Only for testing on your own
     machine; on a server leave it unticked.
   - **Administrator**: user and password (at least 10 characters, bcrypt;
     since 2.9 a password with the null character is refused with its own
     message, which bcrypt cannot take: before, it ended in an untranslated
     PHP error after `config.php` had been written). There is no default
     password and no factory user.

   **The connection is tested before anything is written**: a wrong folder, an
   API that does not answer or a signature it rejects is reported on the same
   screen and no file is created (when the wizard creates the API's
   configuration itself, there is nothing to test yet). Then the wizard writes `config.php` from
   `config.dist.php` — every option keeps its explanatory comment — with
   permissions `0600`, and creates the user.
3. Check that `jsonsqldbadmin/` is writable while you install (for
   `config.php`) and that `jsonsqldbadmin/datos/` stays writable: users and the
   audit trail live there.

If you prefer to configure by hand, `php configurar.php` writes the panel's and
the API's configuration with matching keys; the panel then only asks for the
administrator. It leaves `ADMIN_API_URL` empty, and an empty URL is worked out
on every request from the `Host` header the browser sends (the Configuration
page warns about it): save that page once to have it written down. To **change the connection** later, delete `config.php` and
open the panel: the wizard comes back asking only for the connection, and the
users and audit trail are kept. If you lose access, delete
`jsonsqldbadmin/datos/usuarios.json` and the panel asks for a new
administrator.

**The wizard asks for an installation code** (2.7.3). It is in the file
`codigo-instalacion.txt` of the panel's data folder (`jsonsqldbadmin/datos/`
unless `ADMIN_DATA_PATH` says otherwise), which the web server does not serve;
`php configurar.php` prints it too. Only someone with access to the server can
read it, so a panel published before it is set up can no longer be taken over
by the first visitor who creates the administrator. The code is deleted when
the installation finishes; if the users file is deleted later to recover
access, a new one is made. `configurar.php` only runs from the command line, and
Apache, IIS, nginx and LiteSpeed refuse it over HTTP.

## 2. Panel users

They are independent of the API keys. They are stored in `datos/usuarios.json`
with bcrypt and have nothing to do with the API keys.

| Role | Can |
|---|---|
| `admin` | everything: create and delete databases, tables, columns, keys, triggers, rows and users; import, export and dump |
| `lectura` | see structure and data, and run `SELECT` and `SHOW` from the SQL editor. Since 2.9 it cannot export: the buttons are not shown and the panel refuses the request |

Included protections: session expiry on inactivity (`ADMIN_SESION_MINUTOS`), IP
lockout after repeated failures (`ADMIN_LOGIN_MAX_FALLOS`), CSRF token on every
form, `HttpOnly` + `SameSite=Strict` cookie, and `Secure` automatically when you
come in over HTTPS.

Everything that is done is recorded in `datos/auditoria-YYYY-MM-DD.json`: user,
IP, database, action and detail. It is browsed from the **Audit** tab and purged
on its own after `ADMIN_AUDIT_DIAS` days.

## 3. What it can do

**Databases** — list, create, delete and **export** (administrators only,
2.9). Deleting requires typing the exact name of the database. There are three
ways to export:

- **SQL dump**: a `.sql` with `CREATE TABLE`, the `INSERT`s, the unique and
  foreign keys (at the end, once every table exists), the views and the
  triggers. It is readable and can be re-run statement by statement. It comes
  in five dialects, described below.
- **JSON** (2.9): the whole database as one JSON document, `name.json`, with
  `estructura` (the `CREATE TABLE`s and indexes, as SQL), `tablas` (the rows of
  each table, one per line, parents before children) and `despues` (what has to
  come after the rows: foreign keys of a cycle or of a table to itself, views
  and triggers, as SQL). It is imported back from the page of a database
  (format *Detect* or *jsonSQLDB JSON*), read in pieces of 64 KB, so its size
  does not matter.
- **ZIP copy**: the JSON files exactly as they are on disk, with their folder
  structure. Unzipped into `data/` the database is restored, metadata and
  revisions included. It is the faithful copy. The engine's cache (`.cache/`),
  the temporary files of what a query keeps on disk (`.orden/`) and the lock files are left
  out: they are rebuilt on their own (2.9).

Next to export there is a button to **restore from a ZIP copy**, with the same
conditions: it writes to the engine's disk, so the panel and the API must be on
the same machine, and it first asks the API for the database with the panel's
key, so a key limited to other databases cannot overwrite this one (2.9; an
import that can be undone, because it reaches the database's folder, checks the
same). Before touching anything it sets aside what is there, and if the
restore fails halfway it puts it back.

Only the database's own `.json` files are restored from the ZIP, and from
`.reparados/` the originals kept by a repair and the list of rows set aside
(2.9); anything else is ignored. Since 2.9 that includes a `.htaccess` or
`web.config` in the ZIP, which decide who can download the folder: the
database keeps its own, and gets the engine's if it has none. And anything
under `.cache/`, `.tx/` or `.lote/`: with OPcache the engine loads the `.php`
files of its cache as code, and it would take a journal or a transaction from
the ZIP for a write of its own left half done. Each file is copied in pieces;
the ones checked first (a table's files, and `apartadas.json`) are read whole,
so each has to fit in a third of the memory PHP has free — about 40 MB with
`memory_limit = 128M`. One that does not is reported by name, and nothing is
touched. After a restore, the engine's entries in APCu are emptied, since the
database is another one. Table names are validated, the content
is checked to be JSON of the shape the engine expects, and **if any path in the
ZIP escapes the destination folder the whole file is rejected without touching
anything**: the classic ZIP attack.

The ZIP copy button **only appears if the panel and the API are served from the
same machine**. The panel compares the host of `ADMIN_API_URL` with its own; if
they differ, it hides the button and explains why, instead of letting you copy
the files of another installation that happened to be on the local disk.

The ZIP copy is the only export that reads the engine's files directly, and
read-only: a faithful ZIP needs the `.json` files as they are, and
the API returns data, not files. The path comes from `ADMIN_RUTA_DATOS_MOTOR`;
if it is empty, from the engine's `JSONSQLDB_DATA_PATH` with the direct
connection, or `../data` through the API. The ZIP is built in a temporary file that is always
deleted, even if the download is cut short.

**Tables** — list with column and row counts, create, rename, empty and delete.
The creation form starts with six column rows and has an *Add column* button
for more, plus an X to remove the spare ones; blank ones are ignored.

**Structure** — everything the engine supports:

- Columns: add, **edit** and drop. Editing changes the same as creating: name,
  type, length, decimals, `NOT NULL`, `UNIQUE` and `DEFAULT`. The data is
  converted, and if it does not survive the change nothing is touched and the
  reason is explained. The primary key is managed from «Keys», and
  `AUTOINCREMENT` cannot be changed: the form itself says so. The
  `AUTOINCREMENT` box can only be ticked on an `INTEGER` column that is also
  the primary key, which is all the engine allows. A `DEFAULT` that is worked
  out when inserting (`CURRENT_TIMESTAMP`, `CURRENT_DATE`, `CURRENT_TIME` or an
  expression, 2.8) is shown as such, and in the form it comes with its box
  «Worked out when inserting» ticked; untick it and the text is a fixed value
  (2.9: before, saving the form lost it). What the form does not show, a
  column's `COLLATE`, its `UNSIGNED` and an `ENUM`'s list, is kept (2.9).
- Simple or composite primary key, at creation and also **afterwards**: if a
  table has none, the «Keys» section offers to create one by ticking one or
  more columns, and to drop it. With null or duplicate data it is rejected. An
  `AUTOINCREMENT` key cannot be dropped: the panel says so.
- Unique keys on one or more columns, on tables that already have data.
- Foreign keys with `ON DELETE` / `ON UPDATE` (`NO ACTION`, `CASCADE`,
  `RESTRICT`, `SET NULL`, `SET DEFAULT`). The column dropdown of the target
  table fills itself.
- Triggers, with an assistant: name, `BEFORE`/`AFTER` dropdown,
  `INSERT`/`UPDATE`/`DELETE` dropdown, optional condition (the `WHEN`) and the
  body. Below it the statement about to be created is shown live. In the body
  `NEW.column`, `OLD.column`, `RAISE(ABORT, 'message')`, `IF … END IF` and, in
  a `BEFORE INSERT`/`BEFORE UPDATE`, `SET NEW.column = …` are valid.
- Indexes: create one on one or more columns — the form warns that order
  matters — and drop it. The automatic ones of the primary key and the
  `UNIQUE`s are shown as such and cannot be dropped on their own: they go with
  their constraint.
- Drop any unique or foreign key, any named `CHECK`, any trigger and any
  hand-made index.
- The structure shows each column's `COLLATE`, an `ENUM`'s list of values and,
  among the keys, the table's `CHECK`s with their expression (2.9). They are
  created with SQL (`CREATE TABLE`, `ALTER TABLE … MODIFY`, `ALTER TABLE … ADD
  CHECK`).

When adding a unique or foreign key **the existing data** is validated: with
duplicate values or orphan rows the operation is rejected and the structure is
not touched.

**Data** — paginated listing, ordering by any column, a **filter** that searches
the text in every column at once (combined with ordering, pagination and
export), and inserting, editing and deleting rows. `AUTOINCREMENT` columns are
read-only and have no NULL box, because the database sets the value; `NOT NULL`
columns are marked as «required» and offer no NULL box either. An empty box
means «no value»: in automatic, numeric and date columns the column is not sent
and the engine applies the autoincrement or the `DEFAULT`; in text columns the
empty string is stored. To store a null, tick the NULL box. To edit or delete a
single row the table needs a primary key; if it has none, the panel says so and
sends you to the SQL editor.

**Read-only key** — if you configure `ADMIN_API_KEY_LECTURA` with a key of
`lectura` permission, the panel signs with it whenever the logged-in user is not
an administrator. The engine then becomes the second barrier: even if a panel
bug let a `DELETE` through, the API would reject it. Without it, every user
signs with the admin key and the only barrier is the panel's own check.

**Integrity** — a screen of its own that runs two checks when it opens:
`CHECK KEYS`, that no row points at a non-existent value in its target table,
and since 2.9 `CHECK TABLES`, that the JSON files of every table can be read
and agree with each other. An administrator can fix what they find: the keys
with `REPAIR KEYS`, which sets to `NULL` what can be and never deletes rows, and
the files with `REPAIR TABLES`, which repairs only what can be repaired without
guessing and sets aside a row it cannot read, keeping the original in
`.reparados/` (see [01-core.md](01-core.md#editing-the-files-by-hand-29)).
Each row set aside stays listed as pending, as it was, next to a real row of
its table as an example: the administrator corrects it in an editor and saves
it, which inserts it and forgets it in one transaction, or discards it
(`REPAIR TABLES FORGET n`). A read-only user sees the reports and the pending
rows, without the buttons. Useful when someone has edited a `.json` by hand or
restored the copy of one table without the other.

**Views** — a screen of its own in the menu: a list with their query, creation
with name and `SELECT`, and deletion. From the list you jump to the SQL editor
with the query already written. A read-only user sees them but cannot create or
delete them.

**SQL** — any statement, with the result as a table and the time it took.
Several statements separated by `;` (2.9) run as **one transaction**: all of
them or none, and the result shown is the last one's; the export buttons
appear only after a single statement. With the `lectura` role only statements
that start with `SELECT` or `SHOW` are accepted, each one checked.

The **SQL dump of a database** comes in five dialects, chosen in the list of
databases; the header of each file says how to load it:

- **jsonSQLDB and SQLite** (`base.sql`): loads unchanged into either
  (`sqlite3 base.db < base.sql`). Each table carries its unique and foreign
  keys in its `CREATE TABLE`, the tables come in the order of their foreign
  keys, the rows of a self-referencing table parents first, then its indexes;
  views and triggers at the end. Only a foreign key in a cycle between tables
  goes after the data with `ALTER TABLE`, which SQLite does not accept; the
  dump marks it.
- **MySQL and MariaDB** (`base.mysql.sql`, for MySQL 8 and MariaDB 10.3 or
  later): `mysql nombre_base < base.mysql.sql`. Integers as `BIGINT` (PHP's are
  64-bit), decimals with as many digits as the data need, dates as
  `DATETIME(3)`, text in `utf8mb4_bin` so that comparisons and unique keys
  behave as in jsonSQLDB (they tell capitals and accents apart), a text column
  used in a key as `VARCHAR` long enough for its data, backslashes escaped, and
  strict mode on, so a value that does not fit is an error rather than a
  number changed in silence. A `COLLATE NOCASE` column (2.9) goes in
  `utf8mb4_general_ci`, the closest, which also ignores accents; `COLLATE
  RTRIM` needs nothing, because MySQL already ignores the spaces at the end
  when it compares with these collations. A text column is `TEXT`,
  `MEDIUMTEXT` or `LONGTEXT` by the size in bytes of its longest value, with
  its default value in parentheses, as MySQL 8 requires for all three (2.9),
  and long `VARCHAR`s not in a key become text until a row fits in MySQL's
  65,535 bytes (2.9). `ON DELETE SET DEFAULT`, which InnoDB does not have, is written
  as `NO ACTION` with a note above it (2.9).
- **PostgreSQL** (`base.pg.sql`, PostgreSQL 10 or later): `psql -v
  ON_ERROR_STOP=1 -d nombre_base -f base.pg.sql`. Everything in one
  transaction; integers as `BIGINT`, dates as `TIMESTAMP(3)`, autoincrement as
  `IDENTITY`.
- **SQL Server** (`base.sqlserver.sql`, SQL Server 2017 or later, for
  `STRING_AGG`, `TRANSLATE` and `TRIM(x FROM y)`): `sqlcmd -d
  nombre_base -i base.sqlserver.sql`, or open it in Management Studio, or load
  it with the PowerShell script described below. Everything in one
  transaction; text as `NVARCHAR` with a binary collation, integers as
  `BIGINT`, dates as `DATETIME2(3)`, autoincrement as `IDENTITY`. A foreign key
  from a table to itself goes without `ON DELETE`/`ON UPDATE`: SQL Server does
  not accept cascading actions that could form a cycle.
- **Microsoft Access** (`base.access.sql`, 2.7.3): in Access's own SQL; see
  [The Access SQL file](#the-access-sql-file-and-accesss-limits).

**Views and triggers** go at the end of every dump. In the jsonSQLDB/SQLite
one they are written as they are; for the other four they are translated
(2.7.3, `lib/GeneradorSql.php`): the view or trigger is read with the engine's
own parser and written again in the target's SQL — functions, quoting,
`LIMIT` (`TOP`, `OFFSET … FETCH`), conditions used as values — evening out the
differences that would change a result: `LIKE` without case, division that
does not truncate and gives `NULL` by zero, concatenation with `NULL`, text
ordered as here. In PostgreSQL every `ORDER BY` says `NULLS FIRST` or `NULLS
LAST`, because PostgreSQL puts `NULL` on the other side (2.9). `WITH
RECURSIVE` keeps its word in MySQL and PostgreSQL; SQL Server has no such word
and only takes `UNION ALL` there, and stops at 100 levels, which the view's
note says how to lift. Window functions go to all three, except what a system
lacks (`FILTER`, `GROUPS` and `EXCLUDE` in MySQL and SQL Server; `NTH_VALUE`
and `RANGE` with an offset in SQL Server). Each system's trigger has its own
form:

- **MySQL**: `CREATE TRIGGER … FOR EACH ROW BEGIN … END` between `DELIMITER
  ;;` lines; its `WHEN` becomes an `IF`, `RAISE` a `SIGNAL SQLSTATE '45000'`.
  MySQL forbids a trigger to touch its own table, so an `AFTER` trigger that
  updates its own row becomes a `BEFORE` one with `SET NEW`, named
  `name_antes`.
- **PostgreSQL**: a plpgsql function, `name_fn`, and the trigger that calls it;
  `RAISE` becomes `RAISE EXCEPTION`.
- **SQL Server**, which has neither row triggers nor `BEFORE`: each trigger
  walks the rows of `inserted` and `deleted` one by one with a cursor, with
  `NEW` and `OLD` in variables, and the `BEFORE` triggers of an event become one
  `INSTEAD OF` trigger that does the write at the end; `RAISE` becomes `THROW`.
  For an `UPDATE` or `DELETE` the table needs a primary key, to pair each row
  of `inserted` with its row of `deleted`. SQL Server refuses an `INSTEAD OF
  DELETE` (or `UPDATE`) trigger on a table whose foreign key cascades on that
  event (`CASCADE`, `SET NULL` or `SET DEFAULT`, error 2113), so the triggers
  of such a table are written commented out with that reason (2.9: before,
  that one trigger made the whole dump fail to load).

What a system cannot express at all — any trigger, window function, or `WITH`
that is recursive or has a column list, in Access; inside a trigger, in any of
them, an upsert, `RETURNING`, or an `UPDATE`/`DELETE` with an alias, other
tables, `ORDER BY` or `LIMIT` — is written commented out with its reason, and
listed in a line `-- Not translated (commented out at the end): …`.
`tests/f16_vistas_triggers.php` loads views and triggers into MariaDB and
PostgreSQL, runs the same writes there and here, and checks that every table
and view ends up the same.

In the PostgreSQL and SQL Server dumps (2.9), a `COLLATE NOCASE` column is
`CITEXT` in PostgreSQL —with `CREATE EXTENSION IF NOT EXISTS citext;` at the
top, which needs permission to create it (the owner of the database has it
since PostgreSQL 13)— and `NVARCHAR … COLLATE Latin1_General_100_CI_AS` in
SQL Server. `COLLATE RTRIM` needs nothing in SQL Server, which ignores the
spaces at the end when it compares, and has no equivalent in PostgreSQL: it
goes as a note above the table. A `COLLATE` written in a view or a trigger
becomes the same collations in MySQL and SQL Server, and `LOWER(…)` or
`RTRIM(…)` on both sides in PostgreSQL; in Access, which already compares
without telling case apart, `COLLATE BINARY` cannot be written.

`tests/f14_volcados.php` builds a database with every type, foreign keys with
their actions, a self-referencing table stored child before parent, a cycle,
composite keys, indexes with the same name in two tables, autoincrement, and
text with quotes, backslashes, line breaks, accents and emoji; it loads the
first dump into SQLite with foreign keys on and the second into MySQL (MySQL 8
in CI; MariaDB 10.11 where it was written) and demands the same data, the
foreign keys enforced, the indexes, unique keys that tell capitals apart and
the autoincrement continuing.

**Import** (2.7) — on the page of each database, for administrators:

- **An SQL dump from jsonSQLDB, SQLite, MySQL / MariaDB, PostgreSQL, SQL
  Server or Microsoft Access** (2.7.2): the panel's own dump, one made with
  `sqlite3 base.db .dump`, with `mysqldump` / `mariadb-dump`, with `pg_dump`
  (plain format), Management Studio's "Generate Scripts" (schema and data) or
  the SQL Server and Access scripts described below; or a whole database as
  one JSON document exported by the panel (2.9). PostgreSQL's `COPY` data,
  primary keys and `IDENTITY` declared after the table, and SQL Server's `GO`,
  `IDENTITY` and `ALTER TABLE … ADD DEFAULT … FOR` are understood; column names
  keep their accents, `ñ` and spaces (2.9), and a table, view, index or trigger
  name that is not valid here becomes valid (`Order Details` → `Order_Details`,
  `Año` → `Ano`). The format is detected from the first lines (also with
  Windows line endings),
  or chosen by hand. Dumps of other systems are translated statement by
  statement (`lib/Traductor.php`): backtick and bracket names, MySQL's
  backslash escapes, `X'…'`, `0x…`, `b'…'` and SQLite's `char(10)`; MySQL
  types to the ones here (`INT(11) UNSIGNED` → `INTEGER`, `LONGTEXT` → `TEXT`,
  `SET` → `TEXT`…; `ENUM` keeps its list since 2.9); the `KEY`s inside a `CREATE TABLE` become `CREATE INDEX`;
  a `CREATE UNIQUE INDEX` becomes a `UNIQUE` constraint; the foreign keys go at
  the end, because dumps create tables in any order and load data with checks
  off; and session statements (`PRAGMA`, `SET`, `LOCK TABLES`, `BEGIN`…) and
  the `sqlite_sequence` table are skipped, and so are mysqldump's `/*! … */`
  version comments, except what is SQL inside them (its views and triggers,
  translated as described below). What has no equivalent here — computed
  defaults other than the current date and time, `ON UPDATE`, `SET`'s list of
  values, binary `BLOB` data, and a `CHECK` that uses something this engine does
  not have (a function of another system, a type) — is dropped, and the summary
  at the end says exactly what. Since 2.9 a `CHECK` arrives (PostgreSQL's `::type`
  conversions removed) and is enforced here. Also since 2.9, a column's
  `COLLATE` that ignores case — MySQL's `…_ci`, SQL Server's `…_CI_…`,
  PostgreSQL's `citext` type — becomes `COLLATE NOCASE`, and the summary
  says so, because here only A–Z match regardless of case and accents still
  count; SQLite's `NOCASE` and `RTRIM` stay as they are; a binary one
  (`…_bin`, `…_BIN2`, `…_cs`, `"C"`, `POSIX`) is what there already is here and
  goes without a word; any other is removed and the summary says which. In
  MySQL a text column without a `COLLATE` of its own has its table's
  (`DEFAULT … COLLATE=utf8mb4_unicode_ci`), or, if the table only says its
  `CHARSET`, that character set's, which is always a `…_ci` one except
  `binary`'s; so those become `NOCASE` too. `JSON`, `BLOB` and `BINARY`
  columns are binary there and stay so. A table that says neither has the
  server's, which the dump does not tell: its columns stay binary. `tests/f14_volcados.php` imports a real `sqlite3 .dump` and a
  real `mariadb-dump`, kept in `tests/volcados/`, and compares the data with
  the source databases; in CI it also creates the source in MySQL 8, dumps it
  with its `mysqldump` and imports that.
- **An SQL file** of jsonSQLDB statements: any list of statements separated by
  semicolons. It is read as a stream (only the statement in
  progress is in memory), split respecting strings, quoted identifiers,
  comments and the `BEGIN … END` of triggers, and run in order by the same
  route as the rest of the panel, so it works between machines. Consecutive
  one-row `INSERT`s into the same table, as every dump writes them, are sent as
  one statement of up to 200 rows; through the API, also of no more than
  80,000 bytes (2.9), below its limits per request (`MAX_SQL_LENGTH` and
  `MAX_PARAMS_LENGTH` 100,000, `MAX_POST_SIZE` 200,000). An `INSERT` of several
  rows that does not fit — mysqldump writes them of up to 1 MB — is sent in
  batches of its rows (2.9: before, it was refused as too large). If the API
  has smaller limits, a batch it refuses for its size is split in two until it
  fits.
- **A CSV into an existing table**: the first line gives the column names; the
  separator (comma, semicolon or tab) is the one that gives each of the first
  five lines the same number of fields, more than one, and if several do, the
  one that gives more (2.9: before, the separators of the first line were
  counted, also those inside quotes, and a comma in a column name broke a
  valid file); quotes follow the CSV rules; an empty field is `NULL`; an
  Excel byte-order mark is ignored. A text that starts with an apostrophe
  followed by `=`, `+`, `-` or `@` (or more apostrophes and one of those) loses
  one apostrophe, the one the panel's CSV export puts there against
  spreadsheet formulas (2.9), so every exported text comes back the same. Rows go in batches of
  up to 200, with bound parameters; through the API, fewer when they would
  pass 1,000 parameters or 80,000 bytes (2.9).
- **An Excel file into an existing table** (2.8): see [Excel](#31-databases-larger-than-memory-files-larger-than-the-upload-limit-28).
- **A JSON file into an existing table** (2.9): a list of objects, one per row,
  `[{"id": 1, "name": "Ana"}, …]`, as the panel exports a table or a query.
  The columns are those of the first object; a later object without one of them
  leaves it `NULL`, and one with a column the first did not have is an error.
  `true` and `false` are stored as 1 and 0, and a list or an object as its JSON
  text. It is read in pieces, like the whole-database JSON.

**Examples and templates** (2.9). Next to the import of a table, *See
examples* opens a window with two made-up rows of that table, built from its
own columns, as CSV, as an Excel sheet and as JSON, with what each format
expects, and buttons to download each one as a template to fill in. The
made-up values follow each column's type only: on a table with an `ENUM`, a
`CHECK` or a foreign key, fix them before loading the template.

A file that creates or drops databases is refused: the import is into one
database.

**All or nothing, when the panel reaches the database's folder** (2.7.3): with
the panel on the same machine as the engine (direct connection, or the API on
the same server with `ADMIN_RUTA_DATOS_MOTOR` pointing at its `data/`), the
import runs in **one transaction of the engine** (2.9), which the panel loads
for it (`ADMIN_MOTOR_RUTA`, by default the folder above the panel). If a
statement or a batch fails, or PHP stops with a fatal error in the middle
(memory, time), the engine undoes what the import did, and only that; after a
fatal error, the next time the database is used. While the import runs, the
database is locked: other requests to it, reads included, wait until it ends,
so nothing anyone else writes can be lost or seen half imported. Up to 2.8 the
panel copied the whole database next to it (`name.antes-de-importar`) and put
the copy back on failure, which also undid what others had written meanwhile.

Without that folder, or without the engine at hand, the import does not run in
one transaction: through the API it is many requests, and a transaction never
spans requests. It stops at the failure and what came before is already
written: it says how many statements or rows went in and where it stopped.
Import into a new database when in doubt.

**What the importer copes with** (2.7.3):

- **Files that are not in UTF-8**: an old MySQL dump, or one made with
  `--default-character-set=latin1`, or a CSV saved by Excel. Each text between
  quotes, and each stretch between them, that is not valid UTF-8 is read as
  Latin-1 / Windows-1252, and the summary says how many; the rest of the same
  statement is left as it came (2.9: before, the whole statement was converted,
  and a `BLOB` in one row of a multi-row `INSERT` garbled the texts of the
  others). In a CSV, each field. Before 2.7.3, one such byte stopped the import
  with «Sentencia no soportada: 'I'».
- **Two names that become the same one** when made valid here (`ventas-2024`
  and `ventas_2024`, `Order Details` and `Order_Details`): the second table or
  view gets a suffix (`ventas_2024_2`) and the summary says so. Before, the
  second one's `DROP TABLE IF EXISTS` wiped out the first.
- **`BIT` columns of mysqldump**, which arrive as raw bytes, become the number
  they form; MySQL's **zero date `0000-00-00`** becomes `NULL`, with a warning,
  and a `DEFAULT '0000-00-00'` (WordPress, MySQL before 5.7) is dropped and its
  column accepts `NULL`, so those rows go in (2.9: the import stopped at the
  `CREATE TABLE`); **`ALTER VIEW/FUNCTION/TYPE … OWNER TO`** of a `pg_dump`
  without `--no-owner` is skipped.
- **Dates with a time zone** (2.9): a `timestamptz` of `pg_dump --inserts`, and
  a `datetimeoffset` of SQL Server, written by Management Studio
  (`CAST(N'…+02:00' AS DateTimeOffset)`) or by `sqlserver-to-jsonsqldb.ps1`,
  lose the zone as they already did in a `COPY`, with a warning; a text column
  with the same look is left as it is. A SQL Server `rowversion` (`timestamp`)
  column, whose values SQL Server sets and dumps leave out, arrives as `TEXT`
  that accepts `NULL`.
- **Indexes** (2.9): `COLLATE`, `NULLS FIRST`/`LAST` and PostgreSQL operator
  classes (`varchar_pattern_ops`) are left out and the index is created on its
  columns; an index on an expression (`lower(name)`, MySQL 8's `KEY ((…))`) is
  skipped with a warning, because here an index is on columns. Before, both
  stopped the import.
- **`CHAR(n)`** is a type in `code CHAR(10)` and `CAST(x AS CHAR(10))`, and a
  character in SQLite's `char(10)` and in the `NCHAR(0)` of the panel's own SQL
  Server dump (2.9: a `CHAR(10)` column arrived as `TEXT`).
- **`INSERT`s in a row** are joined into one only when nothing follows their
  row (2.9): two `… ON CONFLICT DO NOTHING`, `… RETURNING` or phpMyAdmin's
  per-row `ON DUPLICATE KEY UPDATE` were joined into invalid SQL.
- **A trigger with `IF … END IF`**, also with `END` and `IF` on two lines, is
  one statement (2.9): the `END` of `END IF` closed the trigger, so the panel's
  own SQL and JSON dumps of such a trigger could not be imported again, and the
  SQL console split it in two. A SQL Server `CREATE OR ALTER` view or trigger
  is read as a `CREATE` one.
- **`||` keeps its meaning** (2.9): here, as in SQLite, `||` binds before `+
  - * / %`, and in PostgreSQL after them, so in a PostgreSQL view or trigger
  each side of `||` that is a calculation goes in parentheses (`'A' || n + 1`
  → `'A' || (n + 1)`); a SQL Server `+` that joins texts becomes `(a || b)`,
  parentheses included. In a MySQL trigger, `||` is `OR`, as MySQL reads it,
  unless the `sql_mode` that mysqldump writes before the trigger has
  `PIPES_AS_CONCAT` (before, it was always taken as a concatenation).
- **PostgreSQL date arithmetic in views** (2.9): `date - date` is the number of
  days between them and `date ± n` a date n days after or before, as there; it
  was a subtraction of texts and gave another number. What gives an interval
  (`timestamp - timestamp`), or involves something that may or may not be a
  date, skips the view with the reason rather than give a wrong value.
- A **`DECIMAL` of more than 15 digits** is warned about: here it is a
  floating-point number with about 15 exact digits. An integer beyond 64 bits
  (`BIGINT UNSIGNED` above 9223372036854775807) is an error, not a silently
  changed number.
- **PostgreSQL** (2.9): tables of any schema, not only `public`; two tables with
  the same name in two schemas stop the import, which says to dump each schema
  on its own (`pg_dump -n schema`), because here there are no schemas and their
  rows would mix. A materialized view becomes a view, computed each time it is
  read; a partitioned table keeps all its rows, the partitions are not created.
- **SQLite virtual tables** (FTS, R-Tree) are skipped with their auxiliary
  tables, and the summary says so (2.9).
- **A dump with only the data** (`mysqldump --no-create-info`, `pg_dump
  --data-only`) into a database without those tables says that the tables
  have to exist first (2.9).
- Each kind of thing skipped — stored functions and procedures, MySQL events,
  session and administration statements — is named in the summary (2.9).

`tests/f5_admin.php` imports the panel's own dump of a database with foreign keys and triggers and checks
the copy, loads a 450-row CSV with quoted separators and empty fields, and
checks the report of a CSV with a bad value in row 250.

### How to make an SQL dump of each database

The import reads the dumps these tools write, as plain SQL text. Make the dump,
upload the file on the page of the database you want it in, and leave the format
on *Detect*. When it finishes, the summary lists anything that did not translate.

**SQLite** — with the `sqlite3` command-line tool:

```sh
sqlite3 shop.db .dump > shop.sql
sqlite3 shop.db ".dump customers orders" > two_tables.sql   # only some tables
```

On Windows it is `sqlite3.exe`, from sqlite.org. *DB Browser for SQLite* also
works: *File → Export → Database to SQL file*.

**MySQL / MariaDB** — with `mysqldump` (`mariadb-dump` on recent MariaDB):

```sh
mysqldump --default-character-set=utf8mb4 -u user -p shop > shop.sql
```

`--default-character-set=utf8mb4` keeps accents and emoji. Do not use `--xml`,
`--tab` or `--compatible`: they do not write SQL the importer reads. From
phpMyAdmin: *Export → Custom → Format: SQL*. **Views and triggers come along**
(2.7.3), translated to the SQL used here: `IF()`, `CONCAT_WS`, `DATE_ADD … INTERVAL`
(which MySQL stores as `x + interval 1 day`), `DATEDIFF`, `DATE_FORMAT`, `YEAR()`,
`LOCATE`, `LEFT`/`RIGHT`, `GREATEST`/`LEAST`, `FLOOR`/`CEIL`, `TRUNCATE`, `MOD`,
`GROUP_CONCAT … ORDER BY … SEPARATOR`, `LIMIT a, b`, and what MySQL 8 writes
its own way: `exists(…) is false` (a `NOT EXISTS`), `regexp_like()`,
`IS [NOT] TRUE/FALSE`, `<=>`, and `CAST(… AS SIGNED)`, which rounds there; in
triggers `IF/ELSEIF/ELSE`,
`SET NEW.col = …`, `SIGNAL … MESSAGE_TEXT` and `INSERT … SET`. Triggers are
created at the end, after the data, so they do not fire while it loads. A view or
trigger that uses something with no equivalent here (local variables, loops,
stored procedures) is skipped and named in the summary with the reason; the
rest of the dump goes on.

**PostgreSQL** — with `pg_dump`, in its default **plain** format:

```sh
pg_dump --no-owner -h host -U user shop > shop.sql
```

Do not use `-Fc`, `-Fd` or `-Ft`: those are binary archives for `pg_restore`.
`--inserts` also works, but is not needed: the importer reads the `COPY` data
of the default format. From pgAdmin: *Backup… → Format: Plain*. **Views and
triggers come along** (2.7.3): the `::type` casts, `x + '1 day'::interval`,
`IS DISTINCT FROM`, `= ANY (ARRAY[…])`, the `~~` with which pg_dump writes
`LIKE` (case-sensitive there, so a literal pattern becomes the equivalent
`REGEXP`), `to_char`, `date_trunc`, `EXTRACT`, `string_agg … ORDER BY`, and
PostgreSQL's `CONCAT`/`GREATEST` that skip `NULL`. A trigger's plpgsql function
is translated with it — `IF/ELSIF`, `NEW.x := …`, `RAISE EXCEPTION`,
`RETURN` — and a trigger for several events (`INSERT OR UPDATE`) becomes one
per event, named `name_insert`, `name_update`…, with `TG_OP` set to its event.
As in PostgreSQL, `OLD.col` is `NULL` in the `INSERT` one and `NEW.col` in the
`DELETE` one (2.9), so a function that names both, in branches for each event
or in `COALESCE(NEW.id, OLD.id)`, comes in whole.
Other functions and procedures are skipped.

**SQL Server** — with Management Studio:

1. Right-click the database → *Tasks* → *Generate Scripts…*
2. Choose the tables (or the whole database).
3. In *Set Scripting Options*, open *Advanced* and set *Types of data to
   script* to **Schema and data**.
4. Save to a single file. Either *Unicode text* (UTF-16, the default) or *UTF-8*
   works: the importer converts UTF-16 on its own.

Or, since 2.9, with the PowerShell script the panel offers in its import box
and in the dumps guide, `sqlserver-to-jsonsqldb.ps1`. It needs **Windows** and
nothing installed: it connects through the `SQLOLEDB` provider that comes with
Windows, with a SQL login or Windows authentication. Right-click it → «Run with
PowerShell», and it asks what to do:

- **Dump a database to SQL**: it lists the databases of the server, and writes
  `name.sqlserver.sql` with the tables of every schema (those of `dbo` with
  their name, the others as `schema_table`, also in the views and triggers
  that use them), primary and unique keys, indexes, foreign keys, data, views
  and triggers, in the syntax of *Generate Scripts*. The database is only read.
  Stored procedures and functions are listed at the end as not included. A
  column of an alias type (`CREATE TYPE … FROM decimal(19,4)`) goes with its
  base type, and a `rowversion` column without its values, which SQL Server
  sets by itself when the file is loaded back (2.9).
- **Load an SQL file into SQL Server**: an `.sqlserver.sql` file made by the
  script or by the panel's *SQL: SQL Server* export, into an existing database
  or a new one. If any of its tables already exists there, it stops before
  changing anything; everything runs in one transaction.

The script it writes — with `GO`, `[dbo].[…]`, `N'…'` and `IDENTITY` — is what
the importer was built for. **Views and triggers come along** (2.7.3). Views:
`TOP`, `OFFSET … FETCH`, `ISNULL`, `LEN`, `CHARINDEX`, `IIF`, `DATEADD`,
`DATEDIFF`, `DATEPART`, `FORMAT`, `CONVERT(type, x, 120)`, `ROUND(x, n, 1)`,
`STRING_AGG … WITHIN GROUP`, `WITH (NOLOCK)`, and `+` as concatenation when one
side is text. Triggers are the bigger change: SQL Server runs a trigger once per
statement, with the rows in the `inserted` and `deleted` tables; here a trigger
runs once per row. With one row, `inserted` is `NEW` and `deleted` is `OLD`, so
the importer rewrites the usual patterns — `UPDATE … FROM t JOIN inserted i ON …`
(the `ON` goes to the `WHERE`), `INSERT … SELECT … FROM inserted`,
`IF [NOT] EXISTS (SELECT … FROM inserted …)`, `IF UPDATE(col)`, variables loaded
with `SELECT @v = col FROM inserted`, `RAISERROR`/`THROW` and `ROLLBACK` — one
trigger per event. A trigger that updates its own row (`UPDATE t … JOIN
inserted i ON t.id = i.id`) becomes a `BEFORE` trigger with `SET NEW`, named
`name_antes`: SQL Server does not fire a trigger again from its own update, and
here it would. `INSTEAD OF` triggers, cursors and loops have no equivalent and
are skipped with the reason. Stored procedures are skipped.

**Microsoft Access** — with the PowerShell script the panel offers in its import
box, `access-to-jsonsqldb.ps1`. It needs **Windows**. The easiest way to run it:
**right-click the downloaded file → «Run with PowerShell»**. It asks what to do:

- **Dump an Access database to SQL**: it asks for the `.mdb` or `.accdb` file and
  for the folder where the dump is saved, and writes `name.access.sql` there with
  the tables (autonumber, keys, indexes, `NOT NULL`), the data, the relationships
  and the saved select queries. The database is opened read-only. Import the file
  here with the format on *Detect* (or *Microsoft Access*).
- **Load an SQL file into Access**: it asks for an `.access.sql` file (one it
  made, or one exported by the panel as *SQL: Microsoft Access*) and for the
  Access database to load it into — a new name creates it — and runs the
  statements one by one, as if each were pasted into a query's SQL view. It
  stops at the first statement that fails and says which one.

It reads through OLEDB and loads through DAO. **An `.mdb` needs nothing
installed**: Windows has its engine (Jet), but only for 32-bit programs, so when
the script runs in the 64-bit PowerShell (the usual one) it opens itself again in
the 32-bit one, with what has been chosen so far. **An `.accdb` needs the
Microsoft Access Database Engine** (2016 Redistributable) with the same bitness
as the PowerShell running the script; if it is missing, the script says so and
offers the download page. With 32-bit Office the 64-bit engine will not install:
use the 32-bit engine and the 32-bit PowerShell
(`C:\Windows\SysWOW64\WindowsPowerShell\v1.0\powershell.exe`).

The panel shows all this in its import box too: *How to make the dump of each
database* opens a window with one tab per engine, and *Microsoft Access: script
and limitations* opens it on the Access tab.

#### The Access SQL file and Access's limits

Both the script's dump and the panel's *SQL: Microsoft Access* export are written
in Access SQL in its usual syntax (**ANSI-89**), the one of a query's SQL view, so
every statement can also be pasted by hand into *Create → Query Design → SQL
View* and run — all but the `CREATE VIEW`s, which Access only runs in its
ANSI-92 syntax (see the table). That syntax sets the limits:

| Access | What the file does |
|---|---|
| The SQL view runs **one statement at a time**, and has no comments | One statement per line or block; the `--` lines are not copied. To load a whole file, the script's *Load an SQL file into Access* |
| `CREATE VIEW` only in some databases and modes (see below) | Saved queries go as `CREATE VIEW [name] AS SELECT …`. By hand, in any version: paste what follows `AS` into a new query and save it with that name. The script's *Load an SQL file into Access* creates them as saved queries without `CREATE VIEW` (through DAO, so their `SELECT` keeps Access's usual syntax), in any version |
| No `DEFAULT` | `-- [table].[column] DEFAULT value` above the table: set it in the table's Design view (*Default Value*) |
| No `ON DELETE` / `ON UPDATE CASCADE` | `-- [relationship] ON DELETE CASCADE` above it: *Database Tools → Relationships → Enforce Referential Integrity → Cascade Delete / Update* |
| No `DECIMAL`, no `BIGINT` | `CURRENCY` (exact, up to 4 decimals) or `DOUBLE`; an integer beyond the 32 bits of `LONG` becomes `DOUBLE` (exact up to 2^53) |
| No multi-row `INSERT` | One `INSERT` per row |
| No way to write a line break inside a text | `'a' & Chr(13) & Chr(10) & 'b'`: the statement stays on one line and no editor changes the data |
| No triggers | Commented out at the end; in an `.accdb`, data macros are made by hand |
| No `FULL JOIN`, `INTERSECT`, `EXCEPT`, `OFFSET`, `GROUP_CONCAT` or regular expressions | The views that use them are commented out with the reason |
| Text up to 255 characters; `MEMO` cannot be indexed | `TEXT(n)` up to 255, `MEMO` beyond; a text key or index wider than 255 keeps `MEMO` |
| Dates from year 100 to 9999; Yes/No is -1 | `DATETIME` with `#yyyy-mm-dd hh:nn:ss#`; `True`/`False` (1/0 here) |
| Text compares without case, also with `=` | Here `=` tells capitals apart; `Like` and `InStr` are translated without case, as in Access |

**Where `CREATE VIEW` works.** It is part of the SQL that came with Jet 4.0,
so Access accepts it in `.mdb` databases of Access 2000 to 2003 (Jet 4.0) and in
`.accdb` ones (Access 2007 and later) — but only in **ANSI-92** syntax: through
ADO/OLEDB, or in a query's SQL view when the database has *SQL Server Compatible
Syntax (ANSI 92)* turned on (an option since Access 2002; in Access 2010 and
later, *File → Options → Object Designers → Query design*). In the usual ANSI-89
syntax of the SQL view it is a syntax error, and in an Access 97 or earlier
database (Jet 3) it does not exist at all. Turning ANSI-92 on changes the
wildcards of `Like` (`%` and `_` instead of `*` and `?`) for every query of that
database, which is one more reason to paste the `SELECT` by hand or let the
script create the queries.

When the file is imported here, the importer reads it as written: the
`CREATE VIEW`s become views, the `-- … DEFAULT` and `-- … ON DELETE CASCADE`
lines are applied to the statement below them, and `Chr(13) & Chr(10)` becomes
the line break again — so a database exported for Access and imported back has
the same tables, keys, cascades, defaults, data and views.

What does not come from Access: attachments and OLE objects (binary data);
action, crosstab and parameter queries; and the hidden queries of forms and
reports. The script lists them when it finishes. The saved queries are
translated as Access stores them: text in double quotes, `#dates#`, `&`, `IIf`,
`Nz`, `Mid`, `Len`, `InStr`, `Format`, `DateAdd`, `DateDiff`, `Year`…,
`CCur`/`CLng`/`CDate`…, the `*`, `?` and `#` wildcards of `Like`, `Table!Field`,
joins in parentheses and `TOP`.

What does not arrive the same in any of them: computed
default values other than the current date and time (`CURRENT_TIMESTAMP`,
`NOW()`, `getdate()`, `curdate()` and the like do arrive, since 2.8), a
`CHECK` that uses something this engine does not have (the others arrive since
2.9, and so do MySQL's `ENUM` lists; a PostgreSQL enumerated type arrives as
text), time zones of dates, and binary data that is not text (kept as its hexadecimal,
`\x…`). Column names arrive as they are, with accents, `ñ` or spaces, as the
engine allows since 2.8 (2.9; they were changed like table names). The name
of a table, view, index or trigger is part of file names, so it is made of
letters, digits and `_`: `Order Details` → `Order_Details`, and a letter with
an accent becomes its plain letter, `Año` → `Ano` (2.9; it became one `_` per
byte, `A__o`). A view of an Access database that uses a table of another
Access database (`[C:\…\other.mdb].table` or `table IN '…'`) is skipped and
the summary says which table and file. And if the dump contains `DROP TABLE`, a table with the same name
here is replaced: import into a new, empty database if in doubt.

**The SQL dump of a whole database is written table by table** (2.7.3): the
rows of each table are read when that table is written, and the dump goes to the
browser as it is made, so only one table is in memory at a time (before, the
whole database was), and since 2.8 a table that does not fit in memory is read
in batches (section 3.1). Before the download starts (2.9), each table is
probed with a one-row read, so a table that cannot be read is reported in the
panel instead of in the middle of the file. If something still fails halfway,
the download has already started, and the file ends with two lines:

```
-- ERROR: the export is incomplete: <the reason>
INCOMPLETE_DUMP_DO_NOT_IMPORT;
```

The second one is not SQL in any engine (2.9), so loading that file anywhere,
here included, stops there with an error instead of taking a part for the
whole. A CSV, JSON or `INSERT` export cut short ends the same way; an Excel
file or a ZIP is never half made, because they are built whole before the
download. The dump for SQLite writes
`AUTOINCREMENT` only on an `INTEGER PRIMARY KEY`, the only place SQLite accepts
it. In views exported to other engines, `CAST(x AS INTEGER)` truncates as it
does here (MySQL and PostgreSQL would round), and `GROUP_CONCAT` without
`ORDER BY` has no defined order in any engine: write the `ORDER BY` when the
order matters.

**Export** — **CSV**, **INSERT**, **JSON** and **Excel** buttons (JSON and
Excel since 2.9) on the data screen, which export the whole table with the
filter and ordering you have set, not just the visible page, and on the SQL
editor's result, which export what a `SELECT` or `SHOW` returned. Only for
administrators (2.9). A query that returns no rows says so instead of
downloading an empty file.

- A text cell that starts with `=`, `+`, `-` or `@` gets an apostrophe in front
  (2.7.3): a spreadsheet would otherwise take it for a formula and run it when
  the file is opened. Excel does not show the apostrophe; numbers, including
  negative ones, are not touched. A text that already starts with apostrophes
  followed by one of those (`'-5`) gets one more (2.9), so the import, which
  takes one off, gives it back unchanged.
- The CSV carries a UTF-8 BOM so Excel does not break accents, and uses `;` as
  separator (`ADMIN_CSV_SEPARADOR`, change it to `,` for other tools). Nulls
  come out as an empty cell. Quotes inside a text are doubled, as the CSV
  standard and Excel expect (2.9: a backslash followed by a quote was written
  without doubling it, and that text did not come back the same).
- JSON is a list of objects, one row per line, the same shape the import of a
  table reads.
- Excel (`.xlsx`, needs PHP's `zip` extension; without it the button is not
  shown) is written row by row to temporary files, without any library, and
  built whole before the download starts, so an error is shown in the panel
  instead of leaving a broken file; its temporary files are deleted even if
  PHP stops halfway (memory, time; 2.9). Numbers are numbers (an integer of more
  than 15 digits goes as text, which Excel would round), and dates are Excel
  dates, with or without time: in a table, the values of its `DATETIME`
  columns; in a query result, any value written as a date. Rows that do not
  fit in a sheet (1,048,575 besides the header) go on to the next one. A text
  longer than the 32,767 characters of a cell is cut, and control characters
  that Excel does not accept are removed; when that happens, a last sheet,
  *Warnings*, lists each cut text and how many characters were removed.
- INSERT generates one statement per row, with quoted names, single quotes
  doubled and nulls as `NULL`. It can be re-run as it is in the SQL editor of
  another database.
- When exporting a query result, the table name of the INSERTs is taken from
  the first `FROM` of the statement; if there is none, it is called `consulta`.
- There is no row cap (2.8): what does not fit in memory goes in batches
  (section 3.1).

### 3.1. Databases larger than memory, files larger than the upload limit (2.8)

**Exporting.** Before asking for the rows, the panel measures what a sample of
20 takes and works out how many fit in the free memory (`memory_limit` minus
what is already in use, with room left for the API's answer and the text being
written). If the whole table or result fits, it is asked for at once, as
before; if not, in batches of that size (`LIMIT … OFFSET …`, at most 100,000
rows per request, and as few as one when rows are very wide), and only one
batch is in memory. This holds for the SQL dumps in every dialect, the
whole-database JSON, CSV, `INSERT`, JSON and Excel of a table or a query, and
scheduled backups. The types a dump works out from the data (text lengths, digits, the
range of integers for Access) come from a first pass that keeps no rows; and a
table that refers to itself, read in batches, cannot be ordered parents first,
so its foreign key goes at the end with the deferred ones. `ADMIN_EXPORT_MAX`,
the old row cap, is gone; a `config.php` that still defines it keeps working.

With the direct connection (2.9) there are no batches: the engine runs in the
panel's own process, so the query runs once and its rows go, as the engine
gives them, to a temporary file that the export then reads. In batches each one
ran the query again, and in the panel's memory a sorted export of 120,000 rows
with 32 MB stopped after 12,500 rows with PHP's memory error.

Two things to know:

- A query with `ORDER BY` that does not fit in the engine's memory is sorted
  on disk since 2.9 (see [Part 2](02-queries.md)), so it exports whole too;
  through the API it is slower, because each batch sorts the result again. If something still
  fails, the panel says so before the download starts, or in the last line of
  the file (`ERROR: the export is incomplete`) if it fails later: the file is
  never taken as complete.
- Through the API each batch is a request of its own, so a write in the middle
  of a long export can show in part of the file. For a consistent copy of a
  database that is being written to, use the ZIP, which locks it while copying.

**Uploading.** `upload_max_filesize` and `post_max_size` limit each request,
not the size of a file. When the file chosen is larger than what fits in one
request, the browser cuts it into pieces that do (at most 8 MB) and sends them
one after another, with a progress bar; when the last one arrives, the form is
sent without the file. If the connection drops, choosing the same file again
resumes from what had arrived: the pieces are identified by the file's name,
size and date (also in a browser without `crypto.subtle`, as over plain HTTP
on an intranet, 2.9). A file uploaded in pieces is deleted when the import ends,
whether it went well or not; one left half way is deleted after two days. A
form that is larger than `post_max_size` anyway is answered with how much it
took and how much the server accepts per request, and nothing is done (2.9).

**The import folder.** A file too large for the browser can be left by FTP in
`jsonsqldbadmin/datos/importar/`: the import forms then offer it in a list,
next to the file field. It is imported from there without being copied, and it
stays there afterwards; delete it when you no longer need it.

Importing reads the file statement by statement (SQL), line by line (CSV),
row by row (Excel) or in pieces of 64 KB (JSON), and inserts in batches
(section 3), so its size does not matter. A single statement or JSON value
still has to fit in memory: up to an eighth of the free memory, and at least
1 MB; one that does not is reported with the line where it starts (2.9).
PostgreSQL's `COPY` data goes in batches of 1 MB (2.9). Long imports and
exports are not cut by `max_execution_time`.

**Excel.** Next to the CSV, the first sheet of an `.xlsx` file goes into a
table the same way: the first row has the column names. It is read row by row
with PHP's `ZipArchive` and `XMLReader`, without any library and without
loading the sheet; the shared strings go to a temporary file. Cells with a
date format arrive as `yyyy-MM-dd`, or as `yyyy-MM-dd HH:mm:ss` when they have
a time or their format shows one (2.9: a date and time at midnight lost its
time), booleans as 1 or
0, errors (`#N/A`) as `NULL`, and formulas as their last calculated value. The
button is disabled when the `zip` or `xml` extension of PHP is missing.

### 3.2. Scheduled backups (2.8)

The **Scheduled backups** page (administrators only) programs automatic
backups: the database, how often (every N hours, every day at an hour, or every
week on a day and at an hour), the format (ZIP or SQL dump) and how many to
keep; when there are more, the oldest are deleted (by the date of the file, not its name: cron and the web server may have different time zones). Each one can also be made at
once, downloaded or deleted from there.

They are kept in `jsonsqldbadmin/datos/copias/<database>/`, named with the date
and time (`-2`, `-3`… if two are made in the same second). The *N hours* field
only shows when *Every N hours* is chosen. A backup is written to a temporary file and renamed when it is
finished, so a backup cut halfway never looks complete. The ZIP needs the
panel and the engine on the same machine, as the ZIP button does.

Who makes them:

- **cron** (best): every 15 minutes, `herramientas/copias-cron.php` makes the
  ones that are due and no others. The page shows the exact line, for cron and
  for the Windows Task Scheduler:

  ```
  0,15,30,45 * * * * php /path/to/jsonsqldbadmin/herramientas/copias-cron.php
  ```

  The script only runs from the command line; the `herramientas/` folder cannot
  be reached from the browser anyway. It exits with code 1 if the last attempt
  of any scheduled backup failed.
- **without cron**, the panel: when someone opens a page and a backup is due,
  the page tells the browser, which asks for it in a separate request without
  waiting for the answer, so nobody waits for it. The server finishes it even if
  the page is closed, and lets go of the session first so the next pages are
  not held up. A backup can then be late if nobody opens the panel.

A lock stops two backups from running at the same time. A backup that fails
shows its error on the page, is written to the audit trail, and is tried again
at its next time.

### 3.3. The panel's data folder

`jsonsqldbadmin/datos/` (`ADMIN_DATA_PATH`) holds the panel's users and audit
trail, and two folders of its own: `copias/` (scheduled backups) and
`importar/` (files left by FTP to import, and uploads in pieces while they
arrive). **PHP must be able to write in `datos/` and in both folders**; they are
created on their own when needed. They are protected like the rest of
`datos/`: the `.htaccess` and `web.config` of the folder deny them to the
browser, and so do the rules in [`nginx/`](../nginx/README.md) and
[`litespeed/`](../litespeed/README.md). If you move `ADMIN_DATA_PATH` outside
the public folder of the website, both go with it.

On a hosting with `open_basedir` that does not include the system's temporary
folder, the temporary files of the panel (the ZIP being built, a copy of an
Excel file) go to `jsonsqldbadmin/datos/` instead, and are deleted the same
way (2.9).

## 4. Creating the first database

`CREATE DATABASE` and `DROP DATABASE` are engine statements, so they work
through all three routes:

- **Panel**: *Databases* → *New database*. The most convenient when there is
  none yet.
- **API**: send the statement with an **empty** `db` parameter. It is the only
  case where `db` may be empty, together with `SHOW DATABASES` and
  `DROP DATABASE`.
- **PHP**: `JsonSQLDB\Database::crear('mydb')`, or
  `Database::consultarGlobal('CREATE DATABASE mydb')`.

```php
$cli = new JsonSqlDbCliente($url, $apiKey, $secret, '');   // no database
$cli->consultar('CREATE DATABASE mydb');
$cli->consultar('CREATE DATABASE IF NOT EXISTS mydb');
$dbs = $cli->consultar('SHOW DATABASES');
```

An API key limited to specific databases (`'bases' => ['mydb']`) **cannot** send
an empty `db`: creating databases needs a key with `['*']`.

## 4.1. HTTPS with your own certificate

If the API runs over HTTPS with a self-signed certificate or one from an
internal CA, cURL rejects it and you see *SSL certificate problem: self-signed
certificate*. Two ways out, in `jsonsqldbadmin/config.php`:

```php
// 1) Recommended: still verifies, but against your certificate
define('ADMIN_SSL_CA', 'C:/xampp/apache/conf/ssl.crt/server.crt');

// 2) Shortcut: accept the certificate without checking it
define('ADMIN_SSL_AUTOFIRMADO', true);
```

Both only apply if `ADMIN_API_URL` is `https://`; over HTTP they are ignored.
`ADMIN_SSL_CA` wins: if it has a value, `ADMIN_SSL_AUTOFIRMADO` is ignored. If
the file does not exist or cannot be read, the panel says so clearly instead of
failing with a confusing network error.

With option 1, **the server name in `ADMIN_API_URL` must match the one in the
certificate**. If the certificate is for `shirka`, the URL must be
`https://shirka:44311/...`, not the IP nor `localhost`. If they do not match,
cURL keeps complaining (about the name now, not the signature) and you need
option 2 or a certificate regenerated with the right name.

Option 2 is reasonable on a trusted internal network, but stops protecting you
against a man in the middle: on a network you do not control, use option 1.

The applications consuming the API have the same in `cliente_ejemplo.php`:

```php
$cli = new JsonSqlDbCliente($url, $apiKey, $secret, 'mydb');
$cli->certificado('C:/xampp/apache/conf/ssl.crt/server.crt');
// or
$cli->aceptarAutofirmado();
```

## 5. Security

- **Signing out is a form (POST) with its CSRF token** (2.7.3): a link or an
  image on another page cannot sign anyone out.
- **Failed sign-in attempts are counted under an exclusive lock** (2.7.3), so
  attempts arriving at the same time are all counted. Since 2.9 each attempt is
  counted before its password is checked, under the same lock, so attempts at
  the same time cannot go past the limit either, and signing in removes only
  the failures of that user from that IP: before, it removed all of them, and
  anyone with an account could keep guessing the password of another without
  ever being locked out, by signing in with their own every few tries.
- **The session cookie carries `Secure`** whenever the request came over HTTPS,
  including behind a trusted proxy (`ADMIN_CONFIAR_EN_PROXY`) that sends
  `X-Forwarded-Proto: https` (2.7.3); before, it only looked at the direct
  connection.
- **Only administrators export and dump** (2.9), as the roles table always
  said: before, a read-only user could download a table or a dump.
- **The read-only role checks each statement** (2.9): since the SQL console
  runs several at once, a write could otherwise have gone behind a `SELECT`.
- **Signing out with an expired form** says «The form has expired» and goes
  back to the start page (2.9); it used to be a fatal error page.
- **Failed sign-ins in the audit trail** (2.9): only real attempts, which the
  IP lockout limits, with the user name cut to 64 characters. Before, a post
  without its token, or from an IP already locked out, wrote whatever it
  carried, and anyone could fill the disk with requests.
- **Signing in takes the same time whether the user exists or not** (2.9):
  the check against a user that does not exist used a cost of 10, and with
  `ADMIN_BCRYPT_COSTE` at 11 the difference showed which names exist.
- **A user name that no user can have is never stored** (2.9): one that does
  not fit the rule for user names (3 to 32 letters, digits and `. _ - @`) is
  answered like a wrong password, in the same time, and counts against the IP
  lockout like any failure, but all of them under one short key. Before, the
  name went whole into `datos/intentos.json`, which every sign-in attempt
  reads, and rewrites when it counts: five posts with a name of 7.7 MB made it 38 MB for up to an hour,
  and with a larger `post_max_size` signing in could run out of memory for
  everyone. A huge password costs nothing either: bcrypt only reads its first
  72 bytes.
- **A form field sent as a list** (`field[]=x`) where the panel expects text is
  taken as empty (2.9): the request gets the usual error for a missing value.
  Before, PHP turned it into the text `Array` with a warning, and, for
  example, a backup was scheduled for a database called `Array`.
- **The Configuration page writes `config.php` by PHP's own tokens** (2.9): a
  key or secret with `);` or `;` in it, saved twice, left the rest of it
  outside the text, as PHP code.

- `config.php`, `lib/`, `vistas/` and `datos/` are blocked by `.htaccess` and
  `web.config`. Only `index.php` and `assets/` are served. **On nginx and
  OpenLiteSpeed those files do not apply**: install the rules from the
  project's `nginx/` or `litespeed/` folder,
  or the folders are reachable from the browser.
- `ADMIN_IPS_PERMITIDAS` limits who can open the panel, by IP or CIDR range. If
  only you use it, from the office or over a VPN, it is the most effective
  measure: whoever is not on the list does not even see the login screen.
- `ADMIN_EXIGIR_HTTPS` rejects access over HTTP. Passwords and data travel
  through the panel, so in production it should be `true`.
- `Content-Security-Policy` header restricted to `self`: everything the panel
  loads (Bootstrap, icons, fonts) is local, so it needs to allow no external
  origin. Scripts run only from the panel's files or with the nonce of each
  response, so an injected script would not run; forms only post to the
  panel, and no page can frame it.
- Every output goes through `h()` (`htmlspecialchars`), so a value with HTML in
  it is shown as text and not executed.
- Form values travel as **bound parameters**: they are never concatenated into
  the SQL. A field with `'); DROP TABLE customers; --` is stored as text and
  alters nothing.
- Table and column names are part of the statement, so they are validated
  first — a table, against `^[A-Za-z_][A-Za-z0-9_]{0,63}$`; a column, from 1 to
  64 characters without control characters, as the engine accepts — and always
  go between double quotes, with any double quote in them doubled.
- `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff` and
  `Referrer-Policy: same-origin` headers.

## 6. Language

The panel is in Spanish and English. It follows the browser's language (the
first of its `Accept-Language` list that the panel has; English if there is
none) until the user picks one with the ES/EN selector: in the top bar the
choice is saved with the panel user and follows them to any browser; on the
sign-in page or in the wizard it lasts for that session. Since 2.9 the selector's links carry a token tied
to the session, and only with it is the language saved in the user: a link or
an image on another page pointing at `?idioma=en` changes, at most, the
language of that session, never the one stored with the user. Messages that come from the engine (a table that
does not exist, a constraint that fails) stay in Spanish: they belong to the
engine. To add a language, copy `idiomas/en.php`, translate the values (the
keys are the Spanish texts), add it to `Idioma::DISPONIBLES` in
`lib/Idioma.php` and make `Idioma::texto()` there load `idiomas/<code>.php`
(it loads `en.php` for every language other than Spanish);
`tests/f15_idiomas.php` checks `en.php` only.

## 7. Configuration

Administrators change it from the panel's **Configuration** page (2.7.1): the
connection (mode, engine folder, API URL, keys, certificate, timeout), security
(allowed IPs, HTTPS, proxy, session length, login lockout, bcrypt cost, audit
retention) and the data screens (rows per page, characters per cell, CSV
separator). What it saves goes into `config.php`, replacing each
`define()` in place so the comments stay.

- **A new connection is tested before it is saved**; if it does not answer,
  nothing is written.
- **Nothing that would lock out the person making the change is accepted**: an
  IP list that does not include their current address, or requiring HTTPS
  while they are on HTTP.
- **Keys are never shown.** An empty key field keeps the current one; the audit
  trail records which settings changed, never their values.
- **It checks that the data folder cannot be downloaded** (2.7.2): it requests
  `data/web.config` over HTTP and shows in red if the server hands it out, as
  nginx does without its rules. The setup wizard shows the same check. It
  cannot be done from PHP's built-in server. Since 2.9 the address comes from
  `ADMIN_API_URL` as written in `config.php`, with the certificate checked as
  in the calls to the API (`ADMIN_SSL_CA`, `ADMIN_SSL_AUTOFIRMADO`). While it
  is empty, as with the direct connection, which does not need it, this page
  uses the address of the installation itself, worked out from the request's
  `Host` header, because only a signed-in administrator sees it. The wizard,
  which anyone can open before installation, does not: it says the check is
  made on this page and that you can check by hand that `data/web.config`
  cannot be downloaded. Before 2.9 the wizard also used the `Host` header, so
  anyone could make the server send a request to any machine and port of its
  network.
- Where the users are kept (`ADMIN_DATA_PATH`), the engine's data folder for the
  ZIP copy and the session cookie name are only changed by hand: changing them
  with the panel running would leave whoever is using it out.

`tests/f11_asistente.php` saves settings and checks the file, and checks each
refusal leaves `config.php` untouched.

| Constant | Default | Purpose |
|---|---|---|
| `ADMIN_CONEXION` | `api` | `api` or `directa` (section 1); the wizard sets it |
| `ADMIN_MOTOR_RUTA` | empty | direct connection: the jsonSQLDB folder (with `engine/` and `config.php`); empty = the panel's parent folder |
| `ADMIN_API_URL` | empty | URL of the API; the wizard and the Configuration page write it; empty = worked out on every request from the `Host` header. Not needed with the direct connection |
| `ADMIN_API_KEY` | admin key | API key the panel works with |
| `ADMIN_HMAC_SECRET` | secret | the same as the API's |
| `ADMIN_SSL_CA` | empty | path to the `.crt`/`.pem` of the API server |
| `ADMIN_SSL_AUTOFIRMADO` | `false` | accept the certificate without checking it |
| `ADMIN_TIMEOUT` | `300` | seconds to wait for each call to the API (the Configuration page takes 5 to 600; 60 before 2.9, and a `config.php` written then keeps its value) |
| `ADMIN_DATA_PATH` | `datos/` | users and audit |
| `ADMIN_API_KEY_LECTURA` | empty | API key for read-only users |
| `ADMIN_HMAC_SECRET_LECTURA` | empty | its secret |
| `ADMIN_IPS_PERMITIDAS` | empty | IPs or CIDR ranges that may open the panel |
| `ADMIN_EXIGIR_HTTPS` | `true` | reject access over HTTP (the wizard can turn it off for local testing) |
| `ADMIN_CONFIAR_EN_PROXY` | `false` | trust X-Forwarded-For / -Proto |
| `ADMIN_SESION_NOMBRE` | `jsonsqldbadmin` | session cookie name |
| `ADMIN_SESION_MINUTOS` | `60` | maximum inactivity |
| `ADMIN_LOGIN_MAX_FALLOS` | `5` | failed attempts before locking the IP |
| `ADMIN_LOGIN_BLOQUEO_MIN` | `15` | minutes of lockout; also how long failed attempts are remembered after the last one (2.9) |
| `ADMIN_BCRYPT_COSTE` | `11` | password hash cost |
| `ADMIN_AUDIT_DIAS` | `90` | days of audit (0 = forever) |
| `ADMIN_FILAS_PAGINA` | `50` | rows per page in the data listing |
| `ADMIN_CELDA_MAX` | `120` | characters before a cell is truncated |
| `ADMIN_CSV_SEPARADOR` | `;` | separator of the exported CSV |
| `ADMIN_RUTA_DATOS_MOTOR` | empty | the engine's `data/` folder, for the ZIP copy and restore, scheduled ZIP backups and all-or-nothing imports; empty = the engine's `JSONSQLDB_DATA_PATH` with the direct connection, `../data` through the API |

## 8. Files

| Path | What it is |
|---|---|
| `jsonsqldbadmin/index.php` | single entry point: setup wizard while unconfigured, then session, router and actions |
| `jsonsqldbadmin/config.dist.php` | template; the wizard writes `config.php` from it |
| `jsonsqldbadmin/config.php` | configuration (not in the repository) |
| `jsonsqldbadmin/lib/Api.php` | calls to the engine, through the API or by direct connection |
| `jsonsqldbadmin/lib/Instalador.php` | the setup wizard and the Configuration page: checks, connection test, writing `config.php`, the data-folder check |
| `jsonsqldbadmin/lib/Auth.php` | users, session, IP lockout and CSRF |
| `jsonsqldbadmin/lib/Audit.php` | audit trail |
| `jsonsqldbadmin/lib/Exportar.php` | export to CSV, `INSERT` statements, JSON, Excel, ZIP and SQL dumps for SQLite, MySQL / MariaDB, PostgreSQL, SQL Server and Access, and the whole database as JSON |
| `jsonsqldbadmin/lib/GeneradorSql.php` | views and triggers written in the SQL of MySQL / MariaDB, PostgreSQL, SQL Server and Access, from the engine's parse tree |
| `jsonsqldbadmin/lib/EscritorXlsx.php` | writes an `.xlsx` row by row, without any library (2.9) |
| `jsonsqldbadmin/lib/Importar.php` | import of SQL dumps, the whole-database JSON, CSV, Excel and JSON into a table, and restore of a ZIP backup |
| `jsonsqldbadmin/lib/JsonFlujo.php` | reads a JSON file in pieces of 64 KB, for imports larger than memory (2.9) |
| `jsonsqldbadmin/lib/Lotes.php` | rows of a query at once or in batches, according to the free memory |
| `jsonsqldbadmin/lib/Subidas.php` | where the file of an import comes from: uploaded whole, in pieces, or from the import folder |
| `jsonsqldbadmin/lib/LectorXlsx.php` | reads the first sheet of an `.xlsx` row by row |
| `jsonsqldbadmin/lib/Copias.php` | scheduled backups |
| `jsonsqldbadmin/lib/arranque.php` | loads the configuration and the classes, for `index.php` and the cron script |
| `jsonsqldbadmin/herramientas/copias-cron.php` | makes the scheduled backups that are due, from cron or the Task Scheduler |
| `jsonsqldbadmin/lib/Traductor.php` | translation of SQLite, MySQL, PostgreSQL, SQL Server and Access dumps into jsonSQLDB's SQL |
| `jsonsqldbadmin/lib/TraductorRutinas.php` | translation of the views and triggers of those dumps |
| `jsonsqldbadmin/lib/NoTraducible.php` | the exception for a view or trigger that cannot be written in the other system, skipped with its reason |
| `jsonsqldbadmin/lib/Idioma.php` | the panel's language: browser, user choice, `t()` |
| `jsonsqldbadmin/idiomas/en.php` | the English texts, keyed by the Spanish ones |
| `jsonsqldbadmin/lib/Store.php` | reading and writing of the panel's JSON files |
| `jsonsqldbadmin/lib/iconos.php` | the icons, inline SVG |
| `jsonsqldbadmin/lib/util.php` | escaping, URLs, messages and validation |
| `jsonsqldbadmin/lib/acciones.php` | every action that changes something |
| `jsonsqldbadmin/vistas/` | pages |
| `jsonsqldbadmin/assets/` | Bootstrap 5.3.3 (CSS and JS), local |
| `jsonsqldbadmin/herramientas/access-to-jsonsqldb.ps1` | the PowerShell script that dumps a Microsoft Access database and loads a dump into one (Windows), downloaded from the import box |
| `jsonsqldbadmin/herramientas/sqlserver-to-jsonsqldb.ps1` | the same for SQL Server (2.9) |
| `jsonsqldbadmin/assets/panel.css` | the design: tokens for light and dark theme, layout and components |
| `jsonsqldbadmin/assets/panel.js` | sidebar, theme, confirmations, Ctrl+Enter, wizard options, column fields, selects that submit their form, uploads in pieces, scheduled backups without cron |
| `jsonsqldbadmin/datos/` | `usuarios.json`, `intentos.json`, `auditoria-*.json`, `copias.json`, the `copias/` and `importar/` folders, and `codigo-instalacion.txt` until the installation finishes |
| `tests/f5_admin.php` | 176 checks driving the real panel through the API |
| `tests/f20_memoria.php` | 31 checks, with the API and with `--directa`: a database larger than memory exported and imported, uploads in pieces, the import folder, scheduled backups |
| `tests/f11_asistente.php` | 41 checks of the setup wizard, the configuration page, sessions and the direct connection |

## 9. Tests

`tests/f5_admin.php` starts two PHP built-in servers (one for the panel and one
for the API, so they do not wait for each other) and drives the panel with real
cookies and CSRF tokens: installation, login, databases, tables, columns, keys,
triggers, data, SQL editor, read-role permissions and audit. It uses a temporary
folder, so it does not touch your data.

```
php tests/f5_admin.php      → OK: 176   the panel, page by page
php tests/f11_asistente.php → OK: 41    setup wizard, direct connection, configuration, sessions, languages
php tests/f14_volcados.php  → OK: 54    dumps, difficult dumps, ZIP paths, all-or-nothing (12 more with servers)
php tests/f15_idiomas.php   → OK: 6     every text translated, none left over, browser language
php tests/f16_vistas_triggers.php → OK: 10    views and triggers exported (6 more run them in MySQL and PostgreSQL)
php tests/f17_rutinas_importadas.php → OK: 6    views and triggers imported (8 more with MySQL and PostgreSQL)
php tests/f18_access.php    → OK: 10    importing and exporting Access (3 more with PowerShell)
php tests/f19_escrituras_contra_sqlite.php → OK: 19  random writes and queries against SQLite
php tests/f20_memoria.php   → OK: 31    larger than memory, uploads in pieces, backups (also with --directa)
php tests/f22_sqlserver.php → OK: 4     the SQL Server script and its dump imported (more with PowerShell)
```

`tests/f20_memoria.php` runs the panel and the API with `memory_limit = 32M` on
a database of 120,000 rows, which as a PHP array takes several times that; with
`--directa` the panel uses the direct connection. It takes about a minute.

`tests/f5_admin.php` and `tests/f20_memoria.php` also take `--open-basedir`
(2.9): the panel and the API run as on a hosting that limits PHP to the
project and its data, without the system's temporary folder, with uploads in
a folder of their own (`upload_tmp_dir`) and PHP warnings shown in the
answers, so that any warning breaks a check. The CI runs them that way too.

`tests/f14_volcados.php` loads the dumps into real servers when it is told where
they are (`JSONSQLDB_TEST_MYSQL`, `JSONSQLDB_TEST_POSTGRESQL`, as
`host:user:password`) and imports Microsoft's Northwind script with
`JSONSQLDB_TEST_NORTHWIND=1`; CI does all three.

The tests need the cURL extension (the panel itself does not). On Windows with
XAMPP, enable it in `php.ini` (`extension=curl`).
