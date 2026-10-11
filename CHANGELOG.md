# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Given that the only supported way in is the HTTP API, the public surface for
versioning purposes is: the API request and response format, the SQL dialect, the
configuration constants, and the on-disk format of `data/`.

## [2.9.0] - 2026-10-11

Transactions, window functions, `WITH RECURSIVE`, `COLLATE NOCASE` columns,
`CHECK` and `ENUM`, `UPDATE` and `DELETE` with other tables, files that a hand
edit broke repaired on their own when it is safe, deletes that no longer move
the rows behind them, OPcache used for the table cache, the common SQL of
SQLite and MySQL/MariaDB, JSON and Excel in the panel, the fixes of two
external audits, a million rows with the same results (what does not fit in
memory goes to disk), upserts that no longer load the table, and faster reads
and writes than 2.8.0, also without OPcache (see Checked). Existing data is
read as it is; index pieces written by 2.9 carry up to four more fields before
their keys (a filter of their keys, which keys it covers, and their smallest
and largest text value), which older versions read and ignore.
A deleted row now leaves a hole that 2.8 and earlier cannot read: run `VACUUM`
before going back to one (see Changed). The cache starts over (see Changed).

### Added

- **The API can be turned off**: `JSONSQLDB_API_ACTIVA` in `config.php`, `true`
  by default, as `JSONSQLDB_CONEXION_DIRECTA` is `false`. Off, every request
  gets HTTP 403 and `La API está desactivada` before its key is looked at, and
  nothing is logged or written. The panel then goes by direct connection. A
  `config.php` without the constant keeps the API on.
- **Window functions**, all of SQLite's and checked against it, 460
  combinations of function and window: `ROW_NUMBER`, `RANK`, `DENSE_RANK`,
  `PERCENT_RANK`, `CUME_DIST`, `NTILE`, `LAG`, `LEAD`, `FIRST_VALUE`,
  `LAST_VALUE`, `NTH_VALUE` and every aggregate with `OVER`; `PARTITION BY`,
  `ORDER BY` with `NULLS FIRST`/`LAST`, frames `ROWS`, `RANGE` (also with a
  distance) and `GROUPS` with `EXCLUDE`, named windows (`WINDOW w AS …`, `OVER
  w`, `OVER (w …)`), with `GROUP BY` (`SUM(SUM(x)) OVER ()`) and in the `ORDER
  BY`. Without `ORDER BY` the rows come out in the order of the first window,
  as in SQLite. A running sum or a ranking per partition over 20,000 rows:
  about 100 ms. What SQLite rejects, or a window function outside the columns
  and the `ORDER BY` of a `SELECT`, is an error that says so. The panel
  exports them to MySQL, PostgreSQL and SQL Server, and leaves commented what
  one of them lacks (`GROUPS`, `EXCLUDE`, `FILTER`, `NTH_VALUE` in SQL Server,
  Access altogether).
- **`FILTER (WHERE …)` after an aggregate**, as in SQLite and PostgreSQL:
  `COUNT(*) FILTER (WHERE paid = 1)`, also with `GROUP BY`, in `HAVING` and in
  a window.

- **`WITH RECURSIVE`**, as in SQLite (with or without the word `RECURSIVE`):
  initial parts, then recursive ones, with `UNION` or `UNION ALL`, `LIMIT` and
  `OFFSET`. Computed in batches, as PostgreSQL and MySQL do: each round gives
  the recursive parts all the new rows of the previous one; without `ORDER
  BY` the order can differ from SQLite's, never the rows. No limit of rounds,
  as in SQLite. What would give something else in batches (an aggregate,
  `DISTINCT`, the query on the `NULL` side of an outer join, naming it twice)
  or `ORDER BY` inside is an error that says so. 10,000 rounds: 109 ms. The
  panel exports it to MySQL and PostgreSQL with `RECURSIVE`, to SQL Server
  without it and with a note about its limit of 100 levels; with `LIMIT`, or
  `UNION` in SQL Server, or in Access, it stays commented with the reason.
- **A list of column names in a `WITH`** (`WITH t(a, b) AS (…)`), and a `WITH`
  inside a subquery (`x IN (WITH … SELECT …)`, `(WITH … SELECT …)`).

- **`COLLATE NOCASE` and `COLLATE RTRIM` in a text column**, as in SQLite and
  checked against it: the value is kept as written, and the column compares
  with its collation in `=`, `<`…, `IN`, `BETWEEN`, `CASE`, `ORDER BY`,
  `GROUP BY`, `DISTINCT`, `MIN`/`MAX`, windows, `NULLIF` and `MIN`/`MAX` of
  several values, and in its `UNIQUE`, primary key, indexes and the foreign
  keys that point to it. SQLite's rules decide which collation a comparison
  uses (written `COLLATE` first, also inside an expression; then the column on
  the left, then the one on the right; `CAST` keeps the column; in `x IN
  (SELECT y …)`, `y`'s), and views, subqueries, `WITH` queries and the columns
  of a `UNION`, `INTERSECT` or `EXCEPT` keep it. A `JOIN` on such columns goes
  by hash or by an index with the same collation (20,000 by 10,000 rows:
  60 ms). `ALTER TABLE … MODIFY` adds or changes it, rebuilding the indexes,
  and is refused if a `UNIQUE` would have repeated values or a foreign key
  would lose its row; `SHOW CREATE TABLE` and `SHOW COLUMNS` (`colacion`) give
  it. The panel shows it in the structure and keeps it when a column is edited
  (and `UNSIGNED` too, which it lost); its importer turns MySQL's `…_ci`, SQL
  Server's `…_CI_…` and PostgreSQL's `citext` into `NOCASE` with a notice —in
  MySQL also the table's or its character set's, which is what a text column
  without its own has there—, and
  its dumps write the closest one in each system: `utf8mb4_general_ci` in
  MySQL, `CITEXT` in PostgreSQL, `Latin1_General_100_CI_AS` in SQL Server.
  A `COLLATE` written in a view or a trigger is exported too.

- **`UPDATE` and `DELETE` with other tables**, in the forms of SQLite and
  PostgreSQL (`UPDATE t SET … FROM s …`, `DELETE FROM t USING s …`) and in
  those of MySQL (`UPDATE t JOIN s ON … SET t.c = …`, `DELETE t FROM t JOIN s …`,
  `DELETE FROM t USING t JOIN s …`). The join is computed once with the
  `SELECT` engine and the data as it was before the statement, then each row
  is written by its position, with its `CHECK`, keys, cascades, triggers and
  `RETURNING` as in any other `UPDATE` or `DELETE`. A row that matches several
  takes the first match. One table is written; two in the `SET`, or `DELETE
  t1, t2`, and `ORDER BY` or `LIMIT` with other tables, are an error that says
  so. 5,000 rows of 20,000 changed from another table: 95 ms, against 14.7 s
  with the subqueries it used to take. Checked against SQLite, also in the
  random writes of `f19`. `UPDATE` and `DELETE` also take an alias for their
  table (`UPDATE t AS a SET … WHERE a.c = 1`).

- **`CHECK` constraints.** On a column or on the table, with or without a
  name, also on a column added or changed with `ALTER TABLE … ADD`, `MODIFY`
  or `CHANGE` (checked first against the rows already there): every row an
  `INSERT`, `UPDATE`, `UPSERT` or cascade writes must not make them false
  (`NULL` passes, as in SQLite and MySQL). Checked against SQLite statement by
  statement. A column a `CHECK` uses cannot be dropped or renamed. Tables
  without one do not change on disk.
- **Importing keeps `CHECK` and `ENUM`.** A dump's `CHECK` constraints (on a
  column, on the table, or added with `ALTER TABLE`) arrive and are enforced,
  PostgreSQL's `::type` conversions removed; one that uses something this
  engine does not have is dropped and the summary says so, as before. `ENUM`
  keeps its list of values; `SET` is still text. Tested with the real MySQL and
  PostgreSQL dumps in `tests/volcados/`.
- **Exporting carries `CHECK` and `ENUM` to every system.** MySQL gets `ENUM`
  as such; SQLite, PostgreSQL and SQL Server get the column's text type and a
  `CHECK (col IN (…))`. A `CHECK` is written in each system's SQL (its quoting,
  `<>`, `CHAR_LENGTH` in MySQL, `LEN` in SQL Server, `COALESCE`); one that would
  not mean the same there (`||` in MySQL or SQL Server, an unknown function),
  and any in Access, goes as a note above the table, to add by hand. The SQLite
  dump is loaded in SQLite in the tests and enforces them.
- **`SHOW COLUMNS` has `valores` and `SHOW KEYS` lists `CHECK`s.** `valores` is
  an `ENUM`'s list (or `null`); each `CHECK` is a row of `tipo` `CHECK` with
  its `expresion` (a new field, `null` in the other rows). The panel's
  Structure tab shows both, and editing an `ENUM` column there keeps its list.
- **`ENUM('a', 'b', …)`, from MySQL.** A text that only takes its values,
  matched without regard to case and stored as written in the list; with `NOT
  NULL` and no `DEFAULT`, the first one. It sorts as text, not by position.
- **`EXPLAIN SELECT …`** (also `EXPLAIN ANALYZE` and `EXPLAIN QUERY PLAN`).
  The index is chosen while reading, from the data, so `EXPLAIN` runs the query
  (without the result cache, without keeping its rows) and tells how each table
  was read: by which index and how (`=`, `IN`, range, in order), a count or a
  smallest and largest taken without reading rows, a scan, or a `JOIN` looking
  each row up in an index, with the rows each read gave. A read-only key can use
  it, and also `SHOW CREATE TABLE`. Normal queries are not slower (measured).
- **`ALTER TABLE … ADD [CONSTRAINT name] CHECK (…)` and `DROP CHECK name`.**
  As in MySQL, adding one first checks the rows already there, and leaves the
  table as it was if any breaks it. `DROP CONSTRAINT name` removes it too.
- **`SHOW CREATE TABLE` and `SHOW CREATE VIEW`.** The statement that creates
  the table or view as it is, with its keys, defaults, `CHECK`s, `ENUM`s and
  indexes; run in another database it makes the same structure (tested both
  ways).
- **An integer primary key numbers itself, as in SQLite.** With one integer
  column as primary key and no `AUTOINCREMENT` (`id INTEGER PRIMARY KEY`, the
  most common SQLite table), an `INSERT` that leaves it out or gives `NULL`
  gets the largest id + 1, taken from its index without reading the table; it
  used to be an error. Several rows in one `INSERT` get consecutive ids, and an
  explicit larger id in between moves the next one past it. As in SQLite, the
  id of a deleted row that was the largest can come back; `AUTOINCREMENT` never
  reuses one. Since `INT` and `BIGINT` are stored as `INTEGER`, `INT PRIMARY
  KEY` numbers itself too (SQLite would store a `NULL` key, MySQL would refuse
  it). Checked against SQLite step by step.
- **More of SQLite and MySQL, second round.** Functions: `glob()`, `like()`,
  `likelihood`, `likely`, `unlikely`, `octet_length`, `bit_length`, `quote`,
  `soundex`, `unhex`, `randomblob`, `zeroblob`, the trigonometric ones,
  `degrees`, `radians`, `cot`, `bin`, `oct`, `conv`, `bit_count`,
  `find_in_set`, `insert`, `strcmp`, `md5`, `sha1`, `sha2`, `crc32`,
  `to_base64`, `from_base64`, `uuid`, `utc_date`, `utc_time`,
  `utc_timestamp`, `timestamp`, `to_days`, `makedate`, `period_diff`,
  `sec_to_time`, `time_to_sec`, `timediff`, `time_format`, `week`,
  `yearweek`, `weekofyear`, and `json`, `json_valid`, `json_array`,
  `json_object`, `json_type`, `json_array_length`, `json_length`,
  `json_keys`, `json_unquote`, `json_contains`, `json_set`, `json_insert`,
  `json_replace`, `json_remove`, with the operators `->` and `->>` (also
  `col -> 'a'` and `col -> 2` for `$.a` and `$[2]`, as in SQLite). Aggregates:
  `string_agg`, `json_group_array`/`json_arrayagg`,
  `json_group_object`/`json_objectagg`, `any_value`, `std`, `stddev`,
  `stddev_pop`, `stddev_samp`, `variance`, `var_pop`, `var_samp`, `bit_and`,
  `bit_or`, `bit_xor`. `x [NOT] GLOB pattern`. Checked one by one: the SQLite
  ones give what SQLite 3.45 gives (45 of 45), the MySQL ones what the MySQL
  manual says (55 of 55); `WEEK`, `YEARWEEK` and `TO_DAYS` follow MySQL's own
  calculation, all eight week modes included. Where the two clash, `TIMEDIFF`
  is MySQL's (`'01:00:00'`, with microseconds when there are any).
  `RANDOMBLOB` and `ZEROBLOB` give bytes, which are fine inside an expression
  (`HEX(RANDOMBLOB(16))`) but not in a table: storing them as they are is
  always an error, seen in the statement, so it does not depend on whether
  some random bytes happen to be valid text. Not taken, on purpose: `LOG(x)`
  with one argument (base 10 in SQLite, natural in MySQL), MySQL's compact
  dates like `'950501'` (a Julian day in SQLite), `PRAGMA`, and the system
  tables (`sqlite_master`, `information_schema`).
- **Statements that do nothing here no longer fail.** `ANALYZE [TABLE] [t]` (the planner keeps no
  statistics), `USE` of the same database (another one is an error: each
  connection and each API request goes to one), and `SELECT … FOR UPDATE`,
  `FOR SHARE [OF t] [NOWAIT | SKIP LOCKED]` and `LOCK IN SHARE MODE` (every
  read already sees a whole state).
- **`VACUUM`** rewrites, without them, the tables that have holes of deleted
  rows (see Changed), and rebuilds the indexes that have a piece written by
  2.8 or earlier, without touching the data, so that a lookup by a text key
  reads only the piece that can hold it: on 20,000 customers written by 2.8.0,
  by a unique email 0.49 → 0.31 ms, after a `VACUUM` of 0.3 s. A second
  `VACUUM` touches nothing. `VACUUM INTO` is an error that says how to make a
  copy.
- **`BEGIN` and `COMMIT` in a batch.** `BEGIN` (or `START TRANSACTION`) as the
  first statement of a batch and `COMMIT` (or `END`) as the last are taken
  and do nothing more, since the batch is already a transaction; `ROLLBACK` as
  the last runs the batch and undoes all of it. Anywhere else, and as a
  statement of its own, they are an error that explains why: transactions
  never span requests.
- **Transactions.** With the direct connection, `$db->transaccion(function
  ($db) { … })`: everything the function does to the database happens, or
  nothing does; inside, each statement sees what the previous ones did, and
  other processes see nothing until it ends. An exception that leaves the
  function undoes it all; a statement that fails inside leaves nothing of its
  own, and the rest goes on if the function catches its error. If a file
  cannot be put in its place half-way through a statement, the transaction can
  only be undone: it takes no more writes, and it is undone when the function
  returns. `REPAIR TABLES FORGET n` takes effect when the transaction is
  committed, so one undone leaves the row pending; `REPAIR TABLES` itself
  cannot run in a transaction, since what it sets aside is recorded outside
  it. `$db->lote([...])` runs a list of statements the same way, and the API
  takes it as the new `lote` field (signed in place of `sql` and `params`),
  returning one result per statement; the three example clients send it with
  `lote()` (PHP and Python) and `API-SQL-Lote` (PowerShell). In the panel's
  SQL console, several statements separated by `;` run as one transaction. If
  the process dies halfway, the transaction is undone when the database is
  next opened. A single statement works exactly as before. Transactions never
  span requests: a client that hangs could otherwise leave tables locked.
- **Files edited or broken by hand.** Each cached table part, structure and
  index piece carries the seal of its file (size, date, inode): an edit by
  hand is seen by the next query that reads the file instead of staying hidden
  behind the cache, and a valid edit gets the table rewritten so that its
  indexes and offsets match it, which also retires its cached query results. A
  JSON broken by an extra or missing comma, bracket or brace between rows, a
  table state or an index piece, or a structure with a trailing comma, is
  repaired on its own when it is read, keeping the original in `.reparados/`;
  so are `_views.json` and the byte order mark an editor may put at the start
  of a file. A row that cannot be fixed without guessing makes the query fail
  with the file and the line, and nothing is touched, until it is fixed by
  hand or `REPAIR TABLES` sets it aside (it stays in the original kept in
  `.reparados/`, and the result of `REPAIR TABLES` lists it with its file,
  line and text under `apartadas`). `COUNT(*)` counts a row followed by extra
  commas as reading it does. Every part a write puts in place leaves in the
  cache its seal and a CRC-32 of its text (one small entry per write, with
  those of all the parts it puts in place), so an edit that keeps the length
  of every line is seen too, by the first query that reads that part, even if
  it had never been read before. A table that does not fit in memory is
  repaired part by part. A row set aside stays pending, with a number that is
  never reused, until it is put back fixed or discarded with the new `REPAIR
  TABLES FORGET n`; in the panel each one has an editor with a real row of its
  table as an example (a made-up one if the table is empty), and saving it is
  checked by the panel and by the engine as it inserts it, together with the
  `FORGET` in one transaction. `CHECK TABLES [FROM t]` lists what is damaged;
  in the panel, the **Integrity** tab.
- **OPcache for the table cache.** If PHP has OPcache active, each table part
  that has not changed for two seconds is also kept as a `.php` file holding
  only its data, which OPcache keeps compiled in shared memory between
  requests. Measured between web requests on 60,000 rows: loading the whole
  table from the cache from about 22 ms to about 0.15 ms, full scans 33–56 %
  faster, queries by index the same. Writes only delete the `.php` of the parts
  they rewrite. Nothing more is given to OPcache once less than a quarter of
  its memory or keys is free, so it can neither fill nor restart because of
  the database. On by default; `JSONSQLDB_CACHE_OPCACHE = false` turns it off. Restoring a ZIP
  in the panel never writes into `.cache/`.
- **`JSONSQLDB_CACHE_APCU`**: APCu was already used when present; `false` now
  leaves it aside and keeps the cache on disk.
- **The common SQL of SQLite and MySQL/MariaDB.** Functions: `UCASE`/`LCASE`,
  `CHAR_LENGTH`, `MID`, `LEFT`, `RIGHT`, `LPAD`, `RPAD`, `REVERSE`, `REPEAT`,
  `SPACE`, `CONCAT_WS`, `LOCATE`, `SUBSTRING_INDEX`, `CHAR`, `UNICODE`, `ASCII`,
  `HEX`, `FIELD`, `ELT`, `CEIL`, `FLOOR`, `TRUNC`, `TRUNCATE(x, d)`, `SIGN`,
  `MOD`, `POW`, `SQRT`, `EXP`, `LN`, `LOG10`, `LOG2`, `LOG(base, x)`, `PI`,
  `GREATEST`, `LEAST`, `RAND`, `TOTAL`, `GROUP_CONCAT … SEPARATOR`, `NOW`,
  `CURDATE`, `CURTIME`, `SYSDATE`, `UTC_DATE`, `UTC_TIME` and `UTC_TIMESTAMP`
  (with or without parentheses), `YEAR`, `MONTH`, `DAY`, `HOUR`, `MINUTE`,
  `SECOND`, `DAYOFWEEK`, `WEEKDAY`, `DAYOFYEAR`, `QUARTER`, `DAYNAME`,
  `MONTHNAME`, `LAST_DAY`, `DATEDIFF`, `UNIX_TIMESTAMP`, `FROM_UNIXTIME`,
  `DATE_FORMAT`, `STR_TO_DATE`, `DATE_ADD`/`DATE_SUB`/`ADDDATE`/`SUBDATE`,
  `TIMESTAMPDIFF`, `TIMESTAMPADD`, `+ INTERVAL n unit`, `JULIANDAY`,
  `UNIXEPOCH`, `IF`, `IIF`, `ISNULL`, `TYPEOF`, `json_extract`, `FORMAT` and
  `PRINTF`. Syntax: `INSERT IGNORE`, `INSERT … SET`, `INSERT … DEFAULT VALUES`,
  `ON DUPLICATE KEY UPDATE … VALUES(col)`, `UPDATE`/`DELETE … ORDER BY … LIMIT`,
  `TRUNCATE TABLE`, `DESCRIBE`, `CREATE TABLE t2 LIKE t` (with its `CHECK`s, as
  in MySQL), `ALTER TABLE CHANGE`,
  `ADD`/`DROP INDEX`, `KEY` and `UNIQUE KEY` inside `CREATE TABLE`, table
  options (`ENGINE`, `CHARSET`, `COLLATE`, `ROW_FORMAT`, `COMMENT` accepted;
  `AUTO_INCREMENT = n` applied), the types `BIT`, `YEAR`, `TINYTEXT`,
  `MEDIUMTEXT`, `LONGTEXT`, `JSON`, blobs and `UUID`, `UNSIGNED` (rejects
  negatives), `JOIN … USING`, `NATURAL JOIN`, `NULLS FIRST`/`LAST`, `LIMIT ALL`,
  `==`, `<=>`, `IS [NOT] DISTINCT FROM`, `& | ~ << >>`, `DIV`, `MOD`, `XOR`,
  `TRIM(LEADING … FROM …)`, `POSITION(a IN b)`, `SUBSTRING(x FROM i FOR n)`,
  `EXTRACT`, `CONVERT`, `CAST(… AS SIGNED/UNSIGNED)` and `COLLATE NOCASE`,
  `RTRIM` and `BINARY` in expressions. `CREATE UNIQUE INDEX` now creates a
  `UNIQUE` constraint. `LOG(x)` with one argument, `HEX(number)`,
  `LAST_INSERT_ID()` and `CHANGES()` give an error that says what to use
  instead (`LN`/`LOG10`, `RETURNING`). A query that gives `'now'` to any date
  function, written in it or as a parameter, is not kept in the result cache.
- **Panel: JSON and Excel.** A table or a query exported to JSON or `.xlsx`
  (numbers and dates with their type, rows spread over several sheets when they
  do not fit, a warning for cut texts; Excel only with the `zip` extension);
  JSON imported into a table; the whole database exported and imported as one
  JSON document; a window with import examples using the columns of the chosen
  table and templates to download in CSV, Excel and JSON.
- **Panel: SQL Server.** `herramientas/sqlserver-to-jsonsqldb.ps1` exports a SQL
  Server database (every schema, tables, keys, indexes, data, views and
  triggers) and imports a dump back into SQL Server in one transaction; it is
  offered from the import screen and the dumps guide.
- **A subquery in the `FROM` without an alias**, as in SQLite and PostgreSQL:
  `SELECT COUNT(*) FROM (SELECT DISTINCT ciudad FROM clientes)`. 2.8.0
  answered «Una subconsulta en FROM necesita alias». Its columns are named
  without a table in front.

### Security

- **API: an IPv6 client could get around the request limit and the block
  after failed attempts** (also in 2.8.0) by changing address on every
  request, since a provider gives each customer a /64 network, 2^64
  addresses. An IPv6 address now counts by its /64 for both; the request log
  and `IPS_PERMITIDAS` still use the whole address, and `::ffff:1.2.3.4`
  counts as `1.2.3.4`.
- **A repeated request could lock an IP out for 24 hours** (audit H2): two
  identical statements in the same second gave the same signature and the
  second was taken as a replay. The signature now carries a random `nonce`;
  the panel and the three example clients (PHP, Python, PowerShell) send it, and
  a signature without one is still accepted.
- **Panel: only administrators export and dump** (audit H8), as documented.
- **Panel: the read-only role could slip a write behind a `SELECT`** once the
  console accepted several statements; each statement is checked.
- **Panel: a key or secret saved from the Configuration page could end up as
  PHP code in `config.php`** (also in 2.8.0). Each value was found with a
  regular expression that stopped at the first `);`: one that carried it,
  saved a second time, left the rest outside the text. And the setup wizard
  lost a `$1` in a key, taken for a back-reference. Both now find the value
  by PHP's own tokens. Only an administrator can save there. Found in the
  security review of 2.9, like the five below.
- **Panel: anyone could fill the disk through the audit trail** (also in
  2.8.0): every failed sign-in wrote the user name it carried, whatever its
  size, also without the form's token or from an IP already locked out. Only
  real attempts are written now, which the lockout limits, with the name cut
  to 64 characters.
- **Panel: a ZIP restored over a database replaced its `.htaccess` and
  `web.config`** (also in 2.8.0), the files that deny the folder to the
  browser: a ZIP made for it could leave the folder downloadable. They are no
  longer taken from the ZIP; the database keeps its own, or gets the engine's.
- **Panel: restoring a ZIP, and the copy an import made, did not check the
  API key's databases** (also in 2.8.0). With a panel key limited to some
  databases, a ZIP could overwrite another one, and an import into another
  one that failed put back its copy. Both now ask the API first, as the ZIP
  export already did; an import no longer copies the database (see the entry on
  all-or-nothing imports below).
- **Panel: signing in took less time for a user that does not exist** (also
  in 2.8.0): that check used a cost of 10, the real ones `ADMIN_BCRYPT_COSTE`
  (11), and the difference told which names exist (74 / 131 ms).
- **Functions that build a text have a ceiling.** `ZEROBLOB`, `RANDOMBLOB`,
  `LPAD`, `RPAD` and the width or precision of `PRINTF` (new in 2.9), like
  `REPEAT`, refuse a result of more than 16 MB with an error that says so,
  instead of running a request out of memory. And a key limited to some
  databases cannot put `SHOW DATABASES` in a `lote`, where its result could
  not be filtered and showed them all.

From the security review at the end of 2.9, each with a test that fails
without its fix:

- **Panel: a ZIP restored over a database could make the engine delete or
  replace files of its folder** (also in 2.8.0), its `.htaccess` and
  `web.config` among them. Files under `.tx/` or `.lote/` were restored, and
  on opening the database the engine took them for a write cut short and
  carried them out. A ZIP now restores only the files of the tables and of
  the database, and `.reparados/`; and a journal or a transaction that the
  engine reads on opening can only put back, rename or delete files of the
  database's tables, never anything else of its folder.
- **SQL dumps: what goes commented could run in the other engine** (also in
  2.8.0). A `CHECK`, a default, or a view or trigger that the other engine
  does not accept goes in the dump as a `--` comment, with the reason; a line
  break inside it, or a lone carriage return, left what followed outside the
  comment, and loading the dump into MySQL, PostgreSQL or SQL Server ran it.
  Every line of a comment now starts with `--`.
- **Panel: signing in cleared the failed attempts of the whole IP** (also in
  2.8.0), so anyone with an account could keep guessing the password of
  another, signing in with their own every few tries, without ever being
  locked out. It now clears only those of the user who signs in. And each
  attempt is counted before its password is checked, under the lock: attempts
  at the same time all passed the check, and went past the limit. Failed
  attempts now expire `ADMIN_LOGIN_BLOQUEO_MIN` minutes after the last one;
  they never did, and a few typing mistakes a month ended in a lockout. A
  name that no user can have (3 to 32 letters, digits and `. _ - @`) is
  answered like a wrong password, in the same time, and counts against the IP
  under one short key, so names of any size never reach the attempts file
  that every sign-in reads.
- **API: a captured request could be sent again** when the client's clock
  ran more than a minute ahead (also in 2.8.0): its token was forgotten one
  window after it arrived, while it was valid until one window after its own
  time. It is now kept until then.
- **API: an unknown key was told apart from a wrong signature** (also in
  2.8.0), against what docs/04-api.md says: whoever had an old or leaked key
  could learn whether it still existed without its secret. Both now answer
  `Token inválido`, after the same checks.
- **API: a key that can only read could fill the disk through the result
  cache** (also in 2.8.0): every different query that took 1 ms or more left
  its whole result in a file (32 MB each in the test), and those that read no
  table were never deleted. On disk, a result of more than 1 MB is no longer
  kept, nor one that reads no table.
- **API: a key that can only read could hold the database for everyone** for
  up to the request's time limit, with a `lote` of heavy reads: a `lote`
  always took the exclusive lock. One that only reads now runs each statement
  with the shared lock, as a single `SELECT` does, and writes nothing to disk to
  be undone. The lock is released between statements: held for the whole
  `lote`, it deadlocked with an `INSERT` that checks a foreign key.
- **Behind a trusted proxy, the client chose its own IP** (also in 2.8.0):
  with `CONFIAR_EN_PROXY` or `ADMIN_CONFIAR_EN_PROXY` on, the API and the panel
  took the first IP of `X-Forwarded-For`, which the client writes, and a
  proxy only adds the real one after it: a client could get past
  `IPS_PERMITIDAS` and the lockout, or lock out another's IP. They take the
  last one now, the one the proxy adds.
- **Panel: changing your own password did not ask for the current one**
  (also in 2.8.0): a session left open for a moment was enough to take the
  account. It asks for it now, with the lockout of signing in.
- **Panel: the PHP version and full server paths showed before signing in**
  (also in 2.8.0): the sign-in page showed the exact PHP version, and the
  setup wizard, which anyone sees until it is finished, the absolute paths of
  its files and of the engine (in the direct connection's field) and its
  errors. The wizard now shows them from the project folder, and only PHP's
  major and minor version.
- **The PHP example client's shortcut stopped checking the certificate of
  any server** (also in 2.8.0): `JsonSqlDbCliente::pruebas()` accepted a
  self-signed certificate without saying so. It does that only on
  `localhost` now.
- **Panel: the setup wizard made the server request the address in the
  `Host` header** (also in 2.8.0). Its check that `data/` cannot be
  downloaded, which anyone can open before installation, requested
  `<scheme>://<Host>/data/web.config` with the `Host` the client sent and
  without checking the certificate: a request from the server to any machine
  and port of its network (`Host: 10.0.0.5:6379`). It now uses
  `ADMIN_API_URL` as written in the configuration and checks the certificate
  as the calls to the API do (`ADMIN_SSL_CA`, `ADMIN_SSL_AUTOFIRMADO`). While
  that URL is empty, the wizard says the check is made in Configuration, and
  that page, which only a signed-in administrator sees, uses the address of
  the installation from the `Host` header, so the direct connection, which
  has no API URL, is still checked.
- **Panel: a link could change the language saved for a signed-in user** (also
  in 2.8.0): `?idioma=en` by GET saved it in `usuarios.json`, so a link or an
  image on another page could change it. The selector's links now carry a
  token derived from the session's; without it, the choice changes only the
  session.
- **A statement with bytes that are not valid UTF-8 could leave no trace in
  the logs** (also in 2.8.0): the query log, and the API's request log,
  dropped the whole line when it could not be encoded, also for a write
  (`INSERT … -- \xff`), and the query log cut a long SQL in the middle of a
  letter (`ñ`), with the same result. Lines are now always written, with
  U+FFFD in place of what is not UTF-8, and the SQL is cut between letters.
- **A write whose triggers read other tables now locks the whole database**
  (also in 2.8.0): subqueries, `UPDATE … FROM`, `DELETE … USING` and
  `SELECT … FROM` in a trigger's statements were not counted, and those tables
  were read without a lock while another process could be writing them.

### Changed

- **The storage layer's methods that only some statements use take the
  storage as their first argument, in classes of their own** (see Faster):
  `$st->leerFilas($t)` is now `StorageEscritura::leerFilas($st, $t)`, and the
  same for the other methods that write rows; changing and removing rows
  already written is in `StorageCambios`, what only reads do
  (`contarFilas()`, the result cache…) in `StorageLectura`, and structure
  changes, transactions and repairs in `StorageMantenimiento`. It only
  concerns code that uses `Storage` directly; what the documentation shows of
  it (`Storage::crearBase()`, `bloquear()`, `desbloquear()` and `Catalog`'s
  methods) works as before. The internal methods of `Parser`, `Select`,
  `Evaluator`, `Functions`, `Indexes`, `Writer` and `Database` that only some
  statements use moved the same way; `Database::lote()` and
  `Database::consultarGlobal()` work as before.
- **The query log records writes and errors by default.** `JSONSQLDB_LOG_NIVEL`
  now defaults to `escrituras`, also with a `config.php` written before that
  does not define it: logging every read opened and appended to the
  log file in every request, about 0.09 ms, 16 % of a lookup by key between web
  requests (632 → 544 µs). `todo` brings reads back. At `escrituras`, `UNION`,
  `SHOW` and `CHECK` are now left out too, not only `SELECT`, as the setting
  always said.
- **Panel: `ADMIN_TIMEOUT` defaults to 300 s** (60 before), with the import
  fixes listed under Fixed. A `config.php` written before keeps its value.
- **The result cache keeps only what is worth keeping, and has a ceiling.** A
  query that took less than 1 ms is not stored (with OPcache, a lookup by key
  went from 691 to 657 µs between requests, against 612 µs with no result
  cache), and the results of the same tables go to 4,096 places at most
  (4,096 in all in APCu). Before, every different query left a file until the
  next write to its tables, so a table that is only read, queried row by row,
  piled them up without end and every write had to list them. A repeated
  expensive query is still served from the cache (9 ms → under 0.5 ms).
- **Deleting a row leaves a hole instead of moving the rows behind it.** The
  deleted row's line becomes `null` in its part, and the table's revision file
  counts the holes per part (`huecos`). Reads skip them, also if that file is
  lost, `COUNT(*)` does not count them, and `INSERT` still goes at the end, so
  the insertion order stays. Deleting the last rows leaves no holes: there are
  no rows behind them to keep in place, so the table is shortened as before,
  and a table used as a queue (rows added at the end and deleted soon after)
  does not pile them up.
  When holes would pass 25 % of a table, the delete that gets there rewrites it
  without them, and `VACUUM` now does that for every table that has any. A
  table with triggers or with a foreign key to itself, which a `DELETE` loads
  whole, is still written without the deleted rows. The
  files stay one row per line and readable; but a table with holes cannot be
  read by 2.8 or earlier, which stop with an error on the first scan (they do
  not return wrong rows): run `VACUUM` before going back to one.
- **The memory safety net no longer sets 2 MB aside in every request.** When
  PHP runs out of memory, the room to answer comes from raising
  `memory_limit` at that moment, by what the 2 MB gave; only if the hosting
  does not let `memory_limit` change (`ini_set` disabled, or the limit fixed by
  the server) are the 2 MB set aside as before. The limit is only ever raised,
  and only when it is short: the function runs at the end of every request,
  and lowering it would leave the shutdown functions after it without memory.
- **The cache starts over with 2.9.** Its entries carry the seal of their file,
  so 2.9 uses keys of its own (`jsq2:`) and ignores those of earlier versions,
  and an earlier version ignores those of 2.9. Deleting `.cache/` after
  upgrading frees the space taken by the old ones.
- **A table touched by a transaction that was undone gets a `gen` field in its
  `rev.json`**, which changes all its cache keys: after the undo its revisions
  count numbers already used inside, and no entry of what was undone can ever
  be served, also from APCu. Earlier versions ignore the field.
- **A damaged `rev.json` no longer restarts the revisions in silence**, which
  could serve stale rows from the cache: it is rebuilt from the data.
- **`||` binds as in SQLite: tighter than `* / %` and `+ -`**, with `->` and
  `->>` at its level. It sat below `+ -`, as in PostgreSQL: `1 + 2 || 3` gave
  `'33'` where SQLite gives 24, and `2 * 3 || 4` gave `'64'` (SQLite 68).
  Checked against SQLite on a broad set of mixed expressions. **Views,
  triggers, `CHECK` and `DEFAULT` stored by an earlier version are parsed with
  the new precedence**: one that mixes `||` with arithmetic without parentheses
  (`'A' || n + 1`) now means `('A' || n) + 1`; write `'A' || (n + 1)` where the
  old meaning is wanted. Importing keeps the meaning of the source: in a
  PostgreSQL view or trigger the arithmetic side of `||` gets parentheses, a
  SQL Server `+` that joins texts becomes `(a || b)`, and in a MySQL trigger
  `||` is `OR` unless the dump's `sql_mode` has `PIPES_AS_CONCAT` (before, it
  was always taken as joining texts). Exporting already wrote every operation
  in parentheses.
- **Comparing and sorting follow one total order: numbers and texts that are
  numbers first, by value, then the other texts.** A text that is a number and
  one that is not compared as texts, and so did a number and a text, so the
  order went round in a circle (`'9' < '10' < '1a' < '9'`) and `ORDER BY`,
  `ORDER BY … LIMIT`, `MIN` and `MAX` could disagree on the same column: with
  `'10'`, `'9'`, `'1a'`, `'5'`, `'2b'`, `'100'`, `'3'`, `ORDER BY` gave
  `9, 10, 1a, 2b, 3, 5, 100` and `MIN` `'3'`. Now `ORDER BY` gives
  `3, 5, 9, 10, 100, 1a, 2b`, and `WHERE`, `BETWEEN`, `MIN`/`MAX`,
  `GREATEST`/`LEAST`, windows and the sort read from an index or done on disk
  all agree. Equality does not change (`'12abc' = 12` is still false), and
  between two texts that are not numbers `ORDER BY` still uses the configured
  collation.

### Fixed

- **Panel with the direct connection: a sorted export of a large table
  stopped halfway** (found by the variant of `f20_memoria` with
  `--directa`): it asked for the rows in batches with `LIMIT … OFFSET …`, each
  one running the whole query again in the panel's own process, and with
  32 MB a sorted export of 120,000 rows ended after 12,500 with PHP's memory
  error. With the direct connection the query now runs once and its rows go,
  as the engine gives them, to a temporary file that the export reads. And a
  sort that asks PHP for its memory in one go (`asort`, `array_multisort`)
  first checks that it fits in what PHP can still ask for; if not, the query
  goes to disk, or ends with the memory error instead of PHP's fatal.
- **An `UPDATE` or `DELETE` that touches many parts held all of them in
  memory** (also in 2.8.0): the new text of every part it changed was kept
  until the end and then written. Updating 100,140 rows spread over a table of
  a million (all its parts) ran out of 256 MB with PHP's fatal error; each
  part is now written as it is made and let go: 10.1 s and 141 MB.
- **APCu filled up and emptied itself over and over** (also in 2.8.0). The
  engine stored in it every part it read, and APCu, when full, empties
  entirely (with `apc.ttl = 0`, its default): scanning a table larger than its
  memory filled it and wiped it on every scan, taking with it what other tables
  and applications kept there, and with a million rows and the default 32 MB a
  scan took 2.5 times what it takes without a cache. As with OPcache, nothing
  more is stored when less than 25 % of it is free; that scan now takes what
  it takes without a cache.
- **API: a statement with a space or a line break at either end was always
  refused** (also in 2.8.0): the API checked the signature of the statement
  without them, while every client signs what it sends, so it answered
  `Token inválido` and counted a failed attempt; ten of them locked the IP
  out for a day. The statement is now signed as it arrives, as `params` and
  `lote` already were.
- **Panel: in the Data tab, a column with `]` or `,` in its name made editing
  and deleting act on other rows** (also in 2.8.0): the name went into the
  form field as it was, and PHP cuts it at the first `]`; and the key columns
  were read from a list separated by commas. Deleting one row could delete
  others. The fields now carry the name in hexadecimal, and the key columns
  come from each column.
- **Panel: a CSV with an unclosed quote, or a ZIP with a huge file, ran PHP
  out of memory** (also in 2.8.0): the rest of the CSV was read into one field,
  and each file of the ZIP whole. Both now stop first with an error that says
  which line (counting the blank ones) or file, as a statement of a dump
  already did. The ZIP and its files are copied to disk in pieces, so together
  they can be larger than PHP's memory; only the files checked before
  restoring are read whole (each `.json` of the database, which the engine
  splits in parts of `JSONSQLDB_FILAS_POR_PARTE` rows, and
  `.reparados/apartadas.json`), and each can take up to a third of the free
  memory.
- **Panel: the time zone changed from one request to another** (also in
  2.8.0): the audit trail and the scheduled backups used `php.ini`'s, except
  in the requests that loaded the engine, whose `config.php` sets its own, and
  one day's audit ended up in two files (the panel's tests failed between
  midnight UTC and midnight in Madrid). With the engine on the same machine,
  the panel now takes its time zone from the start; if `open_basedir` keeps it
  from reading the engine's `config.php`, it keeps `php.ini`'s, without a
  warning.
- **Panel: restoring a ZIP that dies half-way left the database empty** (also
  in 2.8.0): when PHP stopped on its time or memory limit, the copy taken
  before was not put back, and the next restore deleted it. It is put back
  when the process ends, and the restore has no time limit, as the other
  imports. A database with `-` in its name, or starting with a digit, can now
  be restored from its ZIP, and one with rows set aside by `REPAIR TABLES`
  too: its `.reparados/apartadas.json` made the ZIP be refused.
- **The panel could not write views and triggers with numbers when the
  database goes through the API** (also in 2.8.0): with the engine not loaded,
  the panel loaded a list of the engine's classes by hand to use its parser,
  and the list lacked one the parser uses for any number in the SQL, so an
  export to MySQL, PostgreSQL, SQL Server or Access left those views and
  triggers untranslated ("Class Valor not found"), and the console wrapped
  every paged query in a subquery. It now loads the engine's autoloader, which
  brings exactly what is used.
- **The disk cache could give the rows of one part for another** (also in
  2.8.0 and earlier): the file name of a cached part joined its number and its
  revision with nothing between them, so part 1 at revision 13 and part 11 at
  revision 3 were the same file, `…p113.cache`, and the same for the pieces of
  an index. It takes a table of 11 parts or more (over 10,000 rows with the
  default part size) and PHP without APCu (2.8.0 does not use the disk cache
  when APCu is there). Reproduced on 2.8.0 with 110 rows in parts of 10: after
  ten updates of row 1, `SUM` gave twice its value, `MAX(id)` gave 100, and the
  rows of part 11 were missing. The name now has a `-` between the two, and
  every cache entry also carries the seal of its file. Anyone on 2.8.0 without
  APCu and with such tables can set `JSONSQLDB_CACHE_ACTIVA` to `false` in
  `config.php` until upgrading; deleting `.cache/` alone does not stop it
  happening again.
- **After changing `JSONSQLDB_FILAS_POR_PARTE` with data already written, a
  `CREATE INDEX` or a `CREATE TRIGGER` deleted rows** (also in 2.8.0): a
  statement that only changes the structure kept the parts as they were and
  deleted those past the number the new size gives. The table is now rewritten
  with the new size first; the same for `ALTER TABLE … ADD CHECK` and `VACUUM`.
- **A table named `manifiesto` could undo the writes of the others** (also in
  2.8.0): the journal of a write to it that died half-way is
  `.tx/manifiesto.json`, the name of the journal before 2.0, and opening the
  database took it for one, moving every journal pending into it. The old one
  is now told apart by its content, and a transaction keeps its own list as
  `.lote/manifiesto.lote`.
- **A file that could not be put in its place left the database unable to
  open** (also in 2.8.0): when a rename failed half-way through a write of
  several files (on Windows, a file another program holds open), the temporary
  files were deleted while the journal stayed, and opening the database then
  failed with «Falta el temporal». They are kept now, and the write is finished
  the next time the database is opened, as after a crash.
- **`ALTER TABLE … ADD COLUMN … REFERENCES` lost the foreign key** (also in
  2.8.0): the column was added without it and without a word. It is kept now,
  as in `CREATE TABLE`, after checking that the rows already there point to
  existing rows; and `ADD COLUMN … UNIQUE DEFAULT x` is refused when the table
  has two rows or more, which would repeat it.
- **A trigger that changed a row its own `UPDATE` or `DELETE` had still to
  reach left that row out** (also in 2.8.0): `UPDATE t SET v = v + 1` with a
  trigger that touched the next row skipped that row, and a `DELETE` left it
  in the table, with a PHP warning. As in SQLite, the rows chosen at the start
  are all processed, each as the triggers and cascades of the earlier ones left
  it, and one already deleted is skipped. Checked against SQLite in eight
  cases on a table of three parts.
- **`STRFTIME('%s', date)` took the date in PHP's time zone** (also in 2.8.0):
  with `config.php`'s `Europe/Madrid`, `STRFTIME('%s', '2026-03-01 10:00:00')`
  gave an hour less than SQLite and than `UNIXEPOCH()` of the same date. `%s`
  is now what `UNIXEPOCH()` gives: the date taken as UTC, and with `'now'` the
  current moment.
- **API: a query that ran out of `TIME_LIMIT` got an empty answer** (also in
  2.8.0), and was not logged. It now gets an HTTP 500 with a JSON error, as one
  that runs out of memory already did, and both go to the request log. A
  request that fails after its signature is checked is logged with its key's
  label and its operation, which were left empty.
- **A column with a comma in its name broke the exports of its keys and
  indexes** (also in 2.8.0): `SHOW KEYS` and `SHOW INDEXES` list the columns
  separated by commas, and the panel split such a name in two, so the dump
  failed to load (`"a,b"` became `"a"` and `"b"`). Such a name now goes between
  double quotes, as in CSV, and the panel reads it so. Two unique keys whose
  lists only differ in where the commas are (`("x y,z")` and `("x y", z)`)
  were also taken for one: such a table is refused, as one with `(a, b)` and
  `(a_b)`.
- **A `_views.json` with an extra comma lost all the views** (also in 2.8.0):
  it was taken as empty, the views stopped existing, and the next `CREATE VIEW`
  wrote over it. It is repaired now as a table's structure; if it cannot be
  repaired safely, it is an error that says so, and nothing writes over it.
- **A table named `_database` or `_views` wrote over the database's own
  files** (also in 2.8.0): `CREATE TABLE _views (…)` was accepted and the
  views were gone. `_database`, `_views`, `_revs` and `_base` are now reserved.
- **A missing part in the middle of a table shortened it in silence** (also in
  2.8.0): with the second of three parts deleted, the table had the rows of the
  first one only. Reading it is now an error that names the missing file, and
  `CHECK TABLES` lists it. A last part that 2.8 removed when a `DELETE`
  emptied it, but whose revision it left listed in `rev.json`, is not taken
  for a missing one: the parts the table's rows fill are what counts.
- **`UNIQUE (a, b)` was not enforced in a table that also had `UNIQUE (a_b)`**
  (also in 2.8.0): both got the same automatic index file, `auto_a_b`, and a
  repeated pair went in. Such a table can no longer be created (the error asks
  to rename a column); one created before still has its uniqueness checked,
  by reading the table.
- **Panel: a view exported to PostgreSQL sorted `NULL` on the other side.**
  Here, as in SQLite, MySQL and SQL Server, `NULL` goes first in `ASC` and last
  in `DESC`; PostgreSQL does the opposite unless told. Its `ORDER BY` (and that
  of a window) now carries `NULLS FIRST` or `NULLS LAST`.

- **`ALTER TABLE … MODIFY` of a column in a composite primary key was refused
  when that column alone had repeated values** (also in 2.8.0): with
  `PRIMARY KEY (a, b)` and the rows `(1, 'x')` and `(2, 'x')`, changing `b`
  said it had repeated values. Each `UNIQUE` and primary key the column is in is
  now checked as a whole, with the new values.

- **Panel: editing a column lost its `UNSIGNED`.** The form does not show it,
  and saving rewrote the column without it. It is kept now, like an `ENUM`'s
  list.

- **Panel: editing a column lost a `DEFAULT` worked out when inserting**
  (since 2.8.0): `CURRENT_TIMESTAMP` or an expression did not appear in the
  form, and saving left the column without a default. The structure now shows
  it, and the form brings it with a box «Worked out when inserting» ticked,
  which also lets one be set; without the box, what is written is a fixed
  value, so a text such as `(none)` stays a text.

- **Two queries of a `WITH` that named each other used up all the memory**
  (also in 2.8.0): `WITH a AS (SELECT * FROM b), b AS (SELECT * FROM a)` ended
  in PHP's fatal memory error. It is now an error that says so. And the name of
  a query of the `WITH` is now case-insensitive, like a table's: `WITH Cnt AS
  (…) SELECT * FROM cnt` said the table `cnt` did not exist.

- **Panel: exporting a trigger to MySQL, PostgreSQL or SQL Server dropped what
  its statements could not carry** (the upsert since 2.8.0): `INSERT OR
  IGNORE`, `OR REPLACE` or `ON CONFLICT` came out as a plain `INSERT`, and an
  `UPDATE` or `DELETE` with `ORDER BY` or `LIMIT` lost them, so the trigger did
  something else there. Those, `RETURNING`, and an `UPDATE` or `DELETE` with an
  alias or other tables, now leave the trigger commented with the reason, as
  anything else that has no translation.

- **A write with a subquery read the other table without a lock** (also in
  2.8.0): `UPDATE t … WHERE id IN (SELECT … FROM s)`, a `DELETE` with `EXISTS`,
  or an `INSERT` with a subquery in `VALUES`, on a table with no foreign keys
  or triggers, locked only `t`, and with that lock the other tables are read
  without taking theirs: another process could be writing `s` at that moment
  and the subquery could see some of its parts new and some old. Like `INSERT
  … SELECT`, they now take the database lock.

- **A `JOIN … ON` between an integer and a decimal or a numeric text returned
  no rows** (also in 2.8.0): `ON a.int_col = b.double_col` with 1 and 1.0, or
  `ON a.int_col = b.text_col` with 1 and `'1'`, while the same `=` in the
  `WHERE` matched them, as SQLite does. The hash of the join told the types
  apart; it now matches what `=` matches. A join by index already did.
- **`DISTINCT`, `GROUP BY`, `UNION`, `INTERSECT`, `EXCEPT` and `COUNT(DISTINCT
  …)` took 1 and 1.0 as two values, and 0.3 and 0.30000000000000004 as one**
  (also in 2.8.0): `SELECT f FROM a INTERSECT SELECT i FROM b` gave nothing
  with 1.0 and 1, and a decimal was told apart by its first 14 digits. Now as
  in SQLite: 1 and 1.0 are one value, every decimal is exact, and 1 and `'1'`
  are still two.

- **An index piece with no keys was taken for a damaged one.** It was written
  as `"keys":[]`, and the check every write makes on the pieces it does not
  rewrite expects them to end in `}}`; the index was then not trusted, and the
  next `INSERT` loaded the whole table to check its unique keys. It happened to
  the first rows inserted into an empty table, and with 2.9 to a part whose
  rows have all been deleted. Pieces with no keys are written as `{}` now.
- **The result cache served the result of a view that had been redefined.**
  After `DROP VIEW v` and `CREATE VIEW v AS …` with another definition, a query
  on `v` already in the cache kept returning the old rows, also from other
  processes, until something was written to its tables. The key of a cached
  result now carries the definition of every view the query reads. It also
  carries the collation, its map and the engine version: switching
  `JSONSQLDB_COLACION` or updating the engine served results sorted or computed
  with the old rules. Reported by the audit (H1, H7).
- **`GROUP BY 1` grouped by the constant 1**: all the rows in one group and a
  single row of result, with no warning. A number now means that output
  column, as in `ORDER BY`, SQLite, MySQL and PostgreSQL; a number out of range,
  or the number of an aggregate column, is an error. Reported by the audit (H2).
- **Panel: a valid CSV could not be imported** when a column name had a comma
  between quotes, or when a tab-separated file had a comma in its header: the
  separator was the one that appeared most in the first line, counting also
  the ones between quotes. It is now the one of `,` `;` and tab that gives the
  same number of fields, more than one, in the first five lines. Reported by
  the audit (H3).
- **`LIMIT` and `;` as in SQLite**: `LIMIT 1 + 1` and any constant expression,
  `LIMIT -1` as no limit, a negative `OFFSET` as 0, and a final `;` repeated
  (`SELECT 1;;`) were rejected with «Only one statement per query». Reported
  by the audit (H6). `UPDATE` or `DELETE` with `ORDER BY` or `LIMIT`, which
  SQLite only takes when compiled with a special option, is accepted as in
  MySQL (see Added).
- `CAST(x AS BOOLEAN)` had a branch that never ran: `BOOLEAN` is `INTEGER`, as
  in SQLite, and the branch is gone (H4). The error of `CAST(… AS DATETIME)`
  said the format was `YYYY-MM-DD` when a time is also accepted (H5).

- **Panel: batches of 200 rows with the direct connection.** To work out how
  many rows fit in memory, the panel measured the memory before and after
  asking for a sample, and the first time that included loading the whole
  engine: batches came out at the minimum, 200 rows, and exporting 120,000
  rows took 600 requests. It now measures a copy of the sample (found while
  testing the sort on disk).

- **Panel: exporting a query that ends in a comment asked for everything at
  once.** The batches add `LIMIT … OFFSET …` at the end of the query; after a
  `-- comment` the `LIMIT` was part of the comment, the first request brought
  the whole result, and with 120,000 rows and 32 MB nothing was exported. It
  now goes on a line of its own. Found in the code audit of 2.9.
- **Panel: the ZIP copy carried the engine's cache** (`.cache/`, which can be
  large), the temporary files of a sort on disk (`.orden/`) and the turn files
  of each table (`.<table>.turno`). They are rebuilt on their own and are left
  out. Found in the code audit of 2.9.

- **Panel: a scheduled daily or weekly backup was made twice when the clock
  went back.** To find the previous day, 86,400 seconds were subtracted, and
  the day of the change to winter time has 25 hours: in Europe/Madrid, a backup
  at 01:00 or 02:00 also ran at midnight the day after, one at 03:00 the same
  day at midnight. Days are now counted in the calendar. Simulated every 15
  minutes across both changes of 2026: one backup per day and per week. Found
  in the code audit of 2.9.
- **Hostings with `open_basedir`.** The ZIP copy and an Excel file with `#` in
  its name failed when the system's temporary folder is not allowed: their
  temporary file now goes to the panel's data folder if it cannot go there.
  And every write printed a warning (`is_dir(): open_basedir restriction`)
  while looking in `/proc` for dead processes, which on a server showing
  errors ended up inside the response; it is now silent. And the panel, with
  the engine's path empty (the usual case, the engine next to it), looked for
  `/engine` at the root of the disk: the same warning, in the middle of the
  answer to an upload in pieces (which broke it), of an import from the import
  folder or of a backup asked for by the browser. An empty path is no longer
  looked at. Found in the code audit of 2.9.
- **Panel: signing out with an expired form was a fatal error.** A `POST` to
  sign out without a valid token did not close the session, which is right,
  but showed PHP's fatal error page; it now says «The form has expired» and goes back
  to the start page. Found in the code audit of 2.9.

- **Panel: exporting and importing big databases**, reported by a second
  external audit. A dump probes each table before starting, so an unreadable
  table is reported before the download begins instead of in the middle of
  the file; blocks are measured in bytes; a dump cut short ends with
  `INCOMPLETE_DUMP_DO_NOT_IMPORT;`, a line no engine accepts, so importing it
  stops there with an error. A query with no rows says so instead of
  downloading a broken file. Import batches are
  measured in bytes and split in two when the API says one is too big; an SQL
  file is read in bounded chunks and PostgreSQL's `COPY` in 1 MB batches; a
  fatal error during an import is undone too (see the next entry). A form larger than
  `post_max_size` is explained. `ADMIN_TIMEOUT` is 300 s. A ZIP that cannot be
  restored says which folder is missing. The apostrophe that guards against
  spreadsheet formulas is removed when a CSV is imported, exactly one, and a
  text that already starts with apostrophes before a formula character gets
  one more on export, so every value comes back the same (`'-5` stays `'-5`). A file name without
  an extension keeps its date stamp. Uploads without `crypto.subtle` get their
  id from the file.
- **Panel: an all-or-nothing import that failed also undid what others had
  written meanwhile** (since 2.7.3). With the panel on the same machine as the
  engine, the import copied the whole database and, on failure, put the copy
  back: a row another request inserted during the import was lost. It now runs
  in one transaction of the engine, which the panel loads for it also when it
  goes through the API: on failure, or after a fatal error of PHP, only what
  the import did is undone; the database is no longer copied (that needed as
  much free disk again as it took); and while it runs the database is locked,
  so others wait instead of writing into it or seeing it half imported.
- **Panel: works without `mbstring`**, as the engine already did; the CI runs
  the panel's tests without it.
- **Panel: dumps from other engines**, reported by an external review of import
  and export. The encoding is decided per literal, not per statement (a `BLOB`
  in one row of a multi-row `INSERT` no longer garbles the text of the others);
  any PostgreSQL schema is recognised, not only `public`, and two tables with
  the same name in two schemas are reported; a `NUL` inside a text is written
  as each engine needs, or removed with a warning; MySQL gets `TEXT`,
  `MEDIUMTEXT` or `LONGTEXT` by size in bytes and long `VARCHAR`s become text
  until a row fits in 65,535 bytes; `ON DELETE SET DEFAULT` is written as MySQL
  and Access accept it, with a note; PostgreSQL materialized views become views
  and partitioned tables keep their rows in the parent; SQLite virtual tables
  (FTS) are skipped with their auxiliary tables; a data-only dump into an empty
  database explains that the tables have to exist; each skipped object is
  reported by its kind.
- **Panel: a mysqldump imported through the API stopped at its first big
  `INSERT`** (also in 2.8.0). mysqldump writes `INSERT`s of many rows, up to
  1 MB, and the importer only joined and sized one-row `INSERT`s: one of more
  than 200,000 bytes was refused by the API («Petición demasiado grande»). An
  `INSERT` of several rows is now sent in batches of its rows that fit, also
  the 200-row ones made from a PostgreSQL `COPY`; with the direct connection
  nothing changes. Found in the review of the documentation of 2.9.
- **Panel: a CSV export did not come back the same when a text had a
  backslash before a quote** (also in 2.8.0): it was written with `\` as the
  escape character, which leaves that quote without doubling it, and neither
  the import nor Excel read it like that. Quotes are now only doubled, as the
  CSV standard says.
- **Panel: the headers of the MySQL, PostgreSQL and SQL Server dumps said that
  views and triggers go commented out**; they have gone translated since
  2.7.3. Only those that cannot be written there are commented, with the
  reason.
- **`ALTER TABLE … CHANGE` in one step** (the second part read the structure
  left by the first), and **`TRUNCATE` resets the autoincrement** to 1.
- **Counting the rows of a part with huge rows no longer loads them**: three
  rows of 12 MB stopped a `SHOW TABLES` with 32 MB.
- **A `SELECT` run after a row-by-row `SELECT` on the same `Database` object
  logged the rows of the first one**: the count of rows passed to the callback
  was kept until the next row-by-row query. It is cleared when that query
  ends.
- **Panel: a damaged `usuarios.json` (or any other file of the panel) was
  read as empty and overwritten by the next change** — adding a user would
  have dropped the others. A change to a file that exists but is not valid
  JSON now stops with a message that names the file, and leaves it as it is.
  Reading it still shows it as empty, as before.
- **Panel: a chunked upload that cannot open its target closes the chunk it
  received** before reporting the error.
- **A cascade reaches every level, and a child row changed by one goes through
  everything an `UPDATE` does** (also in 2.8.0). `ON UPDATE CASCADE` changed
  the children but not the grandchildren (`UPDATE p SET id = 5` left
  `g2.pid = 1` behind a child whose key is also a foreign key); the children's
  `BEFORE`/`AFTER UPDATE` triggers did not fire, and their `UNIQUE` and foreign
  keys were not checked: an `ON UPDATE SET DEFAULT` could leave two rows with
  the same unique key, and an `ON DELETE SET DEFAULT` point at a parent that
  does not exist. All of that now happens as in SQLite, and a duplicate or a
  missing parent is an error.
- **The parent row is written or deleted before its cascades run**, as in
  SQLite (also in 2.8.0): the triggers of the children deleted in cascade
  still saw it, so `AFTER DELETE ON h … INSERT INTO alog (pid) VALUES
  (OLD.pid)` left rows in `alog` pointing at deleted parents; now that is a
  foreign key error. `OR REPLACE` keeps the old order: the new row usually
  brings the key back, and SQLite accepts that.
- **`ADD UNIQUE`, `ADD PRIMARY KEY`, `ADD FOREIGN KEY`, `MODIFY`, `DROP
  COLUMN` and `CHECK`/`REPAIR KEYS` compare keys as writes do** (also in
  2.8.0). There `'5'` and `'05'` were different keys: `REPAIR KEYS` set to
  `NULL` a child whose parent the engine itself finds, `ADD UNIQUE` accepted
  `'5'` and `'05'`, and `ADD FOREIGN KEY` refused data the engine accepts.
  Their messages now show the values, not the internal key.
- **`ALTER TABLE … ADD COLUMN id INTEGER PRIMARY KEY DEFAULT 0` on a table with
  two or more rows is refused**, like `UNIQUE` (also in 2.8.0): every row got
  the same primary key.
- **`ALTER TABLE … RENAME TO`** (also in 2.8.0) accepted the name of a view,
  which then hid the table, and left the views and triggers that use the table
  pointing at the old name (`La tabla 'a' no existe` on the next write). Both
  are refused now, naming the view or trigger to drop and create again.
- **A unique key could share its name with another one** (also in 2.8.0):
  `CONSTRAINT uq_t_x UNIQUE (y)` next to `x TEXT UNIQUE`, in `CREATE TABLE` or
  `ADD CONSTRAINT`, made one of the two stop being checked. It is refused.
- **`CREATE TRIGGER` refuses `NEW` in a `DELETE` trigger and `OLD` in an
  `INSERT` one**, as MySQL does, and a statement a trigger cannot run (a
  `DROP TABLE`, a `SHOW`…) in its body (also in 2.8.0): the error came on every
  write to the table instead. A PostgreSQL trigger function for several
  events, which names both in the branches for each one or in
  `COALESCE(NEW.id, OLD.id)`, is still imported: `OLD.col` is `NULL` in the
  `INSERT` trigger and `NEW.col` in the `DELETE` one, as in PostgreSQL.
- **A write by parts that committed after an `INSERT` had opened a new part cut
  the table's state to the parts it had seen** (also in 2.8.0): three data
  files and two revisions, so every lookup by any index became a scan until
  the next write, which then rebuilt them all. It now cuts only what it
  removed itself, and each piece's range goes in its own place.
- **A lock that could not be taken left the database's shared lock held** (also
  in 2.8.0): with a table lock file that could not be opened, or a recovery
  that failed, other writers waited forever and the same process's later
  writes, DDL included, ran without a real exclusive lock. The error now
  releases every lock.
- **`ORDER BY` an `AS` alias named like a column sorted by the column** (also
  in 2.8.0): `SELECT -saldo AS saldo … ORDER BY saldo` came out the wrong way
  round. The alias wins on every path, as in SQLite, MySQL and PostgreSQL;
  `t.saldo` and `saldo + 0` are still the column.
- **`LIKE` with many `%` over a long text said "no match" without warning**
  (also in 2.8.0): PCRE gave up and that was taken as a no. It now gives
  SQLite's answer, and if it still cannot decide, an error.
- **`LIMIT 9223372036854775807 OFFSET n`**, what ORMs send for "no limit",
  ended in a PHP error (also in 2.8.0). It returns the rows.
- **`TRIM(x, chars)` removed bytes, not characters** (also in 2.8.0):
  `TRIM('áxá', 'ñ')` left a text that is not valid UTF-8, and `'a..c'` was a
  range. It now removes characters, as SQLite does.
- **`STRFTIME('%W')` gave the ISO week** (also in 2.8.0). It is now SQLite's,
  where week 01 starts on the first Monday of the year.
- **Smaller query fixes** (also in 2.8.0): `15 BETWEEN NULL AND 10` is 0, not
  `NULL`; `'a/b' REGEXP 'a\/b'` is valid; the `ORDER BY` of a `UNION` does not
  tell case apart in column names; `'+0.5 seconds'` and other fractions in date
  modifiers apply to the millisecond (it added a whole second).
- **Panel: a form field sent as a list gives a clean error** (also in 2.8.0):
  `sql[]=x` or `base[]=shop` went through as the text `Array`, with a PHP
  warning, and a backup was scheduled for a database called `Array`.
- **Panel: a password with the null character is refused with its own
  message** (also in 2.8.0): bcrypt cannot take it, and the setup wizard ended
  in an untranslated PHP error after writing `config.php`.
- **No PHP 8.5 deprecation notices**: `curl_close()` and
  `$http_response_header`, deprecated in 8.5, are no longer used, in the panel
  or in the PHP example client, and the panel no longer looks up its language
  with `null` as an array key, which 8.5 reports and which ended up inside
  exported files.
- **Panel: a trigger with `IF … END IF` can be imported again from the panel's
  own dumps** (also in 2.8.0): the `END` of `END IF` closed it, so the SQL and
  JSON dumps failed with «Falta el END del trigger», and the SQL console split
  it in two.
- **Panel: MySQL dumps with `DEFAULT '0000-00-00'` import** (also in 2.8.0):
  WordPress tables and any MySQL before 5.7 stopped at `CREATE TABLE` with
  «Fecha inexistente». The default is dropped and the column takes `NULL`, as
  its rows with that date already did, with a warning.
- **Panel: indexes with `COLLATE`, `NULLS LAST` or a PostgreSQL operator
  class import, and an index on an expression no longer stops the import**
  (also in 2.8.0): `CREATE INDEX ix ON t(lower(nombre))` failed with «Índice
  sobre columna inexistente 'lower'». The extra words are left out, and an
  index on an expression is skipped with a warning.
- **Panel: a string `'('`, `')'`, `','` or `';'` in a view or a trigger being
  imported is a string** (also in 2.8.0): `concat(a, ' (', b, ')')` was cut
  short and a plpgsql trigger with `'log (' || NEW.id || ')'` was skipped.
- **Panel: dates with a time zone in `INSERT`s import** (also in 2.8.0): SQL
  Server's `datetimeoffset` and `pg_dump --inserts`' `timestamptz` were
  refused; the zone was only removed in `COPY`. It is removed now in columns of
  those types, with the usual warning.
- **Panel: `CHAR(n)` as a column type stays a type when importing** (also in
  2.8.0): `code char(10)` arrived as `TEXT`, and `CAST(x AS CHAR(10))` skipped
  its view. Only `char(10)` used as a function becomes its character.
- **Panel: consecutive `INSERT`s are joined only when nothing follows their
  row** (also in 2.8.0): two `INSERT … ON CONFLICT DO NOTHING;` in a row,
  `RETURNING`, or phpMyAdmin's `ON DUPLICATE KEY UPDATE` on each row, became
  invalid SQL.
- **Panel: MySQL dumps put the default of a `MEDIUMTEXT` or `LONGTEXT` in
  parentheses** (also in 2.8.0): MySQL 8 refused `nota MEDIUMTEXT DEFAULT 'x'`
  (error 1101), and the whole file with it.
- **Panel: SQL Server dumps comment out a `BEFORE DELETE` or `UPDATE` trigger
  on a table with a cascading foreign key, with the reason** (also in 2.8.0):
  SQL Server refuses that `INSTEAD OF` trigger (error 2113), and with
  `XACT_ABORT` nothing loaded. The header now says SQL Server 2017, which its
  `STRING_AGG`, `TRANSLATE` and `TRIM(x FROM y)` need.
- **Panel: date arithmetic in PostgreSQL views** (also in 2.8.0):
  `CURRENT_DATE - f::date` was subtracted as text and gave another number,
  silently. `date - date` now gives the days between them and `date ± n` a
  date; what gives an interval skips the view with the reason.
- **Panel: two more constructs of the panel's own dumps import back** (also in
  2.8.0): SQL Server's `N'a' + NCHAR(0) + N'b'` («Función no soportada:
  NCHAR()») and MySQL's `INTERVAL +2 HOUR`.
- **Panel: an Excel date and time at midnight keeps its time when imported**
  (also in 2.8.0): the cell's number format decides now, not the value.
- **Panel: smaller import fixes** (also in 2.8.0): "(n veces)" and the CSV
  field-count error are translated; a name starting with a digit no longer
  becomes 65 characters long; warnings of the first pass of PostgreSQL and SQL
  Server dumps are no longer counted twice.

### Faster

- **A write to a large table no longer checks every index piece by hand.**
  Each write made sure that the pieces of every index it was not touching
  were sound by reading the head and the tail of each file: 3,000 opens on a
  table of a million rows with three indexes, half of a one-row `UPDATE`
  (95 ms). The revision and size of each piece as the last write left them
  now go to the cache, and a piece in the same revision and with the same size
  needs one `stat()`; the rest are read as before, and a damaged piece is still
  found and the index rebuilt. The pieces a write reads in order to rewrite
  them no longer go to the cache either (a tenth of a `DELETE` of many rows).
  On a million rows, against the code before this change: a one-row `UPDATE`
  95 → 53 ms, `INSERT` 98 → 56 ms, `DELETE` 78 → 60 ms.
- **The filters of an index are kept together in the cache.** A lookup by a
  text key opened the head of every piece of the index to read its filter:
  1,000 opens on a million rows, 30 ms. The first lookup that reads them all,
  and a write when enough of them have changed, store them in one entry with
  the revision of each piece; with it a lookup opens only the piece where the
  key can be: by email on a million rows, from 30 ms to about 3 ms more than a
  lookup by key. And the filter now covers the numeric keys too in the pieces
  whose range spans at least 8 times the part size (an index on the customer
  of each order: every piece spans the whole table and the range narrows
  nothing), which `"cubre":"todo"` says (`"texto"` otherwise): the orders of
  one customer, from 42 ms to about 3 ms more than a lookup by key. Pieces
  with narrow ranges (a primary key) keep the range and no filter, as before.
  A write stores the filters, and the verdict of the previous entry, only once
  it is committed: one discarded halfway leaves nothing behind. The cache
  sweep every 32 revisions keeps them.
- **`LIMIT … OFFSET` without `WHERE` or `ORDER BY` skips the parts the
  `OFFSET` leaves behind**, counting the holes of deleted rows the state
  records for each part; it decoded them all: `LIMIT 100 OFFSET 500000` on a
  million rows 596 → 13 ms (the whole process).
- **A `LIMIT` with a `WHERE` that an index covers entirely stops at the
  index.** `WHERE ciudad = 'Elche' LIMIT 50` on a million rows (100,000 of
  them in that city) read the 100,000 positions, found them too many and
  scanned the table: 71 ms. The index pieces go in the order of the table, so
  the first positions found are the first rows: it now stops at the piece
  that completes the `LIMIT`, 14 ms. Only when every condition of the `WHERE`
  is an equality on the index's columns; with any other condition the
  `WHERE` filters after, as before.
- **The temporary files of a sort or a partition on disk are written and read
  in blocks of 64 KB**, not one `fwrite()` and two `fread()` per row, the
  keys of an `ORDER BY` that are a column are taken from the row instead of
  evaluated, and the merge compares two numbers directly. On a million rows:
  `ORDER BY saldo DESC` of the whole table 15.2 → 12.3 s, `GROUP BY` of
  631,796 groups 16.8 → 13.7 s, the join of the million orders with their
  customers 20.4 → 16.9 s, the `UNION` of two million emails 7.1 → 6.6 s.
- **Upserts no longer load the table.** `INSERT … ON CONFLICT`, `INSERT OR
  IGNORE`, `INSERT OR REPLACE` and `REPLACE` look each key up in the indexes
  on disk and write only the rows they add or change, as an `INSERT` or an
  `UPDATE` does; 2.8.0 read the whole table into memory first. A numeric key
  is looked for only in the index piece whose range holds it (by bisection
  when the pieces go in order, as with a key that grows), and a text key in
  the whole index in memory if it fits in what is left up to half of
  `memory_limit`, else in a map of 64-bit fingerprints of its keys (each hit
  confirmed in its piece), and else key by key in the pieces whose filter
  allows it. On 40,000 rows (parts of 1,000, 128 MB), median against 2.8.0:
  `ON CONFLICT` of two rows 603 → 43 ms, `REPLACE` of one row 732 → 41 ms,
  `INSERT OR IGNORE` of one row 208 → 17 ms, `INSERT OR IGNORE … SELECT` of
  40,000 rows 1,164 → 953 ms, and `INSERT OR REPLACE … SELECT` of 40,000 rows,
  which 2.8.0 stopped with the memory error, 1.6 s; `INSERT … SELECT … ON
  CONFLICT (id) DO UPDATE` of 40,000 rows, the same (minimum 1,705 / 1,694 ms).
  With a million rows, see `docs/01-core.md`.

- **Writes of many rows:** the positions of an index that change are replaced
  in place in its piece, the index pieces of the parts a write rewrites are
  rebuilt while each part is written, in one pass, and a `DELETE` that
  compacts the table moves the lines of each part as text, without decoding
  them. An `UPDATE` of the 40,000 rows, 1,634 → 1,022 ms against 2.8.0; with a
  million rows and 256 MB, 24 s and 140 MB. A write that does not fit in
  memory is written in stretches, inside a transaction (see Less memory).

- **A correlated `EXISTS` runs as a semi-join.** When the subquery looks
  outside only through one equality (`EXISTS (SELECT 1 FROM p WHERE p.cid =
  c.id AND p.total > 999)`), the values of `p.cid` that pass the rest are taken
  once and each outer row only checks whether its own is among them, as with
  `IN`; before, the subquery ran once for every outer row. 60,000 rows against
  60,000: more than a minute in 2.8.0, 0.35 s now; a million against a
  million, 2.9 s. A subquery on more than one table or on a view, with
  `GROUP BY`, `LIMIT`, `OFFSET`, an aggregate or a window function, or whose
  equality has a collation, runs as before (`EXISTS (SELECT COUNT(*) …)` is
  always true). `EXPLAIN` shows it.

- **Tables separated by commas are joined by hash.** `FROM a, b WHERE b.x =
  a.y` paired every row of `a` with every row of `b` and then filtered; the
  equalities of the `WHERE` between a table of the list and the ones before it
  now also go to its `ON`, as in `JOIN … ON`. 20,000 by 10,000 rows: 76 s →
  40 ms (`JOIN … ON` takes 46 ms). Only between columns written with their
  table, and not when there is a `RIGHT` or `FULL JOIN`. The same 3,200 queries
  at random give the same rows as before.

- **Deleting one row no longer depends on how many rows are behind it.** It
  used to move them all one place and rewrite every part and index piece behind
  the deleted row. On 60,000 rows with six indexes, deleting rows at five
  places: 443, 615, 26, 221 and 199 ms before; 42, 24, 28, 20 and 20 ms now.
- **A range on a text index no longer opens the header of each index piece.**
  The smallest and largest text of each piece, which decide which pieces a
  range has to read, are kept in the cache with the revision of their piece,
  made when a query reads them; a piece rewritten since is read again, so they
  never hide a row, and writes do nothing extra. Between web requests with
  OPcache: `WHERE email > 'u' LIMIT 1` 456 → 374 µs, a range of dates 38.5 →
  34.8 ms; in-process, a narrow range of dates 2.3 → 1.4 ms. Without APCu or
  OPcache it stays the same.
- **Each table's state is kept decoded with OPcache or APCu.** Reading and
  decoding `rev.json` was the largest single cost at the start of every request;
  it is now kept with the seal of the file, and a `stat()` tells whether it still
  holds (only once the file has not changed for 2 seconds). Between web
  requests: a lookup by key 862 → 834 µs, a text range with `LIMIT` 813 → 719 µs.
- **Without OPcache, a request compiles only the code it uses.** PHP without
  OPcache compiles, in every request, every file the request loads, and the
  engine had grown with this version: a lookup by key loaded 749 KB of code,
  against 503 KB in 2.8.0, and a whole request took 15.5 ms against 11.1. The
  code that only some statements need now lives in classes of its own, which
  the autoloader loads only when one of them is used. In the storage: what
  only reads do (`StorageLectura`), writing rows (`StorageEscritura`),
  changing and removing them (`StorageCambios`), reads by a comparison on an
  index (`StorageRangos`), and the structure, transactions, recovery after a
  crash and repairs (`StorageMantenimiento`, with `CatalogDdl`, `WriterDdl`
  and `ParserSentencias`). In the parser: the rest of `SELECT`
  (`ParserConsulta`), `INSERT`, `UPDATE` and `DELETE` (`ParserEscritura`),
  windows and `WITH RECURSIVE` (`ParserAvanzado`). In queries: sorting
  without an index (`SelectOrden`), `GROUP BY` and the aggregates
  (`SelectAgrupada`), `UNION` and `WITH` (`SelectCompuesta`), the hash `JOIN`
  (`SelectJoin`), windows and `EXPLAIN` (`SelectAvanzada`), the choice of an
  index (`IndexesBusqueda`), compiled expressions (`EvaluatorCompilado`),
  `LIKE`, `GLOB` and `REGEXP` (`EvaluatorPatrones`), and the functions that
  are not SQLite's usual ones (`FunctionsAmpliadas`). In writes: `UPDATE` and
  `DELETE` (`WriterCambios`), foreign keys (`WriterForaneas`), a row that
  clashes with a key (`WriterConflictos`) and the body of triggers
  (`WriterTriggers`). And batches, `SHOW DATABASES` and `EXPLAIN`
  (`DatabaseAvanzada`). An `INSERT` of plain values (also negative numbers)
  takes them as they are, without loading the expression evaluator. What a
  request compiles, against 2.8.0 (pieces of code without spaces or comments,
  which is what costs): a one-row `INSERT` −24 % (423 KB of the engine
  against 484 KB), `UPDATE` and `DELETE` −16 %, a lookup by key −13 %, a
  function −12 %, `ORDER BY` and `UNION` −9 %, `GROUP BY` −7 %, a subquery
  −4 % and `INSERT … SELECT` −3 %; none compiles more. A whole request
  without OPcache, against 2.8.0 (loading the engine and one statement, in
  PHP's own web server, time inside the server, median of 60): a lookup by
  key 10.6 against 13.0 ms, a join 13.4 against 16.1, a range by key 10.8
  against 13.6, `GROUP BY` 14.2 against 17.0, `ORDER BY` 15.0 against 16.8, a
  subquery 16.8 against 17.3, `UNION` 12.3 against 14.5, `UPPER()` 11.4
  against 13.5, `LEFT JOIN` 13.6 against 15.4, a one-row `INSERT` 12.9 against
  15.7, `DELETE` 15.7 against 18.1, `UPDATE` 20.0 against 23.6, an upsert 15.8
  against 34.4 and `INSERT … SELECT` 28.3 against 38.3 ms. With OPcache
  nothing is compiled again, and the classes apart only add finding a few
  more files: about 15 µs in a lookup by key, which takes 0.61 ms against
  1.55 in 2.8.0 (median of 300). `Catalog` keeps all its methods, which hand
  the structure changes to `CatalogDdl`, and `Storage::crearBase()` and
  `borrarBase()` stay where they were.
- **`COUNT(*)` of an equality by index counts without gathering the rows.**
  With a value in many rows (a city), the positions of each index piece were
  decoded, gathered without repeats and sorted only to count them; now the
  commas of each list are counted in the text of the piece. One row has one
  key in each index, so nothing is counted twice. `SELECT COUNT(*) … WHERE
  ciudad = 'Elche'` on 20,000 rows: 0.54 → 0.26 ms, also with the data written
  by 2.8.0.
- **The last table states and structures stay in the process.** A script, the
  panel or an API batch that runs many queries read and decoded each table's
  `rev.json` and normalised its structure again in every query. Now they are
  kept for the next query, up to 32 tables, checked with one `stat()` of the
  file (size, date and inode) and only once it has not changed for 2 seconds.
  In one process, on 20,000 rows: a lookup by key 0.183 → 0.144 ms, one order
  and its customer by `JOIN` 0.328 → 0.257 ms.
- **A numeric key is looked for where its value falls in the index piece.** A
  lookup searches the text of each index piece without decoding it, from the
  start; a numeric key now starts where its value falls within the range of
  the piece (already in `rev.json`), backwards first and then forwards. In a
  piece whose keys go in order, as with a primary key that grows, it is found
  almost at once instead of going through half the piece on average; with the
  keys in any order it costs on average the same as before. And the seal of
  each part read is only taken when the result cache needs it. In one process,
  on 20,000 rows: `id IN` ten keys at the end of their pieces 0.426 → 0.342 ms,
  an `IN` of 100 spread keys 1.83 → 1.47 ms, 20 orders with their customers by
  `LEFT JOIN` 1.55 → 1.43 ms; a key in the middle of its piece, the same
  (0.147 / 0.154 ms).
- **64 lists of row offsets kept in the process, not 16.** A query touching
  more parts than that threw them all away on every pass. In one process, an
  `IN` of 100 keys spread over 20 parts: 3.33 → 1.45 ms. 64 lists of parts of
  1,000 rows take 256 KB.
- **The memory net costs less per row.** Reading a table called the memory
  check once per row; the loops now count the rows themselves and call it only
  when it is due, as often as before. And the evaluation context of a row is
  only built when a column or the order needs it. On 20,000 rows: `SELECT id,
  nombre` 17.5 → 14.0 ms, a `LIKE` 16.4 → 15.1 ms, a `GROUP BY` 19.2 →
  18.5 ms, the `UNION` of two ranges 1.33 → 1.24 ms.
- **A write deletes the cache entries it leaves behind by their name.** It
  listed the cache folder twice, for the table's entries and for its results:
  12 % of a one-row `UPDATE`, and more with more files. Now the stale entries
  are deleted by their exact names, and the results only when the table has
  some (storing one leaves a mark); every 32 revisions, and on `DROP TABLE`,
  the table's entries are swept as before, for what no write leaves behind by
  name. One-row `UPDATE` on 20,000 rows: 3.88 → 3.45 ms; with 2,000 results of
  other tables in the folder, 5.82 → 3.55 ms.
- **About 1 ms less in every request.** Setting the memory net's 2 MB aside
  cost about 0.7 ms per request, and touching that memory more; without it
  (see Changed), between web requests and with everything else the same:
  `SELECT * FROM a WHERE id = 30000` 1906 → 879 µs, `SELECT COUNT(*)` 1752 →
  655 µs (median of 200 requests).
- **Reading SQL is faster than in 2.8.0 again.** 2.9 added precedence levels
  (`XOR`, the bit operators), which made parsing about a third slower. The
  lexer now takes runs of spaces, names and digits at once and goes from quote
  to quote in strings, and each precedence level looks at the next token
  directly. Against 2.8.0, minimum of nine rounds: a `SELECT` with a `JOIN`
  61–64 → 58–65 µs, a two-row `INSERT` 36 → 30–33 µs, an `INSERT` with a
  1 KB text 63–67 → 11 µs. The new lexer gives exactly the same tokens as
  the previous one on 200,000 random statements.
- **An `UPDATE` no longer rewrites the last piece of every index.** When the
  table did not fill its last part, every write rebuilt the last piece of each
  index as if rows had been added at the end. Now the end is only rebuilt when
  rows are added there or move. A one-row `UPDATE` on 60,001 rows with six
  indexes writes one index piece instead of seven: 13.4 → 6.6 ms in the same
  run (the times of `fsync` vary a lot on the test machine; the pieces
  written do not).
- **A write no longer lists the database folder.** Every write searched the
  folder for the table's index files, although its state already names them;
  now the folder is only searched when the state does not say (a table from
  before 2.5) or an index is being dropped, which also needs its files. On a
  table with six indexes in 60 parts (433 files in the folder), that search
  cost 216 µs per write, and more the bigger the database.
- **Knowing how many parts a table has costs two checks, not one per part.**
  The table's state already says it; the engine checks only that the last part
  is there and the next is not, and counts them one by one as before if that
  does not hold. A lookup by key on 60 parts: 0.257 → 0.225 ms, against 2.8.0.
- **`MIN` and `MAX` from the index.** `SELECT MIN(col), MAX(col) FROM t` with
  no `WHERE`, on a numeric column that is the first column of an index (the
  primary key is), comes from the range of each index piece already kept in
  the table's revision file, without reading a row. On 60,000 rows, median of
  five runs against 2.8.0: `MIN(saldo), MAX(saldo)` with an index 136.1 →
  7.0 ms, `MAX(id)` 105.5 → 6.9 ms; a column without an index, unchanged
  (110.5 / 113.9 ms, noise). Reported by the performance review (L4).

- **Ranges by index on texts and dates, and on spread numbers; `COUNT(*)` of
  a range without reading the table.** A `<`, `<=`, `>`, `>=`, `BETWEEN` or
  `=` against a literal on the first column of an index, when the numeric
  range of each piece is not enough, now looks for the matching positions in
  the index pieces and reads only their parts. It only starts when it will not
  cost more than reading the table: with numbers, an estimate from the range
  of each piece (already in the revision file) must leave fewer than half the
  parts to open; with texts and dates, each piece written by 2.9 carries in
  its head the smallest and largest value (`min`, `max`, before `keys`), and
  only the pieces whose range overlaps are read (if they are more than half,
  or the first and the last fall wholly inside, it reads the table as 2.8
  did). A `COUNT(*)` whose `WHERE` is only comparisons on one indexed column,
  not only equalities, counts in the index. The comparison is the `WHERE`'s
  own (`Valor::comparar`), exact; with a text bound every key must be a text
  and with a number every key a number, or it falls back to reading the table.
  On 60,000 rows, median of 3 × 10 runs in one process against 2.8.0: `SELECT
  *` of one day 65.6 → 2.7 ms, `COUNT(*)` of a month 66.3 → 5.0 ms, `COUNT(*) …
  edad BETWEEN 30 AND 40` 33.6 → 5.1 ms, `id BETWEEN 20000 AND 20500` 2.1 →
  1.7 ms; ranges over most of the table and spread numbers (`saldo > 995`),
  the same as 2.8 (within ±5 %, the noise of the machine). Pieces written
  before 2.9 have no `min`/`max`: those queries go as in 2.8. Reported by the
  performance review (L3).

- **Lookups by a text key read one index piece, not all of them.** Each piece
  of an index now carries a Bloom filter of its text keys (`filtro`, before
  `keys`): a lookup by an email, or the `UNIQUE` check of an `INSERT`, reads
  the head of each piece and the keys of only the one where the key can be
  (about 1 % false positives). Text keys have no numeric range, so before
  every piece was read whole: 3.7 MB per `INSERT` with 100,000 rows. A value
  present in every piece (a city) is detected after two pieces and the rest
  are read whole at once, as in 2.8, and small pieces are always read at once.
  Measured against 2.8.0: lookup by email on 60,000 rows 1.10 → 0.58 ms
  (median of 3 × 10 runs in one process), on 300,000 rows 9.1 → 4.9 ms (median
  of five fresh runs); `INSERT` with a unique email on 300,000 rows 26.5 →
  17.2 ms; a city (`COUNT(*) … ciudad = 'Elche'`) 2.1 / 2.1 ms. Pieces written
  before 2.9 have no filter and are read as in 2.8 (1.24 / 1.23 ms). A first
  version kept the filters in a file per index, rewritten whole on every write
  (500 KB per `INSERT` on 300,000 rows): discarded. Reported by the
  performance review (E2).

- **`ORDER BY col [DESC] LIMIT n` from the index.** With one table, no
  `WHERE`, grouping or `DISTINCT`, and one order key that is a numeric column
  with an index, the index pieces are read best end first (their ranges are
  already in the revision file) and the reading stops when the next piece
  cannot beat the n-th value; then only the rows that can make the result are
  read, ties included, and sorted and cut as always. Ascending order needs a
  `NOT NULL` column, because `NULL`s come first and are not in the index. On
  60,000 rows, median of five runs against 2.8.0: `ORDER BY id DESC LIMIT 20`
  117.7 → 2.9 ms, `ORDER BY saldo DESC LIMIT 20` 87.5 → 17.8 ms, `LIMIT 100
  OFFSET 400` 101.5 → 63.7 ms; a column without an index, the same (87.4 /
  89.9 ms, noise). In one process, `ORDER BY id DESC LIMIT 20` 63.2 → 0.7 ms.
  Reported by the performance review (L2).

- **The API state is spread over small files.** Anti-replay tokens, per-IP
  counters and failures and per-key counters go in up to 256 files per kind
  under `logs/api/estado/`, by the md5 of the token, IP or key: a request
  rewrites only the two or three that are its own, small, instead of one
  `estado.json` holding the whole installation's traffic. Per request, against
  2.8.0: with 100 requests in the window 0.23 / 0.26 ms (one file more to
  open), with 1,000 0.63 → 0.25 ms, with 5,000 2.44 → 0.25 ms. The tokens of
  the old `estado.json` are still refused for the minutes they could be valid,
  then it is deleted; per-IP counters start again from zero after updating.
  Reported by the performance review (A1).

- **`ORDER BY` that does not fit in memory is sorted on disk.** The usual
  in-memory sort is not touched: while it gathers the table, `Memoria` warns
  as soon as half of `memory_limit` is in use, and only then the query is run
  again another way: rows are gathered with their sort keys, what was gathered
  is sorted (natively, as in memory, unless a key mixes types) and written to
  a temporary file in the database's `.orden/` folder each time the memory in
  use passes half of `memory_limit` (at least 2 MB each), and at the end the
  files are merged row by row, in exactly the in-memory order, ties included.
  `ORDER BY saldo DESC, id` of the million ids of a table with 256 MB takes
  14 s and 164 MB; measured before release with the reserved memory as the
  trigger, which PHP never gives back, it wrote 389 files and took 174 s. Through
  `consultarPorFilas()` (the API) the rows go out as they are merged. The same
  for an `ORDER BY … LIMIT` with a large `OFFSET`. Temporary files are closed
  and deleted at the end whatever happens, also when a `LIMIT` stops reading
  (Windows does not delete an open file); one left by a dead process is deleted by the
  next sort after an hour. On 40,000 rows with `memory_limit = 24M` it
  answers, where 2.8.0 stopped with the memory error, and the panel now
  exports a whole table sorted by any column with 32 MB. Sorts that fit run
  as in 2.8 (`ORDER BY saldo DESC`, 20,000 rows: 23.6 / 24.0 ms). Reported by
  the performance review (M1).

- **`COUNT(*)` and `SHOW TABLES` without reading the parts.** The number of
  rows of each part is kept in the cache with the size and date of its file,
  and recounted only for a part whose file changed; a file changed in the same
  second it is counted is not trusted. On 60,000 rows, from about 7 ms to
  0.26 ms and from about 9 ms to 0.31 ms.
- **Sorting and grouping texts:** the collation key of a text without accents
  is no longer computed through the whole collation map, and the keys of short
  texts are remembered; `ORDER BY` on a text column up to 44 % faster.
- **`IN (SELECT …)` compiled** like the rest of the `WHERE`: `IN` 17 % and
  `NOT IN` 30 % faster.
- **`JOIN`:** the column names with their alias are built once per query, not
  once per row and column: 10–13 % faster.
- **`ORDER BY` a number with `LIMIT`:** the comparison of each row with the
  worst one kept is done in place when both are numbers, without going through
  the general comparator: the 10 smallest of 200,000 rows, 196 → 165 ms
  (−16 %), the same rows. From the review of the release candidate.
- **`ALTER TABLE … ADD UNIQUE` checks the keys while it builds the index.**
  Comparing the keys as the index does (see Fixed) read and keyed the whole
  table once to look for repeats and again to build the index, and it took 11
  to 15 % longer than 2.8.0; now one pass does both, part by part, and the
  repeat it reports is the same row as before (the first in the table's
  order). On 40,000 rows, CPU time, median of nine: 147 against 171 ms in
  2.8.0 (−14 %).

### Less memory

- **What does not fit in memory goes to disk: `JOIN`, `GROUP BY`, subqueries
  of the `FROM`, views, `WITH`, `UNION` and window functions**, as `ORDER BY`
  does (see Faster). The usual path is untouched; only when half of
  `memory_limit` is in use is the query run again writing what it gathers to
  temporary files in `.orden/` (new `engine/Volcado.php`): a hash `JOIN`
  (`INNER` or `LEFT` with equalities in the `ON`) splits both sides into 64
  files by its key and joins them file by file; a `GROUP BY` splits the rows
  into 64 files by their group; a subquery of the `FROM`, a view or a query of
  the `WITH` goes to a file as its rows come out and is read as it is used; a
  `UNION` or `UNION ALL` without `ORDER BY` passes each row on as it is read
  instead of gathering every part; window functions keep in memory only the
  values they use, a list per expression, and sort in pieces that fit. Same
  rows, and the same order when there is `ORDER BY`. With 24 MB on 60,000 +
  60,000 rows each of them answers as with memory to spare, where 2.8.0
  stopped with the memory error; with a million rows and 256 MB, a `JOIN` of a
  million orders with a million customers grouped by city takes 19 s and
  128 MB, a `GROUP BY` of 631,796 groups 14 s and `ROW_NUMBER()` over the
  million 33 s. When even this does not fit, the usual memory error, and not
  PHP's fatal: before running it again, the memory PHP kept from the first
  attempt is given back (`gc_mem_caches()`). Temporary files are deleted at the
  end whatever happens; one left by a dead process, after an hour.
- **A write that does not fit in memory is written in stretches.** When the
  memory in use passes about half of `memory_limit`, the statement writes what
  it has so far and goes on; outside a transaction it is first run again
  inside one, before writing anything, so that it is still all or nothing
  (new `engine/SentenciaGrande.php`). An `INSERT … SELECT` from other tables
  takes their rows as they come out, in batches of up to 8,192 rows or 8 MB
  (a sixteenth of `memory_limit` if that is less), without gathering them
  all. With a million rows and 256 MB, an `UPDATE` of 300,097 rows takes 17 s
  and 147 MB, and of all of them 24 s and 140 MB.
- **`IN (SELECT …)` takes the values as the rows come out**, without gathering
  the rows of the subquery first; also in an `UPDATE` or `DELETE`.
- **`COUNT(DISTINCT …)` and the other aggregates with `DISTINCT` keep each
  value once as it arrives**, and `COUNT` without the value, instead of
  gathering every value and removing the repeated ones at the end, which held
  them up to three times. Without `GROUP BY`, when they do not fit they go to
  disk too: each value to one of 64 files by its key, and the distinct ones of
  each file are added up. `COUNT(DISTINCT email)` of a million customers,
  183 → 90 MB; `COUNT(DISTINCT email)` and `COUNT(DISTINCT nombre)` together
  reached 245 MB of 256, a step away from PHP's fatal error, and now take
  8.4 s and 152 MB. `GROUP_CONCAT(DISTINCT … ORDER BY …)` still gathers its
  values.
- **`DISTINCT` drops a repeated row as soon as it is projected**, instead of
  gathering the whole table and every projected row and removing the repeated
  ones at the end. On 60,000 rows, median of five runs against 2.8.0:
  `SELECT DISTINCT ciudad` 45.9 → 5.5 MB (in one process 74.4 → 49.7 ms),
  `SELECT DISTINCT ciudad, edad … ORDER BY edad` 103.9 → 62.6 ms. Found while
  measuring the review's proposal of loading only
  the columns a query uses (M1): that one was measured and left out, because
  the engine already keeps only the output columns and `GROUP BY` already
  streams (5.5 MB), so it would only add a copy of every row.
- **`REGEXP` no longer leaves an error handler behind for every row** (also in
  2.8.0): 20,000 rows kept 8 MB until the end of the request.
- **The converted `LIKE` and `GLOB` patterns are kept up to 4,096 or 1 MB**
  (also in 2.8.0, without a limit): `LIKE '%' || b.texto || '%'` over 300,000
  rows kept 70 MB.
- **Correlated subqueries keep at most 4,096 results between them**, and let
  them go when memory runs short or the query goes to disk (also in 2.8.0): a
  scalar subquery over a million rows kept a result for each.

### Checked: what is faster and what is not, against 2.8.0

18 queries covering each change (lookups by key, unique text and index, `IN`
lists, ranges, `LIKE`, `GROUP BY`, `DISTINCT`, `ORDER BY`, `JOIN`, `LEFT
JOIN`, a subquery, `UNION`, `UPDATE`, `INSERT` and `DELETE`) on 20,000
customers and 30,000 orders, with the data written by 2.9 and by 2.8.0: each
engine in its own process after two warm-up runs, the two alternated five
times and the best time of each kept, and the closest ones measured again in
eight more rounds, and those still close in ten or twelve more. With the
data written by 2.9, none is slower: a lookup by key −15 %, by a unique text
−60 to −65 % (its filters come from the cache), by a key and an index −4 to
−14 %, ten keys by `IN` −20 to −26 %, a hundred −62 %, an equality on an
index −51 to −52 %, a range −2 to −8 %, `LIKE` −6 to −26 %, `GROUP BY` the
same to −5 %, `DISTINCT` −22 %, an `ORDER BY` of two columns −38 %, a join
−19 %, a `LEFT JOIN` −14 %, a join with `GROUP BY` −12 %, a subquery −15 %,
the `UNION` −4 to −9 %, a one-row `UPDATE` −26 % and an `INSERT` with its
`DELETE` −34 %; the first 50 rows of a table, the same (between −7 % and +3
%). With the data written by 2.8.0, the same, except the lookup by a unique
text, which is as in 2.8 (between −1 % and +5 %), because its index pieces
have no filter until the table rewrites them, or until `VACUUM` rebuilds
them. A result served from the result cache, 0.13 against 0.15 ms (a join of
the two tables, median of 200).

A whole request (loading the engine and one statement, in PHP's own web
server, time inside the server, without the result cache) with OPcache, as on
almost every web server, median of 300: a lookup by key 0.61 against 1.55 ms,
a join 0.88 against 1.81, a range by key 0.50 against 1.75, `GROUP BY` 0.73
against 2.06, a subquery 1.41 against 3.60, `LEFT JOIN` 4.35 against 5.90, a
one-row `INSERT` 3.67 against 5.11, `UPDATE` 4.47 against 5.95, `DELETE` 3.77
against 5.69, and an upsert 4.79 against 24.32 ms. Without OPcache, every one
is faster too (see Faster).

Writes, on 40,000 rows with a unique text and a foreign key with cascades,
each engine on its own copy of the data it wrote, CPU time, median of nine
(and of fifteen for the closest): a one-row `INSERT` −36 %, `UPDATE` −2 to
−3 %, `DELETE` −96 %, an `UPDATE` of 10,000 rows the same to −7 % and of a
unique column −10 %, a `DELETE` of 10,000 −13 %, upserts of 2,000 rows −45 to
−62 %, cascades −4 to −32 %, `CHECK KEYS` −31 %, `CREATE INDEX` −15 %, `ADD
UNIQUE` −14 %, `INSERT`s of 10,000 rows −5 to −8 %. With 128 MB, median of six
(wall clock): an `UPDATE` of every row 0.73 against 1.04 s, an upsert of
40,000 rows by `ON CONFLICT (id)` from a `SELECT` 1.27 against 1.29 s, `INSERT
OR IGNORE … SELECT` 0.64 against 0.66 s, `INSERT … SELECT` the same (0.76 s),
and `INSERT OR REPLACE … SELECT` 1.07 s, where 2.8.0 ran out of memory; of one
row among them, an upsert 19 against 301 ms, `REPLACE` 20 against 395 ms, a
`DELETE` 20 against 262 ms, and an `INSERT` or an `UPDATE` the same (13 against
14 ms, 19 against 19 ms, median of twenty); a `DELETE` of many rows, from a
tenth to a third of the table, 268 to 316 against 363 to 376 ms.

All of it measured on the final code: what counts is each pair, taken at the
same time.

### Tests

- `f21_compatibilidad`: columns with `COLLATE NOCASE` and `RTRIM` against
  SQLite, 100 queries (comparisons, `IN` with a list and a subquery, `BETWEEN`,
  `CASE`, `ORDER BY`, `GROUP BY`, `DISTINCT`, `MIN`/`MAX`, windows, joins,
  `UNION`, `INTERSECT`, `EXCEPT`, views, subqueries and `WITH` that inherit it,
  recursion, `NULLIF`, `CAST` and a written `COLLATE` inside an expression); 29
  writes with `UNIQUE`, primary key, foreign keys with cascades, upserts and
  `UPDATE`/`DELETE` by index or by parts, and what the indexes find after
  each; `ORDER BY` with `LIMIT`, `MIN`/`MAX` and `COUNT` read from a collated
  index; which index a `JOIN` may use, and a hash join of 20,000 by 10,000
  rows in under 2 s; and `ALTER TABLE`, `CHECK KEYS` and `SHOW`.
  `f14_volcados`: the dumps of each system with their collation, loaded into
  SQLite and imported back, and what the importer does with MySQL (also the
  table's or its character set's), PostgreSQL and SQL Server collations.
  `f5_admin`: editing a column keeps `COLLATE`, `UNSIGNED` and a `DEFAULT`
  worked out when inserting. 57 mutations of the code, each caught by one of
  these tests.

- `f21_compatibilidad`: the 23 window functions (with `FILTER`) in 20 kinds of
  window against SQLite, with the same rows in the same order; with `GROUP BY`
  and `HAVING`, named windows, in the `ORDER BY`, with `DISTINCT` and `LIMIT`,
  in subqueries and in the order of the first window; `FILTER` in plain
  aggregates; the 22 errors; and 20,000 rows in under 3 s (it fails if running
  sums go through the frame on every row). `f16_vistas_triggers`: how window
  functions are exported to each system, and `NULLS FIRST`/`LAST` in
  PostgreSQL. Each fails without its code.

- `f21_compatibilidad`: 22 recursive queries against SQLite (counting, `LIMIT`
  and `OFFSET`, `UNION` cutting cycles, trees, paths, several parts, named
  columns, without `RECURSIVE`, inside a subquery, correlated, in a view) and
  the 11 errors. `f16_vistas_triggers`: how a recursive view is exported to
  each system. Each fails without its code.

- `f16_vistas_triggers`: a trigger with an upsert, other tables, an alias,
  `ORDER BY` or `LIMIT` is not translated to MySQL, PostgreSQL or SQL Server,
  and says why (it fails without the fix).

- `f21_compatibilidad`: `UPDATE … FROM`, `UPDATE … JOIN`, `DELETE … USING` and
  `DELETE … JOIN` against SQLite (or what means the same there): aliases,
  subqueries, several tables, `LEFT` and `RIGHT JOIN`, several matches, a
  self-join, `CHECK`, `UNIQUE`, `DEFAULT`, `RETURNING`, cascades, triggers and
  a trigger that runs an `UPDATE … FROM`; on a table with holes of deleted rows,
  by index and with the table on the right of the `JOIN`; the errors. Each
  part fails without its code. `f19_escrituras_contra_sqlite`: the random
  writes include the four forms. `f7_concurrencia`: a subquery or a join in a
  write takes the database lock (it fails without the fix).

- `f21_compatibilidad`: `JOIN … ON` between integer, decimal and numeric text
  gives what SQLite and the same `=` in the `WHERE` give; `FROM a, b WHERE
  b.k = a.k` gives what `JOIN … ON` gives, on 3,000 by 3,000 rows in under a
  second; `DISTINCT`, `GROUP BY`, `UNION`, `INTERSECT`, `EXCEPT` and
  `COUNT(DISTINCT …)` with 1, 1.0, 0.3 and 0.30000000000000004 as SQLite;
  `EXPLAIN` counts the rows a join by index reads when it has to go through
  the table (each fails without its fix).

- `f8_indices`: four `ORDER BY` (ties, `NULL`s, collation, an expression, a
  large `OFFSET`, `SELECT *`) on 40,000 rows run with `memory_limit = 24M` give
  exactly what they give with 1 GB, sorted in at most 8 temporary files (19
  with the reserved memory as the trigger) that are gone at the end; without
  sorting on disk the 24 MB run dies.
- `f20_memoria`: a whole table sorted by a column exported with 32 MB, with the
  API and with the direct connection (2.8.0 stopped with the memory error); a
  query ending in a comment exported whole (it fails without the fix).
- `f5_admin`: the ZIP copy has no `.cache/`, `.orden/` or `.turno` files.
- `f4_api`, `f7_concurrencia` and `f8_indices` delete their folder in the
  system's temporary folder when they end, as the others already did.
- `f5_admin` and `f20_memoria` take `--open-basedir`: the panel and the API run
  as on a hosting that limits PHP to the project and its data, without the
  system's temporary folder, with uploads in a folder of their own and PHP
  warnings shown in the answers. The CI runs them so (`f20` with the API and
  with the direct connection). The first run found the two `open_basedir`
  warnings above. `f5_admin`: signing out without a token says so, with no
  fatal error.
- `f20_memoria`: the dates of a scheduled backup across the change to winter
  time in Europe/Madrid (they fail with 2.8.0's arithmetic); with
  `open_basedir` and without the system's temporary folder, the temporary file
  goes to the panel's data folder, a sort on disk works and a write prints no
  warning (it fails without the fix).
- `f4_api`: 2,000 requests spread over more than 200 token files, none with
  more than a few dozen; a token from the old `estado.json` refused, and the
  file deleted once it can hold no valid token. The helper that runs the
  limits in another process now cleans the `estado/` folder between checks.
- `f8_indices`: `SELECT DISTINCT` on a table of 30,000 rows run with
  `memory_limit = 16M`; it fails with 2.8.0's code.
- `f8_indices`: `ORDER BY … LIMIT` on integer, double and `NOT NULL` columns
  with many ties, `NULL`s and `OFFSET`, through 8 rounds of writes, equal to
  sorting the whole table; it fails if a piece holding a tie is skipped.
- `f8_indices`: text keys found after 12 rounds of inserts, updates and
  deletes, `UNIQUE` kept, and only the piece of the row read (with the keys of
  every piece whose filter rules the row out broken, the lookup still
  answers; without the filter it fails); pieces without a filter, as 2.8
  writes them, still read right. `f3_escrituras` compares index pieces without
  `filtro`, `min` and `max`, a summary of the keys.
- `f8_indices`: `COUNT(*)` and `SELECT *` with 30 ranges, `BETWEEN` and
  equalities on dates, texts, texts that look like numbers, integers and
  doubles, against the same `WHERE` with the columns wrapped in a `CASE` (no
  index); it fails if a strict `<` is taken as `<=`, if keys of the other type
  are compared, or if a piece is skipped by a wrong `min`/`max`.
- `f8_indices`: `MIN`/`MAX` of integer, double and decimal columns with
  `NULL`s, after 30 rounds of inserts, deletes of the maximum and minimum and
  updates, equal to reading the table; with the data files made unreadable the
  answer still comes (it does not read them); an empty table gives `NULL`.
- `f5_esquema`: a redefined view, and the collation changed between two
  processes sharing the cache on disk; both fail without the fix.
- `f12_contra_sqlite`: `GROUP BY 1`, `GROUP BY 1, 2`, `GROUP BY 1 HAVING`, and
  `LIMIT` with an expression, negative, with a negative `OFFSET` and `;;`,
  against SQLite. `f2_select` and `f2_parser`: the new errors.
- `f5_admin`: CSV files separated by comma, semicolon and tab, with a comma in
  a column name, and with one column.

- `f3_escrituras`: transactions committed and undone (also a `CREATE TABLE`
  and a `DELETE`), a batch that fails at its second statement, a transaction
  inside another, and a process killed with `-9` halfway: other processes see
  nothing while it lasts, and opening the database undoes it. After an undo
  with APCu, no cached structure of what was undone is served (it fails
  without the table generation). The OPcache layer: stable parts loaded from
  OPcache with the same rows, a rewritten part loses its `.php`, and
  `JSONSQLDB_CACHE_OPCACHE = false` makes none. `JSONSQLDB_CACHE_APCU = false`
  keeps the cache on disk. A cached row count is never wrong after a hand edit,
  also in the same second and with the same size (it fails without that rule).
- `f23_reparar` (new): every safe repair, a damaged `rev.json`, structure and
  index piece, an edit by hand seen through the cache, the cached query
  results of the table retired by the query that sees it, an edit made with
  the cache empty, an identical copy of the files that rewrites nothing, a
  doubtful row that fails with its line and changes nothing, `CHECK TABLES`
  and `REPAIR TABLES`. Each protection was removed in turn to check that its
  test fails; run also with APCu, OPcache and both.
- `f4_api`: a `lote` with permissions and signature; `f5_admin`: several
  statements in the console as one transaction, the read-only role unable to
  slip a write behind a `SELECT`, a damaged file shown and repaired from the
  Integrity tab, a ZIP that brings `.cache/` writes nothing there.
- `f24_versiones_anteriores` (new): each of the 27 published versions, from
  1.0.0 to 2.8.0, taken from its git tag, builds a database with its own engine
  and settings, and the current engine must read it as that version did, write
  to it, carry its autoincrement on and keep its constraints, trigger, indexes
  and keys consistent; each also leaves a table exactly at the limit of a part
  after deleting, and `VACUUM` must leave every index piece with its filter and
  the data the same. All 27 pass; with the reader of the pre-2.6 index files
  broken on purpose, those of 2.0 to 2.5 fail, and counting the parts by the
  list in `rev.json` alone, 2.8.0 fails.
- `f21_compatibilidad` (new): the SQLite and MySQL functions and syntax, most
  against SQLite. `f22_sqlserver` (new): the PowerShell script, and its dump
  imported with its view, trigger, cascade, unique key and defaults working.
- `f14_volcados`: one check per fix of the import and export review; a CSV
  exported and imported back with a backslash before a quote, a separator and
  a line break; a Latin-1 MySQL dump with a text of 250 KB next to one in
  UTF-8; the Excel *Warnings* sheet; and the engine's APCu entries emptied
  when a database is put back; a ZIP that brings its own `.htaccess`.
  `f5_admin`: a mysqldump `INSERT` of 600 KB imported through the API; a
  sign-in without token, and a user name of 5,000 characters, in the audit
  trail. `f11_asistente`: keys with `);`, `;`, `$1` and quotes written to
  `config.php`, twice. `f4_api`: `SHOW DATABASES` in a `lote` with a limited
  key. `f21_compatibilidad`: the 16 MB ceiling of each function.
- From the review of the format on disk: `f1_nucleo`, part 1 at revision 13
  next to part 11 at revision 3 in the disk cache; `f3_escrituras`, the
  reserved table names; `f8_indices`, `UNIQUE (a, b)` with `UNIQUE (a_b)`, and
  such a table made as before 2.9; `f23_reparar`, holes with `rev.json` lost,
  a part with holes repaired, «Å» (its UTF-8 holds the byte 0x85) in a part
  repaired, a byte order mark, `_views.json` with an extra comma and cut short,
  a missing part in the middle, and the `rev.json` 2.8 left listing a last
  part that a `DELETE` removed. Each fails with its fix removed. `f8_indices`:
  `VACUUM` rebuilds the index pieces without a filter and not the parts, and a
  second one touches nothing.
- `f4_api`: the three example clients run a `lote` against the API served by
  HTTP (PowerShell with `pwsh`, Python with `python3`, each skipped if missing),
  and one that fails leaves nothing; a wrong signature of the `lote` in each,
  or a PowerShell list that comes back unrolled, makes it fail.
- `f14_volcados`: a failed all-or-nothing import while another process writes
  to the database (the other waits, and its row stays), which fails with the
  copy of before; and an import killed by PHP's time limit, undone on the next
  use of the database. `f5_admin`: the imports that must go through the API
  ask for a panel without the engine at hand.
- `f3_escrituras`: deleting the last rows shortens the table, also with older
  holes before them and from one part to the one before, a queue does not
  pile up holes, and a last part written before 2.7 is shortened too;
  `f7_concurrencia`: writers by parts while another deletes from the end and
  shortens the table. Removing any step of the shortening makes `f3` fail.
- `f3_escrituras` and `f5_esquema` check that they leave nothing in the
  temporary folder (two checks left folders there), and `f5_admin`,
  `f11_asistente` and `f20_memoria` no longer leave their test web servers
  running: they stopped the shell that started each one, not the server.
- CI: the panel's tests also without `mbstring` and cURL, APCu available so the
  tests that use it run, the whole history checked out so `f24` finds the tags,
  and `f21`, `f23` and `f24` added (`f21` was not run there).
- `f1_nucleo`: thirteen kinds of statement (lookups, a join, `COUNT(*)`, a
  range, a function, `GROUP BY`, `ORDER BY`, `UNION`, `WITH`, `INSERT`,
  `UPDATE` and `DELETE`) load none of the classes kept apart that they do not
  use, and none compiles as much code as 2.8.0 did with it on the same
  database (pieces of code, without spaces or comments); and a `CREATE TABLE`
  loads its own. It fails if a class is loaded where it does not belong, or if
  a statement compiles more. `f8_indices`: `COUNT(*)`
  of equalities by index on lists, single keys, `IN` and deleted rows, the
  same as counting the rows. `f16_vistas_triggers`: the panel's SQL writer in
  a process without the engine loaded, as with the database through the API.
- `f3_escrituras`: in one process, the state and structure kept from a query
  are not used after another process changes the table, nor the state of a
  table written in the same second; up to revision 31 each write leaves in the
  disk cache only the entries of the current revisions (and one put there as a
  read crossing a write would), revision 32 sweeps that one, and `DROP TABLE`
  leaves nothing, also the state kept for OPcache. `f8_indices`: every key of
  tables with the keys in order, out of order, with gaps, repeated, negative
  and with decimals, found by index as by reading the table, also with a key
  whose text holds another key. Each fails with its change removed.

- From the security review at the end of 2.9, each failing with its fix
  removed: `f3_escrituras`, a `lote` that only reads does not stop another
  process; `f4_api`, the last IP of `X-Forwarded-For`, an unknown key answered
  as a wrong signature, a statement with spaces at its ends, a token from a
  clock ahead kept until it expires, the PHP client's shortcut; `f5_admin`,
  a ZIP with `.tx/`, `.lote/` and another folder (and `.reparados/` kept),
  sign-in attempts at the same time and from another account, the Data tab
  with `]` and `,` in column names, a database with `-` restored, the current
  password, the panel's proxy IP and time zone; `f5_esquema`, results of more
  than 1 MB or with no table; `f9_journal`, a journal and a transaction not
  written by the engine; `f11_asistente`, no PHP version or full paths before
  signing in; `f14_volcados`, line breaks and a lone `\r` in what a dump
  comments, a CSV with an unclosed quote and a ZIP file larger than memory.
- From the last review before the release, each failing with its fix removed:
  `f3_escrituras`, a read-only `lote` and an `INSERT` with a foreign key do
  not wait for each other, the original of a file that cannot be saved undoes
  the transaction without leaving the statement's journal, a statement that
  fails inside a transaction leaves nothing open, and a file that cannot be
  put in its place is finished when the database is opened (in a
  transaction, it is undone whole); `f9_journal`, `CREATE INDEX`, a trigger,
  a `CHECK` and `VACUUM` with the part size changed lose no rows, and a table
  called `manifiesto`; `f23_reparar`, `COUNT(*)` with extra commas, whole or
  in chunks, and `REPAIR TABLES FORGET` inside a transaction; `f5_esquema`,
  `'now'` in any date function or as a parameter kept out of the result
  cache, and `ADD COLUMN` and `MODIFY` keeping the column's `CHECK` and
  `REFERENCES`; `f12_contra_sqlite`, eight triggers that change or delete rows
  the same statement is about to touch, against SQLite; `f21_compatibilidad`,
  `STRFTIME('%s')` with PHP in `Europe/Madrid`, `UTC_DATE`, `UTC_TIME` and
  `UTC_TIMESTAMP` without parentheses, and `CREATE TABLE … LIKE` with its
  `CHECK`; `f4_api`, a query that passes `TIME_LIMIT` answered in JSON and
  logged with its key and operation; `f5_admin`, failed sign-ins that expire,
  `open_basedir` limited to the panel and text with line breaks edited in a
  text area; `f14_volcados`, a column with a comma in its name in keys,
  indexes and dumps, and a ZIP with an 18 MB part and a 100 MB original in
  `.reparados/` restored within 96 MB of memory.
- From the test with a million rows, each failing with its fix removed:
  `f3_escrituras`, an `UPDATE` of one row in each of 40 parts of 400 KB with
  16 MB of memory, and two scans of a table larger than a 4 MB APCu that
  never empty it; `f8_indices`, the number of temporary files of a sort on
  disk. And `f4_api`: with `JSONSQLDB_API_ACTIVA = false`, a well-signed
  `INSERT` is refused, not run and not logged.
- From the tests with a million rows too, each failing with its change
  removed: `f8_indices`, a `JOIN`, a `LEFT JOIN`, a `GROUP BY`, subqueries of
  the `FROM` with `DISTINCT`, `UNION` and `UNION ALL`, a `UNION`, `IN`,
  `EXISTS`, `WITH`, two window functions and `COUNT(DISTINCT …)` on 60,000 +
  60,000 rows give with 24 MB what they give with memory to spare, without
  leaving temporary files, and two that may not fit even on disk end, if so,
  with the memory error, never with PHP's fatal; `f3_escrituras`, an `UPDATE`, an
  `INSERT … SELECT`, an upsert and a `DELETE` of 16 MB of rows with 16 MB of
  memory, and an `INSERT OR IGNORE … SELECT` into a table with two unique text
  columns that gives the same with 16, 32 and 256 MB, and an `INSERT … SELECT`
  into an empty table that sees a repeated key of its source, also after
  writing a stretch; `f19`, upserts at
  random against SQLite on tables of many parts, without loading the table;
  `f2_select`, a `UNION ALL` passed row by row and in an `INSERT … SELECT`
  keeps the repeated rows of its first part (the first version of the
  streaming dropped them), a subquery of the `FROM` without an alias, and a
  `LIMIT … OFFSET` that skips whole parts gives the rows of the whole table,
  with holes and an empty part; `f8_indices`, a `LIMIT` with a `WHERE` that an
  index covers gives the same rows, in the same order, as scanning, with
  `IN`, `OFFSET`, two columns and a condition the index does not cover, and
  stops reading pieces once it has them;
  `f2_parser`, the same in the parser; `f12_contra_sqlite`, eleven `EXISTS`
  against SQLite; `f21_compatibilidad`, a correlated `EXISTS` resolved once,
  with what `EXPLAIN` says; `f4_api`, IPv6 addresses counted by their /64.
- From the code audit of the release candidate, each check failing with its
  fix removed (checked one by one): `f1_nucleo`, a lock that cannot be taken
  releases the database's, the log keeps a line cut inside a letter or with
  bytes that are not UTF-8, and its default level; `f2_parser`, 20,000 chained
  comparisons and 5,000 nested `NULLIF` or `MIN` parsed and resolved in well
  under a second, every prefix of the test statements (about 100,000) ending
  in a syntax error, `||` binding as in SQLite, and subqueries refused where
  the value is worked out before reading; `f2_select` and
  `f21_compatibilidad`, against SQLite where it applies: `NATURAL JOIN` and
  `USING` with the columns named, `ORDER BY` an alias named like a column on
  every path, `EXISTS` with an aggregate, `OFFSET` or a window, `LIKE` with
  many `%` and with texts that are not UTF-8, `~` compiled, `COLLATE` inside
  an aggregate, extreme values that ended in PHP errors, `TRIM` by characters,
  `DATE_ADD` milliseconds, `STRFTIME('%W')` every day from 2020 to 2031,
  `GLOB` brackets, `BETWEEN` with `NULL`, `STRCMP`, `PRINTF('%,d')`, the total
  order on every path, `REGEXP` without handlers left behind, and the caps of
  the pattern and correlated-subquery caches; `f3_escrituras`, `f5_esquema`
  and `f19_escrituras_contra_sqlite`, cascades of two levels at random against
  SQLite, cascaded rows with their triggers, unique and foreign keys, the
  parent row gone before its cascades, upserts whose triggers write, `TRUNCATE`
  with a foreign key to itself, keys in `ALTER`/`CHECK`/`REPAIR KEYS`,
  `RENAME TO`, unique-key names, `ENUM` values over 40 characters, `CREATE
  TABLE … AS SELECT` types and `NEW`/`OLD` in `CREATE TRIGGER`; `f7_concurrencia`,
  a write by parts discarded by a conflict leaves no filters behind (in
  separate processes: in one, the process's own memory hid the fault), one
  that commits after another opened a part, and two processes rebuilding the
  same OPcache file; `f8_indices`, numbers spread over the table covered by
  the filter (`"cubre":"todo"`), the cache sweep keeping the filters, and a
  sort on disk cut by a `LIMIT` closing its files first; `f10`, a state from
  before 2.7; `f23_reparar`, a hand edit that keeps every line's length, also
  of a part whose fingerprint has been through a sweep, the cached results
  retired by the query that sees an edit, and a repair larger than the
  memory; `f21_compatibilidad`, `STR_TO_DATE` with `%f`; `f5_esquema`, a
  statement a trigger cannot run refused when it is created, and `ADD UNIQUE`
  finding a repeat while it builds its index, across two parts, with an index
  already on the same columns and with indexes off; `f4_api`, parameters out
  of range and
  errors with bytes that are not UTF-8; `f5_admin` and `f11_asistente`, a huge
  user name at sign-in, the wizard and the `Host` header, the language token,
  fields sent as lists, the null character in a password and a damaged panel
  file; `f14_volcados`, `f16` and `f17`, every import and export fix, with a
  real PostgreSQL where there is one. `f14_volcados` no longer needs
  `mbstring` nor a fixed wait in its concurrency test; `f3_escrituras` and
  `f5_esquema` skip, instead of failing, the checks that need APCu, OPcache or
  SQLite when they are missing.
- The whole suite ran before release on every PHP version, 8.0 to 8.5, with
  real PostgreSQL and MySQL servers. `f17_rutinas_importadas` imports a
  PostgreSQL trigger function for three events and compares what it leaves
  with PostgreSQL 16; `f11_asistente` checks the data folder with an
  administrator's session and no API URL. Fixed in the tests themselves: the
  scheduled backup test takes 02:00 on the day the clocks go back as the
  running PHP does (8.0 takes the second one, 8.1 and later the first); the
  tests that read private members call `setAccessible()` only on PHP 8.0, as
  8.5 warns that it is deprecated; `f1_nucleo` and `f14_volcados` start their
  child process without a shell, as `escapeshellarg()` in PHP 8.0 drops
  non-ASCII letters under the C locale; `f3_escrituras` skips its OPcache
  state check without OPcache; `f8_indices` gives the window functions over
  60,000 rows 32 MB instead of 24 MB before PHP 8.2, where a list of integers
  takes twice the memory; and `f21_compatibilidad` reads SQLite's values with
  their types on PHP 8.0, where PDO returns them as text, does not use
  `array_is_list()` (8.1), and skips there the one check that needs the 17
  digits of a decimal, which PDO gives with 15.

### Documentation

- New `docs/06-format.md`: reference of the files on disk, field by field,
  with what happens when each one is missing, the values by type, the index
  keys and filter, the journal, transactions and repairs, and how to read or
  change the files from outside.
- `docs/01-core.md`: column names may have spaces or signs (since 2.8; it
  still gave the old rule), and the example data file has the offsets the
  engine writes. What stays in the process said sixteen index pieces and one
  part, which 2.8 already changed to four pieces and no parts.
  `docs/01-core.md` and `docs/02-queries.md` no longer say that a `DELETE`
  rewrites index pieces without their filter: since the holes, a `DELETE` does
  not move rows.
- `docs/01-core.md`: what does not fit in memory and goes to disk, what still
  has to fit, and a million rows: every query and write measured, with its
  time and memory. `docs/02-queries.md`: the subquery without an alias, the
  `EXISTS` semi-join and what goes to disk. `docs/03-writes.md`: writes in
  stretches and upserts without loading the table. `docs/04-api.md`: IPv6 by
  its /64. The `.orden/` folder holds more than the temporary files of a sort
  (`01-core`, `05-admin`, `06-format`), and the README says what a million
  rows costs instead of «not millions».
- `docs/02-queries.md` said that a text compared with a number takes its
  leading number (`'12abc'` is 12); it does not: `'12abc' = 12` is false, and
  only arithmetic and `CAST` take it. It now has the full operator precedence
  and the total order. Every document was checked against the code before the
  release.

## [2.8.0] - 2026-10-03

Three things every application needs and the engine did not have — knowing the
id of the row just inserted, a default that is the current date, and «insert or
update» — plus `CREATE TABLE … AS SELECT` and more speed. The panel exports and
imports databases larger than PHP's memory and files larger than its upload
limit, loads Excel files and makes scheduled backups. 2.7.5 was never
published: its changes are part of this release. The new SQL works as in SQLite and is checked against it; what
already existed works as before, and the inserts, updates and deletes that do
not use the new syntax were measured and are as fast as before. Nothing
changes in the on-disk format of existing data.

### Added

- **`RETURNING` in `INSERT`, `UPDATE` and `DELETE`**: the statement returns the
  rows written, like a `SELECT` (`RETURNING id`, `RETURNING *`,
  `RETURNING qty * 2 AS twice`), and the API answers with that list. The way to
  know the id of a new row: `SELECT MAX(id)` afterwards could return another
  client's.
- **Defaults worked out on insert**: `DEFAULT CURRENT_TIMESTAMP`,
  `CURRENT_DATE`, `CURRENT_TIME` and `DEFAULT (expression)`, also for
  `UPDATE … SET col = DEFAULT` and `ON DELETE SET DEFAULT`. `CURRENT_TIMESTAMP`
  and the others can be used in any expression. As in SQLite, the expression
  cannot use columns, subqueries or parameters, and such a column cannot be
  added to a table that has rows. `SHOW SCHEMA` has a new key,
  `defecto_calculado`, with its SQL; the table's `.meta.json` keeps it as
  `default_expr`, a new key that older versions ignore (for them the column has
  no default).
- **Upsert**: `INSERT OR IGNORE`, `INSERT OR REPLACE`, `REPLACE INTO`, and
  `ON CONFLICT [(columns)] DO NOTHING | DO UPDATE SET … [WHERE …]` with
  `excluded.column`. `DO UPDATE` goes through everything an `UPDATE` does
  (types, the other unique keys, foreign keys, `UPDATE` triggers); `OR REPLACE`
  deletes the rows it collides with applying their foreign key actions, without
  `DELETE` triggers, as SQLite does by default. Known difference: after a
  collision SQLite uses up a number of the `AUTOINCREMENT` counter even for an
  ignored row, so gaps can differ; numbers are unique and increasing in both.
- **`CREATE TABLE … AS SELECT`** (also with `IF NOT EXISTS`, `WITH` and
  `UNION`): a table with the result of a query, as in SQLite, without keys,
  `NOT NULL` or defaults. A column taken as it is from a table keeps its type,
  length and scale; any other takes it from its values (`INTEGER`, `DOUBLE`,
  or `TEXT` if they are all `NULL` or mix numbers and text: unlike SQLite,
  here a column has one type, so in that mix the numbers are kept as text). An
  expression without `AS` names its column as in any `SELECT` (`id + 1`,
  `COUNT(*)`; SQLite writes `id+1` as it was typed), and a repeated name gets
  `_2`.
- **Column names with spaces or signs**, as in SQLite: from 1 to 64
  characters, no control characters; they are written between double quotes
  (`"id + 1"`). Table, index and trigger names keep the old rule, because they
  are part of file names. The automatic index of a key on such a column is
  named with a hash.
- **Import and export**: MySQL's `INSERT IGNORE` and `ON DUPLICATE KEY UPDATE`
  (with `VALUES(col)`) are translated; defaults with the current date or time
  from any engine (`now()`, `CURRENT_TIMESTAMP(6)`, `GETDATE()`, `curdate()`,
  Access's `Now()`…) are kept instead of dropped; and the export writes
  computed defaults in each dialect (`CURRENT_TIMESTAMP(3)` for a MySQL
  `DATETIME(3)`, translated expressions in parentheses, a note line for Access).

### Panel

- **Exporting no longer has a row limit and does not need the database to fit
  in memory.** The panel measures what a sample of 200 rows takes and how many
  fit in the free memory (`memory_limit` minus what is in use): if the table or
  the result fits, it is asked for at once, as before; if not, in batches of
  that size, with only one batch in memory. This covers the SQL dump in its
  five dialects, CSV and `INSERT` of a table or of a query, and scheduled
  backups; the types a dump works out from the data (lengths, digits, the range
  of integers for Access) come from a first pass that keeps no rows. Checked
  with the panel and the API at `memory_limit = 32M` and a database of 120,000
  rows. `ADMIN_EXPORT_MAX` is gone (it was there only for memory): a
  `config.php` that still defines it keeps working. Two limits: a query with
  `ORDER BY` needs the engine to sort the whole result in its own memory (with
  the direct connection, the same memory as the panel); if it does not fit, the
  panel says so before the download starts or in the last line of the file, so
  it is never taken as complete. (2.9 sorts on disk when it does not fit.) And through the API each batch is a request of
  its own: for a consistent copy of a database being written to, use the ZIP.
- **Importing files larger than PHP's upload limit.** The browser cuts the file
  into pieces smaller than `upload_max_filesize` and `post_max_size` and sends
  them one after another with a progress bar; if the connection drops, choosing
  the same file again resumes from what had arrived. Or the file is left by FTP
  in `jsonsqldbadmin/datos/importar/` and chosen from a list, without going
  through the browser. For SQL, CSV, Excel and the ZIP restore. A file uploaded
  in pieces is deleted when the import ends, well or not; one left half way is
  deleted after two days. Importing already read the file statement by
  statement: a single statement still has to fit in memory (mysqldump writes
  them of 1 MB at most).
- **Excel (.xlsx) into a table**: the first sheet, with the column names in the
  first row. A reader of its own on `ZipArchive` and `XMLReader`, with no
  external library, that reads row by row and keeps the shared strings in a
  temporary file; cells with a date format arrive as dates. The button is
  disabled when the PHP `zip` or `xml` extension is missing.
- **Scheduled backups**: a page to choose the database, how often (every N
  hours, every day or every week at an hour), the format (ZIP or SQL dump) and
  how many to keep; older ones are deleted. They are kept in
  `jsonsqldbadmin/datos/copias/`, written to a temporary file and renamed when
  finished; a temporary file left by a backup that died is deleted by the next
  one. `herramientas/copias-cron.php` makes the ones that are due, from
  cron or the Windows Task Scheduler; without cron, when someone opens the
  panel and one is due, the browser asks for it separately without waiting for
  the answer. A lock stops two from running at once.
- Long imports and exports are no longer cut by `max_execution_time`.

### Faster

- **`UPDATE` and `DELETE` with a `WHERE` no index can answer** go through the
  table part by part instead of loading it whole: only the rows that match stay
  in memory. On 60,000 rows: `UPDATE … WHERE edad = 30` 266 → 175 ms and
  42.8 → 11.1 MB; `DELETE … WHERE edad = 30` 51.6 → 32.3 MB in the same time.
  With triggers, a foreign key to itself or a unique key without an index, it
  works as before.
- **`DELETE` by blocks.** Deleting a row moves every row behind it one place
  (rows are stored by position, and the format stays as it is), so those parts
  and index chunks are rewritten. They used to be decoded, split again and
  encoded again, and their index keys worked out again row by row. Now each row
  is copied as its line of text to its new part (the offsets of each part say
  where it starts), only a row that also changed is encoded, and the index keys
  of the rows that move come from the old index chunks. The files left are byte
  for byte the same as before. On 60,000 rows, in a new process: deleting one
  row at the start 242 → 163 ms and 54 → 33 MB, in the middle 153 → 115 ms and
  30 → 19 MB, three rows spread over the table 305 → 209 ms, by a text without
  index 309 → 194 ms. It applies when the `DELETE` does not load the table (it
  finds its rows through an index or by their text); a part written before 2.7
  or edited by hand, or an index chunk that does not check out, is rewritten
  as before.
- **`ALTER TABLE … ADD COLUMN`, `DROP COLUMN` and `RENAME COLUMN` by blocks.**
  They decoded the whole table, changed every row, encoded it again and rebuilt
  every index. Now each row is changed as its line of text (the new column
  added at the end, the dropped one taken out, the renamed one moved to the end
  with its new name, as PHP did with the array), and the indexes that still
  hold are left as they are, since no row moves. The data files are byte for
  byte the same as before. On 60,000 rows, in a new process: `ADD COLUMN`
  420 → 98 ms and 65 → 10 MB, `DROP COLUMN` 418 → 116 ms, `RENAME COLUMN`
  413 → 123 ms. Dropping a column of the primary key, or a part that does not
  have the expected form, goes as before.
- **`WHERE col = 'text'` on a column without an index** (or `IN` with texts)
  no longer decodes the parts of the table whose file does not contain that
  text: a row can only match if the text is there, as it is, between quotes. It
  is looked for with `strpos()` in the file just read, which costs very little
  next to `json_decode()`. It is only used with texts that are not numbers and
  that any JSON writes the same way (letters, digits, spaces and common signs;
  no quotes, slashes, `<`, `>`, `&` or `'`); with any other value the table is
  read as before. The same disk reads, much less work: on 60,000 rows,
  `WHERE nota = 'nota 45000'` 104 → 14 ms, an `IN` of two texts 145 → 24 ms. A
  value present in every part costs the same as before.
- **`UPDATE` and `DELETE` with that kind of `WHERE`** no longer load the whole
  table when there is no index to use: they take only the rows of the parts
  that contain the text, as they already did with an index. `UPDATE … WHERE
  nota = 'nota 45000'`: 161 ms and 44.8 MB → 19 ms and 4.2 MB.
- **`COUNT(*)` with equalities an index covers** (`WHERE status = 'open'`, or
  an `IN` of texts) counts the index positions instead of reading every row
  that matches: 60,000 rows, a quarter of them matching, 122 → 11 ms. Only with
  texts that are not numbers (with numbers the index puts together values the
  `WHERE` can tell apart, such as `true` and `'1.0'`) and only when the index
  covers exactly those columns and the `WHERE` has nothing else; otherwise it
  is counted as before.
- **Foreign key cascades through an index on the child's columns.** When the
  child table has an index on the foreign key (`CREATE INDEX … ON
  pedidos (cliente_id)`; as in SQLite, it is not created on its own), its rows
  are found through it and the child table is not loaded: changing the key of
  one customer with `ON UPDATE CASCADE`, 56 ms and 16 MB → 24 ms and 4.4 MB.
  Deleting gains less (124 → 114 ms), because removing rows moves the ones
  behind them and that part of the table is rewritten anyway. Without that
  index, or with triggers on the child table, it works as before.

- **Less work per row** in what every query does, measured with the table
  parts already read (what is left once reading and decoding are paid):
  - `WHERE column OP literal` is compiled into one function that reads the
    column and compares with the literal directly (two numbers, or a text that
    is not a number against a text), and `AND` checks the 0/1 of a comparison
    without another call: `edad >= 10 AND edad <= 20` 57 → 46 ms,
    `ciudad <> 'Oslo' AND edad < 30` 64 → 50 ms on 60,000 rows.
  - `IN` with a list of literals is compiled too: 78 → 67 ms; with another
    condition, 119 → 93 ms.
  - `GROUP BY` no longer builds the evaluator's context for each row when
    everything is compiled, and updates each group through a reference:
    `GROUP BY edad` with `COUNT`, `AVG` and `MAX` 91 → 76 ms.
  - `ORDER BY` of one column with `LIMIT` compares each row with the worst one
    kept without the general comparator, and keeps the collation key of that
    one while it does not change: `ORDER BY email LIMIT 50` 127 → 69 ms,
    `ORDER BY saldo LIMIT 20` 54 → 39 ms.

### Fixed

- Exporting a view that used `DATETIME()`, `DATE()` or `TIME()` without
  arguments (the current date) failed with a PHP error.
- **Changing `JSONSQLDB_FILAS_POR_PARTE` with data already written could leave a
  table inconsistent.** The first write after the change rewrote the table with
  parts of the new size, but when that write went through the shared-lock path
  (the one that merges with other writers at commit time) the revision file
  kept the old size. From then on positions did not match the parts: a
  `DELETE` by primary key answered «0 rows deleted» with the row still there.
  Checked on 2.7.4. A write that changes the part size now always takes the
  exclusive path. **If you changed that setting on a database with data**, run
  `SELECT COUNT(*)` and a search by key on its tables; if anything looks wrong,
  export and import it again.
- **`DATEADD(week, n, …)` in views imported from SQL Server added n days**
  instead of n weeks (since 2.7.3). PostgreSQL views with `'2 weeks'::interval`
  were skipped; they are imported now.
- An index lookup on an empty table read index chunks that do not exist
  (`range(1, 0)` is `[1, 0]` in PHP): extra work, no wrong result.
- The memory of index chunks already checked now tells apart a table dropped
  and created again with the same name.
- Code left without use in 2.7.4 is removed (`Writer::ponerFilas()`,
  `Writer::marcarTodo()`), and an unused property of the import translator.

### Measured and left out

- Returning the row itself as the result of a `SELECT *` (no column-by-column
  copy): 8 % faster but 10 MB more on 60,000 rows.

### Checked and not changed

An external report on 2.7.4 proposed a hash join for `JOIN`s without an index
and aggregating `GROUP BY` while reading: both are already done that way (a
`JOIN` of 30,000 × 20,000 rows takes 100 ms, a `GROUP BY` of 60,000 rows uses
4 MB). Its proposal to `fsync` less is real but trades durability, and is left
for a decision of its own.

### Tests

- **`tests/f20_memoria.php` (new)**: with the panel and the API at
  `memory_limit = 32M`, a database of 120,000 rows is exported whole (SQL,
  MySQL, CSV, a query with its order, ZIP), uploaded in pieces (a repeated
  piece is not written twice) and imported, imported from the import folder,
  and backed up by the panel, by «back up now» keeping only the ones asked for
  (the oldest by file date, whatever their names say; two in the same second
  get different names), and by the cron script.
  A filtered table goes in batches with `LIMIT`, not by running the whole query
  again for each batch. It runs again with `--directa`. With batches turned off
  on purpose, the exports fail.
- `f5_esquema`: `CREATE TABLE … AS SELECT` (types, no keys, no rows,
  `IF NOT EXISTS`, names of expressions, the index file name of such a column);
  `ADD`, `DROP` and `RENAME COLUMN` on columns whose names have quotes,
  backslashes, accents and emojis (they go by blocks of text, and the key has to
  be written as `json_encode()` writes it).
- `f5_admin`: an Excel file with shared and inline strings, dates with and
  without time, booleans and empty cells; one left in the import folder with
  `#` in its name; and a file that is not an Excel.
- `f3_escrituras`: 37 statements of `ON CONFLICT`, `OR IGNORE`, `OR REPLACE`,
  `REPLACE INTO` and `RETURNING` with the results SQLite 3.45 gives (composite
  keys named in another order, `NULL` in a unique key, the same key twice in
  one statement, triggers, `INSERT OR IGNORE … SELECT`, `REPLACE` with a
  cascade, errors), and the computed defaults. The results come from SQLite
  through Python: PHP's SQLite3 extension runs a statement with `RETURNING`
  again when its rows are read.
- `f3_escrituras`: `DELETE`s at the start, in the middle, at the end and spread
  over a table of several parts, with odd values and a unique and a plain
  index: each part byte for byte as the full write would leave it, the indexes
  giving the same rows as reading, and a check that they really went by blocks.
- `f3_escrituras`: `ADD`, `DROP` and `RENAME COLUMN` on a table of several parts
  with quotes, `"s":1` inside a text, backslashes, line breaks and emojis: the
  same data as row by row, the unique index still finding rows, and a check
  that the three went by blocks.
- `f14_volcados`: a table with computed defaults, loaded into the real MySQL,
  MariaDB and PostgreSQL servers.
- **`tests/f19_escrituras_contra_sqlite.php` (new)**: on tables cut into parts of
  12 rows, each round makes a random write (`UPDATE` and `DELETE` by a text
  without index, by `IN`, by key, key changes and deletes in cascade) and then a
  dozen queries (text equalities, `IN`, `COUNT(*)` through an index, `ORDER BY`
  with `LIMIT` of one and two columns, `GROUP BY`, `AND` of comparisons,
  `JOIN`), all compared with SQLite; even seeds add indexes on the foreign key
  and the text. It covers what changed in 2.7.4 and 2.7.5. Six seeds by default
  (`--semillas=N` for more); 70 seeds passed before release, and a fault put in
  on purpose in the text skipping makes it fail.
- `f8_indices`: `WHERE col = 'text'` on a column without an index against the
  same condition written so it cannot skip anything (absent values, `IN`, a
  text that looks like a number, special characters); `UPDATE` and `DELETE`
  with that `WHERE` on a copy of a table of several parts; `COUNT(*)` through an
  index against counting by reading.
- `f5_esquema`: the cascades test against SQLite runs again with indexes on the
  foreign keys, and with single-parent deletes and key changes.
- `f9_journal`: with the part size changed, an `UPDATE` and a `DELETE` by a text
  without index touch the right rows.
- `f14_volcados`: weeks in imported views from SQL Server, MySQL, PostgreSQL and
  Access.

### Documentation

- `docs/02-queries.md`: `CREATE TABLE … AS SELECT` and column names.
  `docs/03-writes.md`: `UPDATE` and `DELETE` without an index. `docs/05-admin.md`:
  exporting and importing without size limits, Excel, scheduled backups, the
  folders of `datos/` and the write permissions they need.
- The changelog no longer names the tools used for the external reviews: they
  are referred to as reviews made with artificial intelligence.

## [2.7.4] - 2026-10-02

Speed and memory, from an external review of 2.7.3 made with artificial
intelligence, which measured where the engine chose the wrong path or held more
than it needed. Every change was
measured against 2.7.3 with the same data (60,000 rows, warm and cold
processes) and kept only if it was better; two that were not are listed at the
end. None of them adds disk work: most read or write less. Nothing changes in
the on-disk format, the SQL dialect or the API's request and response (the
responses are byte for byte the same). Figures below are 2.7.3 → 2.7.4.

### Fixed

- **`GROUP BY … ORDER BY` with no rows** (an empty table, or a `WHERE` that
  leaves none) gave a PHP internal error instead of an empty result.

### Faster

- **Foreign key cascades.** Deleting or changing many parents read the whole
  child table once per parent, and each child row was then looked up again by
  scanning, because it was passed on with renumbered positions. The child side
  is now a map built once per statement (from the second parent on) and kept up
  to date as rows change. Deleting 1,000 customers with 5,000 orders in
  cascade: 7.1 s → 0.13 s; changing the key of 200: 1.4 s → 0.13 s. A
  cascading `UPDATE` also stops rewriting the whole child table on save: only
  its changed rows.
- **Writing a row.** Each write checked that the indexes were current by
  reading every index chunk in full, 2-5 times per statement. It now checks the
  header and the end of each chunk (which also catches a chunk cut short by a
  crash, which the old check missed) and remembers the answer while the chunk's
  revision stays the same. An `UPDATE` only touches the index chunks whose keys
  change: changing a column without an index reads and writes no index at all.
  11 single-row `INSERT`s: 110 → 68 ms; 11 `UPDATE`s: 105 → 58 ms; an `UPDATE`
  of 5,000 rows: 468 → 375 ms and 57 → 50 MB.
- **Choosing an index.** With two indexes covering one column each, the first
  one defined won, and indexes created by hand come before the primary key:
  `WHERE id = ? AND ciudad = ?` read every row of that city instead of one
  (114 → 9 ms). A unique index fully covered now goes first, then the one
  covering more columns; if one would not pay off, the next is tried.
- **`IN` with many keys.** Numeric keys are looked for only in the index chunk
  whose range holds them, in its text, without decoding it; the rows of a part
  are read with one open of its file (before, one per row, up to 8); and when
  using the index would not pay off it gives up before searching. Up to 4,096
  numeric keys use the index (512 before). 100 keys spread over the table:
  146 → 16 ms and 8.7 → 3.5 MB; 600 keys: 153 → 19 ms; 100 e-mails: 181 → 65 ms.
- **`ORDER BY … LIMIT`.** Candidates are kept up to twice the limit, sorted with
  PHP's native sort and cut, and the worst one kept becomes the bar a new row
  has to beat. The old heap also made PHP copy the whole list of sort keys for
  every row it dropped. `ORDER BY ciudad, id DESC LIMIT 1000`: 1,448 → 246 ms;
  `ORDER BY saldo DESC LIMIT 20`: 326 → 164 ms.
- **Result cache hits** find their file by its exact name instead of listing
  the cache folder: 11.7 ms with 20,000 files there → 0.1 ms, and it no longer
  grows with the folder.

### Less memory

- **The API writes a `SELECT` response row by row.** Rows of a `SELECT` without
  `ORDER BY`, `DISTINCT` or aggregates go from the engine straight into the JSON
  text, in memory, without the result ever being a PHP array (which takes
  several times its JSON). The text is sent at the end, with no locks held, and
  an error still comes as an error JSON. `SELECT *` of 60,000 rows: 43.9 →
  14.0 MB. `Database::consultarPorFilas()` is the new engine call behind it.
  A row with text that is not valid UTF-8 now gives an error instead of an
  empty response.
- **Projection while reading.** Without `ORDER BY` or `DISTINCT`, each row is
  projected as it is read instead of after reading the whole table, the
  `OFFSET` is skipped without building rows and the `LIMIT` stops the reading.
  `SELECT id FROM a`: 44 → 26 MB; `LIMIT 100 OFFSET 15000`: 13.9 → 4.0 MB.

### Measured and left out

- **`COUNT(*)` from the row count in the revision file** (15.7 → 5.5 ms): after
  recovering an interrupted write that number can differ from the data, and a
  wrong count is worse than a slow one.
- **Remembering text sort keys**, and streaming the rows of a full `ORDER BY`
  to the API: neither was clearly faster, and the second used no less memory.

### Tests

- `tests/benchmark.php` gains the cases where the problems were: a primary key
  plus a low-selectivity index, `IN` with 100 spread keys, a large `OFFSET`, one
  column of the whole table, `ORDER BY` of two columns with `LIMIT 1000`, and a
  cascade of 200 parents.
- New checks: cascades with many parents in three levels against SQLite
  (`f5_esquema`), a cut-short index chunk (`f10`), an `UPDATE` that leaves an
  index untouched (`f10`), index candidates and `IN` with up to 3,000 keys
  (`f8`), `LIMIT`/`OFFSET` applied while reading and `GROUP BY` with no rows
  (`f2_select`), and a 3,000-row `SELECT` through the API by both paths
  (`f4_api`).

## [2.7.3] - 2026-10-01

Security fixes found by four external reviews of 2.7.2 made with artificial
intelligence, each one checked in the code before fixing it, and each with a test
that fails without the fix. **Update recommended; if you ran `configurar.php`
with an earlier version, change the key of the «Clientes de ejemplo» account**
(see the first item and SECURITY.md).

### Fixed after an external audit of 2.7.2

A third audit, of 2.7.2. Its first two findings (the example clients' keys and
the global API lockout) were already fixed above; this is the rest.

- **Taking over a panel published before being set up** (F-03): the wizard
  now asks for an installation code that is only in a file on the server
  (`codigo-instalacion.txt` in the panel's data folder; `php configurar.php`
  prints it), and deletes it when done. `configurar.php` only runs from the
  command line and Apache, IIS, nginx and LiteSpeed refuse it over HTTP.
- **ZIP copy and restore outside the engine's locks** (F-04): both now take the
  engine's exclusive lock on the database (its `.turno` and `.lock` files) for as
  long as they touch its folder, and a restore rewrites the folder in place
  instead of renaming it, so whoever waits for the lock keeps waiting for the
  same file. The same for the all-or-nothing import's copy and roll-back.
  `tests/f7_concurrencia.php` copies a database over and over while four
  processes write into it: every copy has the same rows in two tables a trigger
  keeps equal (without the lock, some copies did not).
- **Panel users changed without a common lock** (F-05): creating, changing,
  deleting a user, their language and their last login now read, change and
  save under one lock, as the login attempts already did.
- **Login CSRF** (F-09): the login form carries a token and the panel checks it.
- **Inline scripts** (F-10): the panel's CSP allows scripts only from its own
  files or with the nonce of each response; the one `onchange` in a page moved
  to `panel.js`. `style-src` still allows inline styles.
- **PHP 8.0** (F-06): the panel warns that writes are not durable across a power
  cut there (no `fsync()` before PHP 8.1). PHP 8.0 is still supported.
- **HTTP status codes of API errors** (F-08): left as they are, and now
  documented (docs/04-api.md): errors come with HTTP 200 and an `error` key,
  except four cases before the API can answer (403, 500). Changing it would
  change what existing clients receive; PowerShell's `Invoke-RestMethod`, for
  one, throws on a 4xx instead of returning the JSON with the reason.
- Not done in this version, with the reason: a different store for the API's rate limits and nonces (F-07),
  splitting the large engine classes (F-11) and health reporting of
  suppressed I/O errors (F-12) are improvements with no fault behind them.

### Fixed after two more external audits

Two further audits of 2.7.3 before its release. Each finding was reproduced
first; this is what was real and what was done.

- **A dump that is not in UTF-8 could not be imported.** One Latin-1 byte (an
  old MySQL dump, `--default-character-set=latin1`) broke the analysis of the
  whole statement: «Sentencia no soportada: 'I'». Statements and CSV fields
  that are not valid UTF-8 are now read as Latin-1 / Windows-1252, and the
  summary says how many. Tested with a real `mariadb-dump` in Latin-1.
- **Two tables whose names became the same one lost data.** `ventas-2024`
  becomes `ventas_2024`; a second table already called `ventas_2024` dropped
  it with its `DROP TABLE IF EXISTS`. The second now gets a suffix
  (`ventas_2024_2`) and a warning. Tested with a real dump.
- **mysqldump's `BIT` values** (raw bytes) now become numbers, **`0000-00-00`**
  becomes `NULL` with a warning, and **`ALTER … OWNER TO`** of a `pg_dump`
  without `--no-owner` is skipped; each stopped the import before.
- **Importing is all or nothing when the panel reaches the database's folder**
  (same machine as the engine): a copy is made first and, on failure, the
  database is put back as it was. With the panel elsewhere, as before.
  APCu's cache of the database is emptied after putting it back, also after a
  ZIP restore, so no result of the undone writes can be served.
- **Integers beyond 64 bits were silently clipped** to 9223372036854775807 in
  a literal (a `BIGINT UNSIGNED` id, for one). They are now read as decimals,
  as SQLite does, `-9223372036854775808` is exact, and storing one in an
  `INTEGER` column is an error. `tests/f12_contra_sqlite.php` compares them
  with SQLite.
- **The SQL dump of a database is written table by table**, with one table in
  memory at a time instead of all of them; a medium-sized database could run
  out of PHP memory.
- **The dump for SQLite** wrote `AUTOINCREMENT` on a column that was not the
  primary key, which SQLite rejects.
- **`CAST(x AS INTEGER)` in views exported to MySQL, PostgreSQL and Access**
  rounded there and truncated here; it now truncates everywhere.
  `tests/f16_vistas_triggers.php` has a row with decimals that shows it.
- **The API address worked out from the `Host` header** (see SECURITY.md): the
  wizard and the Configuration page now always write it down.
- **ZIP restore**: a path with `..`, `.` or an empty component is rejected as a
  whole and every destination is checked to be inside the database. The
  reported escape through paths ending in `..` did not actually write anything
  (only `.json`, `.htaccess` and `web.config` files are written), but such paths
  were accepted silently.
- A `DECIMAL` of more than 15 digits is warned about on import; the panel's
  names are limited to 64 characters, as the engine's.
- A last review before release also made sure that a view or trigger with
  something the translator does not expect (a malformed dump) is skipped and
  named in the summary instead of stopping the import or the dump, and that
  putting a database back after a failed import or ZIP restore never leaves it
  without either copy: the current one is set aside first and only removed once
  the copy is in place.
- **Views, one more round.** `tests/f16_vistas_triggers.php` now exports 25
  shapes of view (every kind of `SELECT` the engine takes: joins of every kind,
  subqueries, CTEs, set operations, `CASE`, `LIKE … ESCAPE`, dates, `GROUP_CONCAT
  … ORDER BY`…) to MySQL and PostgreSQL and compares the results; and
  `tests/f17_rutinas_importadas.php` sends them there and back through
  `mysqldump` and `pg_dump`, and through the SQL Server and Access dumps
  (`tests/f18_access.php`). What it found, now fixed: `SUBSTR` with a negative
  start (from the end here and in MySQL, empty in PostgreSQL and SQL Server);
  `ROUND` of a `DOUBLE` in MySQL (rounds to even there); a condition used as a
  value with `NULL` in Access; and, importing, MySQL's `join` without `ON`
  (a cross join), PostgreSQL's `OFFSET … LIMIT` order, `~~ like_escape(…)`,
  `::time(0) without time zone` and `x + interval -2 hour`, SQL Server's
  `DATALENGTH` and `DATEFROMPARTS`, and Access's `InStr` with binary compare.
- **Access `a & b & c` grew twice as long with every `&`** on import: each
  pair was translated on its own and repeated the previous ones. The whole
  chain is now translated at once (NULL only if every part is). Found running
  the suite on PHP 8.0, where a build with a recursion limit stopped on it.
- **MySQL 8 views** (the tests had run against MariaDB, which writes them
  differently). MySQL 8 stores `NOT EXISTS (…)` and `NOT (x IN (…))` as
  `exists(…) is false` / `x in (…) is false`, and `REGEXP` as `regexp_like()`;
  they are translated now, with `IS [NOT] TRUE / FALSE / UNKNOWN` in general.
  Also fixed on the way: `a <=> b` gave `NULL` instead of 0 when only one side
  was `NULL`, and MySQL's `CAST(x AS SIGNED)` rounds (1.5 gives 2) where the
  engine's `CAST` truncates, so it is imported as `CAST(ROUND(x) AS INTEGER)`.
  `tests/volcados/rutinas_mysql.sql` has two views with all of it, and the test
  dumps were mixed with mysqldump's warning about the password on the command
  line (MySQL 8 prints it, MariaDB does not): its messages now go apart. The
  dump tests pass with MySQL 8.0.46 and with MariaDB 10.11.
- **Every PHP version, 8.0 to 8.5, ran the whole suite** before release. What
  it found:
  - **Converting a number that does not fit in 64 bits to an integer** gave a
    value that depended on the platform, and PHP 8.5 also prints a warning for
    it (in an API response, a warning before the JSON breaks it). It now stays
    at the largest or smallest integer, as `CAST` does in SQLite
    (`CAST(1e20 AS INTEGER)` = 9223372036854775807), in `CAST`, `%`, `ROUND`,
    `SUBSTR` and the date modifiers; a number written in the SQL or stored in an
    `INTEGER` column is checked by its digits before converting. Compared with
    SQLite in `tests/f12_contra_sqlite.php`.
  - The API and collation tests passed accented text to a child process
    through `escapeshellarg()`, which drops non-ASCII characters under the C
    locale; they now start it without a shell.
  - Git turned the CRLF inside a multi-line text of the Access test dump into
    LF (`* text=auto` in `.gitattributes`), so the check of that text failed
    in CI: the test dumps (`tests/volcados/`) are now kept byte for byte.
  - Two tests reached private members with Reflection, which needs
    `setAccessible()` on PHP 8.0 and warns as deprecated on 8.5; they now use a
    closure bound to the class, the same on every version. A test function
    with an implicitly nullable parameter (deprecated in 8.4) is now explicit.
- **`TRANSLATE(text, from, to)`** is a new text function (as in PostgreSQL,
  Oracle and SQL Server): views exported to PostgreSQL and SQL Server sort with
  it, so they come back with it.
- Not changed, with the reason: grouping single-row `INSERT`s only ever falls
  back to not grouping; an empty CSV field is `NULL` (documented); the order of
  `GROUP_CONCAT` without `ORDER BY` is undefined in every engine (documented).

### Fixed

- **Date modifiers were silently ignored**: `DATE(x, '+1 day')`,
  `DATETIME(x, '+2 hours')` or `STRFTIME(f, x, 'start of month')` returned
  `x` unchanged. They now work as in SQLite — `±N seconds/minutes/hours/days/
  months/years`, `start of day/month/year`, `weekday N` and `unixepoch` — and
  an unknown one is an error. `localtime` and `utc` are accepted and change
  nothing (dates carry no time zone here), so the common
  `DATETIME('now', 'localtime')` keeps working. Found while checking the exported views against MySQL and
  PostgreSQL; `tests/f12_contra_sqlite.php` now compares three queries with
  modifiers against SQLite, month overflow included (31 January + 1 month =
  3 March).
- **The example clients' key could be downloaded.** `php configurar.php` wrote
  the key and secret of the «Clientes de ejemplo» account — write access to the
  `pruebas` database — into `api/cliente_ejemplo.php`, `.py` and `.ps1`, and the
  web server handed out the `.py` and `.ps1` as plain text. The clients now read
  the key from environment variables (`JSONSQLDB_API_KEY`,
  `JSONSQLDB_HMAC_SECRET`, `JSONSQLDB_URL`), `configurar.php` prints the values
  instead of writing them into the files, and `api/.htaccess`,
  `api/web.config` and `nginx/jsonsqldb.conf` refuse to serve
  `cliente_ejemplo.*`.
- **The API could be locked for everyone without a key.** 30 requests with an
  invented API key, from anywhere, tripped a global switch that closed the API
  to every client for up to 24 hours. The global switch is gone: authentication
  failures now block only the IP they come from (`RATE_LIMIT_FALLOS_IP`, 10 by
  default), checked before anything else. `RATE_LIMIT_GLOBAL_MAX` is no longer
  read. Going over the request limit no longer counts as an authentication
  failure (a well-signed request over its quota would otherwise get the IP
  blocked for the whole window).
- **Rejected requests could fill the disk.** The request log stored the `db`
  field of a rejected request whole (up to 200 KB) and had no limit per day. The
  field is now cut to 64 characters and the error to 1,000, and once a day has
  20 full log files, requests rejected before their key is known are no longer
  logged.
- **A CSV could carry spreadsheet formulas.** A text cell starting with `=`,
  `+`, `-` or `@`, written by any application with write access, was exported
  as is, and Excel runs it as a formula when the file is opened. Such cells now
  get an apostrophe in front, which Excel does not show; numbers are not
  touched.
- **The nginx rule for hidden files missed those at the root** of the
  installation (`/jsonsqldb/.env`, `/jsonsqldb/.git/config`): it needed a folder
  before the dot; the test checks the rule's expression against
  those URLs.
- **Behind a TLS proxy, the panel's session cookie went out without `Secure`**:
  it looked only at `$_SERVER['HTTPS']`, while the HTTPS check already trusted
  the proxy's header. It now uses the same check.
- **Failed sign-in attempts to the panel could be lost** when they arrived at
  the same time: the counter was read, increased and saved without a lock, so
  two attempts could count as one. It now goes under an exclusive lock
  (`Store::actualizar()`), as the API's state already did; eight processes
  adding 25 failures each now count exactly 200.
- **Signing out was a link (GET)**: any page could sign a user out with an
  image. It is now a POST with the CSRF token.
- **`SECURITY.md` said the supported version was 1.x**; it is the latest 2.x,
  and it now lists the two advisories above.
- The API's `salirConError()` declared the `never` return type, which only
  PHP 8.1 understands: on 8.0 it worked by accident, read as a class name. It is
  `void` now.
- The zip handed out for 2.7.2 carried a request log from the author's own test
  runs (test databases, no user data): it never reached the repository, which
  ignores `logs/`. CI now fails if a file under `data/`, `logs/` or
  `jsonsqldbadmin/datos/` other than their protection files is ever tracked.
 

### Added

- **Trigger bodies accept `IF … THEN … ELSEIF … ELSE … END IF` and
  `SET NEW.column = …`** (in `BEFORE INSERT` / `BEFORE UPDATE`), as in MySQL
  and PostgreSQL. Several assignments in one `SET` go left to right, each one
  seeing the previous ones, and the value is converted to the column's type;
  the changed row goes through `NOT NULL`, unique and foreign-key checks again
  (a last review found that a `SET NEW.col = NULL` slipped past `NOT NULL`).
  Triggers stored by earlier versions are read as before.
- **`GROUP_CONCAT(x [, sep] ORDER BY …)`** orders the values inside each group,
  as MySQL and SQLite 3.44; with `DISTINCT` too. Same results as SQLite
  (`tests/f2_select.php`).
- **Views and triggers of a MySQL / MariaDB dump are imported**, translated,
  instead of being skipped. The importer now reads what mysqldump writes
  around them — `DELIMITER ;;` and the `/*!50003 … */` comments — and
  `TraductorRutinas` rewrites MySQL's functions and syntax (`IF()`,
  `CONCAT_WS`, `x + interval 1 day`, `to_days()`, `DATE_FORMAT`, `YEAR()`,
  `LOCATE`, `LEFT`/`RIGHT`, `GREATEST`, `FLOOR`/`CEIL`, `TRUNCATE`, `MOD`,
  `GROUP_CONCAT … SEPARATOR`, joins in parentheses…) and trigger bodies
  (`IF/ELSEIF/ELSE`, `SET NEW`, `SIGNAL`, `INSERT … SET`). Triggers are created
  at the end, after the data. A view or trigger the engine does not accept, or
  that uses something with no equivalent here (local variables, loops), is
  skipped and named in the summary; the rest goes on.
  `tests/f17_rutinas_importadas.php` (new) creates in MySQL a database with 7
  views and 7 triggers written the MySQL way, dumps it with mysqldump, imports
  it, runs the same ten writes there and here — some rejected by a trigger —
  and checks that every table and every view ends up the same.
- **Views and triggers of PostgreSQL and SQL Server dumps are imported too**,
  translated. PostgreSQL: casts, intervals, `IS DISTINCT FROM`, `ANY (ARRAY…)`,
  `~~` (its `LIKE`, case-sensitive, so a literal pattern becomes a `REGEXP`),
  `to_char`, `date_trunc`, `EXTRACT`, `string_agg`; the trigger's plpgsql
  function goes with it, and a trigger for several events becomes one per
  event with `TG_OP` set. SQL Server: `TOP`, `ISNULL`, `LEN`, `IIF`, `DATEADD`,
  `DATEDIFF`, `FORMAT`, `+` as concatenation, `STRING_AGG … WITHIN GROUP`; its
  triggers, which run once per statement over `inserted` and `deleted`, are
  rewritten to run per row (`JOIN inserted` → `WHERE … NEW.col`,
  `IF EXISTS (SELECT … FROM inserted …)`, `IF UPDATE(col)`, variables loaded
  from `inserted`, `RAISERROR`/`THROW`/`ROLLBACK`), and one that updates its
  own row becomes a `BEFORE` trigger with `SET NEW`, because here it would fire
  itself again. `tests/f17_rutinas_importadas.php` checks PostgreSQL with
  pg_dump as it does MySQL (8 views, 8 triggers), and an SSMS-style script
  with the same rules against the result already checked in MySQL (there is no
  SQL Server here).
- **Microsoft Access, both ways, in Access's own SQL.** The panel offers
  `access-to-jsonsqldb.ps1`, a PowerShell script (Windows; right-click → *Run
  with PowerShell*) that asks what to do. *Dump an Access database to SQL* reads
  the `.mdb`/`.accdb` read-only through OLEDB and writes the tables, keys,
  indexes, relationships, data and saved select queries. *Load an SQL file into
  Access* runs an `.access.sql` file statement by statement through DAO into a
  new or existing database. An `.mdb` needs nothing installed: Windows has the
  Jet engine for 32-bit programs, and from the usual 64-bit PowerShell the
  script opens itself again in the 32-bit one. An `.accdb` needs the Access
  Database Engine; without it the script says so, explains the 32/64-bit match
  and offers the download page.
  Both that dump and the panel's new *SQL: Microsoft Access* export are written
  in Access SQL in its usual syntax (ANSI-89), so each statement can also be
  pasted into a query's SQL view: keys with `CONSTRAINT` and a name, `CURRENCY`
  or `DOUBLE` instead of `DECIMAL`, one `INSERT` per row, a line break inside a
  text as `Chr(13) & Chr(10)`, and what that syntax cannot write — defaults
  and cascading relationships — as `-- [table].[column] DEFAULT …` and
  `-- [relationship] ON DELETE CASCADE` lines for doing it by hand. Saved
  queries go as `CREATE VIEW`, which Access only runs in ANSI-92 mode: by hand,
  what follows `AS` is pasted into a new query; the script's loader creates them
  as saved queries through DAO. The importer reads all of it back (and
  mdbtools' `access` output): the default and cascade lines are applied, and the
  queries are
  translated — double-quoted text, `&`, `IIf`, `Nz`, `Mid`, `InStr`, `Format`,
  `DateAdd`, `DateDiff`, `CCur`…, `Like` with `*`, `?` and `#`, `Table!Field`,
  joins in parentheses, `TOP`. Access's limits are documented in
  docs/05-admin.md and shown in the panel.
  `tests/f18_access.php` (new) checks the script's syntax with PowerShell, that
  its own functions write exactly the test dump, that both files keep to ANSI-89,
  that the script splits them into the statements Access runs one at a time,
  imports the dump (4 tables, 8 queries checked against what Access returns),
  and exports to Access and imports it back with the same keys, cascades,
  defaults, data and views. Reading or writing a real `.mdb` needs Windows and
  could not be run here.
- **The panel's CSS and JavaScript carry their date in the address**
  (`panel.js?v=…`): after an update the browser fetches the new files instead of
  keeping the old ones, which could leave a new button doing nothing.
- **The dump guide is in the panel**: *How to make the dump of each database*,
  in the import box, opens a window with one tab per engine (commands and
  steps for SQLite, MySQL / MariaDB, PostgreSQL, SQL Server and Access, and
  Access's limitations) instead of a link to GitHub. The import box also says
  now that importing is all or nothing when the panel reaches the database's
  folder.
- **Views and triggers are exported to MySQL / MariaDB, PostgreSQL, SQL Server
  and Access**, translated, instead of being left commented out. The view or
  trigger is analysed with the engine's own parser and written again in the
  target's SQL (`GeneradorSql`): functions, quoting, `LIMIT`, conditions used
  as values, and the differences that would change a result — `LIKE` without
  case, division that does not truncate and gives `NULL` by zero, concatenation
  with `NULL`, `AVG` of integers in SQL Server, and text ordered as here (no
  case, no accents, `ñ` after `n`). MySQL triggers use `FOR EACH ROW` with
  `IF` and `SIGNAL`, and one that updates its own row becomes a `BEFORE` with
  `SET NEW` (MySQL forbids a trigger to touch its own table). PostgreSQL gets a
  plpgsql function and the trigger that calls it. SQL Server walks the rows one
  by one with a cursor, and a `BEFORE` becomes an `INSTEAD OF` that does the
  write at the end. Access has no triggers, so they stay commented with that
  reason; what a target cannot express at all (`GROUP_CONCAT` or `OFFSET` in
  Access) is commented with its reason and listed at the top of the dump.
  `tests/f16_vistas_triggers.php` (new) loads 11 views and 7 triggers into
  MariaDB and PostgreSQL, runs the same nine writes there and here — three of
  them rejected by a trigger — and checks that every table and every view ends
  up the same. SQL Server could not be run here; its output is checked for
  shape.
- **A per-API-key request limit** (`RATE_LIMIT_POR_CLAVE`, off by default):
  requests of one key from any IP, so that a leaked key or a runaway
  application cannot use up everything. Suggested by one of the reviews.
- **SQL Server scripts saved as «Unicode»** — UTF-16, what Management Studio
  proposes — are converted to UTF-8 on import, in pieces of 1 MB without
  splitting a character, and a UTF-8 byte-order mark is skipped.
- **A guide to making a dump of each database** (SQLite, MySQL / MariaDB,
  PostgreSQL and SQL Server) for the panel's import, in `docs/05-admin.md`, with
  a link from the import box of the panel.

### Not changed, on purpose

- Folders are still created as `0775` (one review suggested `0750`): on shared
  hosting PHP and the FTP user often need the group to write. SECURITY.md now
  says how to tighten them where PHP runs as its own user.
- One report listed missing CSRF protection, path traversal, non-atomic
  writes, no rate limiting and plain-text passwords; all of them were already in
  place and tested, and nothing was changed for it.

### Documentation

- `README.md`, `NOTICE`, `AUTHORS`, `CONTRIBUTING.md` and `composer.json` no
  longer name a particular AI model: the implementation was produced with
  several AI models, under the author's direction and review.
- `docs/02-queries.md` explains how to add and subtract time with date
  modifiers.
- `docs/05-admin.md` opens with a table of what is new in the panel in 2.7.1
  and 2.7.2 and where each thing is described, has a section on languages, and
  its list of files and of tests includes the translator, the languages and the
  new tests. The README's section on the panel lists the imports and exports
  with other engines, the Configuration page and the two languages.


## [2.7.2] - 2026-09-30

Evidence and a way out, after a second external review: random queries
compared with SQLite on every CI run, an SQL dump that loads unchanged into
SQLite, a written compatibility policy, and a check that the data folder cannot
be downloaded. Nothing changes in the on-disk format.

### Changed

- **`AVG` always returns a decimal.** With PHP's division, the average of −5 and
  −15 came out as the integer −10 and that of 1 and 2 as the decimal 1.5: the
  type depended on the data, which a client with strict types would notice.
  SQLite and MySQL always return a decimal. Checked in `tests/f2_select.php`.
- **The panel's SQL dump loads unchanged into SQLite**, as well as into
  jsonSQLDB: `sqlite3 base.db < base.sql`. Unique and foreign keys now go
  inside each `CREATE TABLE`, which is the only place SQLite accepts them, with
  the tables in the order of their foreign keys and the rows of a table that
  refers to itself written parents first. Only a foreign key in a cycle between
  tables, or on a row that refers to itself, still goes at the end with
  `ALTER TABLE`, which SQLite does not accept, and the dump says so. Views and
  non-unique indexes are now included in the dump; both were missing, so
  restoring a dump lost them. `tests/f5_admin.php` exports a
  database with a self-referencing table stored child before parent, a
  composite unique key, triggers and a view, and loads the dump into SQLite and
  into a new jsonSQLDB database; without the parents-first order the check
  fails.

### Added

- **SQL dump for MySQL and MariaDB**, next to the one for jsonSQLDB and SQLite,
  from the export buttons of each database: `mysql nombre_base <
  base.mysql.sql`. Integers as `BIGINT`, decimals with the digits the data
  need, dates as `DATETIME(3)`, text in `utf8mb4_bin` (so unique keys still
  tell capitals apart, as here), text columns in keys as `VARCHAR` long enough
  for their data, backslashes escaped, `InnoDB` and strict mode. Views and
  triggers go commented out, because their SQL would not mean the same in
  MySQL. Loaded and compared in `tests/f14_volcados.php` (new), against MySQL 8
  in CI; writing it showed that without strict mode MariaDB clamped a large
  decimal to the column's maximum without an error, which is why the dump sets
  it.
- **The panel speaks Spanish and English.** A language selector (ES/EN) sits
  in the top bar, on the sign-in page and in the setup wizard. By default the
  panel follows the browser: the first language of its `Accept-Language` list
  that the panel has, and English if there is none. A language chosen with the
  selector is saved with the panel user, so it follows them to any browser.
  Texts are written in Spanish inside `t()` in the code and translated in
  `jsonsqldbadmin/idiomas/en.php` (571 texts), with whole sentences — markup
  and values included — translated as one, not word by word. Messages that come
  from the engine stay in Spanish. SQL dumps carry a fixed
  `-- jsonsqldb-dialecto:` line so the importer recognises them whatever the
  language of their header. `tests/f15_idiomas.php` (new) checks that every
  text of the panel has its translation and that none is left over, that each
  translation keeps its `{placeholders}` and tags, and the choice of language
  from the browser's list; `tests/f11_asistente.php` checks the panel in
  English with the server running, the selector saving the language in the
  user and following them to another session, and eleven pages in English
  without Spanish left in them.
- **PostgreSQL and SQL Server, both ways.** The dump of a database can be
  exported for PostgreSQL (`psql -v ON_ERROR_STOP=1 -f base.pg.sql`: one
  transaction, `IDENTITY` columns with their sequence moved past the last id,
  `TIMESTAMP(3)`, `NUMERIC` with the digits the data need) and for SQL Server
  (`sqlcmd -i base.sqlserver.sql` or Management Studio: one transaction,
  `[names]`, `N'…'`, `IDENTITY` with `IDENTITY_INSERT`, `NVARCHAR` with a binary
  collation so it compares as here, `DATETIME2(3)`, a single-column unique key
  as a filtered unique index because SQL Server counts `NULL` as a value, no
  `ON DELETE`/`ON UPDATE` on a foreign key from a table to itself because SQL
  Server rejects them, and `RESTRICT` as `NO ACTION`). And dumps from both can
  be imported: `pg_dump` in plain format (its `COPY … FROM stdin` data, the
  primary key and the `IDENTITY`/`serial` declared after the data, `t`/`f`
  booleans, `bytea`, `$$…$$` bodies, `::casts`, schema names) and the script of
  Management Studio's "Generate Scripts" (`GO`, `[dbo].[…]`, `N'…'`,
  `CAST(N'…' AS DateTime)`, `IDENTITY`, defaults added with `ALTER TABLE … ADD
  DEFAULT … FOR`, `WITH (…) ON [PRIMARY]`, `SET DATEFORMAT`, and statements
  without semicolons). Both read the file twice: the first pass collects what
  is declared outside `CREATE TABLE`. Names that are not valid here
  (`Order Details`) become valid (`Order_Details`), and binary data that is
  not text is kept as its hexadecimal `\x…` instead of stopping the import;
  both are listed in the summary, as are dates with more precision than the
  millisecond. Checked in `tests/f14_volcados.php` (now 21 checks with every
  server): the PostgreSQL dump loaded into PostgreSQL 16 here and in CI; a real
  `pg_dump` imported, and in CI one made by the runner's PostgreSQL; a script
  in the shape of Management Studio's imported; both exports imported back;
  and Microsoft's **Northwind** sample script (`instnwnd.sql`, downloaded in CI)
  imported whole — 91 customers, 830 orders, 2,155 order lines, its `mdy`
  dates and its table with a space in the name. **What is not verified:** no
  SQL Server was available to load the SQL Server dump into; it was checked
  with sqlglot's T-SQL parser (all 129 statements parse) and by importing it
  back, not by SQL Server itself.
- **Import of SQLite and MySQL / MariaDB dumps** in the panel, next to the
  panel's own: one made with `sqlite3 base.db .dump` or with `mysqldump` /
  `mariadb-dump`, detected from its first lines or chosen by hand. Each
  statement goes through a translator (`jsonsqldbadmin/lib/Traductor.php`,
  new) that turns the other dialect into jsonSQLDB's: names, MySQL's backslash
  escapes, hexadecimal and bit literals, SQLite's `char(10)`, MySQL types,
  `KEY`s inside `CREATE TABLE` as indexes, unique indexes as `UNIQUE`
  constraints, and foreign keys at the end, because dumps create tables in any
  order. Session statements, `sqlite_sequence` and mysqldump's `/*! … */`
  blocks are skipped. What has no equivalent here — `CHECK`, computed
  defaults, `ON UPDATE`, `ENUM` values, binary data, MySQL views and
  triggers — is dropped and listed in the summary. `tests/f14_volcados.php`
  imports a real `sqlite3 .dump` and a real `mariadb-dump` (in
  `tests/volcados/`) and compares with the source data; in CI it also creates
  the source in MySQL 8 and imports what its `mysqldump` writes. Writing it
  found that the statement splitter took `BEGIN TRANSACTION`, which SQLite
  dumps start with, for the start of a trigger body and swallowed the whole
  file; `BEGIN` now opens a block only inside a `CREATE`. And the splitter now
  understands MySQL's `\'` inside strings and backtick names.
- **The panel checks whether the data folder can be downloaded.** It requests
  `data/web.config`, a file that is always there, over HTTP: if the server
  hands it out, anyone can download the databases — which is what happens with
  nginx when its rules were not added, or with Apache when it ignores
  `.htaccess`. The setup wizard lists the result among its checks, and the
  Configuration page shows it, in red when the folder is exposed. It cannot be
  checked from PHP's built-in server, which says so.
- **A compatibility policy**, in `docs/01-core.md`: the on-disk format, the SQL,
  the API protocol and the configuration stay compatible for the whole 2.x
  series; an incompatible change would be 3.0, announced a version in advance
  and with a migration tool.
- **`tests/f13_fuzz_contra_sqlite.php`**, written during an external review:
  it generates random queries — filters with `NULL`s, `IN`, `BETWEEN`, `LIKE`,
  nested `AND`/`OR`/`NOT`, aggregates, `GROUP BY`/`HAVING`, expressions,
  joins, `IN`/`NOT IN` subqueries — runs each in jsonSQLDB and in SQLite on the
  same data and compares; with `ORDER BY` it compares the order too. Every
  query is reproducible from its seed and number (`--semilla=… --solo=…`). CI
  runs 3,000 with a fixed seed, with and without indexes; run it by hand with
  `--n=50000` before a release. A query the engine rejects and SQLite accepts
  counts as a failure. Verified here: 16,000 queries over four seeds, with and
  without indexes, no divergence; changing `>` to `>=` in the engine's fast
  comparison path makes it fail. It leaves out, on purpose, the documented
  differences (text ordering, `/`, `'5' = 5`, `ROUND` ties).

### Checked before release

- 24,000 more random queries against SQLite over four seeds, with and without
  indexes, 2,000 random writes against the in-memory model with parts of 5 to
  13 rows and with APCu, and a third hand-written set of 44 queries against
  SQLite (`RIGHT` and `FULL JOIN`, `EXCEPT`/`INTERSECT`, `LIKE … ESCAPE`,
  integer overflow, empty groups, `HAVING` on an alias): no divergence. The only
  difference found is that the engine does not accept row values such as
  `(a, b) IN (SELECT …)`; it says so with a syntax error, and it is now in the
  list of what is not supported, next to `CHECK`.
- The SQL dump orders the rows of a self-referencing table without recursion,
  so a long chain stored in reverse (checked with 5,000 rows, loaded into
  SQLite with foreign keys on) cannot exhaust PHP's stack; and the data-folder
  check says it cannot tell, instead of reporting "protected", when the API or
  the engine is not where a normal installation puts them.

### Documentation

- The README now opens with what the project gives and the evidence behind it —
  comparison with SQLite, crash and concurrency tests, the way out to SQLite,
  the compatibility policy — and then says when to use something else and the
  limits, instead of opening with the limits. Nothing was removed: no
  transactions, one commit at a time per table and PHP's speed are all still
  said, now in their own section.
- `docs/02-queries.md` says that `AVG` returns a decimal.

## [2.7.1] - 2026-09-29

Faster writes and five fixes, three of them security fixes found by an external
review of 2.7.0. Nothing changes in how the engine is used: same SQL, same
on-disk format, same configuration. Update from 2.7.0 by replacing the files;
there is nothing to migrate.

### Changed

- **An `INSERT` or `UPDATE` no longer decodes and re-encodes the part it
  touches.** An `INSERT` appends its rows to the text of the last part, and an
  `UPDATE` replaces the lines of the rows it changes; the row offsets are
  recomputed and the rest of the file is copied as it is. The result is the
  same file byte for byte (`tests/f3_escrituras.php` rewrites a part with
  quotes, backslashes, newlines, accents, emoji, `NULL`s and decimals through
  both paths and compares them; it fails if the splice is off by one byte). A
  part that is not in the expected shape — from before 2.7, or edited by hand
  — is written the old way.
- **Written parts are not copied into the cache.** Serialising a part and
  writing the copy cost more than it saved the first reader, which decodes it
  once and caches it then.
- **The old rows of an `UPDATE` are read by line**, not by decoding their part.
- **A key in a text index is searched for in the text of the pieces**, without
  decoding them: the uniqueness check of an `INSERT` into a table with a
  `UNIQUE` text column (an email) used to build a thousand entries per piece
  to look up one, and a lookup by that column did the same. The key is
  searched as `"key":` preceded by `{` or `,` after checking the header of
  each piece; many rows in one statement switch to decoding the pieces once.
- A part is written in 64 KB blocks instead of one `fwrite` per row, and the
  numeric range of a piece of an integer key is computed with one regular
  expression over all its keys.

Measured with `php tests/benchmark.php` on 20,000 rows (one core, on-disk
cache), mean of three runs of each version, 2.7.0 against 2.7.1: `INSERT` 10.0 →
7.2 ms and 9.2 → 6.4 MB (two runs at 6.0–6.3 ms and one at 9.3), `UPDATE` by
key 13.0 → 6.7 ms and 9.9 → 5.7 MB, `DELETE` by key 17.1 → 11.1 ms, bulk load
−9 %, lookup by a `UNIQUE` text column 1.97 → 0.57 ms. With the kernel made to
delay each `fsync` by 3 ms, as a shared host's disk does, and a `UNIQUE` text
column in the table, 2.6.1 against 2.7.1: `INSERT` 64 → 42 ms, `UPDATE` 65 →
37 ms, `DELETE` 86 → 41 ms. On 100,000 rows, one run each, 2.6.1 against
2.7.1: `INSERT` 22.5 → 13.6 ms, `UPDATE` 26.1 → 13.9 ms, `DELETE` 22.5 →
9.9 ms, lookup by a `UNIQUE` text column 8.6 → 1.9 ms. `DELETE` still decodes the parts after the
deleted row, because every row after it moves.

### Fixed

- **An API key with administration permission limited to some databases could
  create and drop any other database** with `CREATE DATABASE` or
  `DROP DATABASE` sent from one of its own. Those statements go to the global
  path, which did not look at the key's databases. A limited key can no
  longer create or drop databases at all. `tests/f4_api.php` checks it with a
  limited administration key; without the fix the check drops the other
  database.
- **A limited key saw every database in `SHOW DATABASES`** sent from one of its
  own databases. It now sees only its own.
- **`DROP DATABASE` did not wait for queries using that database**: it deleted
  the files under a running read. It now takes the database's exclusive lock
  first, through its turnstile, and deletes the lock files last (Windows does
  not delete an open file). `tests/f7_concurrencia.php` drops a database while
  another process holds a read open for a second; without the lock the reader
  crashes.
- **An `INTEGER` out of PHP's range was stored as another number**:
  `'9223372036854775808'` became the largest integer without a word. It is now
  an error, and so is a decimal like `1e19` for an `INTEGER` column.
- **The API's rate limit kept recording rejected requests**, so a flood of
  thousands of requests grew the state file that every request reads and
  rewrites. Past the limit nothing more is recorded; the failure log has a
  ceiling too.

### Added

- **The panel's configuration can be changed from its Configuration page**,
  which until now only showed it: connection, security and data screens. A
  new connection is tested before it is saved; an IP list without your own
  address, or requiring HTTPS while you are on HTTP, is refused so nobody
  locks themselves out; keys are never shown, and an empty key field keeps the
  current one; the audit trail records which settings changed, not their
  values. `tests/f11_asistente.php` checks saving and every refusal.

### Fixed — found by looking for bugs

- **Deleting a panel user, or changing their password, did not end their open
  sessions.** The session kept the user and role from the moment of logging in
  and was never checked again, and since it expires by inactivity, a session
  kept busy never expired: a deleted administrator kept administering. Every
  request now checks that the user still exists with the same password and
  takes the role from what is stored. Everyone is logged out once when
  upgrading, because older sessions lack the check. Two checks in
  `tests/f11_asistente.php` fail without the fix.
- **The panel failed with a `config.php` made by an earlier version**, which
  lacks the options added since: the new Configuration page stopped with
  "Undefined constant". The panel now loads `config.php` first and then the
  template, which defines only what is missing, with its default value. And a
  page that fails halfway is no longer sent with the error page embedded in
  it: the page is prepared whole, and discarded if something fails. Both
  checked in `tests/f11_asistente.php` with a `config.php` in the old shape.
- The Configuration page showed the engine's response time as 0.0 ms: it was
  timing an answer the sidebar had already obtained.
- **The ZIP export did not ask the engine for permission.** It reads the files
  directly, so a read-only panel user whose API key is limited to some
  databases could download any other one on the same host. It now asks the
  engine about the database with the user's credentials first.

- **Importing an SQL dump doubled the line breaks inside text values.** The
  statement splitter of the panel's SQL import passed every character of a
  line, the line break included, and then added another; inside a string that
  second break became part of the value. A text with line breaks exported and
  imported again came back with twice as many. `tests/f5_admin.php` exports and
  imports a value with `\n`, `\r\n`, `--` and `;` inside it.
- **The SQL dump and the CSV export rounded decimals to ten places**:
  `0.30000000000000004` came out as `0.3`. They now write the shortest decimal
  that gives back exactly the same number (the same test checks it).
- **A condition of the `WHERE` could be applied too early in a chain of joins
  with a `RIGHT` or `FULL JOIN` further on.** `a JOIN b … RIGHT JOIN c …
  WHERE a.x IS NULL OR a.x = 1` returned an extra row of `c`: removing rows of
  `a` before the join left a row of `c` without a match, the `RIGHT JOIN` filled
  it with `NULL`s, and the condition let it through. The `WHERE` is no longer
  applied early when any join of the chain is `RIGHT` or `FULL`. Introduced in
  2.7.0; `tests/f2_select.php` checks the case against a condition that cannot
  be applied early.
- An `INSERT` into a table whose last part was exactly full decoded and
  rewrote that part unchanged; it now leaves it alone and writes only the new
  part.
- The panel's SQL import refuses files that create or drop databases: it
  imports into one database, and a file should not be able to drop another.

### Fixed — found by comparing with SQLite

- **An `UPDATE` or `DELETE` with a subquery ran the subquery again for every
  row.** `UPDATE u SET x = x + 1 WHERE x > (SELECT AVG(x) FROM u)` on 5,000
  rows took 10.1 s, and `DELETE … WHERE id IN (SELECT …)` 2.8 s; now 48 ms and
  42 ms. A subquery that does not look at the row being written runs once per
  statement, and `IN (SELECT …)` looks values up in a set.
- **A subquery in an `UPDATE` or `DELETE` could not refer to the row being
  written**: `UPDATE u SET y = (SELECT b FROM t WHERE t.id = u.tid)` failed with
  "Columna desconocida". It works now, as it already did in a `SELECT`.
- **`ORDER BY 1` did not order**: a bare number was taken as a constant, and the
  rows came in table order without a word. It now orders by that column of the
  result, as SQLite and MySQL do, and a number beyond the columns is an error.
- **A `HAVING` could not use the aliases of the `SELECT`**: `SELECT d,
  COUNT(*) AS n … HAVING n > 3` failed with "Columna desconocida"; it works now.

### Tests

- **`tests/f12_contra_sqlite.php`** runs 139 queries and 16 writes on the same
  data in jsonSQLDB and in SQLite, with and without indexes, and demands the
  same results — filters with `NULL`s, `IN`, `LIKE`, arithmetic, functions,
  aggregates, `GROUP BY`/`HAVING`, `ORDER BY`/`LIMIT`, every kind of join,
  correlated and uncorrelated subqueries, `UNION`, and `UPDATE`/`DELETE`/
  `INSERT … SELECT` with subqueries. The documented differences (`7 / 2`,
  `'5' = 5`, `ROUND` on binary ties, collation in `ORDER BY`) are left out on
  purpose. It uses the `sqlite3` extension rather than PDO, which returns
  numbers as text before PHP 8.1, and skips itself without it. This is what
  found the four faults above.
- `tests/_azar_escrituras.php`, run by `tests/f3_escrituras.php`: three
  hundred random `INSERT`s, `UPDATE`s by key and by condition and `DELETE`s on
  a table with a primary key, a `UNIQUE` text column and an index, with parts
  of 7 and of 50 rows so that writes cross their edges all the time. Every
  twenty operations it compares the table with a model kept in memory, every
  index with a scan that cannot use it, and the text of every part with what a
  complete rewrite would produce.

### Documentation

- The concurrency table of the README has the row for writes by part, and
  `docs/03-writes.md` explains how an `INSERT` and an `UPDATE` edit the text of
  a part.

## [2.7.0] - 2026-09-29

The release that goes to the floor: for every kind of operation, what the
engine cannot go below while data stays in readable JSON, PHP interprets every
row and writes survive a power cut — measured, and written down in
`docs/01-core.md` §11 so the question "can it be faster?" has a fixed answer.
Prompted by a comparison against a document store that found key lookups the
weakest point of 2.6: 3.4 ms for one row by primary key, a loop of 5,000 of
them taking twenty seconds, and an `UPDATE` by condition taking four. Nothing
breaking; two keys added to files (`rangos` in `rev.json`, `offsets` in the
data parts), filled in as tables are written; the journal manifest is now a
single file.

### Added

- **Writes by part.** An `UPDATE` or `DELETE` that depends only on each row —
  no foreign keys or triggers on the table, nobody referencing it, no
  subqueries, and no primary key or `UNIQUE` column in the `SET` — does its work
  (reading, computing, writing and forcing the new files to disk) holding the
  table's shared lock, and takes the exclusive lock only to commit. At commit
  it checks that the parts and index pieces it rewrote are still as it read
  them; if another write went to the same part in between, it discards its
  files without having renamed anything and runs again with the whole table
  locked. The on-disk format does not change. Measured with one writer and two
  readers on a 20,000-row table, one core, `fsync` delayed 3 ms: 3,837 reads
  instead of 334, read latency p50 2.5 ms instead of 35.7; writes done 88
  instead of 166, because on one core the readers now share the processor with
  the writer. Between writers the gain is small (30–31 updates a second either
  way), since three of a write's four `fsync` calls have to stay inside the
  commit. `JSONSQLDB_ESCRITURA_POR_PARTES = false` turns it off.
  `tests/f7_concurrencia.php` gains three checks with real processes — four
  processes adding to the same row must not lose a single addition (without
  the commit check they lose three quarters), writers in different parts with a
  `DELETE` at the same time must leave every sum and index exact, and a
  `UNIQUE` column is never written by part.
- Temporary files of a write are only swept when the process that wrote them no
  longer exists (checked in `/proc`, or with `posix_kill`, or failing both
  after a minute). A write holding a table's lock used to sweep them all, which
  is no longer safe: a write by part waiting to commit has its files written.

- **jsonSQLDBadmin has a setup wizard.** While the panel has no `config.php`
  (or still has the template's `CHANGE_ME_` keys), the only thing it serves is
  a wizard: choose how the panel talks to the engine, whether to accept plain
  HTTP for local testing, and the administrator. **The connection is tested
  before anything is written** — a wrong folder, an API that does not answer or
  a rejected signature is reported on the same screen and no file is created.
  Then it writes `config.php` from `config.dist.php`, keeping every comment,
  with permissions `0600`. If the API of the same installation is not
  configured yet, the wizard can create its configuration with new random keys.
  Deleting `config.php` brings the wizard back for the connection only; users
  and audit trail are kept. Replaces the old text-only "the panel is not
  configured" error.

- **jsonSQLDBadmin can connect directly to the engine**, without the API:
  `ADMIN_CONEXION = 'directa'`, chosen in the wizard. Only when the panel and
  the data are on the same machine; no keys to configure, and no HTTP request
  per query. Measured on the PHP built-in server with five 500-row tables, per
  page: table list 4.3 → 2.4 ms, data browser 10.6 → 3.5 ms, structure
  15.3 → 3.0 ms. **The engine still applies each panel user's role**: a
  read-only user's statements are checked against the same list an API key
  with read permission gets, and refused before they run.
  `tests/f11_asistente.php` (new, 18 checks) walks the wizard like a user and
  then calls the engine the way the panel does, bypassing the pages, to prove
  it is the engine that refuses a read-only `DELETE`; it fails if that check is
  removed.

- **Import in the panel**: an SQL file (the panel's own dump or any list of
  statements) and a CSV into an existing table, from the page of each
  database. Both are read as a stream, go through the API or the direct
  connection like everything else, and send rows in batches of 200. The SQL
  splitter respects strings, quoted identifiers, comments and the `BEGIN … END`
  of triggers. No transactions, and it says so: on failure it reports how much
  went in and where it stopped. Before, the only import was the ZIP restore,
  which needs the panel and the engine on the same machine — and its error
  message told the user to use "the SQL dump", which the panel could produce
  but not load. Three checks in `tests/f5_admin.php`; the one for the dump fails
  if the splitter stops honouring `BEGIN … END`.
- **Configuration page** in the panel (administrators): connection, engine
  response time, paths, HTTPS, allowed IPs, versions, and how to change them.

### Changed

- **jsonSQLDBadmin has a new design**: sidebar with the
  databases and the tables of the current one (with a filter when there are
  many), top bar with the path, light/dark theme remembered per browser, page
  and table headers with tabs, the same login and setup screens. The icons are
  inline SVG drawn in the same stroke; **Bootstrap Icons is no longer bundled**
  (390 KB of CSS and fonts less). The link to `https://miguelenred.es/jsonsqldb`
  is at the bottom left of the sidebar.

- **Creating the first administrator needs a CSRF token and the password
  typed twice.** Before, the form accepted a bare POST: a page on another site
  could make the browser of someone on the same network as an unconfigured
  panel create the administrator with a password chosen by the attacker.

- The panel answers each `SHOW` once per request: the sidebar and the page
  asked for the same tables; any other statement clears it.

- **A numeric comparison or `BETWEEN` in the `WHERE` is decided inline.**
  `col <op> number` and `col [NOT] BETWEEN number AND number` compare numeric
  values with PHP's own `<=>` in the loop, which is exactly what
  `Valor::comparar()` does for two numbers, without the call per row; any
  other value takes the usual path. A range without index on 20,000 rows went
  from 19.5 ms to 12.0 ms. `tests/f2_select.php` runs thirteen more predicates
  over integer, decimal and text columns (with numbers stored as text and
  `NULL`s) through both paths and demands the same rows.

- **The engine keeps less in memory between statements**: row offsets are held
  packed, four bytes per row instead of a PHP array of integers, and at most
  four index pieces; data parts are never kept.

- **A `JOIN` behind a selective `WHERE` looks its rows up instead of hashing a
  table.** Two things: the conditions of the `WHERE` that only concern tables
  already joined are now applied *before* the join (for `INNER` and `LEFT`;
  a `RIGHT` or `FULL` join would change its result, so they are left alone);
  and if what remains on the left is small compared with the right-hand table
  — one row per 150 of the table, up to a thousand — and that table has an
  index on the columns the `ON` equates, each left row is looked up by key
  rather than building a hash of the whole table. `FROM orders o JOIN
  customers c ON c.id = o.customer_id WHERE o.id = ?` on 30,000 orders and
  20,000 customers: 20 ms and 25 MB before, 0.35 ms and 6 MB now; the same
  with `LEFT JOIN` over twenty orders, 60 ms and 23 MB before, 3 ms and 7 MB
  now. Above the threshold, or without a usable index, the hash join runs as
  before on whatever the `WHERE` left — the aggregated `JOIN` of the whole
  benchmark is unchanged. Equalities of the `ON` the index does not cover,
  and the rest of the condition, are checked on each candidate.
  `tests/f2_select.php` runs nine joins through both paths — the same query
  with the `ON` written so no index can be used — and demands identical rows.

- **The `AUTOINCREMENT` counter lives in `rev.json`.** It used to live in the
  structure file, so every `INSERT` rewrote and force-synced `meta.json` just
  to move a number; now it rides on a file the write touches anyway. One
  `fsync` less per `INSERT`: four per write (the part, `rev.json`, the
  manifest and the directory), five when the insert opens a new part. With
  the kernel made to delay each `fsync` by 3 ms, as a shared host's disk
  does, a one-row `INSERT` went from 50 ms in 2.6.1 to 28 ms. `meta.json`
  keeps whatever value it had, and the larger of the two wins on reading, so
  bases from earlier versions and hand-edited counters keep working.

- **Each data part records where every row's line starts.** `offsets` at the
  end of the part file, written along with the rows. A lookup that needs a
  few rows of a part reads their lines by byte offset — three small reads,
  about 30 µs — instead of decoding the thousand rows of the part. Combined
  with the index ranges below, a primary key lookup goes from 2.3 ms and 7 MB
  to 0.5 ms and 5 MB on 20,000 rows, and from 10.8 ms and 13 MB to 0.7 ms and
  5 MB on 100,000; `IN` of ten keys from 3.2 ms to 0.4 ms; five thousand
  lookups by key in one process from 9.9 s to 0.9 s. Parts from earlier
  versions have no offsets and are decoded whole until they are next written.

- **A range on a numeric indexed column reads only the parts that can hold it.**
  `BETWEEN`, `<`, `<=`, `>`, `>=` with numeric literals on the first column of
  an index, in the top-level `AND` chain of the `WHERE`, use the per-piece
  ranges to skip parts whose values cannot fall in the range. On a table that
  grows by appending, `WHERE id BETWEEN a AND b` reads one or two parts:
  2.4 ms on 20,000 rows and 2.6 ms on 100,000, where a scan takes 18 ms and
  92 ms. On a column whose values are spread at random it reads everything,
  as before. `tests/f8_indices.php` compares eleven range conditions against
  the same conditions written so the index cannot be used, after every move,
  delete and reinsert of ids across pieces.

- **Half the `fsync` calls per write.** The manifest is a single file,
  `.tx/<scope>.json`, in a folder that is created once and stays, instead of
  a folder per scope created and synced on every write; and the index pieces
  are written without `fsync`, listed in the manifest as regenerable: if a
  crash loses one, recovery carries on without it, lookups on that index scan
  the table, and the next write to the table rebuilds it (every piece's
  header and tail are checked before a write trusts it). A one-row `INSERT`
  into a table with three indexes went from ten `fsync` calls to five, which
  is the floor for keeping every data file durable on its own. On this
  machine an `fsync` costs 0.1 ms and the change is invisible; with the
  kernel made to delay each `fsync` by 3 ms, as a shared host's disk does,
  the `INSERT` went from 50 ms to 34 ms, and by 8 ms from 102 ms to 59 ms.
  `tests/f9_journal.php` checks that a lost index piece neither stops
  recovery nor changes a result, and is rebuilt by the next write. Manifests
  left by 2.5 and 2.6 in their folders are still recognised and applied.

- **Index pieces carry their numeric range.** For an index whose first
  column is numeric, `rev.json` now records the smallest and largest value in
  each piece (`"rangos": {"auto_id": [[1, 1000], [1001, 2000], ...]}`; `[]`
  for an empty piece, `null` for a text column). A lookup opens only the
  pieces whose range can hold the value, and a value outside every range is
  answered without opening any. With an auto-increment key each piece covers
  a stretch of ids, so a lookup by id reads one piece instead of all of them:
  from 2.3 ms to 0.6 ms on 20,000 rows and from 10.7 ms to 0.7 ms on 100,000;
  `IN` of ten keys from 2.8 ms to 1.9 ms. The uniqueness check of an `INSERT`
  benefits the same way: a new id is known to be unique without reading a
  piece. Numbers spread at random over the table gain nothing and lose
  nothing; text keys still read every piece. The range is recomputed from the
  piece's keys every time the piece is written, so a key moved by an `UPDATE`
  or a `DELETE` is never left outside. `tests/f8_indices.php` checks the
  recorded ranges and that lookups agree with a full scan after ids are moved
  across pieces, deleted and reinserted. Bases from 2.6 work as they are:
  lookups read every piece until each piece gets its range at its next write.

- **The last cache entries stay in the process.** Eight index pieces, sixteen
  offset lists and a few table structures — about a megabyte — are kept
  decoded across statements, keyed exactly as in the cache, revision included,
  so an entry made stale by another process is never used, and it is the
  first thing dropped when memory runs short. A single query in a request
  gains nothing; a loop of lookups stops decoding the same piece on every
  turn. Data parts are not kept: they are large, and lookups no longer need
  them.

- **The memory watchdog keeps a 2 MB block in reserve** on the memory PHP has
  requested from the system, not only on the memory in use: PHP requests
  memory in 2 MB blocks, and once the next block no longer fits under the
  limit any allocation that does not fit in the existing ones is the fatal
  error, however small. Found by `tests/f1_nucleo.php` with a 16 MB limit
  after the process kept a little more between queries.

- **An `UPDATE` or `DELETE` by an indexed condition that hits many rows
  decoded the same part once per row.** The rows of the candidate positions
  were fetched one by one, each through the cache, so an `UPDATE` of the
  2,000 rows of one city on 20,000 rows took 1.6–2.7 s. Positions are read
  in order and each part is decoded once: 120 ms for the same statement.
  This is the "update by condition" case a comparison against a document
  store found ten times slower than the competitor; it is now faster.

- The number of parts of a table is remembered for the duration of a lock:
  a write of many rows asked the file system for it once per row.

### Fixed

- **The writer-starvation check in `tests/f7_concurrencia.php` failed on the
  GitHub runner with PHP 8.0**: the writer kept 29 % of its rate alone next to
  three readers, and the check demanded 30 %. That margin was too tight for a
  shared runner. The check now demands 15 % and allows one retry; without the
  turnstile the writer keeps 5–7 %, so it still fails when the protection is
  removed (verified by removing it).
- **`?p=fila` crashed the panel.** The page was on the list of allowed pages
  but had no view behind it, so requesting it was a PHP fatal error. Removed.
- **The documentation said things that were not true**, found while reviewing
  it: that the panel requires cURL (it falls back to PHP's streams; only the
  tests need cURL), and that `ADMIN_EXIGIR_HTTPS` defaults to `false` (the
  template sets `true`).
- **What PHP 8.0 does not guarantee is now said plainly.** `fsync()` exists only
  from PHP 8.1 and there is no reliable pure-PHP substitute on 8.0, so on 8.0 a
  power cut can lose the last few seconds of writes, even though a killed or
  crashed process loses nothing. The documentation used to say only that 8.0
  "flushes PHP's buffer, which is as far as that version can go". The README,
  `docs/01-core.md`, the setup wizard and the panel's Configuration page now
  say what that means and recommend 8.1 or later; the README has a section of
  its own, "PHP 8.0 works, but 8.1 or later is recommended", with the reason in
  detail and a table of what is and is not lost on each version. PHP 8.0 stays
  supported and in CI. The code is unchanged.

### Measured against 2.6.1

`php tests/benchmark.php`, 20,000 customers and 30,000 orders, on-disk cache,
one core, PHP 8.3, mean of three runs of each version taken one after the
other (ms · peak MB):

| Operation | 2.6.1 | 2.7.0 |
|---|---|---|
| Lookup by primary key | 2.85 · 7.1 | 0.58 · 5.5 |
| `IN` of ten primary keys | 3.45 · 7.1 | 0.41 · 5.5 |
| Lookup by `UNIQUE` text column | 2.40 · 7.3 | 1.97 · 6.8 |
| Equality on an indexed column | 17.6 · 7.3 | 17.8 · 7.6 |
| Numeric range, no index | 19.5 · 5.7 | 12.0 · 6.3 |
| `BETWEEN` on the primary key | — | 1.89 · 6.3 |
| `LIKE` by prefix | 19.7 · 5.7 | 19.0 · 6.3 |
| `GROUP BY` with `SUM` | 24.4 · 5.7 | 25.7 · 6.3 |
| `ORDER BY … LIMIT 20` / whole table | 22.4 / 36.6 | 22.3 / 36.3 |
| `JOIN` aggregated by city | 134 · 21.5 | 134 · 22.1 |
| `JOIN` of one order with its customer | — | 0.46 · 5.6 |
| Subquery with `IN` | 67 · 9.0 | 72 · 9.3 (equal in a separate measurement: 56–60 both) |
| `INSERT` / `UPDATE` / `DELETE` one row | 11.0 / 13.6 / 18.3 | 10.0 / 13.0 / 17.1 |

On 100,000 rows (one run each): primary key 9.3 → 0.75 ms, ten keys by `IN`
9.1 → 0.56 ms, range without index 92 → 58 ms, lookup by `UNIQUE` text column
8.9 → 9.1 ms (unchanged: every piece of the index is read), everything else
within ±7 %. Differences under 10 % are within what two runs of the same
version differ by on this machine. Memory is about 0.6 MB higher outside the
lookups: 0.24 MB of it is the engine's larger code, which OPcache keeps out of
each request, and the rest is what the process keeps between statements.

### Not changed

- Parsing SQL was measured at 0.013 ms per statement, 0.6 % of a key lookup;
  there was nothing to gain there.
- The authorship note now says what is true today: the project is **directed
  by Miguel Sanchez** and **assisted by artificial intelligence** — several
  models, not one. README, AUTHORS, NOTICE and composer.json.
- Lock-free reads (read, then verify nothing changed, repeat if it did) were
  built and measured in 2.6.1 and discarded; see that entry. Concurrent writes
  to the same table stay serialised: the last part and the index pieces are
  files rewritten whole, and two processes cannot rewrite one at once.

## [2.6.1] - 2026-09-12

A locking fix found by measuring, plus documentation and one setting prompted
by a review of 2.6.0 that measured what the release notes had not said: the
on-disk cache costs space and files.

### Fixed

- **A writer could starve behind readers that never stop.** `flock` grants an
  exclusive lock only when no shared lock is held; with readers overlapping
  continuously that moment may never come. Measured: three processes reading
  one table without pause let a fourth insert 49 rows in two seconds (690
  alone); four readers let it insert two rows in eight seconds. Every lock is
  now taken through a turnstile — a second `flock` file (`.turno`,
  `.<table>.turno`) crossed before asking for the lock and released as soon
  as it is held — so a waiting writer stops new readers, the readers already
  inside finish, and the writer gets in: 305 rows in the same test, 44 % of
  its rate alone. Two extra system calls per lock (6 µs); lock order unchanged,
  so deadlock is still impossible. `tests/f7_concurrencia.php` now checks it
  against the writer's own rate on the machine. Reads without any lock — read,
  then verify that nothing changed and repeat if it did — were built and
  measured first and discarded: they fix the starvation too, but a read that
  overlaps a commit is thrown away and repeated, and long scans got noticeably
  slower under ten writes per second for a gain readers could not feel.

### Added

- `tests/benchmark_concurrencia.php`: readers and writers on the same table in
  real processes; prints writes per second and read latency percentiles.

- **`JSONSQLDB_CACHE_ACTIVA` accepts `'apcu'`**: cache in shared memory only,
  never in `.cache/` on disk. Without APCu the engine keeps a serialised copy
  of every part, index piece and query result, which takes about twice the
  space of the data and index files and as many files as they have — on a
  100,000-row benchmark, 41 MB and 707 files next to 29 MB and 709 files of
  data and indexes. Until now the only way to avoid it was to disable the
  cache altogether.
- **`php tests/benchmark.php` reports data, indexes and cache separately**,
  in megabytes and files, instead of a single figure that counted the `.json`
  files and left the cache out.
- **`docs/01-core.md` §9, "Files, space and how to tune them"**: where every
  file comes from, with the counts and sizes measured on the benchmark; which
  settings trade files for speed and by how much (the cache setting, the part
  size, the number of indexes), with the effect of 5,000 rows per part
  measured operation by operation; why compressing the cache does not pay;
  and how to measure it on your own data. A short version is in the README.

### Changed

- Nothing in the engine beyond reading the new value of the constant; the
  default is unchanged.

## [2.6.0] - 2026-09-11

Speed and memory release, reads and writes. Nothing breaking in SQL or in the
API; no data conversion. One configuration constant added. One bug fixed that
silently disabled the cache in CLI scripts.

### Fixed

- **With APCu installed but disabled for the command line** (`apc.enable_cli=0`,
  the default), the engine believed APCu was available and used it, so
  `apcu_store` did nothing and `apcu_fetch` never hit: scripts and cron jobs ran
  with **no cache at all**, decoding every part on every read. Web requests
  (Apache, PHP-FPM) were not affected. Detection now uses `apcu_enabled()`,
  which answers for the SAPI in use.
- **A table dropped and recreated under the same name could be served the old
  table's cache** from APCu, where entries cannot be deleted by table: the new
  table starts again at revision 1, and its cache keys collided with the old
  one's. `rev.json` now carries `creada`, a random number fixed at the table's
  first write, and every cache key includes it. Added on the first write of a
  table from an earlier version; its cache entries are regenerated once.

### Changed

- **Single-table queries use the rows as they come out of the cache**, without
  copying them with the alias in front of each column name. The copy was
  needed only to keep two tables' columns apart in a `JOIN`, and it cost a
  full second copy of the table in time and memory. Now only a `JOIN` copies,
  and it copies only the columns the query names.

- **`WHERE` conditions are compiled into PHP closures** once per query:
  comparisons, `AND`/`OR`/`NOT`, `BETWEEN`, `IS NULL`, `LIKE` with a literal
  pattern, concatenation and arithmetic. The tree-walking evaluator runs only
  for what cannot be compiled (functions, subqueries, columns of an outer
  query), with exactly the same three-valued results.
  `tests/f2_select.php` runs eighteen predicates through both paths and demands
  identical rows.

- **Aggregates are accumulated as the rows go by, not collected.** `GROUP BY`,
  `COUNT`, `SUM`, `AVG`, `MIN` and `MAX` keep one accumulator per group and the
  first row of the group; the rows themselves are never kept. `DISTINCT` inside
  an aggregate and `GROUP_CONCAT` keep the values of that column only.
  Memory is proportional to the number of groups: a `GROUP BY` over 100,000
  rows went from 58 MB to 6 MB. The same aggregate written twice shares an
  accumulator; aggregates in `HAVING` and `ORDER BY` are accumulated with the
  rest. `tests/f2_select.php` checks every aggregate, with and without
  `DISTINCT` and with `NULL`s, against the same figures computed by hand from
  the rows.

- **A `JOIN` streams.** Joined rows flow into the `WHERE` and the grouping as
  they are produced instead of being materialised first; each side is loaded
  with only the columns the query names; and the hash index stores a single
  position as an integer. The aggregated `JOIN` of the benchmark went from
  43 MB to 22 MB on 20,000 customers and from 193 MB to 85 MB on 100,000.

- **`ORDER BY … LIMIT n` keeps only the n rows in the lead** while it reads:
  memory no longer depends on the size of the table (69 MB to 6 MB on 100,000
  rows). A full `ORDER BY` sorts with `asort` (one numeric key) or
  `array_multisort` (several keys, or text) when every key is all numbers or
  all text — with the collation key computed once per row instead of once per
  comparison, which is what made sorting 20,000 rows by name take 444 ms — and
  falls back to the comparator otherwise. Same result as before in every
  case: `NULL`s first (last with `DESC`), text by collation key then byte by
  byte, ties by position. `tests/f2_select.php` compares every path against
  the reference comparator on 600 rows with ties, `NULL`s, accents and mixed
  case, and every `LIMIT`/`OFFSET` against cutting the whole ordered result.

- **Output columns that are plain table columns are copied directly**, and
  when the `ORDER BY` uses table columns the sort happens before the
  projection: with `LIMIT`, only the rows that go out are built.

- **A repeated `SELECT` on unchanged data is served from a result cache.** The
  key is the SQL text, the bound parameters and the revision (and `creada`) of
  every table the query touches, views included, so any write to one of them
  makes the cached result stop matching; on disk the file is deleted at that
  moment, in APCu it expires after an hour. Queries that depend on the moment
  (`RANDOM()`, `DATE('now')` and the date functions with no argument) are not
  cached, nor are results over `JSONSQLDB_CACHE_RESULTADOS` rows (5,000 by
  default; `0` turns the result cache off). Queries run inside a trigger never
  use it. `tests/benchmark.php` switches it off for the run and measures it
  separately at the end: the aggregated `JOIN`, 111 ms the first time, takes
  0.2 ms the second.

- **Indexes are stored in one piece per part of the table**:
  `<table>.idx.<name>.json` for part 1, `<table>.idx.<name>.part2.json` for
  part 2 and so on, each with the keys of the rows of that part (positions
  counted from the start of the table, as before). `rev.json` records one
  revision per piece (`"indexes": {"auto_id": [3, 3, 7]}`). A write rewrites
  only the pieces of the parts it touched — appending a row to a table of a
  hundred parts rewrites one piece of each index, not the whole index — and
  checks the header of every other piece so a damaged or hand-edited file is
  rebuilt at the next write. A lookup reads every piece, since the key can be
  in any of them; the total is what it was. A one-row `INSERT` went from
  20 ms and 13 MB to 10 ms and 9 MB on 20,000 rows, and from 95 ms and 43 MB to
  30 ms and 22 MB on 100,000; a `DELETE` by key from 153 ms to 53 ms on
  100,000. Indexes written before 2.6 (one file, one revision) are read as
  they are and split on the next write of the table. Uniqueness and foreign
  key checks in the writer ask the pieces directly instead of loading a merged
  key map. `tests/f8_indices.php`, `f10_indices_incrementales.php` and
  `f6_cortes.php` were adapted to the pieces; `f10` also checks that every
  piece is numbered right and holds only positions of its part.

- **Sort keys are kept by column, not by row**: one list per `ORDER BY`
  expression instead of one small array per row, which cost ten times the
  value it held.

### Added

- `litespeed/README.md`: LiteSpeed Enterprise works like Apache (it reads the
  bundled `.htaccess`); OpenLiteSpeed applies `.htaccess` only for rewrite
  rules and only at startup, so the access rules go in the virtual host — the
  file has them, plus the checks to run after deploying on either edition.
  The `nginx/` and `litespeed/` folders are now blocked from the browser by
  the root `.htaccess`, `web.config` and `nginx/jsonsqldb.conf` like the other
  internal folders.
- `JSONSQLDB_CACHE_RESULTADOS` in `config.php`: maximum rows of a `SELECT`
  result to keep in the result cache; `0` disables it. Default 5000.
- `rev.json`: `creada`, and `indexes` as a list of revisions per index.
- `Storage::posicionesPorIndice()`, `claveEnIndice()`, `indiceValido()`,
  `claveResultado()`, `resultadoCacheado()`, `guardarResultado()`;
  `Select::tablasDe()`; `Evaluator::compilar()` and `marcarAgregados()`;
  `Indexes::anotar()` is public. Internal API.
- `tests/f5_esquema.php`: the result cache — repeated query, invalidation by a
  write, through a view, `RANDOM()` not cached, a recreated table not
  inheriting results.

### Removed

- `Storage::clavesDeIndice()`, `leerIndice()` and `filasPorIndice()`'s reliance
  on a single index file; `Evaluator`'s per-group evaluation of aggregates
  (`$ctx['grupo']`), superseded by the accumulators. Internal API.

### Upgrading

Replace the folder and keep the two configuration files. No data conversion:
`creada` and the index pieces appear on the first write of each table. If you
run the engine from cron or the command line with APCu installed, note that
until now it was running without cache; nothing to change, it just gets faster.

## [2.5.0] - 2026-09-04

Memory and speed release: writes stop reading the table, reads stream one part
at a time, and the journal stops copying files. Nothing breaking in SQL or in
the API; no data conversion. Two configuration constants removed.

### Changed

- **The journal is a redo log, not a copy set.** Up to 2.4 every write copied
  every file of the table (all parts, all indexes, structure and revision) into
  `.tx/` with an `fsync` each, before touching anything, so that a crash could
  be undone. Now every file the write produces goes to its temporary first,
  forced to disk; when all are written, a manifest listing the renames and
  deletions is written in one piece, and only then are the renames applied. A
  crash before the manifest leaves the data untouched (the temporaries are
  swept by the next write); a crash after it is finished on the next open, as
  many times as it takes. The cost went from copying the whole table to one
  small file and a couple of directory syncs. On a 20,000-row table a one-row
  `INSERT` went from 96 ms to 18 ms; on 100,000 rows from 742 ms to 104 ms.
  Journals of the previous format (copies plus a manifest with `estado`), and
  the pre-2.0 flat layout, are still recognised and undone, so a database left
  with a pending journal by an earlier version recovers correctly.
  `tests/f9_journal.php` now rebuilds every intermediate state of a commit —
  each temporary already renamed or still pending, each surplus file already
  deleted or not — and demands the exact bytes of the finished write; and it
  blocks the journal folder to prove that a write which cannot journal changes
  nothing at all. `tests/f6_cortes.php` times each child process first so the
  `SIGKILL` lands inside the commit window, which is much narrower now.

- **`<table>.rev.json` records the state of the table**: `rows` (row count),
  `parts` (the revision at which each part was last written) and `indexes` (the
  same per index), on top of `rev` and `chunk`. Files from 2.0–2.4 are read as
  they are and completed on the first write; the first write of each table
  rebuilds its indexes once, since without `rows` the engine cannot prove that
  the old ones are still valid.

- **The cache is per part, keyed by the revision of that part.** A write that
  touches one part of a hundred leaves the other ninety-nine cached, and the
  whole-table serialised entry — which every write used to regenerate — is
  gone. Reading a table streams its parts; a query never holds the whole table
  unless it needs every row at once.

- **`SELECT` streams from its first source.** Rows are read part by part and
  flattened and filtered as they arrive; a `WHERE` scan keeps only the rows
  that pass. `LIMIT` is applied while reading. Only the inner side of a `JOIN`,
  `ORDER BY` without `LIMIT`, `GROUP BY` and `DISTINCT` materialise. A numeric
  range on 20,000 rows went from 22 MB to 7 MB of peak memory; `LIMIT 50` from
  12 ms and 22 MB to 0.5 ms and 5 MB.

- **Indexes are corrected instead of rebuilt** whenever the engine can prove
  what changed: rows appended at the end (as before), rows replaced in place by
  an `UPDATE` (the old key is removed using the old row, read from the part
  that has not been replaced yet), and rows shifted from a position on by a
  `DELETE` (entries from that position are cut and the rows behind re-added).
  An index whose content does not change — an `UPDATE` of a column it does not
  cover, an `ALTER TABLE` that only touches the structure — is not rewritten
  at all; `rev.json` records that it is still current. Any doubt rebuilds it
  from the rows, as before.

- **Index entries with a single position are stored as an integer**, not a
  one-element list: `"keys":{"n1:5":3}`. On a primary key that is every entry,
  and in memory a list of one costs three times what an integer does. Indexes
  written before 2.5 (always lists) are read the same way and rewritten in the
  new form when they next change. `tests/f3_escrituras.php` and
  `tests/f10_indices_incrementales.php` compare indexes as maps, since a
  corrected index does not list its keys in the same order as a rebuilt one.

- **Writes no longer read the table when nothing forces them to.**
  - An `INSERT` into a table with no triggers, whose primary key and unique
    constraints have their index on disk, checks uniqueness against the index,
    reads only the last part and appends to it. Foreign keys are checked against
    the parent's index when it has one on the referenced columns.
  - An `UPDATE` or `DELETE` whose `WHERE` an index can answer reads only the
    parts of the candidate rows and rewrites only those; a delete shifts every
    row after it, so from the first deleted position on the parts are redone.
  - A trigger on the table, a self-referencing foreign key, an index that
    cannot be trusted or a `WHERE` no index can answer fall back to loading the
    table, which is what every write did before.

  On 20,000 rows an `UPDATE` by key went from 132 ms and 31 MB to 13 ms and
  11 MB, a `DELETE` by key from 215 ms and 32 MB to 32 ms and 13 MB; on 100,000
  rows an `INSERT` from 140 MB to 43 MB and an `UPDATE` by key from 936 ms and
  135 MB to 47 ms and 33 MB. `tests/f8_indices.php` runs the same sequence of
  `UPDATE`s and `DELETE`s by key against a table with indexes and one without
  and demands identical results after every statement, index against scan and
  cache against disk. Uniqueness checks in the writer now use the same key
  function as the indexes (`Indexes::clave`), so `5` and `5.0` in a numeric
  unique column are the same key in both places.

- **`tests/benchmark.php` reports the mean** of several runs of each query
  (seven per read, five per write and per heavy query), not the median. The
  documentation numbers were remeasured with it.
- `tests/f1_nucleo.php` no longer leaves its empty temporary folder behind:
  the final `rmdir` ran before the memory tests that still used the folder. It
  now runs last and is checked.

- **All documentation is in English** and the files were renamed:
  `docs/00-index.md`, `01-core.md`, `02-queries.md`, `03-writes.md`,
  `04-api.md`, `05-admin.md` and `nginx/README.md`. Every section touched by
  this release (journal, revision file, cache, indexes, writes, memory,
  upgrading) was rewritten to match the code. Source code comments and engine
  messages remain in Spanish.

### Removed

- `JSONSQLDB_JOURNAL_DATOS`: the journal is always on. It used to be optional
  because copying the table on every write was expensive; it is not any more,
  and turning it off was the one way to lose data on a power cut. Still defined
  in an old `config.php`, it is ignored.
- `JSONSQLDB_CACHE_MAX_FILAS`: there is no whole-table cache entry to cap. Ignored
  if defined.
- `Storage::tieneIndices()`, `Storage::leerFilas()`'s `$tope` and `$guardarCache`
  arguments, and `Storage::txIniciar()`'s table list: internal API, superseded
  by the redo journal and the streaming reads. `Storage::filas()` (a generator),
  `anadirFilas()`, `modificarFilas()`, `filasEnPosiciones()` and
  `clavesDeIndice()` are new.

### PHP versions

Nothing new is required beyond 8.0. `fsync()` (8.1) is used when available for
the temporaries, the manifest and the directory entries; on 8.0 the buffer is
flushed, as before. `array_is_list()` (8.1) is not used. CI runs 8.0 to 8.5.

### Upgrading

Replace the folder and keep the two configuration files. No data conversion:
`rev.json` and the indexes are completed on the first write of each table.
Open each database once with the previous version before replacing the folder
if it may have a pending journal — not required, since 2.5 undoes the old
format, but tidier. If your `config.php` defines `JSONSQLDB_JOURNAL_DATOS` or
`JSONSQLDB_CACHE_MAX_FILAS`, remove the lines.

## [2.4.0] - 2026-08-31

`SELECT COUNT(*)` no longer reads the table. Nothing breaking, no data conversion.

### Changed

- **`SELECT COUNT(*) FROM tabla` — with nothing else in the query — answers from
  the row count, not from the rows.** The engine writes one row per line, so
  counting is reading lines: no `json_decode`, no materialised table, and the
  memory peak is one line. On 100,000 rows it went from 180 ms to 22 ms, and it
  no longer depends on the table fitting in memory — including the cache:
  counting skips the whole-table cache on purpose, because reading that cache
  is the one thing that could still not fit. CI caught exactly that: the same
  `COUNT(*)` under a 12 MB limit passed or failed depending on whether the PHP
  version's memory baseline let the cache be read. `tests/f1_nucleo.php` now
  probes the memory guard with `COUNT(v)`, which still materialises the table,
  and separately demands that `COUNT(*)` succeed right where the table does
  not fit.

  The shortcut steps aside for anything it cannot answer by counting: a `WHERE`,
  `GROUP BY`, `HAVING`, `DISTINCT`, `ORDER BY`, `LIMIT`/`OFFSET`, more than one
  column or table, a view, a CTE, `COUNT(col)`, or a trigger counting in the
  middle of a write, where the rows that matter are in memory and not on disk
  yet — that last one is covered by `tests/f3_escrituras.php`, which failed until
  the shortcut learned to step aside. A part file not in the one-row-per-line
  format (edited by hand, or compacted) falls back to counting by decoding, same
  as reading does. On a canonical-looking file with a corrupt row the count
  includes it; detecting that is `INTEGRITY CHECK`'s job, not `COUNT(*)`'s.

- `SHOW TABLES` benefits from the same counting, since it reports row counts
  through the same code path.

- **A write no longer serialises the table it is about to change into the
  cache.** The read that precedes every `INSERT`, `UPDATE` or `DELETE` was
  storing the whole table in the cache under the current revision — and the
  write then retired that revision and cached the new rows itself, so the store
  was thrown away within the same operation. On tables under the cache cap it
  was the table serialised and written to disk once per write, for nothing.
  Single-row writes on a 15,000-row table got 10-20 % faster; tables above
  `JSONSQLDB_CACHE_MAX_FILAS` never paid this and are unchanged. Reads cache
  exactly as before.

- **README trimmed by a fifth** (48 KB -> 39 KB): the deep dives on locking,
  journalling, admin features and test internals now summarise and point to
  `docs/`, which already had them in full. While at it, the index section still
  said the whole index is rebuilt on every write, which stopped being true in
  2.3.0.

## [2.3.0] - 2026-08-31

Faster writes on tables with indexes. Nothing breaking, no data conversion.

### Added

- **`tests/f10_indices_incrementales.php`**, a suite of its own for the change
  below. It has one because the risk is not symmetric: an extra entry in an index
  only makes a query slower, since the `WHERE` is applied again to the rows that
  are read, but a missing one returns incomplete results with nothing to show for
  it — no error, no warning, just fewer rows. Every check compares the extended
  index against rebuilding it from scratch, and every query against the same
  query written so the index cannot be used.

  Removing either safeguard on its own does not turn it red, because the two
  cover each other; removing both does, with `sobran 396 posiciones`. That was
  checked, not assumed.

### Fixed

- **The scaling tests for bulk writes were unreliable, and weaker than they
  looked.** Two separate problems, both found because CI failed on PHP 8.1 with
  `el coste por fila se multiplicó por 12.7` while the code was fine.

  They timed one run per size, and the smaller size ran first — so it paid the
  warm-up that the larger one did not. Locally the same measurement varied
  thirteenfold between runs. They now take the fastest of several runs and
  discard the first: noise can only add time, so the fastest run is the one
  closest to the real cost.

  And they compared 1,000 rows against 4,000, where the fixed costs — rewriting
  the parts, rebuilding the indexes — hid the quadratic term. Removing the
  `posicionEn` shortcut on purpose, which makes the update quadratic again, did
  **not** turn them red: they were passing whatever the code did. Against 8,000
  rows it now fails with a factor of 4.7, which is the point of having them.

### Changed

- **An index is extended instead of rebuilt when a write only appends.**
  Rebuilding it was 67 % of what a single-row `INSERT` costs on a large table —
  measured, `Indexes::construir` plus `clave` plus `trozo` — and it is repeated
  work: if nothing moved, the keys of the existing rows are the same ones.

  It is only reused when it can be shown to still fit: the write appended at the
  end and touched no scattered positions, the index on disk is readable and for
  these same columns, its revision is exactly the one before this write, and it
  claimed to hold as many rows as there were positions. Any doubt and it is
  rebuilt.

  Measured: a single-row `INSERT` on 100,000 rows went from 363 ms to 321 ms, and
  on 50,000 from 180 ms to 156 ms. Less than the 67 % ceiling, because a write
  does other things too and checking the index files costs a read of each.

### Note

Reading each part through its own cache on full scans was tried and dropped. It
saved about 6 ms on a 50,000-row table above the cache cap, but made writes worse
— `leerFilas()` runs during writes, and caching each part means serialising it —
and it broke `REPAIR KEYS`. The measurement said yes and the test suite said no.

## [2.2.1] - 2026-08-31

One durability fix. Nothing breaking, no data conversion.

### Fixed

- **Renaming a file put its contents on disk, but not its name.** Every write
  ends in `rename()`, and the `fsync()` before it covers the file's contents —
  not the directory entry that gives it its name. That entry can sit in the
  operating system's cache, so a power cut in between could leave the data
  written and the file missing from its directory, or the name still pointing at
  the old inode. The same applies to deleting a surplus part, and to creating and
  removing the journal folder.

  On ext4 with `data=ordered` it usually comes out right because of how writes
  are ordered, but POSIX does not promise it, and "usually" is not what this
  engine claims. The directory is now synced after renaming, after deleting a
  part, and around the journal folder.

  **How it is done matters.** `fsync()` on the handle from `opendir()` returns
  false and does nothing — quietly, so it would have looked like a fix while
  changing nothing. The directory has to be opened with `fopen($dir, 'r')`.
  `f1_nucleo.php` checks both, and fails if the working one stops working or the
  useless one starts.

  On Windows `fopen()` on a directory does not work, so this does nothing there —
  which is the platform where `rename()` is not atomic either, and that is what
  the journal is for. On PHP 8.0 there is no `fsync()` at all, a limitation the
  documentation already states.

  Cost: one `fsync` per write operation. Measured against 2.2.0 it does not undo
  that release's gains — a single-row `INSERT` on 50,000 rows is 130 ms against
  149 ms in 2.1.1.

  Found by an external review of 2.2.0, which flagged the gap and proposed the
  `opendir()` version that does not work.

## [2.2.0] - 2026-08-30

Table-level locking for writes that can propagate, less memory and faster
queries. Nothing breaking, no data conversion.

### Added

- **`tests/benchmark.php`.** A reproducible benchmark in the repository, so the
  figures in the README can be checked on your own machine instead of taken on
  trust. Fixed seed, median of several runs rather than the mean, and a `csv`
  mode for comparing two versions. It is not a test: it neither passes nor fails,
  it measures.

### Fixed

- **The documentation index still said version 1.10.1**, three releases behind.
  The number is gone from it: written by hand it falls behind without anyone
  noticing, which is exactly what happened. It points at `VERSION` and the
  changelog instead.

- **The version was read in two independent places.** The panel had its own
  reader; it now asks the engine, and only falls back to reading the file
  directly because it does not load the engine — it talks to it over HTTP.
  `f1_nucleo.php` checks that the engine, the panel, the `VERSION` file and the
  first heading of this changelog all say the same thing.

- **`Config::version()` was added in this release and never called**, which made
  it dead code by the strictest reading. The API now returns it in an
  `X-JsonSQLDB-Version` header, which is useful for telling which version
  answered without opening an FTP session.

- **Recovery copied a leftover temporary file into the data folder.** It restored
  everything it found in the journal folder, skipping only the manifest — so a
  process killed while writing the manifest left its
  `manifiesto.json.<pid>.tmp` there, and recovery put it among the tables. It now
  skips temporaries too. Restoring only what the manifest lists would be more
  precise, but an incomplete list would lose files, and here prudence beats
  precision.

  CI caught this on one crash out of twelve, so `f9_journal.php` now builds the
  situation by hand instead of waiting for luck: it fails if the file reappears.

- **A read-only API key could not run `SHOW INDEXES`.** The list of statements a
  `lectura` key may execute is kept by hand, and `show_indexes` was never added
  when indexes arrived in 2.0 — even though it reveals nothing beyond the
  structure, which the other `SHOW` statements already do. `f4_api.php` now walks
  every `SHOW` with a read-only key, so adding one to the parser and forgetting
  the list shows up as a failure.

- **`composer test` did not run the index and journal suites.** The script listed
  f1 to f7, so a contributor running it never exercised `f8_indices.php` or
  `f9_journal.php` — the two that cover indexes and crash recovery.

- **The header comment in `Storage.php` still described `_revs.json`** as the
  per-table revision counter. Since 2.0 each table has its own
  `<table>.rev.json`, and the shared file is only read for databases written by
  an earlier version.

- **The API permission matrix is now written out in the documentation**, one row
  per statement and one column per permission, instead of being implied. That
  list is kept by hand in the code, and the `SHOW INDEXES` omission above is what
  happens when it is not visible anywhere.

- **`Config::VERSION` was dead and stuck at `1.0.0`.** Nothing read it, so it
  drifted several releases without anyone noticing. It is now
  `Config::version()`, reading the `VERSION` file: one place that can state the
  version instead of two that can disagree.


### Changed

- **`ORDER BY … LIMIT` no longer sorts everything to return a handful of rows.**
  It keeps only the best N as it goes, in a heap of fixed size. On 50,000 rows,
  `ORDER BY saldo DESC LIMIT 20` went from **457 ms and 63 MB to 169 ms and
  38 MB**, and with `LIMIT 1000` from 443 ms to 201 ms.

  The delicate part was proving it returns the same thing: ties break by the
  row's original position, which is exactly what the stable sort did, so the rows
  and their order are identical to sorting everything and slicing. Checked
  against the full sort over 11 cases built with plenty of ties on purpose,
  including `LIMIT 0`, an `OFFSET` past the end of the table, and a `LIMIT`
  larger than the table.

- **A write that can propagate no longer locks the whole database.** Writing to a
  table does not always stay in it: a foreign key with `ON DELETE CASCADE` drags
  child rows and a trigger can write anywhere, and that was enough to take the
  exclusive database lock — so any other write waited, even to tables with no
  relation to it at all.

  The engine now works out the set of tables the write could reach before locking
  anything: foreign keys in both directions and transitively, plus wherever the
  triggers write, parsing their SQL rather than pattern-matching it. Only those
  are locked. Two padre/hija groups with no relation between them now write at
  the same time.

  Two things make it safe. **All the locks are taken up front**, because asking
  for one more halfway through a write is exactly how deadlocks happen. And they
  are taken **in alphabetical order**, so two processes needing the same tables
  ask in the same sequence: one waits for the other instead of both waiting
  forever. It falls back to the database lock the moment the set cannot be
  stated — a trigger whose SQL will not parse, an `INSERT ... SELECT`, any schema
  change, or more than eight tables.

  The journal follows: its scope is the first table of the set, the manifest
  lists them all, and recovery takes the exclusive lock of every one of them —
  without waiting — before undoing anything.

  `f7_concurrencia.php` checks the two halves of the claim: two unrelated groups
  overlap, and two writes to the same group do not. It measures overlap rather
  than total time, because on a single-core machine two processes take the same
  wall time whether they run together or in turn.

- **A write only rewrites the parts that can have changed.** Inserting one row
  into a table spread over a hundred part files rewrote all hundred. The writer
  now tracks which positions changed and whether the rest shifted, and passes
  that down. Writes are 20–25 % faster and the gap grows with the table: on
  100,000 rows a single-row `INSERT` went from 384 ms to 294 ms.

  Writes are 20–25 % faster and the gap grows with the table: on 100,000 rows a
  single-row `INSERT` went from 272 ms to 221 ms, an `UPDATE` from 282 ms to
  231 ms, and an `UPDATE` of the last twenty rows from 316 ms to 213 ms.

  It falls back to rewriting everything at the slightest doubt: when the caller
  did not say what changed, or when `JSONSQLDB_FILAS_POR_PARTE` is not the one
  the table was written with — that one matters more than it looks, because
  changing it moves the boundaries, so a part left untouched ends up holding rows
  that are no longer its own. The part size is now noted in `<table>.rev.json`,
  and the count of existing parts comes from the files on disk rather than from
  arithmetic, because that is the one figure that cannot lie.

  Two tests guard it. `f3_escrituras.php` runs ten kinds of write, forces a full
  rewrite afterwards with the same rows, and demands that not a single byte
  changes. `f9_journal.php` covers the part-size change across processes, and it
  was written the hard way: the first version passed with the safeguard removed,
  because the cache was serving the correct rows under the new revision and
  hiding the damage on disk. Reading past the cache, removing the safeguard
  leaves 160 rows out of 200 — which is the point of having the test.

- **The source rows are released while the result is built.** They used to live
  alongside the finished result until the loop ended, which is two copies of the
  same data at the peak. The order keys are no longer built either when there is
  no `ORDER BY` to use them.

- **Writing a part no longer builds its JSON in memory first.** It is written row
  by row to the file, so the row array, the complete string and each row's
  `json_encode` no longer coexist. Same guarantees as before — temporary file,
  `fsync`, `rename` — and the output is identical byte for byte.

### Note

The queries that were already fast stay the same, within measurement noise: this
release moves `ORDER BY` and writes, and touches nothing else. What is left for
memory is the query engine materialising whole tables in arrays — turning
`Select::cargar()` into a generator, and having `agrupar()` keep aggregation
state instead of every row of every group. Both are large enough to deserve their
own release.

## [2.1.1] - 2026-08-30

Fixes over 2.1.0. Nothing breaking. Most of these come from external reviews of
2.1.1 and from running it on a real shared host; each was reproduced here before
being changed, except the temporary-file sweep, which is noted below.

### Fixed

- **The concurrency suite failed on slow machines for no good reason.** It
  compared total wall times against a fixed 1.7× margin, and every measurement
  includes the cost of starting a PHP process — a fixed addend that is a few tens
  of milliseconds here and 150 or more on a modest shared host. Past that point,
  two writes that really did serialise came in under the margin and the test
  called it a failure. It now measures the process startup separately and takes
  the margin over the time the lock is actually held, and prints all three
  numbers so a failure says which one drifted. Reported from an external
  environment where it gave 3 failures out of 20 with nothing wrong.

- **`configurar.php` now creates the configuration files as `0600`.** They hold
  every key and secret in the system, and with the usual umask they came out
  `0644` — readable by the other users of the machine, who on shared hosting are
  strangers. If the permissions cannot be set it says so instead of staying
  quiet.

- **An I/O failure on the API state file reported "rate limit exceeded".** It
  still closes the door, which is right, but the message sent whoever read it to
  look in the wrong place. It now says the service is unavailable.

- **Clearing a table's part caches in APCu issued 512 deletions per write**,
  covering a fixed ceiling of parts whether they existed or not — and a table
  past that ceiling kept entries that were never cleared. It now clears exactly
  the parts the table has or is about to have.

- `config.php` pointed at `docs/02-seguridad.md`, which does not exist.

- The `[2.0.1]` section of this file was removed: it was never released, and its
  contents shipped inside 2.1.0, so it only duplicated them.

- **Sweeping orphaned temporary files no longer depends on comparing process
  ids.** After a `SIGKILL` a write can leave its `.tmp` behind, and the next
  write to that table removes it — it holds the table's exclusive lock, so any
  temporary it finds is someone else's leftover. It skipped files whose name
  ended with its own `.<pid>.tmp`, which is both unnecessary — the sweep runs
  before writing anything, so there is no temporary of its own in flight — and
  fragile, because one process id can be a suffix of another's. The sweep also
  reaches the shared files now (`_views.json` and friends): holding the shared
  database lock means nobody can hold the exclusive one, so nobody is writing
  them. Only other tables' temporaries are left alone, because those may be live.

  This was reported by CI on PHP 8.4 only, in one run out of twelve, as
  "temporary files were not swept". **It has not been reproduced here** — the
  crash suite needs the timing of a kill to land in a specific window, and 8.4 is
  not available in this environment — so this removes the one mechanism that
  could plausibly cause it rather than a confirmed diagnosis. The test now names
  the files that were left, so a recurrence says which write produced them.

- **Exporting a database and restoring it failed.** The importer checks that
  every `.json` in the ZIP has the name of a table file, and its list of allowed
  suffixes was never updated for the two file types 2.0 introduced:
  `<table>.rev.json` and `<table>.idx.<name>.json`. The exporter puts everything
  in the folder into the ZIP, so a ZIP the panel had just produced was rejected
  by the panel itself with "a name that is not a table's". Restores of ZIPs from
  earlier versions keep working.

  It went unnoticed because the round-trip test skips itself when the `zip`
  extension is missing, which it was on the machine this was developed on: it
  printed "(omitida: falta la extensión zip)" and counted as passing, and only
  CI, which has the extension, ever ran it.

## [2.1.0] - 2026-08-30

Sets up in one command, refuses to start with the example keys, and bulk writes
and point lookups are several times faster. Nothing breaking.

### Added

- **`configurar.php`.** One command creates both configuration files with random
  keys already in place, keeping the panel's key and secret matching its account
  in the API. `--local` also allows plain HTTP so you can try it on your own
  machine. It never overwrites an existing configuration. Doing it by hand meant
  nine values across two files, two of them duplicated, with no clear error when
  you got it wrong.

### Fixed

- **Leaving the example keys in place used to work.** The two templates carry the
  same `CHANGE_ME_` placeholders on both sides, so forgetting to replace them
  left a working install — with a key and an HMAC secret that are published in
  this repository. The API and the panel now refuse to start while any remain,
  and say how to generate proper ones.

- **The HTTPS refusal now says what to do about it.** Both the API and the panel
  reject plain HTTP, which is the first wall anyone hits installing on their own
  machine, and the message was just "only accepts HTTPS". It now names the two
  constants and the two files, and reminds you to put them back before
  publishing. The README covers it too, which it did not.

- **The admin panel refused to restore a ZIP when served over IPv6.** Restoring
  requires the panel and the engine to be on the same machine, decided by
  comparing hosts. An IPv6 address arrives as `[::1]:8080`, and taking everything
  before the first `:` returned `[`. A bare `::1` came out empty, and two empties
  compared equal, which would have wrongly allowed it.

- **A failing panel test now says what went wrong.** The diagnostics printed the
  first 160 characters of the page with tags stripped, which is the stylesheet.

### Changed

- **Bulk `UPDATE` and `DELETE` were quadratic.** Three costs at once: each
  affected row was located by scanning the table for a match by value;
  `Catalog::tablas()` listed the directory once per row, because foreign-key
  propagation asks "does anyone reference me?" for each one; and pulling the
  table into a local variable before writing to it triggered PHP's copy-on-write,
  copying every row on every iteration. `DELETE` also compacted with
  `array_values()` per row, moving every following row and invalidating the
  position just found. Rows are now found by the position they already had, an
  `isset` in the normal case, with the old scan as the fallback for when a
  trigger has moved things. On 8,000 rows: `UPDATE` **1,244 ms → 53 ms**,
  `DELETE` down to **11.7 ms**. `f3_escrituras.php` measures the growth so it
  cannot creep back.

- **Point lookups cache the part they read.** The whole-table cache is no use
  here — the point of an index is not reading the whole table — so every lookup
  re-decoded its part to keep one row. On 20,000 rows a primary key lookup went
  from **11.2 ms to 2.6 ms**, and by unique from 13.0 ms to 2.5 ms.

- **The API's per-request state went from three file rewrites to one.** Checking
  the global block, registering the nonce and counting the request each read,
  decoded, modified and rewrote the whole state file under an exclusive lock, and
  that file grows with traffic, so latency got worse on its own. The check is now
  read-only, and the nonce and the count share one transaction. With 100 requests
  in the window the state cost per request went from **0.70 ms to 0.21 ms**, and
  the file is a third of the size. A request rejected for database permissions
  now consumes rate-limit quota, which for an anti-abuse limit is the wanted
  behaviour.

- **The cache is no longer written atomically, and skips tables over
  `JSONSQLDB_CACHE_MAX_FILAS` rows (20,000 by default).** It is regenerable and
  its key carries the table revision, so a half-written file just fails to
  unserialise and counts as a miss — which the read path already handled. That
  removes an `fsync` the size of the table from every write, and the memory spike
  from serialising large tables.

- The credit for the four performance findings in this release goes to an
  external review made with artificial intelligence, which measured them on the
  2.0.0 release. Each was
  verified independently here before being applied.

## [2.0.0] - 2026-08-27

Major version because the API request format changes in a way that breaks
existing clients. See the first entry under *Changed*.

### Added

- **Indexes.** `CREATE INDEX name ON t (a, b)`, `DROP INDEX name [ON t]` and
  `SHOW INDEXES [FROM t]`. Primary keys and unique constraints get one
  automatically, named `auto_<columns>`. An index stores the positions of the
  rows holding each value, which tells the engine which part files to decode: a
  primary key lookup over 50,000 rows went from 107 ms and 50 MB of peak memory
  to 17 ms and 19 MB.

  Composite indexes are used left to right — `(a, b)` serves a lookup on `a`, or
  on `a` and `b`, but not on `b` alone. Only `=` and `IN` against literals, and
  only in the top-level `AND` chain of a `WHERE`: anything under a `NOT`, a
  top-level `OR`, `IS NULL` and `NOT IN` are deliberately left alone, because an
  index there would change the answer instead of just finding it faster.

  Index keys follow the engine's equality rather than PHP's, so `5`, `'5'` and
  `'5.0'` share a key and looking up a number still finds a row that stored it as
  text. The whole index is rebuilt on every write, because saving re-packs the
  rows from zero and a single `DELETE` moves every row after it into a different
  part. Each file records the revision it belongs to; a mismatch makes the engine
  ignore it and scan, so a stale index can cost speed but never correctness.

  Indexes only help reads. Writes get slower, since a table with indexes rewrites
  their files too. `JSONSQLDB_INDICES` turns the whole thing off.

- **The admin panel lists, creates and deletes indexes** on the table structure
  screen. Automatic ones are shown but cannot be deleted on their own.

### Fixed

- **The journal's copies were not forced to disk, which is exactly what a power
  cut needs.** `copy()` leaves the contents in the operating system's cache. That
  survives the process dying — the cache belongs to the system, not to it — but
  not the power going out. The manifest *was* flushed, so a cut could leave a
  perfectly valid manifest pointing at copies that were empty or half written,
  and recovery would then restore those over data that was intact. Copies are now
  streamed and `fsync()`ed before the manifest is written, and restoring does the
  same, so an interrupted recovery leaves something the next one can repeat.

- **The manifest now records the size of every copy, and recovery checks it.** If
  a copy does not measure what it should, the engine refuses to touch anything
  and says so, leaving the journal in place to be looked at. Restoring blindly
  would destroy data that might be perfectly fine, and deleting the journal would
  throw away the only copy left.

- **A journal could be lost to a race between two writers.** Creating
  `.tx/<scope>/` makes `.tx/` first and the scope directory second, and another
  process finishing its own journal could sweep the empty `.tx/` in between. The
  second `mkdir` then failed and the whole write was lost. It is retried now.
  Found by the concurrency suite, which lost five rows out of forty about one run
  in five; it now also reports what a failing child process said instead of
  silently counting rows that never arrived.

- **A boolean and its number produced different index keys.** The engine
  compares booleans as text, so `true` and `1` — which it considers the same
  value — hashed to different keys, and looking up `1` would not have found a row
  storing `true`. Booleans are now normalised to numbers before the key is built.
  Where the engine's own equality cannot be reproduced exactly (it is not
  transitive: `true == 1` and `1 == '1.0'`, but `true != '1.0'`), the key errs
  towards returning *more* candidate rows, because the `WHERE` filters those out
  afterwards while a missing row is never noticed.

- **`REPAIR KEYS` deleted the indexes of the table it repaired.** It rewrote the
  rows without declaring them, and a write that declares no indexes removes the
  files of any it finds. Results stayed correct — without an index the table is
  scanned — but repairing a foreign key has no business making the table slower.

- **A write to a table spread over several files was not crash-safe.** The
  journal decided by counting *tables*, but the unit that has to be atomic is
  *files*, and one table is rarely one file: past `JSONSQLDB_FILAS_POR_PARTE`
  rows it is several parts, with indexes it is one file per index, and an
  `INSERT` into a table with `AUTOINCREMENT` also rewrites the schema file. A
  power cut between two renames left some parts new and some old — and since
  parts are split by position, that did not lose "a few rows", it threw the
  table out of alignment from the cut onwards. Any write touching more than one
  file now runs under a journal.

- **The journal is scoped to the lock the write holds**, `.tx/_base/` or
  `.tx/<table>/`, so making single-table writes crash-safe did not cost the
  concurrency that the table lock buys: two writes to different tables still run
  at the same time. Recovery of a table journal asks for that table's lock
  without waiting — if it cannot get it, a live write owns the journal and there
  is nothing orphaned to undo.

- **An interrupted journal could corrupt the table it was protecting.** The
  manifest is written after the copies, so a process killed while copying left a
  half-written file with no manifest — and recovery restored it over the intact
  original. With no readable manifest the copies are now discarded instead:
  copying happens before anything is modified, so if it did not finish, nothing
  was touched. The manifest is also deleted first when clearing a journal, so a
  crash during cleanup cannot leave a manifest pointing at a set of copies that
  is already half gone.

- **Reads did not take the table lock**, so a reader could see half a write. A
  `SELECT` took only the shared database lock while a write to one table held the
  table lock, and the two could run at once. Harmless when a table was one file
  and a single atomic rename; wrong as soon as it spans several, where a reader
  could pick up the first part already new and the second still old. Reads now
  take the shared lock of every table they touch — shared locks do not block each
  other, so reads still run together and only wait on a write to that same table.

- **The revision counter is now per table.** All tables shared `_revs.json`, and
  two writes to different tables — which run at the same time by design — each
  read it and rewrote it whole, so whichever finished last erased the other's
  bump and left that table's cache serving rows from before the write, with no
  visible symptom. Bases created with earlier versions keep reading the old file
  until each table is written once.

- **The revision is now written before the data, not after.** A crash between the
  two leaves a new revision over old data: nothing is cached under that revision,
  so the next read goes to the file and sees the truth. The other order left new
  data under an old revision, with the cache serving what was there before —
  which is not detectable afterwards.

- **Orphaned temporary files are swept.** A process killed with `SIGKILL` never
  runs the `finally` that removes its temporary, so it stayed on disk. Every
  write now clears any temporary of that table left by another process, which it
  can do safely because it holds the table's exclusive lock; taking the database
  lock clears the rest.

- **The memory guard now also checks the memory PHP has requested from the
  system**, not only what is in use. The limit applies to the former, and the gap
  — blocks already requested but too fragmented to reuse — is not negligible on a
  small `memory_limit`: with 16 MB the process hit PHP's fatal with 13 MB in use.
  The reserve kept before cutting also never drops below a fraction of what is
  already used, to cover an array doubling, which costs about as much as the
  array already occupies and cannot be predicted from how the previous rows grew.

### Changed

- **A join rebuilt the list of every inner row on each outer row, and threw it
  away.** `array_keys($internas)` sat inside the loop over outer rows and was
  overwritten straight after whenever there was a hash index — which is the
  normal case. A join of 30,000 by 20,000 rows built a 20,000-element array
  thirty thousand times for nothing. The aggregate join in the benchmark went
  from **673 ms to 95 ms**.

- **Building an index no longer goes the long way round for the common values.**
  `Indexes::trozo()` was almost half the cost of an `INSERT`, because it is
  called once per row and column and every value went through `esNumerico()` and
  `aNumero()`. Integers and non-numeric strings — primary keys, emails, cities —
  now take a direct path to the same result. An `INSERT` of one row went from
  **117 ms to 90 ms**.

- **`Valor::comparar()` short-circuits two numbers and two strings.** It is the
  single hottest function in the engine: an `ORDER BY` over 20,000 rows calls it
  around three hundred thousand times, and it was doing four function calls to
  reach a comparison that `<=>` or `strcmp` answers directly. The shortcuts give
  the same result — verified exhaustively over 1,225 pairs against the long path,
  including `NAN`, `INF`, `'0x1A'`, `' 5 '` and booleans. `ORDER BY … LIMIT` went
  from **177 ms to 109 ms** and a filtered scan from 39 ms to 22 ms.

- **`IN (SELECT …)` was quadratic, and is now linear.** The list of values was
  scanned in full for every row, so 30,000 rows against 2,000 values meant sixty
  million comparisons: 13.4 seconds where SQLite took 8 ms. When the values are
  the same for every row — a subquery that does not look outwards, or a list of
  literals — they are now grouped by key once and each row costs a lookup. On the
  benchmark that is **13,364 ms → 105 ms**, and doubling the data now doubles the
  time instead of quadrupling it.

  The key only narrows the candidates; equality is still decided by
  `Valor::comparar`, because two different values can share a key and the answer
  has to be the same as before rather than merely similar. Correlated subqueries
  change with each row and have nothing to reuse, so they are scanned as before.

- **`JSONSQLDB_FILAS_POR_PARTE` now defaults to 1,000 instead of 5,000.** It sets
  how much an indexed lookup can skip: the index says which positions hold the
  rows and therefore which parts, so with large parts you decode a lot to get a
  little. With 20,000 rows and parts of 5,000 there are only four files and
  almost any lookup touches them all. Measured on the benchmark table: a primary
  key lookup goes from **8.56 ms to 4.46 ms** and an `IN` of ten keys from
  **46.11 ms to 9.44 ms**. Full scans are unaffected, and writes cost between 4 %
  and 15 % more depending on table size. Going below 1,000 barely improves reads
  and makes writes noticeably worse.

  Existing tables keep whatever size they were written with until the next write
  to them; nothing has to be converted.

- **BREAKING: the HMAC signature now covers the database name.** The formula
  goes from

  ```
  "+" . api_key . "|" . timestamp . "|" . sql . params . "¿"
  ```

  to

  ```
  "+" . api_key . "|" . db . "|" . timestamp . "|" . sql . params . "¿"
  ```

  The `db` field was outside the signature, so a legitimate signed request could
  be captured, have its `db` changed, and be replayed against a different
  database — the signature stayed valid because it did not cover the field. For
  an API key with access to more than one database, that was enough to run a
  statement where it did not belong.

  **Every client signing with the old formula stops working** and has to be
  updated. The four bundled clients (PHP, Python, PowerShell and the admin
  panel) already use the new one. For statements that target no database
  (`SHOW DATABASES`, `CREATE DATABASE`) the empty string is signed, which is what
  the clients send.

- **Rows are read one at a time when the query will not keep them all.** The
  files are one row per line, so a query that discards most rows no longer holds
  the whole file and the whole decoded array at once. `SELECT * FROM t LIMIT 50`
  over 50,000 rows went from 56 ms and 50 MB to 3.6 ms and 3.6 MB. When every row
  is wanted the file is still decoded in one go, which is about 25 % faster and
  costs no extra memory, since the rows were going to be held anyway.

- **`LIMIT` is pushed into the read** when there is no `WHERE` and no `JOIN`.
  With either of those the surviving rows are not the first ones, so the read
  cannot stop early.

- **`SELECT COUNT(*)` and `SHOW TABLES` no longer build the rows.** `SHOW TABLES`
  loaded every table in the database into memory just to count its rows.

- **Rows are no longer held twice while a table is prepared for a query.**
  Loading a table copies every row with its columns prefixed by the table alias,
  and both full copies were kept until the loop finished. The original is now
  released row by row: `SELECT COUNT(*)` over 50,000 rows went from 50 MB of peak
  memory to 32 MB. That loop was also outside the memory guard's watch, so it
  could reach PHP's fatal without the engine getting a chance to stop first.

- **The cache is skipped when memory is tight.** Storing a table means
  serialising it, which holds it twice for a moment. Past half of `memory_limit`
  the engine gives up the cache rather than risk the query for it.

- **Data and structure are written in a single operation.** They were two writes,
  each raising the revision and rebuilding the indexes, so every `INSERT` into a
  table with `AUTOINCREMENT` did that work twice and left an instant with new
  rows and an old schema.

- `CREATE UNIQUE INDEX` is rejected with an explanation rather than silently
  accepted: an index here only speeds up lookups, and uniqueness is what
  `ALTER TABLE … ADD UNIQUE` is for — which creates its own index anyway.

- **New on-disk files.** `<table>.rev.json` per table, and
  `<table>.idx.<name>.json` per index. `_revs.json` is no longer written. Nothing
  has to be migrated by hand: an existing database is read as it is, and each
  table moves over on the first write to it, carrying on from the revision the
  old `_revs.json` had rather than starting from zero — otherwise a stale cache
  entry could be taken for a current one. Indexes appear at that same moment.

  One case needs care and is handled: a database left with a **pending journal
  from before 2.0**. Journals used to keep their copies loose inside `.tx/`, with
  no scope directory, and recovery now looks for subdirectories — so an old
  journal went unnoticed, and an operation interrupted before the upgrade was
  never rolled back. Those are now picked up and undone like any other.

- **New test suite `f8_indices.php`** (57 checks), which validates almost
  everything by comparing the indexed query against the same condition rewritten
  so the index cannot be used. It covers booleans, dates and decimals; strings
  with quotes, newlines, emoji and shapes that look like index keys themselves;
  and composite keys that would collide if the format did not record where each
  part ends (`('x','yz')` against `('xy','z')`).

- **`f6_cortes.php` went from 5 checks to 29.** It now kills processes during a
  write to a table spread over part files with indexes; during each kind of
  operation in turn (`UPDATE` of every row, `UPDATE` of an indexed column, an
  `INSERT` that adds a part, a `DELETE` that removes one, `CREATE INDEX`,
  `DROP INDEX`, four kinds of `ALTER TABLE`, `CREATE TABLE`, `DROP TABLE`); and
  it builds damaged journals by hand to cover the states a power cut produces but
  a `SIGKILL` cannot — a truncated copy under a valid manifest, a journal with no
  manifest, a `COMMITTED` one, two table journals at once, a revision file that
  is ahead of the data, and a missing one. After every kill it demands that no
  row be left mixed between the old and new value, that the indexes agree with
  the data, that the cache agrees with the files, and that nothing is left over.

- **New test suite `f9_journal.php`** (34 checks), exhaustive where
  `f6_cortes.php` is only a sample. Killing processes is realistic but it
  samples: where the kill lands is luck. This one opens a real journal on a table
  spread across ten files and builds by hand **every** state the write could have
  been interrupted in — first file replaced and the rest not, first two, first
  three, all of them; the same with files truncated, and again with them deleted;
  each under both journal scopes. Sixty-six states, each demanding that every file
  return to its exact original bytes.

  It also pins the two invariants the scheme rests on: that the journal copies
  everything a write touches, across eleven kinds of write; and that writes which
  skip the journal really do touch one file. To tell whether a write journalled
  without guessing, it drops a *file* named `.tx` where the directory would go, so
  any attempt to journal fails and a write that succeeds is one that never tried.

  It also covers upgrading: a database in the old format is read without being
  written to, its first write produces the new files without reusing revision
  numbers, and a journal left pending by the previous version is undone.

- `f7_concurrencia.php` gained a reader running against a writer on a partitioned
  table, which is the case the shared table lock exists for.

## [1.10.1] - 2026-08-26

### Added

- **Data is flushed to disk before the rename.** Writes were atomic in the sense
  that the file was replaced in one step, but the contents could still be sitting
  in the operating system's cache: a power cut could leave the new file empty or
  half written even though the rename had already happened. Every write now
  flushes and calls `fsync()` before the rename. `fsync()` exists from PHP 8.1;
  on 8.0 the buffer is flushed, which is as far as that version goes.

- **The memory guard checks a file before reading it.** A file is materialised in
  one instruction, so checking every 512 rows never got the chance to intervene:
  a large table exhausted memory inside `json_decode()`. The size is now
  estimated before opening. It is a heuristic — how much data expands depends on
  its shape — so it narrows the window rather than closing it; closing it would
  mean reading in chunks instead of whole, which is a change to the storage
  layer.

- **Two tests looked for the log files by today's date**, which broke near
  midnight: the file is named by the API process, which sets the timezone from
  `config.php`, so it can already be on the next day relative to whoever checks.
  They now match by pattern. The engine behaved correctly; only the tests were
  time-dependent.

- **A partially deleted database is now reported.** `DROP DATABASE` ignored
  errors while removing files, so a permission problem could leave a directory
  half deleted, looking like it existed but unusable. It now says what could not
  be removed.

### Fixed

- **Several documents still described the engine as it was before 1.9.0.** An
  audit found the same kind of debt in more places than the locking section
  fixed above: the tables of unsupported features in `docs/02-consultas.md` and
  the README still listed **correlated subqueries, `INTERSECT`, `EXCEPT` and
  CTEs as unsupported**, when all four have worked since 1.9.0 — and the README
  contradicted itself, listing them as supported a few paragraphs earlier. Only
  `WITH RECURSIVE` is genuinely out.

  Also corrected: the README's Concurrency section still described the old
  database-wide lock; `docs/01-nucleo.md` had a "pending phases" section listing
  work finished long ago; the configuration table was missing six constants
  (`JSONSQLDB_CONEXION_DIRECTA`, the two memory ones, the journal one and the two
  collation ones); `MEMORIA` was missing from the list of error types; atomic
  writes were described without the `fsync` added in this same release;
  `composer.json` ran seven suites instead of nine; and `SECURITY.md` claimed to
  support "1.0.x".

- **The documentation still described the old locking.** Section 3 of
  `docs/01-nucleo.md` said the lock covered the whole database and that writes
  serialised against each other, which stopped being true in 1.9.0 and
  contradicted the section further down that describes the two levels. The same
  outdated sentence was in `Storage.php`'s header comment. Both corrected.

- **The memory guard aborted the query that came after a big one.** It was
  measuring `memory_get_usage(true)`, the memory PHP has requested from the
  operating system — which includes blocks that are already free and kept for
  reuse. After a large query that number stays high (28 MB reserved with only
  1.5 MB actually in use), so the next query, however small, was stopped before
  it began.

  It now measures the memory actually in use, which is what grows and eventually
  meets the limit. Verified at seven different memory limits, and the test now
  runs a second, small query after the abort — the exact case that failed.

  This is what broke CI on PHP 8.2 and above while passing on 8.0 and 8.1: how
  much the allocator keeps in reserve differs between versions.

- The guard also stops when **another jump the size of the last one would not
  fit**. Getting this right took three attempts, and each one failed on different
  PHP versions:
  - PHP doubles an array's hash table as it grows, so between two checks the
    usage can jump past the remaining margin.
  - The reserve is computed **per row checked, not per batch**: a single 4 KB row
    can take more than a whole batch of small ones.
  - Checks happen every 512 rows while there is room and every 8 once past half
    the limit. A check costs 0.01 µs, so tightening up where it matters does not
    show in the benchmarks.
  - The result-building loop was not being watched at all — with large rows the
    query died there, not in the join.

  Verified at eight memory limits with small rows and seven with 4 KB rows.

- **And underneath all of that, a safety net that does not depend on guessing
  right.** Predicting consumption is a heuristic — PHP allocates in bursts and
  how much differs between versions — so no estimate is infallible. The engine
  now sets aside two megabytes at the start and registers a shutdown function.
  If PHP does run out of memory, that function releases the reserve, which frees
  room to work, and reports it: the API answers with an ordinary JSON error
  instead of an empty or truncated body.

  Shutdown functions run even after a fatal error. That is the difference: the
  predictive guard stops earlier and with a better message, but the net works
  even when the guard does not. Registered regardless of
  `JSONSQLDB_MEMORIA_VIGILAR`, and there is a test that disables the guard on
  purpose to prove the net alone is enough.

## [1.10.0] - 2026-08-26

### Added

- **Memory guard.** A query whose result does not fit in `memory_limit` used to
  die with PHP's fatal error: not catchable, no `finally`, and the client got a
  broken response instead of a message. The engine now checks every 512 rows and
  stops at 85 % of the limit with an ordinary error (`sqlState` `MEMORIA`)
  explaining what happened and what to do. The query still fails — what does not
  fit does not fit — but the process stays alive and the API answers properly.
  Controlled by `JSONSQLDB_MEMORIA_VIGILAR` and `JSONSQLDB_MEMORIA_MARGEN`.
  - The engine deliberately does **not** raise `memory_limit` by itself. That
    limit exists so one request cannot take down the others; raising it silently
    would take a decision that belongs to whoever runs the server.

- **Faster reads.** Two optimisations that need no change to the storage format
  and no configuration:
  - **Fast path for simple comparisons.** A `WHERE column = value` (and `<>`,
    `<`, `<=`, `>`, `>=`) is now resolved without going through the general
    expression evaluator on every row. Measured over 2,000 rows: a primary-key
    lookup drops from 2.9 ms to 1.7 ms. `UPDATE` and `DELETE` use the same path.
  - **Early stop on `LIMIT`.** When a query has `LIMIT` and no `ORDER BY`,
    `GROUP BY`, `DISTINCT` or aggregates, scanning stops as soon as enough rows
    are found. `WHERE ... LIMIT 10` over 2,000 rows drops from 4.0 ms to 1.4 ms.
    With `ORDER BY` it cannot stop early, because the last row of the table might
    be the first of the result.

  Multi-row `INSERT` already existed and is by far the biggest lever: 2,000 rows
  in one statement take ~30 ms against ~5,000 ms as 2,000 separate statements,
  **180× faster**. It is now documented prominently, because a benchmark that
  inserts row by row measures the wrong thing.

### Fixed

- **`$externa` was used outside its scope** in two places added by the correlated
  subquery work in 1.9.0: the extra `ON` condition of a join and the `GROUP BY`
  grouping. It produced a PHP warning on every query that joined and grouped at
  the same time.

## [1.9.0] - 2026-08-26

### Added

- **Table-level write locks.** There are now two levels — the database and the
  table — always acquired in that order, which is what makes a deadlock
  impossible. Reads and single-table writes take a shared lock on the database;
  anything that can touch more than one table takes the exclusive one.
  - Two writes to different tables now run in parallel, and a write no longer
    blocks reads of other tables. This restores the behaviour originally
    specified: a `SELECT` should wait for writes **to its table**, not to the
    whole database.
  - The decision is deliberately suspicious: a foreign key, another table
    referencing this one, a trigger, or an `INSERT ... SELECT` all fall back to
    the database lock, which waits for every pending write on every table
    involved. When in doubt, the database lock — too much locking only costs
    parallelism, too little costs data.
  - `tests/f7_concurrencia.php` measures this with real simultaneous processes.

- **Correlated subqueries.** The subquery can now read columns from the enclosing
  query, which is how `EXISTS` is normally written:

  ```sql
  SELECT n FROM clientes c WHERE EXISTS (SELECT 1 FROM pedidos p WHERE p.cid = c.id);
  SELECT n, (SELECT SUM(total) FROM pedidos p WHERE p.cid = c.id) AS gastado FROM clientes c;
  ```

  Uncorrelated subqueries keep running once and being cached; correlated ones are
  cached per outer row, which is the most that can be reused.

- **`INTERSECT` and `EXCEPT`**, completing `UNION`. They chain with each other
  and with `UNION`, and the trailing `ORDER BY` and `LIMIT` still apply to the
  whole.

- **Common table expressions**: `WITH nombre AS (SELECT ...) SELECT ...`. Several
  can be declared at once and each can use the previous ones. `WITH RECURSIVE`
  is rejected with an explicit message.

- **The admin panel can restore a database from a ZIP backup**, the mirror of the
  export. It writes the engine's files directly, so it only appears when the
  panel and the API share a host; across machines the SQL dump is the way, as
  before.
  - Everything is validated before anything is touched: only `.json` files (plus
    `.htaccess` and `web.config`) are restored, table names are checked against
    the engine's own rule, and the contents must be valid JSON with the shape the
    engine expects.
  - **A ZIP containing a path that escapes the destination folder is rejected
    whole**, without writing anything. That is the classic attack against ZIP
    imports, and there is a test that builds such an archive and checks nothing
    lands outside.
  - The current contents are moved aside before writing, and put back if the
    restore fails halfway.

- **Optional read-only API key for the panel** (`ADMIN_API_KEY_LECTURA`). When
  set, the panel signs with it for users whose role is read-only, so the engine
  itself refuses writes: until now the only thing stopping a read-only user was
  a check in the panel's own code. Leaving it empty keeps the previous behaviour.

### Changed

- **`RAISE` now only accepts `ABORT`.** `FAIL`, `ROLLBACK` and `IGNORE` were
  parsed and then all treated as `ABORT`. `ROLLBACK` cannot mean what it means in
  SQLite because there are no multi-statement transactions, so promising it was
  wrong. They are rejected with a message explaining why.

- CI now also runs on **PHP 8.5**, the current stable branch, and runs the
  concurrency suite.

### Documentation

- **The documentation no longer claims to be "SQLite compatible".** It says what
  is true: the dialect resembles SQLite's and takes most of its decisions from
  it, but there are constructs SQLite has and this does not, others borrowed from
  MySQL, and concrete behavioural differences — all of them now listed. The
  biggest is type comparison: here a text compared against a number is converted
  (`'12abc'` is 12), whereas SQLite applies the declared column's affinity. That
  difference is deliberate, and this documentation is the reference, not
  SQLite's.

## [1.8.0] - 2026-08-25

### Added

- **`UNION` and `UNION ALL`.** All parts must return the same number of columns;
  the result takes its column names from the first part and the others contribute
  by position. A trailing `ORDER BY` and `LIMIT` apply to the whole union, not to
  the last part — at that point the source tables are gone, so the `ORDER BY`
  accepts result column names or positions (`ORDER BY 1`). Each part keeps its
  own `WHERE`, `GROUP BY` and joins.

- **`GROUP_CONCAT(col)` and `GROUP_CONCAT(col, separator)`.** Honours `DISTINCT`
  and skips `NULL`s like the other aggregates. Unlike SQLite, the concatenation
  order is defined: it follows the rows of the group.

- **`CONCAT(a, b, ...)`.** Does not exist in SQLite, where `||` does the job; it
  is here for people coming from MySQL, with MySQL's semantics — if any argument
  is `NULL`, the result is `NULL`.

- **`FULL JOIN` / `FULL OUTER JOIN`.** Brings the unmatched rows from both
  sides, padded with `NULL`s. Neither SQLite nor MySQL 5 have it.

- **`REGEXP` and `RLIKE`** (MySQL's operator; in SQLite `REGEXP` exists but has
  to be supplied by the host program). `NOT REGEXP` works too, `NULL` propagates
  as `NULL`, and it is **case-sensitive** — use `(?i)` at the start of the
  pattern for the opposite. An invalid pattern, or one so expensive that the
  regex engine gives up on a long text, produces a clear error instead of a
  hung process.

- **`CAST(expr AS type)`.** Accepts the same type names as `CREATE TABLE`,
  aliases included, and tolerates a length that it ignores
  (`CAST(x AS VARCHAR(10))`). `NULL` stays `NULL`, `INTEGER` truncates towards
  zero rather than rounding, and `DATETIME` validates the date instead of
  inventing one.

- **`EXISTS` and `NOT EXISTS`** are now implemented. The README listed them among
  the supported operators, but the parser did not know them: any query using
  `EXISTS` failed with a syntax error. They work with non-correlated subqueries.

### Fixed

- **`5 % 0.4` crashed the request** with PHP's `DivisionByZeroError` instead of
  returning a value. The zero check ran before the cast to integer, so a divisor
  like `0.4` passed it and then became `0`. Any query could trigger it, and the
  error escaped as a fatal rather than a controlled engine error. It now returns
  `NULL`, as SQLite does.

- **`SUBSTR` with index 0 returned one character too many.** In SQL position 0
  is the gap before the first character, so `SUBSTR('abcdef', 0, 3)` covers
  positions 0, 1 and 2, of which only two exist: the answer is `ab`, not `abc`.
  The window is now computed once and clipped once, which also fixed
  `SUBSTR('abcdef', 2, -2)` — the characters *preceding* a position.

- **`DATE(NULL)`, `TIME(NULL)` and `DATETIME(NULL)` returned the current date
  and time** instead of `NULL`. A `?? 'now'` was treating "no argument" and "a
  `NULL` argument" as the same thing. `DATE()` with no arguments still returns
  now, as it should.

- **Impossible dates were being silently corrected.** `DATE('2026-02-30')`
  returned the 2nd of March, `DATE('2026-13-40')` jumped to 2027, and
  `'0000-00-00'` produced a year -1. The check only validated the format with a
  regular expression and PHP then normalised the overflow. Dates are now checked
  against the calendar, so those are rejected — including when writing to a
  `DATETIME` column, which accepted them before.

- **The Actions workflow now declares `permissions: contents: read`.** Without an
  explicit block the `GITHUB_TOKEN` inherits the repository's permissions, which
  on older repositories means write access. Reported by CodeQL.

- **Static analysis clean.** The whole engine, API and panel now pass PHPStan at
  level 6 with no real findings. Fixing them turned up:
  - `Valor::aNumero()` added `0` to a string extracted by a regular expression.
    It always held digits in practice, but under PHP 8 a non-numeric string in
    that position raises a `TypeError`; the conversion is now explicit.
  - `Database::consultar()` used two variables that only one branch initialised.
    A new branch that set neither would have produced a PHP warning and a wrong
    log entry.
  - The API did not check that `$API_KEYS` exists before using it; an incomplete
    configuration produced a fatal error instead of a message.
  - One dead public method removed (`Catalog::exigirQueNoSeaVista()`): the same
    protection ended up implemented in `Writer::ejecutar()` and this one was never
    called from anywhere. Static analysis does not flag unused public methods, so
    it took a separate pass over the call graph to find it.
  - Three provably dead checks removed (`is_array` on a constant already tested
    with `defined`, `is_string` on a typed parameter, `array_values` on a list),
    and two impossible comparisons in date parsing.

- **Correlated subqueries now say so.** They were failing with
  `Columna desconocida: 'u.id'`, which sent you looking for a typo. The message
  now explains that correlated subqueries are not supported and suggests a
  `JOIN`. They are also listed in the "what is not supported" table.

### Documentation

- New section explaining **where each construct comes from**. The dialect is
  mainly SQLite's, but `CONCAT`, `REGEXP`/`RLIKE` and `LIMIT n, m` come from
  MySQL, and `CAST` and `FULL JOIN` are standard SQL. Anything not listed
  behaves as in SQLite.

- The Python client documents why its `HMAC-SHA256` is not the weak-password-
  hashing problem CodeQL reports: it signs a message with a shared key, which is
  exactly what HMAC is for. Panel passwords use bcrypt, which is the right tool
  for that job.

- `RIGHT JOIN` was listed as unsupported in an earlier draft of the docs. It has
  always worked; the table of unsupported features has been corrected. `FULL
  JOIN` is the one that is genuinely missing.

### Changed

- Tokens carry a precise type declaration (`@phpstan-type`), and the parser's
  state-dependent helpers are marked `@phpstan-impure`. This is what makes the
  static analysis able to follow the parser instead of guessing, so future
  analysis findings are real ones.
- The PHPStan configuration is **not** committed: it is a development tool and
  the project must need nothing beyond PHP itself. What does stay in the source
  are the `@phpstan-type` and `@phpstan-impure` annotations, which are ordinary
  PHPDoc, cost nothing at runtime, and are what makes such an analysis able to
  follow the parser instead of guessing.

## [1.7.0] - 2026-08-24

### Fixed

- **Unsupported SQL is now rejected instead of silently ignored.** These were
  parsed, accepted and then quietly dropped, so the statement looked correct and
  behaved as something else entirely:
  - `INSERT OR IGNORE` and `INSERT OR REPLACE` behaved as a plain `INSERT`, with
    no conflict handling at all.
  - `CREATE TEMP TABLE` and `CREATE TEMPORARY TABLE` created a **permanent**
    table. This was the dangerous one: data you believed was temporary stayed on
    disk.
  - `WITHOUT ROWID` was accepted and ignored.

  All four now raise a `SYNTAX` error explaining what to do instead. The rule
  from here on: **if a statement is accepted, it does exactly what it promises;
  otherwise it is rejected with a clear error.**

- **`RANDOM()` now returns a signed 64-bit integer**, like SQLite's `random()`.
  It was returning a roughly 32-bit range.

### Documentation

- New section in `docs/02-consultas.md` listing **what is not supported** and the
  behavioural differences from SQLite (`DECIMAL` as a rounded float, collation in
  `ORDER BY`, accent-sensitive `LIKE`), so the supported subset is stated rather
  than discovered.

## [1.6.0] - 2026-08-24

### Added

- **Crash recovery test with real process kills** (`tests/f6_cortes.php`). The
  existing journal tests build the `.tx` directory by hand and check that undoing
  works; this one kills a child process with `SIGKILL` while it is running a
  cascading `DELETE`, then reopens the database and requires it to be in one of
  the two valid states — the delete fully applied or fully undone. Anything in
  between is corruption. It reports how many kills landed inside the write
  window, so a run that never hit it says so instead of quietly passing.

### Fixed

- **The panel's ZIP backup no longer offers itself when the engine is on another
  machine.** It reads the engine's files straight from disk, so it only works
  when the panel and the API share a host. The panel now compares the host of
  `ADMIN_API_URL` with its own: if they differ, the button is hidden and the
  reason is explained, instead of silently copying whatever happened to be in the
  local `data/` directory. The SQL dump goes through the API and works between
  machines, as before.

## [1.5.0] - 2026-08-21

### Added

- **Direct engine access, off by default.** PHP code on the same server can use
  the engine without going through HTTP, once
  `JSONSQLDB_CONEXION_DIRECTA` is set to `true` in `config.php`. Until then, any
  attempt to instantiate `Database` outside the API is rejected with an explicit
  message.
  - **For experienced developers only**, and both `config.php` and the README say
    so plainly. A direct connection is always equivalent to an `admin` key —
    there is no way to restrict it to one database or to read-only — and it
    bypasses HMAC authentication, the rate limit, replay protection and the IP
    allow-list. Security becomes entirely the developer's responsibility.
  - Queries are still logged, exactly as through the API, with the `ip` field set
    to `"local"` since there is no HTTP request to take an address from.
  - Bound parameters still work and are still the only protection against
    injection.

- **`composer.json`,** so the project can be installed from Packagist:

  ```bash
  composer require miguelenred/jsonsqldb
  ```

  It gives PSR-4 autoloading for the `JsonSQLDB\` namespace, so
  `engine/bootstrap.php` is not needed. Its `require` section contains PHP itself
  and nothing else: **there are no dependencies to download**. Copying the folder
  and requiring `engine/bootstrap.php` by hand remains equally supported.

### Documentation

- The README no longer says "Composer is not used", which was accurate but
  confusing next to a `composer.json`. It now states the actual point: the
  project uses **no third-party libraries at all**, and Composer is merely an
  optional way to install it.

## [1.4.0] - 2026-08-21

### Added

- **The journal now covers data writes that touch more than one table**: a
  `DELETE` with `ON DELETE CASCADE`, an `UPDATE` with `ON UPDATE SET NULL`, or a
  trigger writing into another table. This closes the gap left open in 1.3.0.
  - Changes are accumulated in memory and flushed at the end, so at that point
    the engine knows exactly which tables are involved and opens the journal only
    when there are two or more.
  - Single-table writes are **not** journalled: that would mean copying the whole
    data file on every `INSERT`, and the atomic rename already covers them.
    Controlled by `JSONSQLDB_JOURNAL_DATOS`, `true` by default.
  - Measured: a cascading `DELETE` across 2,500 rows in two tables takes 3.3 ms
    with the journal in place.
  - Still not covered: grouping several statements into one unit of work. There
    is no `BEGIN`/`COMMIT`.

- **Python example client** (`api/cliente_ejemplo.py`), standard library only —
  no `pip`, no `requests`. Same bound parameters, same signature and the same
  certificate options as the PHP and PowerShell clients. Requires Python 3.7+.
  A test checks that Python and PHP compute the same token for an identical
  request, so the two can never drift apart.

### Changed

- **The global `HMAC_SECRET` is gone.** `hmac_secret` is now mandatory on every
  entry of `$API_KEYS`. In 1.3.0 it was optional, with `HMAC_SECRET` acting as a
  fallback; keeping both meant explaining a precedence rule in three places for
  no benefit. An account without `hmac_secret` cannot sign anything: the API
  answers "Configuración incompleta" naming the account and what it is missing.

### Upgrading from 1.3.0

Give every entry in `$API_KEYS` its own `hmac_secret`, remove the `HMAC_SECRET`
line, and make sure each client carries its account's secret. Nothing else
changes.

## [1.3.0] - 2026-08-20

### Added

- **Referential integrity check and repair.** `CHECK KEYS [FROM table]` reports
  rows whose foreign key points at a value that no longer exists in the parent
  table; `REPAIR KEYS [FROM table]` additionally sets those keys to `NULL` where
  the column allows it. The engine enforces foreign keys on every write, so this
  cannot happen through SQL — it happens when someone edits a `.json` by hand or
  restores one table's backup without the other.
  - **Never deletes rows.** A key in a `NOT NULL` or primary key column is
    reported and left alone: what to do with that row is your decision.
  - Reads straight from disk, bypassing the cache. The cache is invalidated by a
    revision counter that only moves when the engine writes, so a hand edit would
    otherwise stay hidden.
  - jsonSQLDBadmin has an **Integridad** screen with the report and a repair
    button. `CHECK` needs read permission, `REPAIR` needs write.

- **Crash-safe schema changes.** `CREATE TABLE`, `DROP TABLE` and every
  `ALTER TABLE` now run under a journal: the files they are about to touch are
  copied to `data/<database>/.tx/` first, along with a manifest. On success the
  directory is removed; if the process dies mid-operation the directory survives,
  and its presence is the signal that something did not finish — the next time
  the database is opened, the copies are restored and everything is back as it
  was.
  - The manifest is marked `COMMITTED` before the directory is deleted, so a
    crash in that last instant does not undo an operation that actually finished.
  - Checking for a pending journal costs one `stat` (half a microsecond), once
    per request, when the lock is taken.
  - Not covered: data writes spanning several tables, such as a `DELETE` with
    `ON DELETE CASCADE`.

### Changed

- **API keys are configured differently.** Entries in `$API_KEYS` are now keyed
  by account name, with the key itself in a `key` field, and the secret field was
  renamed from `secreto` to `hmac_secret`:

  ```php
  'My application' => [
      'key'         => '...',
      'permiso'     => 'escritura',
      'bases'       => ['mydb'],
      'hmac_secret' => '...',
  ],
  ```

  The old shape (keyed by the API key, with `nombre` and `secreto`) is **not**
  supported: rewrite the entries. Nothing else changes — the request format, the
  signature and the clients are the same.

## [1.2.0] - 2026-08-20

### Added

- **Views.** `CREATE VIEW [IF NOT EXISTS] name AS SELECT ...`, `DROP VIEW
  [IF EXISTS]` and `SHOW VIEWS`. A view is a stored `SELECT` that you query like
  a table; it holds no data and is resolved on every query, so it always returns
  current rows.
  - Anything a `SELECT` can do: joins, `GROUP BY`, `HAVING`, subqueries, and
    other views (up to 8 levels of nesting, which is what stops two views that
    reference each other from hanging the engine).
  - Read-only: `INSERT`, `UPDATE` and `DELETE` against a view are rejected with
    an explicit message, as is `DROP TABLE`.
  - A view cannot share a name with a table, or the other way round.
  - Stored in `data/<database>/_views.json` as the original `SELECT` text.
  - Views do **not** make anything faster. With no indexes, a view over a
    three-table join scans all three every time you query it. They exist to
    avoid repeating SQL, not to speed it up.
- **jsonSQLDBadmin** has a Views screen: list with the stored query, create,
  drop, and a jump to the SQL editor with the query prefilled.

### Documentation

- The README now states that the **first time the admin panel is opened it asks
  you to create the administrator account**. There is no default password and no
  factory user.

## [1.1.0] - 2026-08-20

Security release. Nothing here changes the SQL dialect or the on-disk format, but
**the defaults changed**: an installation that is upgraded without touching its
configuration becomes stricter. See "Upgrading" below.

### Security

- **Fixed a replay weakness.** The anti-replay nonce was
  `SHA256(timestamp | IP | token)`. Because the client IP is not covered by the
  HMAC signature, a captured request replayed from a different IP produced a
  different nonce and passed the check while the signature stayed valid. The
  nonce is now `SHA256(token)`, and the token is already unique per request.
- **Each API key can now have its own HMAC secret**, via a `secreto` field in
  `$API_KEYS`. With a single shared secret, any application holding it could sign
  requests impersonating another key — including the admin key — which made
  per-key permissions meaningless. `HMAC_SECRET` remains as a fallback for keys
  without one, so existing installations keep working.
- **Bound parameter values are no longer written to the query log** unless
  `JSONSQLDB_LOG_PARAMS` is enabled. Passwords, tokens and personal data travel
  in those values and the log is kept for 90 days.

### Changed — defaults

| Setting | Was | Now |
|---|---|---|
| `EXIGIR_HTTPS` | `false` | `true` |
| `ANTI_REPLAY_ACTIVO` | `false` | `true` |
| `RATE_LIMIT_ACTIVO` | `false` | `true` |
| `DEVOLVER_ERRORES` | `true` | `false` |
| `ADMIN_EXIGIR_HTTPS` | `false` | `true` |
| `TIME_LIMIT` | 1200 s | 60 s |
| `MEMORY_LIMIT` | 1 GB | 256 MB |

A query holds a PHP worker for the whole of `TIME_LIMIT`; with a handful of
workers, 20 minutes was a denial of service built out of legitimate requests.

### Fixed — documentation

- The README claimed the engine supports **transactions**. It does not: there is
  no `BEGIN`, `COMMIT` or `ROLLBACK`. Individual statements are atomic, but they
  cannot be grouped into a unit of work. The README now says so, and also warns
  that DDL is not crash-safe.

### Upgrading from 1.0.0

1. If you develop locally over `http://localhost`, set `EXIGIR_HTTPS` and
   `ADMIN_EXIGIR_HTTPS` to `false` in your configuration.
2. Give each API key its own `secreto` and update the matching client. For the
   admin key, the same value goes in `ADMIN_HMAC_SECRET` in the panel config.
3. If any query or export legitimately takes longer than 60 seconds, raise
   `TIME_LIMIT` for your case rather than leaving it at the old value.

### Known limitations, not yet addressed

- DDL is not crash-safe. A `RENAME TABLE` interrupted halfway can leave the table
  incomplete. A journal for multi-file operations is planned.
- No cost governor: an authorised caller can run an expensive query against a
  large table. `TIME_LIMIT` and `MEMORY_LIMIT` are the only brakes.
- Permissions are per database and operation type, not per table.

## [1.0.0] - 2026-08-20

First public release. Everything below is the starting point, not a change.

### Engine

- SQL parser and executor in plain PHP: `SELECT` with `DISTINCT`, `INNER`/`LEFT`/
  `CROSS JOIN`, `WHERE`, `GROUP BY`, `HAVING`, `ORDER BY`, `LIMIT`/`OFFSET`,
  subqueries and `CASE WHEN`.
- `INSERT`, `UPDATE`, `DELETE`, and DDL: `CREATE`/`DROP`/`ALTER TABLE`,
  `CREATE`/`DROP TRIGGER`, `CREATE`/`DROP DATABASE`.
- `SHOW DATABASES`, `SHOW TABLES`, `SHOW SCHEMA`, `SHOW COLUMNS`, `SHOW KEYS`,
  `SHOW TRIGGERS` for structure introspection from SQL.
- Constraints: primary keys (simple and composite), `AUTOINCREMENT`, `NOT NULL`,
  `UNIQUE`, `DEFAULT`, and foreign keys with `ON DELETE`/`ON UPDATE`.
- `BEFORE`/`AFTER` triggers on `INSERT`/`UPDATE`/`DELETE` with `WHEN`, `NEW.`,
  `OLD.` and `RAISE(ABORT, '…')`.
- `ALTER TABLE` can add, modify, rename and drop columns, add and drop unique and
  foreign keys, and add and drop the primary key of an existing table. Existing
  data is validated before any change is written.
- Configurable collation for `ORDER BY` (`JSONSQLDB_COLACION`): case- and
  accent-insensitive by default, with a per-language override map.
- Shared/exclusive file locking, atomic writes, and a per-query log.

### API

- Single signed HTTP endpoint. HMAC-SHA256 over API key, timestamp, SQL and
  parameters.
- Bound parameters: values travel separately and are inserted into the syntax
  tree, never concatenated into SQL text.
- Three permission levels (`lectura`, `escritura`, `admin`) per API key, each
  restricted to a list of databases.
- Optional protections, all off by default: IP allow-list with CIDR support,
  HTTPS enforcement, HSTS, replay protection, per-IP rate limiting, and
  suppression of detailed error messages.
- PHP and PowerShell example clients.

### jsonSQLDBadmin

- Web admin panel that talks to the engine exclusively through the API.
- Databases, tables, columns, keys, triggers and rows, all manageable from the
  browser, plus a SQL editor.
- Export to CSV, to `INSERT` statements, to a full SQL dump, or to a ZIP of the
  database files.
- Own users with `admin`/read-only roles, bcrypt passwords, session expiry,
  per-IP lockout, CSRF tokens and a daily audit trail.
- Bootstrap 5.3.3 and Bootstrap Icons bundled locally; no external requests.

### Deployment

- `.htaccess` (Apache) and `web.config` (IIS) shipped in every private folder.
- `nginx/` with equivalent rules and setup instructions.

### Tests

- 441 checks across seven suites, including a suite that drives the admin panel
  over real HTTP with cookies and CSRF tokens.

[2.9.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v2.9.0
[2.8.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v2.8.0
[2.7.4]: https://github.com/miguelenred/jsonsqldb/releases/tag/v2.7.4
[2.7.3]: https://github.com/miguelenred/jsonsqldb/releases/tag/v2.7.3
[2.7.2]: https://github.com/miguelenred/jsonsqldb/releases/tag/v2.7.2
[2.7.1]: https://github.com/miguelenred/jsonsqldb/releases/tag/v2.7.1
[2.7.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v2.7.0
[2.6.1]: https://github.com/miguelenred/jsonsqldb/releases/tag/v2.6.1
[2.6.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v2.6.0
[2.5.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v2.5.0
[2.4.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v2.4.0
[2.3.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v2.3.0
[2.2.1]: https://github.com/miguelenred/jsonsqldb/releases/tag/v2.2.1
[2.2.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v2.2.0
[2.1.1]: https://github.com/miguelenred/jsonsqldb/releases/tag/v2.1.1
[2.1.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v2.1.0
[2.0.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v2.0.0
[1.10.1]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.10.1
[1.10.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.10.0
[1.9.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.9.0
[1.8.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.8.0
[1.7.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.7.0
[1.6.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.6.0
[1.5.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.5.0
[1.4.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.4.0
[1.3.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.3.0
[1.2.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.2.0
[1.1.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.1.0
[1.0.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.0.0
