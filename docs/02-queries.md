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
read lock) and of logging the query.

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
  accepts names of result columns or their position (`ORDER BY 1`).
- Each part keeps its own `WHERE`, `GROUP BY` and `JOIN`.

### Where each thing comes from

The dialect is **mainly SQLite's**, the lightweight engine this project
resembles. A few constructs come from elsewhere, because they are what people
expect to find:

| Construct | Origin | Note |
|---|---|---|
| `\|\|` to concatenate | SQLite, standard | The native form here |
| `CONCAT(...)` | MySQL, SQL Server | Not in SQLite. If any argument is `NULL`, the result is `NULL` |
| `GROUP_CONCAT(col, sep)` | SQLite and MySQL | MySQL writes `SEPARATOR sep`; here it is the second argument, as in SQLite |
| `REGEXP` / `RLIKE` | MySQL | In SQLite `REGEXP` exists but must be provided separately. Here it just works |
| `LIMIT n, m` | MySQL | `LIMIT n OFFSET m`, from SQLite, works too |
| `IFNULL` | SQLite and MySQL | `COALESCE` is the standard and is there as well |
| `CAST(x AS type)` | Standard | Accepts the `CREATE TABLE` types and their aliases |
| `FULL JOIN` | Standard | Not in SQLite nor in MySQL 5 |
| `AUTOINCREMENT` | SQLite | MySQL's `AUTO_INCREMENT` is accepted too |

If something appears neither in this list nor in the next table, it most likely
behaves as in SQLite, but **this documentation is the reference**, not
SQLite's: where the two disagree, this one wins.

### Writing queries that run fast

Without an index a lookup scans the table. Three things avoid or cheapen that,
all automatic, and it pays to write with them in mind.

**Indexes.** The primary key and the `UNIQUE`s have theirs from creation; the
rest are added with `CREATE INDEX`. They are only used on equalities and `IN`
against literals in the top-level `AND` chain of the `WHERE`: `WHERE city =
'Elche' AND age = 30` yes, `WHERE city = 'Elche' OR age = 30` no, and nothing
under a `NOT`, no `IS NULL`, `NOT IN`, ranges or `LIKE`. A composite index is
used left to right: one on `(city, age)` serves a lookup by `city`, or by both,
but not by `age` alone. Details in [03-writes.md](03-writes.md).

**Simple comparisons.** A `WHERE column = value` — and the same with `<>`, `<`,
`<=`, `>`, `>=` — is resolved without going through the general expression
evaluator. Wrapping the comparison in something more complex (`OR`, functions,
parentheses with other conditions) disables the shortcut.

**Early cut by `LIMIT`.** If the query has `LIMIT` and **no** `ORDER BY`,
`GROUP BY`, `DISTINCT` or aggregates, the engine stops reading as soon as it has
the rows you asked for. With `ORDER BY` it cannot, because the last row of the
table could be the first of the result.

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
believing in a guarantee they do not have.

These constructs exist in SQLite and **raise an error** here:

| Construct | Why, and what to do |
|---|---|
| `INSERT OR IGNORE` / `OR REPLACE` | There is no upsert. `SELECT` first and decide between `INSERT` and `UPDATE` |
| `CREATE TEMP TABLE` / `TEMPORARY` | No temporary tables. Create a normal one and `DROP TABLE` it |
| `WITHOUT ROWID` | There is no `rowid`: rows are JSON objects and the key is the one you declare |
| `BEGIN` / `COMMIT` / `ROLLBACK` | No multi-statement transactions. Each statement is atomic on its own |
| `CHECK (...)` | Use a `BEFORE` trigger with `RAISE(ABORT, '...')` |
| `CREATE UNIQUE INDEX` | An index here only speeds up, it does not enforce uniqueness. Use `ALTER TABLE t ADD UNIQUE (...)`, which also creates its own index |
| Window functions (`OVER`) | Out of the project's scope |
| `WITH RECURSIVE` | Plain CTEs are there; a query cannot refer to itself |
| `ALTER TABLE` on the primary key | Handled with `ADD`/`DROP PRIMARY KEY`, and `AUTOINCREMENT` only at creation |

And these behavioural differences are worth keeping in mind:

- `DECIMAL` is rounded floating point, not exact decimal.
- `ORDER BY` uses the configured collation, not binary order, unless you
  change it.
- `LIKE` is case-insensitive but **does** distinguish accents.
- Comparing a text with a number **converts the text**: `'12abc'` is worth 12.
  SQLite does not do this: it applies the affinity of the column's declared
  type, with rules of its own. It is the biggest behavioural difference between
  the two engines, and it is deliberate: here the rule is one and fits in a
  line.
- `ROUND` rounds away from zero (`ROUND(2.5)` = 3), like SQLite. But
  `ROUND(2.675, 2)` gives 2.68 here and 2.67 in SQLite: 2.675 does not exist
  exactly in floating point and each engine breaks the tie its own way. If the
  cent has to add up, store cents in an `INTEGER`.
- `%` works with integers, as in SQLite: if the divisor truncates to zero
  (`5 % 0.4`), the result is `NULL`.

## 2. Supported syntax

| Clause | Detail |
|---|---|
| `SELECT` | columns, expressions, `*`, `table.*`, `DISTINCT`, `AS` (the `AS` is optional) |
| `FROM` | tables, aliases, subqueries (`FROM (SELECT ...) t`, alias required) |
| `JOIN` | `INNER`, `LEFT [OUTER]`, `RIGHT [OUTER]`, `FULL [OUTER]`, `CROSS`, comma; `ON` with any condition |
| `WHERE` | any expression |
| `GROUP BY` | one or more expressions |
| `HAVING` | with or without `GROUP BY` |
| `ORDER BY` | expressions or output aliases, `ASC`/`DESC`, several keys |
| `LIMIT` | `LIMIT n`, `LIMIT n OFFSET m`, `LIMIT m, n` |
| `WITH` | plain (non-recursive) CTEs |

Operators: `= <> != < <= > >=`, `AND OR NOT`, `IN`, `NOT IN`, `BETWEEN`,
`NOT BETWEEN`, `LIKE ... [ESCAPE c]`, `NOT LIKE`, `REGEXP`, `IS NULL`,
`IS NOT NULL`, `+ - * / %`, `||` (concatenation).

Identifiers with spaces: `"my field"`, `[my field]` or `` `my field` ``.

## 3. Functions

| Group | Functions |
|---|---|
| Aggregates | `COUNT(*)`, `COUNT(x)`, `COUNT(DISTINCT x)`, `SUM`, `AVG`, `MIN`, `MAX`, `GROUP_CONCAT` |
| Text | `UPPER`, `LOWER`, `LENGTH`, `SUBSTR`/`SUBSTRING`, `TRIM`, `LTRIM`, `RTRIM`, `REPLACE`, `INSTR`, `CONCAT` |
| Numbers | `ABS`, `ROUND`, `RANDOM` |
| Dates | `DATE`, `TIME`, `DATETIME`, `STRFTIME` |
| Nulls | `COALESCE`, `IFNULL`, `NULLIF` |
| Other | `MIN`/`MAX` with 2 or more arguments (scalar), `CASE ... WHEN ... THEN ... ELSE ... END`, `CAST` |

- Text functions work with **UTF-8 characters**, not bytes
  (`LENGTH('María')` = 5). If the host has no `mbstring`, the engine uses an
  equivalent calculation of its own, accents and `ñ` in `UPPER`/`LOWER`
  included.
- `DATE`/`TIME`/`DATETIME` with no argument, or with `'now'`, return the current
  date: `SELECT DATE('now')`.
- `STRFTIME` accepts `%Y %m %d %H %M %S %f %j %w %W %s %%`.
- Aggregates ignore `NULL`; `SUM` and `AVG` over an empty set return `NULL` and
  `COUNT` returns 0.

## 4. Value semantics

- **Comparisons**: if both values are numeric they compare as numbers;
  otherwise as text. Dates are stored in the fixed format `yyyy-MM-dd...`, so
  comparing and sorting them works without conversion.
- **NULL**: any comparison with `NULL` is unknown, so `phone <> '600111222'`
  does **not** return rows with a null phone (same as SQLite and MySQL). To
  include them: `phone IS NULL OR phone <> '...'`.
- `LIKE` **is case-insensitive**, like SQLite, but **distinguishes accents** —
  SQLite does the same only because it does not know them either.
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
  different values and a unique key does not consider them duplicates.
- Division by zero returns `NULL` instead of failing.
- In `ORDER BY`, `NULL`s go first.

## 5. Performance

Measured with `php tests/benchmark.php` (PHP 8.3, 20,000 customers and 30,000
orders; mean of several repetitions):

| Query | Time · peak memory |
|---|---|
| Lookup by primary key | 1.9 ms · 7 MB |
| Equality on an indexed column (2,000 matches) | 17 ms · 7 MB |
| Numeric range, no index | 22 ms · 7 MB |
| `LIKE` by prefix | 26 ms · 11 MB |
| `LIMIT 50`, no filter | 0.5 ms · 5 MB |
| `COUNT(*)` of a 30,000-row table | 4 ms · 5 MB |
| `GROUP BY` + `SUM` + `ORDER BY` | 34 ms · 15 MB |
| `ORDER BY ... LIMIT 20` | 47 ms · 18 MB |
| `JOIN` 30,000 × 20,000 with aggregation | 128 ms · 43 MB |
| `IN (SELECT ...)` subquery | 81 ms · 9 MB |

Decisions that make this possible:

1. **Flat rows**: during execution each row is an array keyed `alias.column`;
   reading a column is a direct array access.
2. **Names resolved once**: every column reference (ambiguities included) is
   resolved before the rows are visited and the exact key is stored in the
   query tree. No names are resolved per row.
3. **Streaming from the first source**: rows are read one part at a time and
   flattened and filtered as they arrive. A `WHERE` scan keeps only the rows
   that pass; the decoded part and the flattened rows never coexist in full.
   Only the inner side of a `JOIN` and anything that needs every row at once
   (`ORDER BY` without `LIMIT`, `GROUP BY`, `DISTINCT`) is materialised.
4. **Hash `JOIN`**: if the `ON` contains equalities (`a.id = b.a_id`), the
   inner side is indexed and looked up directly instead of comparing every row
   against every row. Conditions that are not equalities are applied afterwards,
   only to the candidates.
5. **Subqueries executed once**: `IN (SELECT ...)` and scalar subqueries run
   once per query and their result is reused.
6. **Per-part cache**: parts already read are reused while nobody writes them
   (see [01-core.md](01-core.md)).
7. **Indexes**: an equality on an indexed column decodes only the parts that
   hold the matching rows.
8. **`LIMIT` pushed into the read** when there is no `WHERE` and no `JOIN`, and
   **`COUNT(*)` counting lines** instead of decoding rows.

## 6. Not supported yet

| Not supported | Alternative |
|---|---|
| `WITH RECURSIVE` | Plain CTEs are there |
| Window functions (`OVER`) | — |
| `GLOB` | Use `LIKE` or `REGEXP` |

Anything unsupported returns a clear error with the line number, never a
silently wrong result.

## 7. Files

| File | Responsibility |
|---|---|
| `engine/Lexer.php` | SQL → tokens (comments, strings, identifiers, numbers) |
| `engine/Parser.php` | tokens → query tree |
| `engine/Valor.php` | comparison, truth and conversion of values |
| `engine/Collation.php` | alphabetical order of ORDER BY, configurable per language |
| `engine/Functions.php` | scalar and aggregate functions |
| `engine/Evaluator.php` | evaluation of expressions over rows and groups |
| `engine/Select.php` | sources, JOIN, filters, grouping, ordering and limit |
| `engine/Database.php` | façade: parses, locks, executes and logs |
| `engine/Config.php` | reads `config.php` with defaults |
| `engine/Logger.php` | query log |
| `tests/f2_parser.php` | 70 checks of the parser |
| `tests/f2_select.php` | 138 checks of the executor, with real data |

## 8. Tests

```
php tests/f1_nucleo.php     → OK: 66
php tests/f2_parser.php     → OK: 70
php tests/f2_select.php     → OK: 138
php tests/f8_indices.php    → OK: 59
```
