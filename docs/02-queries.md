# jsonSQLDB — Part 2: `SELECT` queries

A parser of its own (lexer + parser) and a `SELECT` executor over the JSON
files. The dialect **looks like SQLite's**, from which it takes most of its
decisions, but it is **not compatible with it**: some SQLite constructs do not
exist here, others come from MySQL, and there are specific behavioural
differences. All of that is in the two tables below. Do not assume that a
query that works in SQLite works here, nor the other way round.

## 1. Usage

```php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/engine/bootstrap.php';

use JsonSQLDB\Database;

$db = new Database('mydb');

$rows = $db->consultar("
    SELECT city, COUNT(*) AS customers
    FROM   users
    WHERE  joined >= ?
    GROUP  BY city
    HAVING COUNT(*) > 1
    ORDER  BY customers DESC
", ['2026-01-01']);
```

Returns an array of associative rows. The SQL can be **multi-line** and carry
`--` or `/* */` comments. `Database::consultar()` takes care of locking (shared
read lock) and of logging it as `JSONSQLDB_LOG_NIVEL` says (by default
`'escrituras'`: a `SELECT` is logged only when it fails; see
[01-core.md](01-core.md#query-log)).

The second argument holds the values of the `?`: they are inserted into the
statement tree already parsed, never into the SQL text, so a value cannot alter
the query. See [04-api.md §1.1](04-api.md).

### UNION

```sql
SELECT name FROM customers
UNION
SELECT company_name FROM companies
ORDER BY 1;
```

`UNION` removes duplicate rows; `UNION ALL` keeps them and is faster because it
compares nothing.

- Every part must return **the same number of columns**; otherwise it is
  rejected saying how many each returns.
- **Column names come from the first part.** The others contribute their values
  by position, whatever their columns are called.
- The final `ORDER BY` and `LIMIT` apply **to the whole set**, not to the last
  part. At that point the source tables no longer exist, so `ORDER BY` only
  accepts names of result columns (in upper or lower case alike, 2.9) or their
  position (`ORDER BY 1`).
- Each part keeps its own `WHERE`, `GROUP BY` and `JOIN`.

### Where each thing comes from

The dialect is **mainly SQLite's**, the lightweight engine this project
resembles. A few constructs come from elsewhere, because they are what people
expect to find:

| Construct | Origin | Note |
|---|---|---|
| `\|\|` to concatenate | SQLite, standard | The native form here. Since 2.9 it binds as in SQLite, tighter than `* / %` and `+ -`: `1 + 2 \|\| 3` is `1 + '23'` = 24 (before, `'33'`, as in PostgreSQL). Views, triggers, `CHECK`s and `DEFAULT`s saved by an earlier version are read with the new rule: `'A' \|\| n + 1` now means `('A' \|\| n) + 1`; write `'A' \|\| (n + 1)` where the old meaning is wanted |
| `CONCAT(...)` | MySQL, SQL Server | As in MySQL, if any argument is `NULL`, the result is `NULL` (SQLite, since 3.44, skips the `NULL`s) |
| `GROUP_CONCAT(col, sep)` | SQLite and MySQL | The separator as the second argument, as in SQLite, or with MySQL's `SEPARATOR sep` (2.9). `GROUP_CONCAT(col, sep ORDER BY x DESC)` orders inside each group (2.7.3), as MySQL and SQLite 3.44 |
| `REGEXP` / `RLIKE` | MySQL | In SQLite `REGEXP` exists but must be provided separately. Here it just works |
| `LIMIT n, m` | MySQL | `LIMIT n OFFSET m`, from SQLite, works too |
| `IFNULL` | SQLite and MySQL | `COALESCE` is the standard and is there as well |
| `CAST(x AS type)` | Standard | Accepts the `CREATE TABLE` types and their aliases. `BOOLEAN` is `INTEGER`, as in SQLite: `CAST(2 AS BOOLEAN)` is 2. `DATETIME` takes `YYYY-MM-DD` with an optional time `HH:MM[:SS[.fff]]` after a space or a `T` |
| `FULL JOIN` | Standard | Not in MySQL; in SQLite since 3.39 |
| `AUTOINCREMENT` | SQLite | MySQL's `AUTO_INCREMENT` is accepted too |

If something appears neither in this list nor in the next table, it most likely
behaves as in SQLite, but **this documentation is the reference**, not
SQLite's: where the two disagree, this one wins.

### Writing queries that run fast

`EXPLAIN SELECT …` (2.9; also `EXPLAIN ANALYZE` and `EXPLAIN QUERY PLAN`) tells
how each table was read. Here the index is chosen while reading, from the data
itself (an index whose rows are spread over most of the table is not worth
using), so `EXPLAIN` runs the query, without the result cache and without
keeping the rows, and reports what it did: one row per read with `paso`, `tabla`,
`acceso` (`índice (=)`, `índice (IN)`, `índice (parte de la clave)`,
`índice (parte de la clave, en las partes sin NULL)` (2.10), `índice, rango`,
`índice, en orden`, `recuento, sin leer filas`, `recorrido`…), `indice` and
`filas`, the number of rows that step returned. Only `SELECT`; a read-only key can use it.


Without an index a lookup scans the table. Three things avoid or cheapen that,
all automatic, and it pays to write with them in mind.

**Indexes.** The primary key and the `UNIQUE`s have theirs from creation; the
rest are added with `CREATE INDEX`. They are used on equalities and `IN`
against literals in the top-level `AND` chain of the `WHERE`: `WHERE city =
'Elche' AND age = 30` yes, `WHERE city = 'Elche' OR age = 30` no, and nothing
under a `NOT`, no `IS NULL`, `NOT IN` or `LIKE`. Since 2.9 also on ranges
(`<`, `<=`, `>`, `>=`, `BETWEEN`) on the first column of an index, when the
rows they match are in few parts, and for `ORDER BY column [DESC] LIMIT n` on
a single table with no `WHERE`, when the column is numeric (since 2.10 also a
`TEXT` column without a `COLLATE` of its own). A composite index is
used left to right: one on `(city, age)` serves a lookup by `city`, or by both,
but not by `age` alone; and by `city` alone fully only when `age` is `NOT
NULL`, since a row with `NULL` in any column of an index is not in it: otherwise
it serves the parts where every row is in it, and reads the others (2.10). Details in
[03-writes.md](03-writes.md).

**Simple comparisons.** A `WHERE column = value` — and the same with `<>`, `<`,
`<=`, `>`, `>=` — is resolved without going through the general expression
evaluator. Wrapping the comparison in something more complex (`OR`, functions,
parentheses with other conditions) disables the shortcut.

**Early cut by `LIMIT`.** If the query has `LIMIT` and **no** `ORDER BY`,
`GROUP BY`, `DISTINCT` or aggregates, the engine stops reading as soon as it has
the rows you asked for. With `ORDER BY` it cannot, because the last row of the
table could be the first of the result, unless an index gives that order (see
above).

**And the one that makes the biggest difference: batch your `INSERT`s.** Every
write statement rewrites the last part of the table, its revision file and its
indexes, so two thousand loose `INSERT`s do that two thousand times. The same
load in one statement with many `VALUES` does it once:

```sql
-- 2,000 statements: seconds
INSERT INTO customers (name) VALUES ('Ana');
INSERT INTO customers (name) VALUES ('Luis');
...

-- one statement: milliseconds
INSERT INTO customers (name) VALUES ('Ana'), ('Luis'), ...;
```

With bound parameters from PHP:

```php
$vals = []; $params = [];
foreach ($rows as $r) { $vals[] = '(?, ?)'; $params[] = $r['name']; $params[] = $r['city']; }
$cli->consultar('INSERT INTO customers (name, city) VALUES ' . implode(',', $vals), $params);
```

Mind the API limit: `MAX_PARAMS` is 1,000 parameters per request, so for large
loads go in batches of a few hundred rows.

## 1.1. What is NOT supported

The project's rule is simple: **if a statement is accepted, it does exactly
what it promises. If it cannot be done, it is rejected with a clear error.**
Nothing is accepted and silently ignored, because that leaves the programmer
believing in a guarantee they do not have. The few statements accepted without
doing anything (2.9) are those whose promise already holds here: `ANALYZE`
(the planner keeps no statistics), `USE` of the database already in use (any
other is an error: each connection and each API request goes to one),
`SELECT … FOR UPDATE`, `FOR SHARE [OF t] [NOWAIT | SKIP LOCKED]` and
`LOCK IN SHARE MODE` (every read already sees one whole state), and MySQL's
table options `ENGINE`, `CHARSET`, `COLLATE`, `ROW_FORMAT` and `COMMENT`
([Part 3](03-writes.md)).

These constructs exist in SQLite and **raise an error** here:

| Construct | Why, and what to do |
|---|---|
| `INSERT` / `UPDATE` `OR FAIL`, `OR ROLLBACK` | Every statement here is all or nothing. Use `OR IGNORE`, `OR REPLACE` or `ON CONFLICT` (2.8; `UPDATE OR IGNORE` and `OR REPLACE` since 2.10) |
| `CREATE TEMP TABLE` / `TEMPORARY` | No temporary tables. Create a normal one and `DROP TABLE` it |
| `WITHOUT ROWID` | There is no `rowid`: rows are JSON objects and the key is the one you declare |
| `BEGIN` / `COMMIT` / `ROLLBACK` | Only at the start and the end of a batch, which is already a transaction (2.9, [Part 3](03-writes.md)); a transaction never spans requests |
| `ALTER TABLE` on the primary key | Handled with `ADD`/`DROP PRIMARY KEY`, and `AUTOINCREMENT` only at creation |
| `VACUUM INTO 'file'` | An error that says how to make a copy (the panel's ZIP or SQL dump). `VACUUM` alone is supported (2.9) |
| `PRAGMA`, `sqlite_master`, `information_schema` | Not taken, on purpose: they belong to each engine's internals. Use `SHOW TABLES`, `SHOW COLUMNS`, `SHOW CREATE TABLE`… (since 2.10 the error says so; a query reads only its own database, so a schema before a table name is an error too, except `main.`) |
| `LOG(x)` with one argument | Base 10 in SQLite and natural in MySQL: an error that says to use `LN(x)` or `LOG10(x)` |
| MySQL's compact dates (`'950501'`) | In SQLite that is a Julian day; here dates are written `YYYY-MM-DD`: storing it in a date column or `CAST` is an error, and the date functions give `NULL` |

And these behavioural differences are worth keeping in mind:

- `DECIMAL` is rounded floating point, not exact decimal.
- `ORDER BY` uses the configured collation, not binary order, unless you
  change it.
- `LIKE` only ignores the case of `A` to `Z`, as in SQLite (2.10): `'É' LIKE
  'é'` is 0. Before 2.10, with UTF-8, it was 1.
- `UPPER` and `LOWER` change accented letters too (`UPPER('ñandú')` is
  `'ÑANDÚ'`), as MySQL, PostgreSQL and Access do, so that a view or a query
  brought from them gives the same here; SQLite changes only `A` to `Z`.
- `SUM` of integers that does not fit in 64 bits is an error ("integer
  overflow"), as in SQLite (2.10; before, an approximate decimal without
  warning). A text that is an integer (`'12'`) counts as an integer; with any
  other value, before or after, `SUM` adds in decimal and does not fail, as
  SQLite 3.45 does. `TOTAL` and `AVG` always add in decimal. They add as
  SQLite does (2.10): integers exact until the first decimal or until they no
  longer fit, and from there everything in the order it comes, keeping the
  error of each sum apart (the compensated sum SQLite uses since 3.43; before,
  `TOTAL` of 1e100, 3 and -1e100 gave 0, now 3), so the figures are SQLite's,
  bit for bit, also the `NULL` SQLite 3.45 gives when the last value makes the
  sum overflow to infinity, and through the disk. In a window whose frame
  does not start at `UNBOUNDED PRECEDING`, each frame is added with its own
  values, while SQLite carries what it saw before in the partition, so there
  the two can differ: never with a wrong number here, which SQLite can give in
  some frames; and a frame of integers that do not fit in 64 bits is the
  overflow error here, while SQLite gives a decimal if one was in an earlier
  frame.
- An integer and a decimal compare exactly, also past 2^53, as in SQLite
  (2.10; PHP turns the integer into a decimal, and `9007199254740993 >
  9007199254740992.0` was false): in `WHERE`, `ORDER BY`, `MIN`, `MAX`,
  `GROUP BY`, `DISTINCT`, joins and windows. An index gives a whole decimal
  the key of that integer in the whole range, up to 2^63 (2.10; before, past
  9·10^18 they had two keys: an equality with such a value did not use the
  index, and a foreign key between an `INTEGER` and a `REAL` column with such
  values failed).
- Texts and numbers compare as in SQLite (2.10), with the affinity of the
  column: two texts compare as texts, byte by byte, also when they are numbers
  (`'7' = '007'` is false and `'10' < '9'` is true); a number compared with a
  `TEXT` column is taken as its text (`codigo = 7` finds `'7'`, not `'007'`),
  and a text that is a number compared with a numeric column as that number
  (`edad = '30'` finds 30). Two differences with SQLite are kept on purpose:
  a number and a text that is a number with no column on either side
  (literals, parameters, expressions) compare as numbers here (`'7' = 7` is
  true; in SQLite, false), so that a parameter bound as text still finds a
  number; and in `x IN (…)` with a list each value is compared as in its own
  `x = value` (SQLite applies the affinity of `x` to the whole list: with a
  text column, `7 IN (codigo, 'zz')` finds `'7'` here and nothing there). A
  text that is not a number (`'12abc'`, `'1a'`, `'-x'`) never equals a number,
  and sorts after every number (see section 4). Only arithmetic and `CAST` take
  its leading number (`'12abc' + 0` and `CAST('12abc' AS INTEGER)` are 12, as
  in SQLite). Until 2.9 a text that was a number compared as that number
  everywhere: `'7' = '007'` was true, and a `UNIQUE` text column took them as
  duplicates.
- `ROUND` rounds away from zero (`ROUND(2.5)` = 3), like SQLite. But
  `ROUND(2.675, 2)` gives 2.68 here and 2.67 in SQLite: 2.675 does not exist
  exactly in floating point and each engine breaks the tie its own way. If the
  cent has to add up, store cents in an `INTEGER`.
- `AVG` always returns a decimal, as SQLite and MySQL do (2.7.2).
- An integer literal that does not fit in 64 bits is read as a decimal, as in
  SQLite (`SELECT 18446744073709551615` gives `1.8446744073709552E+19`), and
  `-9223372036854775808` is the smallest integer, exactly (2.7.3). Before, it
  was silently clipped to 9223372036854775807. Stored in an `INTEGER` column it
  is an «out of range» error, as it already was through a parameter.
- `/` always divides exactly, as in MySQL: `7 / 2` is `3.5`. SQLite divides
  two integers as integers and gives 3. For the integer part, use
  `CAST(7 / 2 AS INTEGER)`.
- `%` works with integers, as in SQLite: if the divisor truncates to zero
  (`5 % 0.4`), the result is `NULL`.
- `ORDER BY 2` orders by the second column of the result, and a `HAVING` can
  use the aliases of the `SELECT` (`HAVING n > 3` with `COUNT(*) AS n`), as in
  SQLite and MySQL (2.7.1).
- Without `ORDER BY`, the rows of a table come in its own order, the one they
  went in (a row whose key changes keeps its place), and SQLite, reading a
  whole table, gives them by its integer primary key. SQL leaves that order
  open in both: write the `ORDER BY` where it matters, also in an `INSERT …
  SELECT` that takes new ids, which are given in the order the rows come.

## 2. Supported syntax

| Clause | Detail |
|---|---|
| `SELECT` | columns, expressions, `*`, `table.*`, `DISTINCT`, `AS` (the `AS` is optional) |
| `FROM` | tables, aliases, subqueries (`FROM (SELECT ...) t`; the alias can be left out, as in SQLite, 2.9) |
| `JOIN` | `INNER`, `LEFT [OUTER]`, `RIGHT [OUTER]`, `FULL [OUTER]`, `CROSS`, comma (also followed by `ON` or `USING`, as in SQLite, 2.10); `ON` with any condition; `USING (a, b)` and `NATURAL [INNER \| LEFT \| RIGHT] JOIN` on tables (2.9, not with `FULL` or `CROSS JOIN`, an error: the common columns appear once in `SELECT *`, and without a table in front they are not ambiguous; with a view or a subquery, write the `ON`) |
| `WHERE` | any expression |
| `GROUP BY` | one or more expressions, or the number of an output column (`GROUP BY 1`, 2.9) |
| `HAVING` | with or without `GROUP BY` |
| `ORDER BY` | expressions, output aliases or column numbers (`ORDER BY 2`), `ASC`/`DESC`, several keys; a name written alone that is an `AS` alias of the `SELECT` is that alias even if a column has the same name (`SELECT -saldo AS saldo … ORDER BY saldo` sorts by `-saldo`; `t.saldo` is still the column), as in SQLite, MySQL and PostgreSQL (2.9); `NULLS FIRST` / `NULLS LAST` (2.9; by default `NULL` goes first in `ASC` and last in `DESC`) and `COLLATE NOCASE` (see below) |
| `LIMIT` | `LIMIT n`, `LIMIT n OFFSET m`, `LIMIT m, n`, `LIMIT ALL`. Since 2.9, as in SQLite: a constant expression (`LIMIT 10 * 2`), a negative `LIMIT` means no limit (`LIMIT -1 OFFSET 20`) and a negative `OFFSET` is 0; `LIMIT 9223372036854775807`, what some ORMs send for "no limit", works with any `OFFSET`. `UPDATE` and `DELETE` take `ORDER BY` and `LIMIT` too ([Part 3](03-writes.md)) |
| `WITH` | CTEs, also with a list of column names (`WITH t(a, b) AS …`) and recursive (2.9, see below) |

Operators: `= == <> != < <= > >=`, `AND OR NOT`, `IN`, `NOT IN`, `BETWEEN`,
`NOT BETWEEN`, `LIKE ... [ESCAPE c]`, `NOT LIKE`, `REGEXP`, `IS NULL`,
`IS NOT NULL`, `+ - * / %`, `||` (concatenation). Since 2.9, also those of
SQLite and MySQL that compare `NULL` as one more value — `a IS b`, `a IS NOT b`,
`a IS [NOT] DISTINCT FROM b`, `a <=> b` —, the bit operators `& | ~ << >>`
(as in SQLite, between the comparisons and `+ -`), and MySQL's `DIV` (integer
division towards zero; `NULL` when dividing by 0), `MOD` (as `%`) and `XOR`
(between `OR` and `AND`, as in MySQL). Also `x [NOT] GLOB pattern` (2.9, as in
SQLite: `*`, `?` and `[…]`, telling case apart; inside `[…]`, `^` first
negates, a `]` right after `[` or `[^` is one of the characters, `a-z` is a
range, and a `[` that is never closed matches nothing) and `x -> path` / `x ->> path`
(2.9, as in SQLite and MySQL: the piece of a JSON text as JSON, or as a value;
`col -> 'a'` and `col -> 2` stand for `$.a` and `$[2]`; a negative or decimal
number points at nothing and gives `NULL`, as in SQLite; until 2.10, an error).
From tightest to loosest, as in SQLite: unary `- + ~`, `COLLATE`,
`|| -> ->>`, `* / % DIV MOD`, `+ -`, `& | << >>`, `< <= > >=`, then `= == <>
!= IS IN LIKE GLOB REGEXP BETWEEN`, `NOT`, `AND`, `XOR`, `OR`. Until 2.9 the
comparisons shared one level, and `0 = 1 < 2` was 1 where SQLite gives 0
(2.10). What follows `IS` is a whole expression: `1 IS NULL + 1` is
`1 IS (NULL + 1)`.

**`COLLATE`** (2.9, as in SQLite) goes after an expression: `x COLLATE NOCASE`
compares and sorts ignoring the case of A–Z (only those, as SQLite: `Ñ` and `ñ`
are still different), `COLLATE RTRIM` ignores the spaces at the end, and
`COLLATE BINARY` is the usual comparison. It works in `=`, `<>`, `<`, `>`…, in
`IN`, `BETWEEN`, `CASE x WHEN`, `ORDER BY`, `GROUP BY` and `DISTINCT`, and
inside an aggregate or a window function: `COUNT(DISTINCT x COLLATE NOCASE)`,
`MIN(x COLLATE NOCASE)`, `GROUP_CONCAT(DISTINCT x COLLATE NOCASE)`; if one
side of a comparison has it, it applies to both, also in `IS` (2.10: `'a' IS
'A' COLLATE NOCASE` was 0). On its own (`SELECT x COLLATE NOCASE`) the value
does not change. A column can have one too (2.9, below), and keeps it through
a view or a subquery that takes it with `SELECT *` (2.10: it was lost there).
In `ORDER BY`, a collation is applied to the values and then they are sorted
in the configured order (`JSONSQLDB_COLACION`, section 4), but `ORDER BY x
COLLATE BINARY` sorts byte by byte whatever that setting (2.10: with the
default, `'general'`, it gave `A, a, B, b`), and `ORDER BY 2 COLLATE NOCASE`
applies it to the second column (2.10: it was left out).

### COLLATE in a column (2.9)

A text column can be declared with `COLLATE NOCASE` or `COLLATE RTRIM`:
`email TEXT COLLATE NOCASE` (`BINARY` is the usual comparison and is not
stored). The value is kept as it was written; what changes is how the column
compares, as in SQLite, and `tests/f21_compatibilidad.php` checks it against
SQLite's own results:

- In `=`, `<>`, `<`, `>`…, `IN`, `BETWEEN`, `CASE col WHEN`, `ORDER BY`,
  `GROUP BY`, `DISTINCT`, `COUNT(DISTINCT …)`, `MIN` and `MAX`, the
  `PARTITION BY` and `ORDER BY` of a window, `NULLIF`, and `MIN`, `MAX`,
  `GREATEST` and `LEAST` of several values (of two equal ones, `MIN` gives the
  last and `MAX` the first, as in SQLite). `LIKE` stays as it is: it already
  ignores case.
- Which collation a comparison uses follows SQLite's rules: one written with
  `COLLATE` wins (the first from the left, also inside an expression:
  `b || 'x' COLLATE NOCASE`); if not, the column on the left, even if it has
  none —that is, binary—; and if the left is not a column, the one on the
  right. `CAST(col AS …)` is still that column. In `x IN (SELECT y …)`, when
  `x` does not decide it, `y` does.
- `UNIQUE`, `PRIMARY KEY`, indexes and foreign keys use it: with `NOCASE`,
  `'ana'` and `'ANA'` are a duplicate, an index on the column finds both, and
  a foreign key that points to the column accepts `'abc'` for `'ABC'` (the
  collation is the parent column's). A `JOIN` uses an index only if it has the
  collation the comparison uses, or if the comparison is binary.
- A view, a subquery in `FROM`, a `WITH` query (also a recursive one) and the
  columns of a `UNION`, `INTERSECT` or `EXCEPT` keep the collation of the
  column they come from; in a compound, that of the first part from the left
  that has one, which is also the one that decides which rows are repeated.
- Of several values that are equal, `DISTINCT`, `GROUP BY` and `UNION` show the
  first one found. SQLite does not say which one, and sometimes shows another.
- `NOCASE` only folds A–Z, as in SQLite: `'Ñ'` and `'ñ'` are still different.
  `RTRIM` ignores the spaces at the end.
- `ORDER BY` first applies the column's collation and then sorts with the
  configured order (`JSONSQLDB_COLACION`, section 4).

`ALTER TABLE … MODIFY` adds, changes or removes it: the indexes are rebuilt
with it, and the change is refused if a `UNIQUE` or the primary key would end
up with repeated values, or if a foreign key that points to the column would
lose its row (with `BINARY`, `'abc'` no longer finds `'ABC'`). `ADD UNIQUE`,
`ADD PRIMARY KEY`, `ADD FOREIGN KEY` and `CHECK KEYS` take it into account.
`SHOW CREATE TABLE` writes it and `SHOW COLUMNS` gives it in `colacion`
(`NOCASE`, `RTRIM` or `null`). It only goes on text columns, and any other
name (`utf8mb4_general_ci`…) is an error here; the panel's importer translates
them ([Part 5](05-admin.md)).

### WITH RECURSIVE (2.9)

A query of the `WITH` that names itself is recursive, with or without the
word `RECURSIVE` (as in SQLite): first the parts that do not name it, then
the ones that do, joined with `UNION` or `UNION ALL`.

```sql
WITH RECURSIVE cnt(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM cnt WHERE n < 100)
SELECT n FROM cnt;

-- everyone under employee 2, with their level
WITH RECURSIVE sub(id, name, level) AS (
  SELECT id, name, 0 FROM staff WHERE id = 2
  UNION ALL
  SELECT s.id, s.name, sub.level + 1 FROM sub JOIN staff s ON s.boss = sub.id)
SELECT * FROM sub ORDER BY level, name;
```

- It is computed **in batches**, as PostgreSQL and MySQL do: each round gives
  the recursive parts all the rows of the previous round as the table, until
  no new row comes out. SQLite goes row by row, so without `ORDER BY` the
  rows can come out in another order; they are the same rows.
- With `UNION`, rows already produced are dropped, so a cycle ends. With
  `UNION ALL`, the recursion ends when its `WHERE` or its `LIMIT` says so.
  As in SQLite there is no limit of rounds: a recursion that never ends is
  stopped by the memory net or PHP's time limit, with their error.
- `LIMIT` and `OFFSET` of the query of the `WITH` stop the recursion once
  enough rows have come out; the rows the `OFFSET` skips still recurse.
- What would give something else in batches than row by row is an error that
  says so: an aggregate, `GROUP BY` or `DISTINCT` in a recursive part, the
  query on the side of a `LEFT`, `RIGHT` or `FULL JOIN` that is filled with
  `NULL`, naming it twice or in a subquery, and `ORDER BY` inside it (in
  SQLite it decides the order of the queue): sort in the outer query. Two
  queries of the `WITH` that name each other are also an error.
- Each round is a `SELECT`: with the query first in the `FROM` and an index
  on the other side of the `JOIN`, each row is looked up by key. The chain of
  14 bosses of an employee on 20,000 rows: 3 ms written `FROM chain JOIN staff
  s ON s.id = chain.boss`, 198 ms the other way round (it reads the table in
  each round). 10,000 rounds of `cnt`: 109 ms.
- A `WITH` can also go inside a subquery: `x IN (WITH … SELECT …)`.

### Window functions (2.9)

All of SQLite's, checked against it: `ROW_NUMBER`, `RANK`, `DENSE_RANK`,
`PERCENT_RANK`, `CUME_DIST`, `NTILE(n)`, `LAG(x [, n [, default]])`,
`LEAD(…)`, `FIRST_VALUE`, `LAST_VALUE`, `NTH_VALUE(x, n)`, and any aggregate
with `OVER` (also with `FILTER (WHERE …)`).

```sql
SELECT day, amount,
       SUM(amount) OVER (ORDER BY day) AS running,
       AVG(amount) OVER (ORDER BY day ROWS BETWEEN 6 PRECEDING AND CURRENT ROW) AS avg7,
       amount - LAG(amount, 1, 0) OVER (ORDER BY day) AS change
FROM sales;

SELECT * FROM (SELECT customer, total,
                      RANK() OVER (PARTITION BY customer ORDER BY total DESC) AS r
               FROM orders) t
WHERE r = 1;                                   -- the largest order of each customer

SELECT city, SUM(total), RANK() OVER w FROM orders GROUP BY city WINDOW w AS (ORDER BY SUM(total) DESC);
```

- The window: `PARTITION BY`, `ORDER BY` (with `NULLS FIRST`/`LAST`) and a
  frame `ROWS`, `RANGE` or `GROUPS` `BETWEEN … AND …` with `UNBOUNDED
  PRECEDING`, `n PRECEDING`, `CURRENT ROW`, `n FOLLOWING` and `UNBOUNDED
  FOLLOWING`, and `EXCLUDE NO OTHERS`, `CURRENT ROW`, `GROUP` or `TIES`.
  Without a frame, as in SQL: from the start of the partition to the last row
  equal to the current one in the `ORDER BY` (the whole partition without
  `ORDER BY`). With `ROWS` and `GROUPS`, `n` is an integer that fits in 64
  bits (2.10: `9e999` was 0). `RANGE` with a distance needs a single expression in the
  `ORDER BY`; a `NULL` or a text (also one like `'5'`, which sorts after the
  numbers) has no distance, and its frame is its peers. The distance goes with SQLite's arithmetic, row by row (2.10): `n PRECEDING`
  adds `n` to the value of each row and compares it with the current one, and
  `n FOLLOWING` compares each row with the current value plus `n`; an integer
  plus an integer is an integer, anything else a decimal, which past 2^53 is
  rounded (`9007199254740992 + 1.0` is `9007199254740992.0`, and the row at
  1.0 is left out, as in SQLite). The frame starts at the first row that
  reaches its bound and ends before the first one that passes it, and peers
  have the frame of the first of them. A `NULL` or
  a text sorts before or after every number, and is in the frames that reach
  it (`UNBOUNDED PRECEDING AND 1 PRECEDING` takes the `NULL`s before the
  numbers). With an infinite distance (`9e999`) and infinite values, -INF +
  INF is `NULL`, as in SQLite, and compares as its `NULL`s do; with both bounds
  `PRECEDING` the frame does not go past the row's peers, as there. With
  values past 2^53, or infinite ones, SQLite can give a row the frame of the
  one before it, or more rows than the partition has; here each row has its
  own.
- Named windows: `WINDOW w AS (…)`, `OVER w`, and `OVER (w ORDER BY …)` or
  `OVER (w ROWS …)` building on one, with SQLite's rules (a window that
  builds on another cannot change its `PARTITION BY`, nor its `ORDER BY` if
  it has one, nor extend one with a frame).
- They go in the columns of a `SELECT` and in its `ORDER BY`, after `WHERE`,
  `GROUP BY` and `HAVING`: with `GROUP BY` they work on the groups, and an
  aggregate can go inside (`SUM(SUM(x)) OVER ()`). Elsewhere, or one inside
  another, `DISTINCT` inside the parentheses, and what SQLite rejects, are
  errors that say so.
- Without an `ORDER BY` in the query, the rows come out in the order the
  first window leaves them, as in SQLite.
- They need all the rows: a `LIMIT` does not stop the reading early. Running
  sums and the like (a frame that starts or ends at the edge of the
  partition) are accumulated as the frame advances: a running sum, a ranking
  per partition and a moving average over 20,000 rows take about 100 ms
  each. Other frames go through their rows for each row.
- Of each row, only the columns the query uses are kept until the windows
  are worked out, without the rest of its context (2.10; they kept the whole
  row, and a context of the same size beside it). On 30,000 customers of
  seven columns, `ROW_NUMBER() OVER (PARTITION BY ciudad ORDER BY saldo)`
  peaks at 24.1 MB instead of 40.8, `SUM(saldo) OVER (ORDER BY id)` at 16.5
  instead of 35.2, and with rows of 500 characters that are not used, 11 MB
  instead of 30.3 (`tests/f8_indices.php`).

A statement may end in one or more `;` (2.9). Identifiers with spaces: `"my field"`, `[my field]` or `` `my field` ``. A
column can be named that way (2.8); tables, indexes and triggers cannot. A
column without `AS` is named after its expression when it is a column, a
literal, a function or an operator: `SELECT id + 1 FROM t` returns the key
`"id + 1"` (SQLite writes it as it was typed, `id+1`). A `CASE` is named
`CASE`, a subquery `subconsulta`, and the rest (`CAST`, `IS NULL`, `BETWEEN`,
`IN`, `LIKE`, `EXISTS`…) `expr`, `expr_2`…: give them an `AS`.
`CREATE TABLE … AS SELECT` is in [Part 3](03-writes.md).

## 3. Functions

| Group | Functions |
|---|---|
| Aggregates | `COUNT(*)`, `COUNT(x)`, `COUNT(DISTINCT x)`, `SUM`, `AVG`, `MIN`, `MAX`, `TOTAL`, `GROUP_CONCAT` (with `SEPARATOR ';'` as in MySQL too) and `STRING_AGG` (the same); since 2.9 also `ANY_VALUE`, `JSON_GROUP_ARRAY`/`JSON_ARRAYAGG`, `JSON_GROUP_OBJECT`/`JSON_OBJECTAGG`, `STD`/`STDDEV`/`STDDEV_POP`, `STDDEV_SAMP`, `VARIANCE`/`VAR_POP`, `VAR_SAMP`, `BIT_AND`, `BIT_OR`, `BIT_XOR`; `FILTER (WHERE …)` after any of them (2.9): `COUNT(*) FILTER (WHERE paid = 1)` |
| Text | `UPPER`/`UCASE`, `LOWER`/`LCASE`, `LENGTH`, `CHAR_LENGTH`/`CHARACTER_LENGTH`, `OCTET_LENGTH`, `BIT_LENGTH`, `SUBSTR`/`SUBSTRING`/`MID`, `LEFT`, `RIGHT`, `TRIM`, `LTRIM`, `RTRIM`, `LPAD`, `RPAD`, `REPLACE`, `TRANSLATE`, `INSERT`, `INSTR`, `LOCATE`, `FIND_IN_SET`, `STRCMP`, `CONCAT`, `CONCAT_WS`, `REVERSE`, `REPEAT`, `SPACE`, `SUBSTRING_INDEX`, `CHAR`, `UNICODE`, `ASCII`, `HEX`, `UNHEX`, `QUOTE`, `SOUNDEX`, `FIELD`, `ELT`, `FORMAT`/`PRINTF`; `like(pattern, x)` and `glob(pattern, x)` as functions |
| Numbers | `ABS`, `ROUND`, `RANDOM`, `RAND`, `CEIL`/`CEILING`, `FLOOR`, `TRUNC`, `TRUNCATE(x, d)`, `SIGN`, `MOD`, `POW`/`POWER`, `SQRT`, `EXP`, `LN`, `LOG10`, `LOG2`, `LOG(base, x)`, `PI`, `GREATEST`, `LEAST`; `SIN`, `COS`, `TAN`, `COT`, `ASIN`, `ACOS`, `ATAN`, `ATAN2`, `DEGREES`, `RADIANS`, `BIN`, `OCT`, `CONV`, `BIT_COUNT` (2.9) |
| Hashes and identifiers (2.9) | `MD5`, `SHA1`, `SHA2(x, bits)`, `CRC32`, `TO_BASE64`, `FROM_BASE64`, `UUID()`, `RANDOMBLOB(n)`, `ZEROBLOB(n)` |
| Dates (SQLite) | `DATE`, `TIME`, `DATETIME`, `STRFTIME`, `JULIANDAY`, `UNIXEPOCH` |
| Dates (MySQL) | `NOW()`, `CURDATE()`, `CURTIME()`, `SYSDATE()`, `LOCALTIME`, `LOCALTIMESTAMP`, `UTC_DATE`, `UTC_TIME`, `UTC_TIMESTAMP` (these five with or without parentheses; the `UTC_` ones in UTC), `YEAR`, `MONTH`, `DAY`/`DAYOFMONTH`, `HOUR`, `MINUTE`, `SECOND`, `DAYOFWEEK`, `WEEKDAY`, `DAYOFYEAR`, `WEEK`, `WEEKOFYEAR`, `YEARWEEK`, `QUARTER`, `DAYNAME`, `MONTHNAME`, `LAST_DAY`, `DATEDIFF`, `DATE_ADD`/`ADDDATE`, `DATE_SUB`/`SUBDATE`, `TIMESTAMPDIFF`, `TIMESTAMPADD`, `TIMESTAMP`, `TO_DAYS`, `MAKEDATE`, `PERIOD_DIFF`, `SEC_TO_TIME`, `TIME_TO_SEC`, `TIMEDIFF`, `TIME_FORMAT`, `UNIX_TIMESTAMP`, `FROM_UNIXTIME`, `DATE_FORMAT`, `STR_TO_DATE`, `EXTRACT(unit FROM d)` |
| Nulls and conditions | `COALESCE`, `IFNULL`, `NULLIF`, `IF`, `IIF`, `ISNULL`, `TYPEOF`; `LIKELY`, `UNLIKELY`, `LIKELIHOOD` (they give their argument: hints for SQLite's planner) |
| JSON | `json_extract(json, path, …)`; since 2.9 `json`, `json_valid`, `json_array`, `json_object`, `json_type`, `json_array_length`, `json_length`, `json_keys`, `json_unquote`, `json_contains`, `json_set`, `json_insert`, `json_replace`, `json_remove`, and the operators `->` and `->>` |
| Other | `MIN`/`MAX` with 2 or more arguments (scalar), `CASE ... WHEN ... THEN ... ELSE ... END`, `CAST`, `CONVERT(x, type)`, `CONVERT(x USING utf8mb4)` |

The ones added in 2.9 were checked one by one: those of SQLite give what
SQLite 3.45 gives, those of MySQL what the MySQL manual says; `WEEK`,
`YEARWEEK` and `TO_DAYS` follow MySQL's own calculation, all eight week modes
included.

The functions of MySQL and of SQLite that are not in the other (2.9) behave as
in the engine they come from; where both have one with the same name and
different behaviour, the table below says which wins. The forms written with
words are accepted as in the standard and MySQL: `TRIM([LEADING | TRAILING |
BOTH] [c] FROM x)`, `POSITION(a IN b)`, `SUBSTRING(x FROM i [FOR n])`,
`EXTRACT(YEAR FROM d)`, `CAST(x AS SIGNED | UNSIGNED)`.

| Function | Here |
|---|---|
| `FORMAT` / `PRINTF` | With a format text (`'%d'`, `'%.2f'`…) as first argument, it is SQLite's `printf`, also with its `,` flag for thousands separators in `%d`, `%i`, `%u` and `%f` (`PRINTF('%,d', 1234567)` = `1,234,567`, 2.9); with a number, MySQL's `FORMAT(n, decimals)` (`1,234.50`) (the rounding is PHP's: a tie can come out otherwise than in SQLite, as with `ROUND`) |
| `STRCMP(a, b)` | -1, 0 or 1 comparing the two as texts, byte by byte (`STRCMP('10', '9')` = -1, 2.9) |
| `TRIM(x, chars)`, `LTRIM`, `RTRIM` | Remove characters, not bytes: `TRIM('áxá', 'ñ')` leaves `'áxá'`, and `'a..c'` is the three characters `a`, `.` and `c`, as in SQLite (2.9) |
| `TRUNCATE(x, d)` | With more decimals than the number has, the number as it is; with `d` far below zero, 0 (2.9) |
| `LOG(x)` | An error that says to use `LN(x)` or `LOG10(x)`: SQLite and MySQL give different results. `LOG(base, x)` is the same in both |
| `HEX(number)` | An error that says to use `PRINTF('%X', n)`: in MySQL it is the number in hexadecimal, in SQLite the hexadecimal of its text. `HEX('text')` works |
| `LAST_INSERT_ID()`, `last_insert_rowid()`, `CHANGES()` | An error that explains `RETURNING`: through the API every request is independent, so they would return nothing that makes sense |
| `CAST(x AS UNSIGNED)` | An integer; a negative one is an error (MySQL would wrap it to 18446744073709551615) |
| `DATE_ADD`, `+ INTERVAL n unit` | As in MySQL: adding a month to 31 January gives 28 February (29 in a leap year), and a date without time stays without time with units of days or more; a result before year 0 or after 9999 is `NULL`, as is `MAKEDATE` out of that range. Fractions of a second are kept to the millisecond, the precision the engine stores and reads back (`'… 10:00:01.500'`, 2.9; before, six digits that the engine itself did not take). Units: `MICROSECOND`, `SECOND`, `MINUTE`, `HOUR`, `DAY`, `WEEK`, `MONTH`, `QUARTER`, `YEAR`. `INTERVAL` only exists added to or subtracted from a date, or inside `DATE_ADD`/`DATE_SUB` |
| `TIMESTAMPDIFF(unit, a, b)` | `b - a` in whole units towards zero; months, quarters and years count complete ones, as MySQL |
| `STR_TO_DATE(text, format)` | Returns `YYYY-MM-DD`, `HH:MM:SS` or both, as the format has; `NULL` if the text does not fit or the date does not exist. Specifiers: `%Y %y %m %c %d %e %H %k %h %I %l %i %s %S %f %p %M %b %T %%`; `%f` reads up to six digits and keeps the millisecond, the precision the engine stores (`'10:20:30.500'`) |
| `json_extract` | As in SQLite: paths `$`, `$.key`, `$."key with spaces"`, `$[2]`, `$[#-1]` (the last one); an object or a list comes back as JSON text, `true`/`false` as 1/0, a path that is not there as `NULL`; with several paths, a JSON list of their values. Malformed JSON or path is an error. Integers too big for PHP come back as a decimal (`1.2345678901234567e+19`), also inside the JSON text of `$` |
| `QUOTE(decimal)` | As in SQLite, the same number again (2.10; before, 14 digits, and `QUOTE(0.30000000000000004)` was `0.3`): 15 digits when that is enough, and if not the 16 or 17 that are needed, with an exponent (`3.0000000000000004e-01`). SQLite sometimes writes a digit or two more (`3.00000000000000044e-01`), which give the same number |
| `json_array`, `json_object` with decimals | Every digit, as stored: `json_array(0.1 + 0.2)` is `[0.30000000000000004]`; SQLite writes 15 (`[0.3]`) and its exponent with two digits (`1.0e-05`; here `1.0e-5`) |
| `NOW()`, `RAND()`, `UNIX_TIMESTAMP()`, `UNIXEPOCH()` | Not cached: each run gives the current value |
| `TIMEDIFF(a, b)` | MySQL's: `'01:00:00'`, with microseconds when there are any (2.9) |
| `RANDOMBLOB`, `ZEROBLOB` | Bytes, fine inside an expression (`HEX(RANDOMBLOB(16))`) but not in a table: storing them as they are is always an error, seen in the statement (2.9) |
| `REPEAT`, `SPACE`, `LPAD`, `RPAD`, `RANDOMBLOB`, `ZEROBLOB`, `PRINTF` | A result of more than 16 MB (or a `PRINTF` width or precision beyond that) is an error that says so, instead of a request that runs out of memory (2.9) |

- Text functions work with **UTF-8 characters**, not bytes
  (`LENGTH('María')` = 5). If the host has no `mbstring`, the engine uses an
  equivalent calculation of its own, accents and `ñ` in `UPPER`/`LOWER`
  included (2.10: without `mbstring`, `LOWER` turned every `S` into `ß` and
  `UPPER('straße')` gave `'STRASE'`; now `'STRASSE'`, as with it).
- `DATE`/`TIME`/`DATETIME` with no argument, or with `'now'`, return the current
  date: `SELECT DATE('now')`.
- **Adding and subtracting time** works as in SQLite, with *modifiers* after the
  date, applied left to right (2.7.3; before, they were silently ignored):

  ```sql
  SELECT DATE('2026-03-01', '+1 day');                 -- 2026-03-02
  SELECT DATETIME(creado, '+2 hours', '-30 minutes') FROM pedidos;
  SELECT DATE('now', '-7 days');                       -- a week ago
  SELECT DATE(x, 'start of month', '+1 month', '-1 day');  -- last day of x's month
  SELECT * FROM pedidos WHERE creado >= DATETIME('now', '-24 hours');
  ```

  The units are `seconds`, `minutes`, `hours`, `days`, `months` and `years`,
  singular or plural, with or without a sign (`'+1 day'`, `'3 days'`,
  `'-1.5 hours'`). Seconds, minutes, hours and days admit decimals, applied to
  the millisecond as in SQLite (2.9: `'+0.5 seconds'` added a whole second);
  months and years must be whole. `'start of day'`, `'start of month'` and
  `'start of year'` take the date back to that point; `'weekday N'` moves it to
  the next day of the week N (0 = Sunday), or leaves it if it is already one;
  `'unixepoch'`, first, reads the value as seconds since 1970 (UTC), as in
  `DATETIME(1700000000, 'unixepoch')`. Dates here carry no time zone, so
  `'localtime'` and `'utc'` are accepted and change nothing. As in SQLite, adding
  months does not clip to the end of the month: 31 January plus one month is
  3 March (MySQL and PostgreSQL would give 28 February). A modifier the engine
  does not know is an error, never a silently wrong date. MySQL's
  `DATE_ADD(x, INTERVAL 1 DAY)` and `x + INTERVAL 1 MONTH` are also there (2.9),
  with MySQL's own rule for months (see the table above).
- `STRFTIME(format, date, modifiers…)` accepts `%Y %m %d %H %M %S %f %j %w %W %s %%`,
  and the same modifiers after the date. `%W` is SQLite's week of the year,
  00 to 53, where week 01 starts on the first Monday (2.9; before, the ISO
  week). `UNIXEPOCH` and `JULIANDAY` of a date that goes past the year 9999
  are `NULL`, as in SQLite. `%s` is the seconds since 1970 that
  `UNIXEPOCH()` gives for the same date: the date taken as UTC, as in SQLite,
  and with `'now'` the current moment (2.9; before, the date was taken in PHP's
  time zone).
- Aggregates ignore `NULL`; `SUM` and `AVG` over an empty set return `NULL` and
  `COUNT` returns 0.

## 4. Value semantics

- **Comparisons and ordering** follow one total order, as in SQLite (2.10):
  first the numbers, by their value, then the texts, also those that are
  numbers. `WHERE`, `ORDER BY` (also with `LIMIT`, from an index or sorted on
  disk), `MIN`/`MAX`, `GREATEST`/`LEAST`, `BETWEEN` and window `ORDER BY` all
  use it, so they agree: with the texts `'10'`, `'9'`, `'1a'`, `'2b'` and
  `'100'`, `ORDER BY` gives `10, 100, 1a, 2b, 9`, `MIN` is `'10'` and `MAX` is
  `'9'`; with the numbers 10 and 9 and the text `'1a'`, `9, 10, 1a`. Between
  two texts, `ORDER BY` (a window's too) applies the configured collation
  (`JSONSQLDB_COLACION`, below) and `WHERE`, `MIN`/`MAX`, `GREATEST`/`LEAST`
  and `BETWEEN` compare them byte by byte: by default `ORDER BY` puts `'B'`
  after `'a'`, but `MAX` of the two is `'a'`. With `'binaria'` they agree
  there too. Until 2.9 a text that was a number sorted among the numbers
  (`9, 10, 100, 1a, 2b`), and `MIN`/`MAX` of several values compared a
  number with a text as numbers (now by class, as in SQLite: `MAX(10, '9')`
  is `'9'`). `NULL` is outside the order (see below). Dates are stored in the
  fixed format `yyyy-MM-dd...`, so comparing and sorting them works without
  conversion. The `ON` of a `JOIN` compares the same way as the `WHERE`, with
  the affinity of each column (2.10): a `TEXT` column and an `INTEGER` one
  match `'1'` with 1, two `TEXT` columns only the same text.
- **`DISTINCT`, `GROUP BY`, `UNION`, `INTERSECT` and `EXCEPT`** take `1` and
  `1.0` as the same value and `1` and `'1'` as two, as SQLite does (2.9). Of
  two values that are the same written differently (`1` and `1.0`, or `'A'`
  and `'a'` in a `NOCASE` column), all of them keep the first that comes;
  SQLite keeps the last in a `UNION`: `SELECT 1 UNION SELECT 1.0` gives 1 here
  and 1.0 there. It is kept on purpose: the API sends each row as soon as it
  has it, and the last one is only known at the end.
- **NULL**: any comparison with `NULL` is unknown, so `phone <> '600111222'`
  does **not** return rows with a null phone (same as SQLite and MySQL). To
  include them: `phone IS NULL OR phone <> '...'`. `x BETWEEN a AND b` is
  `x >= a AND x <= b`, so a `NULL` bound only makes it unknown when the other
  one does not decide: `15 BETWEEN NULL AND 10` is 0 and `15 NOT BETWEEN NULL
  AND 10` is 1, as in SQLite (2.9; before, both were `NULL`).
- `LIKE` **is case-insensitive** for `A` to `Z` only, like SQLite: `'Á'` and
  `'á'` are different, and so are `'á'` and `'a'` (2.10; before, with UTF-8,
  `'É' LIKE 'é'` was 1). `ESCAPE` takes one character, also one of several
  bytes such as `ñ` (before 2.10 only its first byte counted), and anything
  else is an error, as in SQLite; a pattern that ends in it matches nothing,
  and `ESCAPE NULL` gives `NULL`. A pattern
  with many `%` over a long text (`'%a%a%a…%c'`) has the same answer as any
  other (2.9: past the backtracking limit of PHP's regular expressions it
  said "no match" without warning); if even so it cannot be decided, it is an
  error that says so, as with `REGEXP`.
- `ORDER BY` on text uses the collation configured in `config.php`
  (`JSONSQLDB_COLACION`). By default, `'general'`: ignores case and accents and
  places each letter where it belongs, so `'Óscar'` sorts among the Os and
  `'ñu'` between n and o. With `'binaria'` it sorts byte by byte, as SQLite does
  by default: upper case first, then lower case, accented letters last.

  Not every language has the same alphabet: in Swedish `å`, `ä` and `ö` are
  letters of their own that go **after z**, not variants of `a` and `o`. That is
  corrected with `JSONSQLDB_COLACION_MAPA` in `config.php`, without touching the
  engine:

  ```php
  define('JSONSQLDB_COLACION_MAPA', [
      'å' => 'z{',  'Å' => 'z{',
      'ä' => 'z{{', 'Ä' => 'z{{',
      'ö' => 'z{{{', 'Ö' => 'z{{{',
  ]);
  ```

  The collation **only affects `ORDER BY`**. Comparisons (`=`, `<`, `>`), unique
  keys, `GROUP BY` and `DISTINCT` stay exact: `'Óscar'` and `'oscar'` are two
  different values and a unique key does not consider them duplicates. A column
  with `COLLATE NOCASE` does change that for `'Oscar'` and `'oscar'` (2.9,
  section 2).
- Division by zero returns `NULL` instead of failing.
- An operation whose result is not a number (`INF - INF`, `INF * 0`, `INF /
  INF`, `MOD(INF, 2)`, `LOG(INF, INF)`, `SUM` of `+INF` and `-INF`) returns
  `NULL`, as in SQLite (2.10; before, NaN, which passed any `WHERE x > 2` and
  sorted anywhere).
- A decimal turned into text (stored in a `TEXT` column, also one declared
  without a type, which is `TEXT` here; `CAST(x AS TEXT)`, `||`) has 15
  significant digits and SQLite's exponent, as there (2.10; before, PHP's 14):
  `123456789.123456789` is `'123456789.123457'` (before `'123456789.12346'`),
  `1e100` is `'1.0e+100'` (before `'1.0E+100'`) and `1e-5` is `'1.0e-05'`. Of
  200,000 random decimals written with 17 digits, 278 (about 1 in 700) get
  another last digit than in SQLite: 269 are exact ties at the 16th digit,
  which go to the even digit here and away from zero there, and in 9 SQLite's
  conversion misses it. It does not depend on the locale nor on `precision` in
  `php.ini`. An infinite number is
  `'Inf'` or `'-Inf'`, as in SQLite (before 2.10, an empty text, also stored
  in a `TEXT` column without warning). What is already stored does not
  change.
- In `ORDER BY`, `NULL`s go first in ascending order and last in descending, as
  in SQLite and MySQL; `NULLS FIRST` and `NULLS LAST` (2.9) choose.

## 5. Performance

Measured with `php tests/benchmark.php` and 2.10 (PHP 8.3, one core, 20,000
customers and 30,000 orders, on-disk cache; each figure the median of seven
runs, each run the mean of several repetitions, the first of the process
included, which loads the code; two runs of the same thing differ by up to
10–15 %). The memory is the peak of the whole process:

| Query | Time · peak memory |
|---|---|
| Lookup by primary key | 1.1 ms · 4.9 MB (about 0.2 ms once the process has made one) |
| Lookup by a `UNIQUE` text column | 0.25 ms · 4.8 MB |
| `BETWEEN` on the primary key (1,000 rows) | 2.0 ms · 6.0 MB |
| `COUNT(*)` of an equality on an indexed column (2,000 matches) | 0.33 ms · 4.9 MB |
| Numeric range, no index | 12 ms · 5.9 MB |
| `LIKE` by prefix | 17 ms · 5.9 MB |
| `LIMIT 50`, no filter | 0.64 ms · 5.9 MB |
| `COUNT(*)` of a 30,000-row table | 0.87 ms · 5.2 MB |
| `GROUP BY` + `SUM` + `ORDER BY` | 21 ms · 6.1 MB |
| `ORDER BY ... LIMIT 20` | 12 ms · 6.1 MB |
| `ORDER BY`, whole table | 24 ms · 15 MB |
| `JOIN` 30,000 × 20,000 with aggregation | 109 ms · 18 MB |
| `IN (SELECT ...)` subquery | 54 ms · 6.8 MB |
| `JOIN` of one order with its customer, after `WHERE o.id = ?` | 0.44 ms · 5.3 MB |
| `LEFT JOIN` of twenty orders with their customers | 2.6 ms · 5.9 MB |
| Any of the above repeated on unchanged data | 0.19 ms · 2.8 MB |

The process takes 0.1–0.3 MB more than with 2.9.0 in most lines, and the
writes 0.4–0.6 MB more (the whole `ORDER BY`, 4.7 MB less): the engine has
more code, which PHP keeps in each process's memory unless OPcache holds it
(see the CHANGELOG, 2.10, *Checked*).

Decisions that make this possible:

1. **Rows straight from the cache**: a single-table query works on the rows
   exactly as the storage returns them, without copying them. Only a `JOIN`
   copies rows, with the alias in front of each column name so two tables
   with a column of the same name do not collide, and it copies only the
   columns the query names.
2. **Names resolved once**: every column reference (ambiguities included) is
   resolved before the rows are visited and the exact key is stored in the
   query tree. No names are resolved per row.
3. **Compiled `WHERE`**: comparisons, `AND`/`OR`/`NOT`, `BETWEEN`, `IN` with
   literals, `LIKE`, `IS NULL` and arithmetic are compiled into PHP closures
   once per query; the general expression evaluator only runs for what cannot
   be compiled (functions, subqueries, columns of an outer query). A single
   `col <op> number` or `col BETWEEN number AND number` is decided inline, with
   no call per row, when the value is a number (2.7).
4. **Streaming from the first source**: rows are read one part at a time and
   filtered as they arrive. A `WHERE` scan keeps only the rows that pass.
5. **Accumulated aggregates**: `GROUP BY`, `COUNT`, `SUM`, `AVG`, `MIN` and
   `MAX` keep one accumulator per group as the rows go by, never the rows of
   the group. Memory is proportional to the number of groups.
6. **`ORDER BY … LIMIT n` keeps only the n rows in the lead**; a full sort uses
   `array_multisort` when every key is all numbers or all text, computing the
   collation key once per row rather than once per comparison. Since 2.10 a
   full sort projects each row as it is read and keeps only that and its sort
   keys, not the table: `SELECT id, balance … ORDER BY balance DESC` on 30,000
   rows, 104 → 47 ms and 33.8 → 13.5 MB.
7. **Hash `JOIN`**: if the `ON` contains equalities (`a.id = b.a_id`), the
   inner side is indexed and looked up directly instead of comparing every row
   against every row, and the joined rows stream into the `WHERE` and the
   grouping. Conditions that are not equalities are applied afterwards, only
   to the candidates. The parts of the `WHERE` that only concern tables
   already joined are applied before the join (2.7), and when what is left
   on the left is small and the right-hand table has an index on the `ON`
   columns, each left row is looked up by key instead of hashing the table:
   one order and its customer in 0.44 ms instead of about 20. Tables separated
   by commas are joined the same way when the `WHERE` has equalities between
   their columns, written with the table in front (`FROM a, b WHERE b.x =
   a.y`, 2.9): 20,000 by 10,000 rows, 76 s → 40 ms.
8. **Subqueries executed once**: `IN (SELECT ...)` and scalar subqueries run
   once per query and their result is reused. The values of an `IN (SELECT
   …)` are taken as its rows come out, without gathering the rows (2.9). A
   correlated `EXISTS` that looks outside only through one equality (`EXISTS
   (SELECT 1 FROM p WHERE p.cid = c.id AND p.total > 999)`) runs as a
   semi-join (2.9): the values of `p.cid` that pass the rest are taken once,
   and each outer row only checks whether its own is among them, as with
   `IN`. Before, the subquery ran once for every outer row: on 60,000 rows
   against 60,000, more than a minute in 2.8.0 and 0.35 s now; with a million
   customers and a million orders, 2.9 s. `EXPLAIN` shows it. A subquery with
   an aggregate (`EXISTS (SELECT COUNT(*) …)`, which always returns one row),
   `GROUP BY`, `HAVING`, `LIMIT`, `OFFSET` or a window function runs as
   before, row by row: its answer is not "some row passes". A correlated
   subquery keeps its result for each outer row, up to 4,096 of them between
   all, and lets them go if memory runs short (2.9).
9. **Per-part cache**: parts already read are reused while nobody writes them;
   and a **result cache** serves a repeated `SELECT` on unchanged data without
   running it (see [01-core.md](01-core.md)).
10. **Indexes**: an equality on an indexed column decodes only the parts that
    hold the matching rows, and on a numeric column only the index pieces
    whose range can hold the value (2.7). Inside a piece the key is looked
    for in its text, without decoding it; a numeric key starts where its
    value falls in the range of the piece (2.9), so in a piece whose keys go
    in order, as with a primary key that grows, it is found almost at once
    (ten keys at the end of their pieces, 0.426 → 0.342 ms). A few rows from
    a part are read by their byte offset, one line each, without decoding
    the part (2.7), up to 30 of a part; the index is used while that costs
    no more than reading half the table, counting 200 lines as one part (2.10;
    until 2.9.0 it counted 30, and gave the index up with more than 15
    matching rows per part: `WHERE age = 30`, 453 of 30,000 rows, 21.9 →
    1.8 ms). An index on several columns serves its first ones fully only
    when the columns after them are `NOT NULL`: a row with `NULL` in an
    indexed column is not in the index, so it serves the parts with none of
    those rows, and the others are read whole (2.10; before, a lookup by `a`
    through an index on `(a, b)` missed the rows with `b` `NULL`: in `UPDATE`
    and `DELETE` from 2.5.0, in `SELECT` from 2.7.4; [01-core.md](01-core.md)). A
    `BETWEEN`, `<` or `>` on a numeric indexed column reads only the parts
    whose piece can hold a value in the range (2.7) — one or two parts for a
    range of ids on a table that grows by appending, the whole table for a
    column whose values are spread at random.
11. **`LIMIT` pushed into the read** when there is no `WHERE` and no `JOIN`,
    and **`COUNT(*)` counting lines** instead of decoding rows. Since 2.9 the
    `OFFSET` skips whole parts too, counting the holes of deleted rows the
    state records for each part (`LIMIT 100 OFFSET 500000` on a million rows,
    596 → 13 ms), and a `LIMIT` with a `WHERE` that an index covers entirely
    (equalities on its columns, nothing else) stops at the index piece that
    completes it, since the pieces go in the order of the table (`WHERE
    ciudad = 'Elche' LIMIT 50` with 100,000 such rows: 71 → 14 ms, where
    before the 100,000 positions were read, found too many, and the table
    scanned).
12. **Text keys by the filter of each index piece** (2.9): a lookup by a text
    key (`WHERE email = ?`, and the `UNIQUE` check of an `INSERT`) reads only
    the head of each index piece, with its filter, and the keys of the piece
    where it can be. A value found in every piece (a city) switches to reading
    the pieces whole after two, as before. On 300,000 rows, against 2.8.0:
    the lookup 9.1 → 4.9 ms and the `INSERT` 26.5 → 17.2 ms; on 60,000 rows
    1.10 → 0.58 ms. The filters of an index are also kept together in the
    cache (by the first lookup that reads them all, and by writes), so a
    lookup opens only the piece where the key can be: by email on a million
    rows, from 30 ms to about 3 ms more than a lookup by key. The filter covers numbers too in pieces whose range spans
    at least 8 times the part size (the customer of each order), so the orders
    of one customer on a million take about 3 ms more than a lookup by key instead of 42. Pieces written
    before 2.9 have no filter and are read whole.
13. **Ranges by index on texts, dates and spread numbers** (2.9): when the
    numeric range of each index piece is not enough, a `<`, `<=`, `>`, `>=`,
    `BETWEEN` or `=` against a literal on the first column of an index looks
    for the matching rows in the index pieces and reads only their parts, a
    few rows by their lines. It only starts when it will not cost more than
    reading half the table: with numbers, an estimate from the range of each piece
    must leave fewer than half the parts to open; with texts and dates, the
    head of each piece written by 2.9 carries its smallest and largest value,
    and only the pieces that can hold the range are read. A `COUNT(*)` whose
    `WHERE` is only such comparisons on one indexed column counts in the index
    without reading the table; with only equalities it does so for texts
    that are not numbers and, since 2.10, for numbers on an `INTEGER`,
    `DOUBLE` or `DECIMAL` column and any text on a `TEXT` column, where each
    value has a single key (`COUNT(*) WHERE age = 30` on 30,000 rows, 21.3 →
    0.36 ms; `COUNT(*) WHERE codigo BETWEEN '01000' AND '01100'`, codes that
    are numbers written as texts, 41.2 → 23.4 ms, which read the table). The comparison is
    the `WHERE`'s own, exact: with a text the index must hold only texts, with
    a number only numbers, or the table is read as before. On 60,000 rows,
    against 2.8.0: `SELECT *` of one day 65.6 → 2.7 ms, `COUNT(*)` of a month
    66.3 → 5.0 ms, `COUNT(*) … BETWEEN 30 AND 40` 33.6 → 5.1 ms; ranges over
    most of the table, the same.
14. **`ORDER BY col [DESC] LIMIT n` from the index** (2.9): with one table, no
    `WHERE`, grouping or `DISTINCT`, and a single order key that is a numeric
    column first in an index (whose other columns are `NOT NULL`), the index
    pieces are read starting with the one whose range has the best end,
    stopping as soon as the next one cannot beat
    the n-th value, and only the n rows of the result are read: in a tie on
    the last value, the first ones in the table, which are the ones the usual
    sort keeps (2.10; before, every row of that value was read: `ORDER BY age
    DESC LIMIT 20` with 439 rows tied, 11.5 → 3.6 ms).
    In ascending order `NULL`s come first and are not in the index: the column
    must be `NOT NULL` (the primary key is). On 60,000 rows, median of five
    runs against 2.8.0: `ORDER BY id DESC LIMIT 20` 117.7 → 2.9 ms, `ORDER BY
    saldo DESC LIMIT 20` (values spread over the table) 87.5 → 17.8 ms, with
    `LIMIT 100 OFFSET 400` 101.5 → 63.7 ms. Since 2.10 also on a `TEXT`
    column without a `COLLATE` of its own, in the configured collation or byte
    by byte (`COLLATE BINARY`): every piece is read, keeping only what can
    still come out (`ORDER BY ciudad DESC LIMIT 20` on 30,000 rows, 29.2 →
    2.0 ms; see [01-core.md](01-core.md)). With a `WHERE`, on a column without
    an index or with `NULL`s in ascending order, the table is read as before.
15. **`DISTINCT` drops repeated rows as they come** (2.9) instead of gathering
    the whole table and every projected row to remove them at the end:
    `SELECT DISTINCT ciudad` on 60,000 rows, 45.9 → 5.5 MB.
16. **`ORDER BY` on disk when it does not fit** (2.9): the in-memory sort is
    the usual one, untouched; while it gathers the table, as soon as half of
    `memory_limit` is in use the query is run again another way: rows go into
    memory with their sort keys, what was gathered is sorted and written to a
    temporary file in the database's `.orden/` folder each time memory gets
    tight, and at the end the files are merged row by row. The order is
    exactly the in-memory one, ties included. With the `consultarPorFilas()`
    sink (the API) the rows go out as they are merged, so the result does not
    have to fit either. The same for an `ORDER BY … LIMIT` with a large
    `OFFSET`. The temporary files are deleted at the end whatever happens, and
    one left by a process that died is deleted by the next sort after an hour.
    On 40,000 rows with `memory_limit = 24M` it answers; 2.8.0 stopped with the
    memory error.
17. **`MIN` and `MAX` from the index** (2.9): `SELECT MIN(col), MAX(col) FROM t`,
    with no `WHERE`, on an `INTEGER`, `DOUBLE` or `DECIMAL` column that is the
    first column of an index whose other columns are `NOT NULL` (the primary
    key is), comes from the range of
    each index piece that the table already keeps, without reading a row:
    136 → 7 ms on 60,000 rows. With a `WHERE`, or on a column without an index,
    the table is read as before.
18. **What else does not fit, on disk too** (2.9). As with `ORDER BY`, the
    usual path is untouched, and only when half of `memory_limit` is in use is
    the query run again another way, writing what it gathers to temporary
    files in `.orden/`:
    - a hash `JOIN` (`INNER` or `LEFT`, with equalities in the `ON`) splits
      both sides into 64 files by the key of the `ON` and joins them file by
      file;
    - a `GROUP BY` of many groups splits the rows into 64 files by their group
      and groups each file;
    - a subquery of the `FROM`, a view or a query of the `WITH` is written to a
      file as its rows come out, and read from it as they are used;
    - a `UNION` or `UNION ALL` without `ORDER BY` passes each row of each part
      on as it is read (dropping the ones already seen, with `UNION`) instead
      of gathering every part first;
    - window functions: the rows go to a file and only the values the windows
      use stay in memory, a list per expression; each window is sorted in
      pieces that fit, and the rows come back from the file in its order.

    The rows are the same as in memory, and so is their order when the query
    has an `ORDER BY`. A `COUNT(DISTINCT …)` without `GROUP BY` keeps each
    value once as it comes, and if they do not fit, sends them to 64 files by
    their key and adds up the distinct ones of each. What still has to fit:
    the rows already seen by a `DISTINCT` or a `UNION`, the groups of one of
    the 64 files, and about 16 bytes per row for each value a window uses
    (about 32 on PHP 8.0 and 8.1, whose lists take twice the memory). When even that does not fit,
    the query stops with the usual memory error, not with PHP's fatal. With
    `memory_limit = 24M` on 60,000 + 60,000 rows, each of these answers the
    same as with memory to spare (`tests/f8_indices.php`; the window
    functions, with 32M on PHP 8.0 and 8.1); 2.8.0 stopped with
    the memory error in every one it can run (it has no window functions). A
    million rows: see
    [01-core.md](01-core.md#a-million-rows-29).

## 6. Not supported yet

| Not supported | Alternative |
|---|---|
| Row values: `(a, b) IN (SELECT …)`, `(a, b) = (1, 2)` | `a = 1 AND b = 2`, or `EXISTS` with both conditions |

Anything unsupported returns a clear error with the line number, never a
silently wrong result.

## 7. Files

| File | Responsibility |
|---|---|
| `engine/Lexer.php` | SQL → tokens (comments, strings, identifiers, numbers) |
| `engine/Parser.php` | tokens → query tree: expressions and `FROM`; the rest of the `SELECT` is parsed by `ParserConsulta`, and the other statements by its subclasses (see [03-writes.md](03-writes.md)) |
| `engine/ParserConsulta.php` | the grammar of `SELECT` (`WITH`, `UNION`, columns, `GROUP BY`, `ORDER BY`, `LIMIT`…) (2.9) |
| `engine/ParserFunciones.php` | the functions written with words, such as `TRIM(… FROM …)`, `EXTRACT(… FROM …)` or `CONVERT(… USING …)` (2.10; in `ParserConsulta` in 2.9) |
| `engine/ParserAvanzado.php` | window functions and the check of a `WITH RECURSIVE` (2.9) |
| `engine/Valor.php` | comparison, truth and conversion of values |
| `engine/Collation.php` | alphabetical order of ORDER BY, configurable per language |
| `engine/Functions.php` | SQLite's usual scalar functions |
| `engine/FunctionsAmpliadas.php` | the other functions of SQLite and those of MySQL: JSON, MySQL's dates, `PRINTF`… (2.9) |
| `engine/Evaluator.php` | evaluation of expressions over rows and groups |
| `engine/EvaluatorCompilado.php` | expressions compiled into closures, for a query that evaluates them over many rows (2.9) |
| `engine/EvaluatorPatrones.php` | the patterns of `LIKE`, `GLOB` and `REGEXP` (2.9) |
| `engine/EvaluatorIn.php` | `IN` evaluated row by row, when it is not compiled nor resolved by an index (2.10; in `Evaluator` before) |
| `engine/EvaluatorConjunto.php` | looking a value up in a prepared set: the `IN` row by row or compiled, and a correlated `EXISTS` (2.10; in `Evaluator` before) |
| `engine/Select.php` | sources, filters, ordering by index and limit, and the result cache (2.10; in `Database` before) |
| `engine/SelectJoinIndice.php` | the `JOIN` that looks each row up in an index of the other table (2.10; in `Select` before), and `FROM a, b WHERE …` as a `JOIN` |
| `engine/SelectOrden.php` | `ORDER BY`: whether an index gives the first rows of `ORDER BY … LIMIT` (2.10), and without one, in memory (2.9) |
| `engine/SelectEnDisco.php` | an `ORDER BY` that does not fit in memory, sorted on disk (2.10; in `SelectOrden` before) |
| `engine/SelectAgrupada.php` | `GROUP BY`, the aggregates and `HAVING`, also on disk (2.9) |
| `engine/SelectCompuesta.php` | `UNION`, `INTERSECT`, `EXCEPT`, `WITH` and `WITH RECURSIVE` (2.9) |
| `engine/SelectJoin.php` | the hash `JOIN` (also on disk), `USING`, `NATURAL`, and a correlated `EXISTS` as a semi-join (2.9) |
| `engine/SelectAvanzada.php` | window functions, `EXPLAIN` and the position column of an `UPDATE` or `DELETE` with `JOIN` (2.9) |
| `engine/Volcado.php` | rows in temporary files, for what does not fit in memory (2.9) |
| `engine/Database.php` | façade: parses, locks, executes and logs |
| `engine/DatabaseAvanzada.php` | batches of statements and transactions, `SHOW DATABASES`, `CREATE` and `DROP DATABASE`, `EXPLAIN`, writes in stretches (2.9), and the repairs left for the end of a query (2.10) |
| `engine/Config.php` | reads `config.php` with defaults, and decides whether a statement goes to the log (2.10) |
| `engine/Logger.php` | query log, loaded only when something is written to it; who runs the queries and from where comes from `Logger::contexto()` or, from the API and the panel, the constant `JSONSQLDB_REGISTRO` (2.10) |
| `tests/f2_parser.php` | 86 checks of the parser |
| `tests/f21_compatibilidad.php` | 132 checks of the SQLite and MySQL syntax and functions, against SQLite where it has them |
| `tests/f2_select.php` | 165 checks of the executor, with real data |

## 8. Tests

```
php tests/f1_nucleo.php     → OK: 76
php tests/f2_parser.php     → OK: 86
php tests/f2_select.php     → OK: 165
php tests/f8_indices.php    → OK: 93
php tests/f21_compatibilidad.php → OK: 132
```
