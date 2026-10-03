# 05 — jsonSQLDBadmin (administration panel)

A web panel to administer jsonSQLDB from the browser. Pure PHP, no Composer and
nothing from outside: Bootstrap, the stylesheet and the icons (inline SVG, no
icon font) are in `jsonsqldbadmin/`. Light and dark theme, remembered in each
browser; in Spanish and English (section 6).

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
  query costs.

With either, **the engine applies the role of the panel user**: a read-only
user cannot write even if a page of the panel failed to check, because the
statement is refused before it runs — by the API key with read permission in
API mode (if you set one, see section 2), and by the same list of allowed
statements in direct mode. `tests/f11_asistente.php` checks the direct-mode
case by calling the engine the way the panel does, bypassing the pages, and
fails if that check is removed.

The panel does not read or write the data files itself, with one exception:
the ZIP backup and restore (section 3), which need the files exactly as they
are on disk. Before exporting a ZIP, the panel asks the engine about that
database with the credentials of whoever asks, so a key limited to other
databases cannot take it.

**Sessions follow the stored user** (2.7.1): on every request the panel checks
that the user still exists with the same password and takes their role from
what is stored. Deleting a user or changing their password ends their open
sessions; changing your own password keeps the session you are using.

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
   - **Administrator**: user and password (at least 10 characters, bcrypt).
     There is no default password and no factory user.

   **The connection is tested before anything is written**: a wrong folder, an
   API that does not answer or a signature it rejects is reported on the same
   screen and no file is created. Then the wizard writes `config.php` from
   `config.dist.php` — every option keeps its explanatory comment — with
   permissions `0600`, and creates the user.
3. Check that `jsonsqldbadmin/` is writable while you install (for
   `config.php`) and that `jsonsqldbadmin/datos/` stays writable: users and the
   audit trail live there.

If you prefer to configure by hand, `php configurar.php` writes the panel's and
the API's configuration with matching keys; the panel then only asks for the
administrator. To **change the connection** later, delete `config.php` and
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
| `admin` | everything: create and delete databases, tables, columns, keys, triggers, rows and users |
| `lectura` | see structure and data, and run `SELECT` and `SHOW` from the SQL editor |

Included protections: session expiry on inactivity (`ADMIN_SESION_MINUTOS`), IP
lockout after repeated failures (`ADMIN_LOGIN_MAX_FALLOS`), CSRF token on every
form, `HttpOnly` + `SameSite=Strict` cookie, and `Secure` automatically when you
come in over HTTPS.

Everything that is done is recorded in `datos/auditoria-YYYY-MM-DD.json`: user,
IP, database, action and detail. It is browsed from the **Audit** tab and purged
on its own after `ADMIN_AUDIT_DIAS` days.

## 3. What it can do

**Databases** — list, create, delete and **export**. Deleting requires typing
the exact name of the database. There are two ways to export:

- **SQL dump**: a `.sql` with `CREATE TABLE`, the `INSERT`s, the unique and
  foreign keys (at the end, once every table exists) and the triggers. It is
  readable and can be re-run statement by statement.
- **ZIP copy**: the JSON files exactly as they are on disk, with their folder
  structure. Unzipped into `data/` the database is restored, metadata and
  revisions included. It is the faithful copy.

Next to export there is a button to **restore from a ZIP copy**, with the same
conditions: it writes to the engine's disk, so the panel and the API must be on
the same machine. Before touching anything it sets aside what is there, and if
the restore fails halfway it puts it back.

Only the `.json` files are restored from the ZIP, plus `.htaccess` and
`web.config`; anything else is ignored. Table names are validated, the content
is checked to be JSON of the shape the engine expects, and **if any path in the
ZIP escapes the destination folder the whole file is rejected without touching
anything**: the classic ZIP attack.

The ZIP copy button **only appears if the panel and the API are served from the
same machine**. The panel compares the host of `ADMIN_API_URL` with its own; if
they differ, it hides the button and explains why, instead of letting you copy
the files of another installation that happened to be on the local disk.

The ZIP copy is the only thing in the panel that reads the engine's files
directly, and read-only: a faithful ZIP needs the `.json` files as they are, and
the API returns data, not files. The path comes from `ADMIN_RUTA_DATOS_MOTOR`,
or `../data` if left empty. The ZIP is built in a temporary file that is always
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
  the primary key, which is all the engine allows.
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
- Drop any unique or foreign key, any trigger and any hand-made index.

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

**Integrity** — a screen of its own that checks that no row points at a
non-existent value in its target table, and a button to fix by setting to
`NULL` what can be. It never deletes rows. A read-only user sees the report but
not the button. Useful when someone has edited a `.json` by hand or restored
the copy of one table without the other.

**Views** — a screen of its own in the menu: a list with their query, creation
with name and `SELECT`, and deletion. From the list you jump to the SQL editor
with the query already written. A read-only user sees them but cannot create or
delete them.

**SQL** — any statement, one per run, with the result as a table and the time
it took. With the `lectura` role only `SELECT` and `SHOW` are accepted.

The **SQL dump of a database** comes in four flavours (2.7.2), chosen in the
list of databases; the PostgreSQL and SQL Server ones are described in the
changelog and their headers say how to load them:

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
  number changed in silence. Views and triggers go **commented out** at the
  end: their SQL is jsonSQLDB's — `||` is a logical OR in MySQL, and a MySQL
  trigger needs `FOR EACH ROW`, `DELIMITER` and `SIGNAL` instead of `RAISE` —
  so they have to be reviewed before creating them there.

`tests/f14_volcados.php` builds a database with every type, foreign keys with
their actions, a self-referencing table stored child before parent, a cycle,
composite keys, indexes with the same name in two tables, autoincrement, and
text with quotes, backslashes, line breaks, accents and emoji; it loads the
first dump into SQLite with foreign keys on and the second into MySQL (MySQL 8
in CI; MariaDB 10.11 where it was written) and demands the same data, the
foreign keys enforced, the indexes, unique keys that tell capitals apart and
the autoincrement continuing.

**Import** (2.7) — on the page of each database, for administrators:

- **An SQL dump from jsonSQLDB, SQLite, MySQL / MariaDB, PostgreSQL or SQL
  Server** (2.7.2): the panel's own dump, one made with `sqlite3 base.db .dump`,
  with `mysqldump` / `mariadb-dump`, with `pg_dump` (plain format), or Management
  Studio's "Generate Scripts" (schema and data). PostgreSQL's `COPY` data,
  primary keys and `IDENTITY` declared after the table, and SQL Server's `GO`,
  `IDENTITY` and `ALTER TABLE … ADD DEFAULT … FOR` are understood; names that
  are not valid here become valid (`Order Details` → `Order_Details`). The format is detected from the first lines,
  or chosen by hand. SQLite and MySQL dumps are translated statement by
  statement (`lib/Traductor.php`): backtick and bracket names, MySQL's
  backslash escapes, `X'…'`, `0x…`, `b'…'` and SQLite's `char(10)`; MySQL
  types to the ones here (`INT(11) UNSIGNED` → `INTEGER`, `LONGTEXT` → `TEXT`,
  `ENUM` → `TEXT`…); the `KEY`s inside a `CREATE TABLE` become `CREATE INDEX`;
  a `CREATE UNIQUE INDEX` becomes a `UNIQUE` constraint; the foreign keys go at
  the end, because dumps create tables in any order and load data with checks
  off; and session statements (`PRAGMA`, `SET`, `LOCK TABLES`, `BEGIN`…), the
  `sqlite_sequence` table and mysqldump's `/*! … */` blocks are skipped. What
  has no equivalent here — `CHECK`, computed defaults other than the current
  date and time, `ON UPDATE`, `ENUM`'s list of values, binary `BLOB`
  data, MySQL views and triggers — is dropped, and the summary at the end says
  exactly what. `tests/f14_volcados.php` imports a real `sqlite3 .dump` and a
  real `mariadb-dump`, kept in `tests/volcados/`, and compares the data with
  the source databases; in CI it also creates the source in MySQL 8, dumps it
  with its `mysqldump` and imports that.
- **An SQL file** of jsonSQLDB statements: any list of statements separated by
  semicolons. It is read as a stream (only the statement in
  progress is in memory), split respecting strings, quoted identifiers,
  comments and the `BEGIN … END` of triggers, and run in order by the same
  route as the rest of the panel, so it works between machines. Consecutive
  `INSERT`s into the same table are sent as one statement of up to 200 rows.
- **A CSV into an existing table**: the first line gives the column names; the
  separator (comma, semicolon or tab) is taken from it; quotes follow the CSV
  rules; an empty field is `NULL`; an Excel byte-order mark is ignored. Rows go
  in batches of 200, with bound parameters.

A file that creates or drops databases is refused: the import is into one
database.

**All or nothing, when the panel reaches the database's folder** (2.7.3): with
the panel on the same machine as the engine (direct connection, or the API on
the same server with `ADMIN_RUTA_DATOS_MOTOR` pointing at its `data/`), the
import first copies the database next to it (`name.antes-de-importar`) and, if a
statement or a batch fails, puts it back as it was and says so; the copy is
removed when the import finishes. The engine has no multi-statement
transactions, so with the panel on another machine the import stops at the
failure and what came before is already written, as before: it says how many
statements or rows went in and where it stopped. Import into a new database when
in doubt.

**What the importer copes with** (2.7.3):

- **Files that are not in UTF-8**: an old MySQL dump, or one made with
  `--default-character-set=latin1`, or a CSV saved by Excel. Each statement or
  field that is not valid UTF-8 is read as Latin-1 / Windows-1252 and the
  summary says how many. Before, one such byte stopped the import with
  «Sentencia no soportada: 'I'».
- **Two names that become the same one** when made valid here (`ventas-2024`
  and `ventas_2024`, `Order Details` and `Order_Details`): the second table or
  view gets a suffix (`ventas_2024_2`) and the summary says so. Before, the
  second one's `DROP TABLE IF EXISTS` wiped out the first.
- **`BIT` columns of mysqldump**, which arrive as raw bytes, become the number
  they form; MySQL's **zero date `0000-00-00`** becomes `NULL`, with a warning;
  **`ALTER VIEW/FUNCTION/TYPE … OWNER TO`** of a `pg_dump` without `--no-owner`
  is skipped.
- A **`DECIMAL` of more than 15 digits** is warned about: here it is a
  floating-point number with about 15 exact digits. An integer beyond 64 bits
  (`BIGINT UNSIGNED` above 9223372036854775807) is an error, not a silently
  changed number. `tests/f5_admin.php` imports
the panel's own dump of a database with foreign keys and triggers and checks
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
Other functions and procedures are skipped.

**SQL Server** — with Management Studio:

1. Right-click the database → *Tasks* → *Generate Scripts…*
2. Choose the tables (or the whole database).
3. In *Set Scripting Options*, open *Advanced* and set *Types of data to
   script* to **Schema and data**.
4. Save to a single file. Either *Unicode text* (UTF-16, the default) or *UTF-8*
   works: the importer converts UTF-16 on its own.

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

What does not arrive the same in any of them: `CHECK` constraints, computed
default values other than the current date and time (`CURRENT_TIMESTAMP`,
`NOW()`, `getdate()`, `curdate()` and the like do arrive, since 2.8), `ENUM` value lists,
time zones of dates, and binary data that is not text (kept as its hexadecimal,
`\x…`). Names that are not valid here become valid: `Order Details` →
`Order_Details`. And if the dump contains `DROP TABLE`, a table with the same name
here is replaced: import into a new, empty database if in doubt.

**The SQL dump of a whole database is written table by table** (2.7.3): the
rows of each table are read when that table is written, and the dump goes to the
browser as it is made, so only one table is in memory at a time (before, the
whole database was), and since 2.8 a table that does not fit in memory is read
in batches (section 3.1). If something fails halfway, the download has already started: the file ends with an
`-- ERROR: the dump is incomplete` line. The dump for SQLite writes
`AUTOINCREMENT` only on an `INTEGER PRIMARY KEY`, the only place SQLite accepts
it. In views exported to other engines, `CAST(x AS INTEGER)` truncates as it
does here (MySQL and PostgreSQL would round), and `GROUP_CONCAT` without
`ORDER BY` has no defined order in any engine: write the `ORDER BY` when the
order matters.

**Export** — **CSV** and **INSERT** buttons on the data screen (exports the
whole table, with the ordering you have set, not just the visible page) and on
the SQL editor's result (exports what the query returned).

- A text cell that starts with `=`, `+`, `-` or `@` gets an apostrophe in front
  (2.7.3): a spreadsheet would otherwise take it for a formula and run it when
  the file is opened. Excel does not show the apostrophe; numbers, including
  negative ones, are not touched.
- The CSV carries a UTF-8 BOM so Excel does not break accents, and uses `;` as
  separator (`ADMIN_CSV_SEPARADOR`, change it to `,` for other tools). Nulls
  come out as an empty cell.
- INSERT generates one statement per row, with quoted names, single quotes
  doubled and nulls as `NULL`. It can be re-run as it is in the SQL editor of
  another database.
- When exporting a query result, the table name of the INSERTs is taken from
  the first `FROM` of the statement; if there is none, it is called `consulta`.
- There is no row cap (2.8): what does not fit in memory goes in batches
  (section 3.1).

### 3.1. Databases larger than memory, files larger than the upload limit (2.8)

**Exporting.** Before asking for the rows, the panel measures what a sample of
200 takes and works out how many fit in the free memory (`memory_limit` minus
what is already in use, with room left for the API's answer and the text being
written). If the whole table or result fits, it is asked for at once, as
before; if not, in batches of that size (`LIMIT … OFFSET …`, at most 100,000
rows per request), and only one batch is in memory. This holds for the SQL
dumps in every dialect, CSV and `INSERT` of a table or a query, and scheduled
backups. The types a dump works out from the data (text lengths, digits, the
range of integers for Access) come from a first pass that keeps no rows; and a
table that refers to itself, read in batches, cannot be ordered parents first,
so its foreign key goes at the end with the deferred ones. `ADMIN_EXPORT_MAX`,
the old row cap, is gone; a `config.php` that still defines it keeps working.

Two limits:

- A query with `ORDER BY` needs the engine to sort the whole result in its own
  memory; with the direct connection, that is the same memory as the panel's.
  If it does not fit, the panel says so before the download starts, or in the
  last line of the file (`ERROR: the export is incomplete`) if it fails later:
  the file is never taken as complete. Without `ORDER BY` there is no limit.
- Through the API each batch is a request of its own, so a write in the middle
  of a long export can show in part of the file. For a consistent copy of a
  database that is being written to, use the ZIP, which locks it while copying.

**Uploading.** `upload_max_filesize` and `post_max_size` limit each request,
not the size of a file. When the file chosen is larger than what fits in one
request, the browser cuts it into pieces that do (at most 8 MB) and sends them
one after another, with a progress bar; when the last one arrives, the form is
sent without the file. If the connection drops, choosing the same file again
resumes from what had arrived: the pieces are identified by the file's name,
size and date. A file uploaded in pieces is deleted when the import ends,
whether it went well or not; one left half way is deleted after two days.

**The import folder.** A file too large for the browser can be left by FTP in
`jsonsqldbadmin/datos/importar/`: the import forms then offer it in a list,
next to the file field. It is imported from there without being copied, and it
stays there afterwards; delete it when you no longer need it.

Importing already read the file statement by statement (SQL) or line by line
(CSV) and inserted in batches of 200 rows, so its size never mattered; a single
statement still has to fit in memory (mysqldump writes them of 1 MB at most).
Long imports and exports are no longer cut by `max_execution_time`.

**Excel.** Next to the CSV, the first sheet of an `.xlsx` file goes into a
table the same way: the first row has the column names. It is read row by row
with PHP's `ZipArchive` and `XMLReader`, without any library and without
loading the sheet; the shared strings go to a temporary file. Cells with a
date format arrive as `yyyy-MM-dd` or `yyyy-MM-dd HH:mm:ss`, booleans as 1 or
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
  be reached from the browser anyway. It exits with code 1 if a backup failed.
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
  attempts arriving at the same time are all counted.
- **The session cookie carries `Secure`** whenever the request came over HTTPS,
  including behind a trusted proxy (`ADMIN_CONFIAR_EN_PROXY`) that sends
  `X-Forwarded-Proto: https` (2.7.3); before, it only looked at the direct
  connection.

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
  origin.
- Every output goes through `h()` (`htmlspecialchars`), so a value with HTML in
  it is shown as text and not executed.
- Form values travel as **bound parameters**: they are never concatenated into
  the SQL. A field with `'); DROP TABLE customers; --` is stored as text and
  alters nothing.
- Table and column names are part of the statement, so they are validated
  against `^[A-Za-z_][A-Za-z0-9_]*$` and quoted with double quotes.
- `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff` and
  `Referrer-Policy: same-origin` headers.

## 6. Language

The panel is in Spanish and English. It follows the browser's language (the
first of its `Accept-Language` list that the panel has; English if there is
none) until the user picks one with the ES/EN selector in the top bar, on the
sign-in page or in the wizard; that choice is saved with the panel user and
follows them to any browser. Messages that come from the engine (a table that
does not exist, a constraint that fails) stay in Spanish: they belong to the
engine. To add a language, copy `idiomas/en.php`, translate the values (the
keys are the Spanish texts) and add it to `Idioma::DISPONIBLES` in
`lib/Idioma.php`; `tests/f15_idiomas.php` will tell you if a text is missing.

## 7. Configuration

Administrators change it from the panel's **Configuration** page (2.7.1): the
connection (mode, engine folder, API URL, keys, certificate, timeout), security
(allowed IPs, HTTPS, proxy, session length, login lockout, bcrypt cost, audit
retention) and the data screens (rows per page, characters per cell, CSV
separator, dump limit). What it saves goes into `config.php`, replacing each
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
  cannot be done from PHP's built-in server.
- Where the users are kept (`ADMIN_DATA_PATH`), the engine's data folder for the
  ZIP copy and the session cookie name are only changed by hand: changing them
  with the panel running would leave whoever is using it out.

`tests/f11_asistente.php` saves settings and checks the file, and checks each
refusal leaves `config.php` untouched.

| Constant | Default | Purpose |
|---|---|---|
| `ADMIN_CONEXION` | `api` | `api` or `directa` (section 1); the wizard sets it |
| `ADMIN_MOTOR_RUTA` | empty | direct connection: the jsonSQLDB folder (with `engine/` and `config.php`); empty = the panel's parent folder |
| `ADMIN_API_URL` | empty | URL of the API; empty = derived |
| `ADMIN_API_KEY` | admin key | API key the panel works with |
| `ADMIN_HMAC_SECRET` | secret | the same as the API's |
| `ADMIN_SSL_CA` | empty | path to the `.crt`/`.pem` of the API server |
| `ADMIN_SSL_AUTOFIRMADO` | `false` | accept the certificate without checking it |
| `ADMIN_TIMEOUT` | `60` | seconds to wait for the call |
| `ADMIN_DATA_PATH` | `datos/` | users and audit |
| `ADMIN_API_KEY_LECTURA` | empty | API key for read-only users |
| `ADMIN_HMAC_SECRET_LECTURA` | empty | its secret |
| `ADMIN_IPS_PERMITIDAS` | empty | IPs or CIDR ranges that may open the panel |
| `ADMIN_EXIGIR_HTTPS` | `true` | reject access over HTTP (the wizard can turn it off for local testing) |
| `ADMIN_CONFIAR_EN_PROXY` | `false` | trust X-Forwarded-For / -Proto |
| `ADMIN_SESION_NOMBRE` | `jsonsqldbadmin` | session cookie name |
| `ADMIN_SESION_MINUTOS` | `60` | maximum inactivity |
| `ADMIN_LOGIN_MAX_FALLOS` | `5` | attempts before locking the IP |
| `ADMIN_LOGIN_BLOQUEO_MIN` | `15` | minutes of lockout |
| `ADMIN_BCRYPT_COSTE` | `11` | password hash cost |
| `ADMIN_AUDIT_DIAS` | `90` | days of audit (0 = forever) |
| `ADMIN_FILAS_PAGINA` | `50` | rows per page in the data listing |
| `ADMIN_CELDA_MAX` | `120` | characters before a cell is truncated |
| `ADMIN_CSV_SEPARADOR` | `;` | separator of the exported CSV |
| `ADMIN_RUTA_DATOS_MOTOR` | empty | the engine's `data/` folder, for the ZIP copy |

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
| `jsonsqldbadmin/lib/Exportar.php` | export to CSV, `INSERT` statements, ZIP and SQL dumps for SQLite, MySQL / MariaDB, PostgreSQL and SQL Server |
| `jsonsqldbadmin/lib/Importar.php` | import of SQL dumps, CSV and Excel, and restore of a ZIP backup |
| `jsonsqldbadmin/lib/Lotes.php` | rows of a query at once or in batches, according to the free memory |
| `jsonsqldbadmin/lib/Subidas.php` | where the file of an import comes from: uploaded whole, in pieces, or from the import folder |
| `jsonsqldbadmin/lib/LectorXlsx.php` | reads the first sheet of an `.xlsx` row by row |
| `jsonsqldbadmin/lib/Copias.php` | scheduled backups |
| `jsonsqldbadmin/lib/arranque.php` | loads the configuration and the classes, for `index.php` and the cron script |
| `jsonsqldbadmin/herramientas/copias-cron.php` | makes the scheduled backups that are due, from cron or the Task Scheduler |
| `jsonsqldbadmin/lib/Traductor.php` | translation of SQLite, MySQL, PostgreSQL and SQL Server dumps into jsonSQLDB's SQL |
| `jsonsqldbadmin/lib/Idioma.php` | the panel's language: browser, user choice, `t()` |
| `jsonsqldbadmin/idiomas/en.php` | the English texts, keyed by the Spanish ones |
| `jsonsqldbadmin/lib/Store.php` | reading and writing of the panel's JSON files |
| `jsonsqldbadmin/lib/iconos.php` | the icons, inline SVG |
| `jsonsqldbadmin/lib/util.php` | escaping, URLs, messages and validation |
| `jsonsqldbadmin/lib/acciones.php` | every action that changes something |
| `jsonsqldbadmin/vistas/` | pages |
| `jsonsqldbadmin/assets/` | Bootstrap 5.3.3 (CSS and JS), local |
| `jsonsqldbadmin/herramientas/access-to-jsonsqldb.ps1` | the PowerShell script that dumps a Microsoft Access database (Windows), downloaded from the import box |
| `jsonsqldbadmin/assets/panel.css` | the design: tokens for light and dark theme, layout and components |
| `jsonsqldbadmin/assets/panel.js` | sidebar, theme, confirmations, Ctrl+Enter, wizard options, column fields, selects that submit their form, uploads in pieces, scheduled backups without cron |
| `jsonsqldbadmin/datos/` | `usuarios.json`, `intentos.json`, `auditoria-*.json`, `copias.json`, the `copias/` and `importar/` folders, and `codigo-instalacion.txt` until the installation finishes |
| `tests/f5_admin.php` | 142 checks driving the real panel through the API |
| `tests/f20_memoria.php` | 21 checks (22 with `--directa`): a database larger than memory exported and imported, uploads in pieces, the import folder, scheduled backups |
| `tests/f11_asistente.php` | 35 checks of the setup wizard, the configuration page, sessions and the direct connection |

## 9. Tests

`tests/f5_admin.php` starts two PHP built-in servers (one for the panel and one
for the API, so they do not wait for each other) and drives the panel with real
cookies and CSRF tokens: installation, login, databases, tables, columns, keys,
triggers, data, SQL editor, read-role permissions and audit. It uses a temporary
folder, so it does not touch your data.

```
php tests/f5_admin.php      → OK: 142   the panel, page by page
php tests/f11_asistente.php → OK: 35    setup wizard, direct connection, configuration, sessions, languages
php tests/f14_volcados.php  → OK: 31    dumps, difficult dumps, ZIP paths, all-or-nothing (30 with servers)
php tests/f15_idiomas.php   → OK: 6     every text translated, none left over, browser language
php tests/f16_vistas_triggers.php → OK: 11   views and triggers exported, run in MySQL and PostgreSQL
php tests/f17_rutinas_importadas.php → OK: 11    views and triggers imported from MySQL, PostgreSQL, SQL Server
php tests/f18_access.php    → OK: 13    the Access script, importing and exporting Access
php tests/f19_escrituras_contra_sqlite.php → OK: 7  random writes and queries against SQLite
php tests/f20_memoria.php   → OK: 21    larger than memory, uploads in pieces, backups (22 with --directa)
```

`tests/f20_memoria.php` runs the panel and the API with `memory_limit = 32M` on
a database of 120,000 rows, which as a PHP array takes several times that; with
`--directa` the panel uses the direct connection. It takes about a minute.

`tests/f14_volcados.php` loads the dumps into real servers when it is told where
they are (`JSONSQLDB_TEST_MYSQL`, `JSONSQLDB_TEST_POSTGRESQL`, as
`host:user:password`) and imports Microsoft's Northwind script with
`JSONSQLDB_TEST_NORTHWIND=1`; CI does all three.

The tests need the cURL extension (the panel itself does not). On Windows with
XAMPP, enable it in `php.ini` (`extension=curl`).
