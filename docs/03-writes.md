# jsonSQLDB — Part 3: writes, constraints and triggers

`INSERT`, `UPDATE`, `DELETE` and all the DDL through SQL, with validation of
types, `NOT NULL`, primary key, `UNIQUE`, foreign keys, and trigger execution.

## 1. Usage

```php
$db = new Database('mydb');

$db->consultar("INSERT INTO customers (name, email) VALUES ('Ana', 'ana@x.es')");
// ['success' => true, 'filas' => 1, 'mensaje' => '1 fila(s) insertada(s)']
```

`SELECT` returns the list of rows; every other statement returns
`['success' => true, 'filas' => n, 'mensaje' => '...']`.

A write to one table takes that table's **exclusive lock**; reads of other
tables and writes to other tables carry on meanwhile. A write that can reach
other tables (foreign keys, triggers) locks that group, and structure changes
lock the whole database. See [01-core.md §3](01-core.md).

## 2. Supported statements

### INSERT

```sql
INSERT INTO customers (name, email) VALUES ('Ana', 'ana@x.es');
INSERT INTO customers (name, city) VALUES ('Luis', 'Madrid'), ('María', 'Valencia');
INSERT INTO customers VALUES (100, 'Marta', 'marta@x.es', 25.5, 'Alicante', '2026-05-01');
INSERT INTO customers (name, balance, city) VALUES ('Sara', 10 * 3 + 0.5, DEFAULT);
INSERT INTO copy (name, city) SELECT name, city FROM customers WHERE city = 'Madrid';
```

`INSERT OR REPLACE` / `OR IGNORE` are rejected: there is no upsert. Run a
`SELECT` and decide between `INSERT` and `UPDATE`.

### UPDATE and DELETE

```sql
UPDATE customers SET balance = balance + 100, city = UPPER(city) WHERE id = 3;
DELETE FROM orders WHERE date < '2026-01-01';
```

Without `WHERE` they affect the whole table.

### CREATE TABLE

```sql
CREATE TABLE IF NOT EXISTS orders (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NOT NULL,
    reference   VARCHAR(20) NOT NULL,
    total       DECIMAL(10,2) NOT NULL DEFAULT 0,
    date        DATETIME,
    CONSTRAINT uq_ref UNIQUE (customer_id, reference),
    FOREIGN KEY (customer_id) REFERENCES customers(id)
        ON DELETE CASCADE ON UPDATE CASCADE
);
```

- Column constraints: `PRIMARY KEY [AUTOINCREMENT]`, `NOT NULL`, `UNIQUE`,
  `DEFAULT value`, `REFERENCES table(col) [ON DELETE ...] [ON UPDATE ...]`.
- Table constraints: `PRIMARY KEY (cols)` (composite keys allowed),
  `UNIQUE (cols)`, `FOREIGN KEY (cols) REFERENCES ...`, with `CONSTRAINT name`.
- One `AUTOINCREMENT` per table and only on `INTEGER`.
- `DEFAULT` accepts a fixed value, not expressions.
- Not supported: `CHECK`, `COLLATE`, generated columns.

### ALTER TABLE and DROP TABLE

```sql
ALTER TABLE customers ADD COLUMN phone VARCHAR(20) DEFAULT '-';
ALTER TABLE customers RENAME COLUMN phone TO mobile;
ALTER TABLE customers DROP COLUMN mobile;
ALTER TABLE copy RENAME TO customers_copy;
DROP TABLE IF EXISTS customers_copy;
```

`RENAME TO` also updates the foreign keys of other tables pointing at it. A
column that is part of a composite `UNIQUE` or of a foreign key (own or
another table's) cannot be dropped, and neither can a table that is referenced.

### Triggers

```sql
CREATE TRIGGER trg_check BEFORE INSERT ON orders
FOR EACH ROW
WHEN NEW.total < 0
BEGIN
    SELECT RAISE(ABORT, 'The total cannot be negative');
END;

CREATE TRIGGER trg_sum AFTER INSERT ON orders
FOR EACH ROW
BEGIN
    UPDATE customers SET balance = balance + NEW.total WHERE id = NEW.customer_id;
END;

DROP TRIGGER IF EXISTS trg_sum;
```

- Timing: `BEFORE` or `AFTER`. Event: `INSERT`, `UPDATE` or `DELETE`.
- `FOR EACH ROW` is optional (it always runs per row).
- `WHEN` filters which rows fire it.
- The body accepts `INSERT`, `UPDATE`, `DELETE` and `SELECT RAISE(...)`.
- `NEW.column` is available in INSERT and UPDATE; `OLD.column` in UPDATE and
  DELETE. They are replaced by their value before the statement runs.
- `RAISE(ABORT, 'message')` cancels the whole operation: nothing is written.
- A trigger sees the changes made by its own statement, even though they are
  not on disk yet.
- Maximum nesting of 8 levels: an infinite recursion is cut with an error and
  leaves nothing written.
- Not supported: `INSTEAD OF` and `UPDATE OF columns`.

A table with triggers is always read in full before writing to it, because a
trigger may query it; a table without them is written by the cheap paths
described in [§4](#4-atomicity-and-what-a-write-costs).

### CREATE INDEX and DROP INDEX

```sql
CREATE INDEX idx_email ON customers (email);
CREATE INDEX IF NOT EXISTS idx_city_age ON customers (city, age);
DROP INDEX idx_email;
DROP INDEX IF EXISTS idx_city_age ON customers;
SHOW INDEXES FROM customers;
```

The `PRIMARY KEY` and the `UNIQUE`s already have theirs, created automatically,
named `auto_<columns>`; they appear in `SHOW INDEXES` with `automatico = 1` and
cannot be dropped on their own — they go when the constraint goes. The `auto_`
prefix is reserved.

A composite index is used **left to right**: one on `(city, age)` serves a
lookup by `city`, or by `city` and `age`, but not by `age` alone.

Only equalities and `IN` against literals in the top-level `AND` chain of the
`WHERE` use an index. Ranges, `LIKE`, `ORDER BY`, aggregates, `IS NULL`,
`NOT IN`, a top-level `OR` and anything under a `NOT` scan the table.

Indexes also make writes cheaper: an `INSERT` checks uniqueness against the
index instead of loading the table, and an `UPDATE` or `DELETE` whose `WHERE`
an index can answer reads and rewrites only the parts that hold the affected
rows. What they cost is keeping them: a write to a table with indexes rewrites
the ones that changed. `JSONSQLDB_INDICES` set to `false` disables them
altogether.

`CREATE UNIQUE INDEX` is rejected with a message that explains it: an index
here only speeds up, it does not enforce uniqueness. For that there is
`ALTER TABLE t ADD UNIQUE (...)`, which also creates its own index.

## 3. Constraints

| Constraint | When it is checked | What happens if it fails |
|---|---|---|
| Data type | INSERT and UPDATE | error `TYPE` |
| `NOT NULL` | INSERT and UPDATE | error `CONSTRAINT` |
| Primary key / `UNIQUE` | INSERT and UPDATE | error `CONSTRAINT` |
| Foreign key (child side) | INSERT and UPDATE | error `CONSTRAINT` |
| Foreign key (parent side) | UPDATE and DELETE of the parent | according to `ON DELETE` / `ON UPDATE` |

Foreign key actions: `NO ACTION` (default), `RESTRICT`, `CASCADE`, `SET NULL`,
`SET DEFAULT`. Cascades propagate through several levels and fire the triggers
of the child tables.

If any column of the key is `NULL`, neither uniqueness nor the foreign key is
checked (same rule as standard SQL).

## 4. Atomicity, and what a write costs

A write statement **is applied in full or not at all**. Every change is
accumulated in memory and flushed to the JSON files at the end. If a constraint
fails on the third row of a three-row `INSERT`, none is written. Same with
triggers: if a `RAISE(ABORT)` fires at the deepest level of a cascade, nothing
is left half done.

Files are written with a temporary, `fsync()` and `rename`, so a `.json` cannot
be left half written even if the power goes. And because a write almost never
touches a single file — a table over `JSONSQLDB_FILAS_POR_PARTE` rows lives in
several parts, each index is a file, and every write rewrites the revision
file — the whole set is put in place under a **redo journal**: every file is
written to a temporary first, a manifest lists the renames, and a power cut in
the middle is finished when the database is next opened. Details in
[01-core.md §6](01-core.md).

What a write reads and rewrites, since 2.5:

| Statement | Reads | Rewrites |
|---|---|---|
| `INSERT` (no triggers, unique constraints indexed) | the last part and the unique indexes | the last part (plus new parts), the indexes, `rev.json` |
| `UPDATE ... WHERE key = ?` (no triggers) | the parts of the candidate rows | those parts, the indexes whose columns changed, `rev.json` |
| `DELETE ... WHERE key = ?` (no triggers) | the parts from the first deleted row on | those parts, the indexes, `rev.json` |
| anything else (`WHERE` without index, triggers, self-referencing key, `UPDATE`/`DELETE` without `WHERE`) | the whole table | the parts that changed, the indexes that changed, `rev.json` |

Measured on 20,000 rows (`php tests/benchmark.php`, mean of several runs): a
one-row `INSERT` 18 ms and 13 MB, an `UPDATE` by key 13 ms and 11 MB, a `DELETE`
by key 32 ms and 13 MB. On 100,000 rows: 104 ms / 43 MB, 47 ms / 33 MB and
182 ms / 45 MB. The cost grows with the size of the indexes, not of the table.

The comparison to keep in mind: one `INSERT` with 2,000 `VALUES` costs about
the same as one `INSERT` with one row. Batch them.

## 5. Files of this part

| File | Responsibility |
|---|---|
| `engine/Show.php` | executes the SHOW statements |
| `engine/Indexes.php` | index keys, construction and correction, choice of index for a query |
| `engine/Writer.php` | INSERT, UPDATE, DELETE, DDL, constraints and triggers |
| `engine/Integrity.php` | CHECK KEYS and REPAIR KEYS |
| `engine/Parser.php` | extended with DML, DDL, triggers and `RAISE` |
| `engine/Database.php` | decides the lock scope of each statement |
| `tests/f3_escrituras.php` | 59 checks |

## 6. Tests

```
php tests/f1_nucleo.php       → OK: 66
php tests/f2_parser.php       → OK: 70
php tests/f2_select.php       → OK: 138
php tests/f3_escrituras.php   → OK: 59
php tests/f8_indices.php      → OK: 59
php tests/f10_indices_incrementales.php → OK: 16
```

---

## Appendix: structure through SQL (SHOW and constraints)

Added for the panel, but usable from any application.

### Querying the structure

They return rows like a `SELECT`, and **read** permission is enough:

| Statement | Returns |
|---|---|
| `SHOW DATABASES` | `base` |
| `SHOW TABLES` | `tabla`, `columnas`, `filas`, `creada` |
| `SHOW VIEWS` | `vista`, `sql`, `creada` |
| `SHOW SCHEMA t` (or `SHOW COLUMNS FROM t`) | `columna`, `tipo`, `longitud`, `escala`, `pk`, `auto`, `notnull`, `unico`, `defecto` |
| `SHOW KEYS FROM t` | `tipo` (`PRIMARY`/`UNIQUE`/`FOREIGN`), `nombre`, `columnas`, `tabla_destino`, `columnas_destino`, `on_delete`, `on_update` |
| `SHOW INDEXES FROM t` | `indice`, `columnas`, `automatico` |
| `SHOW TRIGGERS [FROM t]` | `nombre`, `tabla`, `timing`, `evento`, `cuando`, `sql` |

### Modifying an existing column

```sql
ALTER TABLE customers MODIFY COLUMN balance DECIMAL(10,2) NOT NULL DEFAULT 0;
ALTER TABLE customers MODIFY COLUMN code VARCHAR(20) UNIQUE;
```

`MODIFY COLUMN` (or `CHANGE COLUMN`, or `ALTER COLUMN`) changes the type,
length, decimals, `NOT NULL`, `UNIQUE` and `DEFAULT` of an existing column. The
data is converted to the new type.

Before saving anything the current data is checked against the change: if a
value cannot be converted, if a null remains in a column that becomes
`NOT NULL` without `DEFAULT`, or if there are duplicates in one that becomes
`UNIQUE`, the statement fails and **the table stays exactly as it was**.

What `MODIFY COLUMN` cannot change: the primary key (handled separately, below)
and `AUTOINCREMENT`, which requires recreating the table. To rename,
`ALTER TABLE t RENAME COLUMN old TO new`.

### Dropping a column

```sql
ALTER TABLE customers DROP COLUMN notes;
```

The column, its value in every row and **everything that depended on it** are
removed: if it was the `AUTOINCREMENT` column, the counter goes with it.

It is not dropped, and the reason is explained, if the column:

- is part of a unique or foreign key of the table;
- is referenced by a foreign key of another table;
- is mentioned in a trigger of the table (drop or rewrite it first);
- is part of a composite primary key and removing it would leave duplicates in
  the key that remains.

If you find a table from an earlier version with an orphan `autoincrement` in
its `.meta.json`, the engine ignores it when reading and removes it the next
time the structure is saved. There is no need to touch the file.

### Checking referential integrity

The engine enforces foreign keys on every `INSERT`, `UPDATE` and `DELETE`, so
working through SQL they cannot break. But the data is JSON files on disk:
someone can edit them by hand, restore the copy of one table without the other,
or mix databases.

```sql
CHECK KEYS;                   -- checks the whole database and only reports
CHECK KEYS FROM orders;       -- only that table
REPAIR KEYS;                  -- also fixes what can be fixed on its own
```

`CHECK KEYS` returns one row per problem, with the table, the constraint, the
orphan value, what it points at and whether it can be fixed automatically. If
there are no problems, it returns zero rows.

`REPAIR KEYS` sets orphan keys to `NULL` where the column allows it. **It never
deletes rows**: if the column is `NOT NULL` or part of the primary key, it
reports and leaves it, because what to do with that data is your decision.

An important detail: the check **reads from disk, bypassing the cache**. The
cache is invalidated by a revision counter that only moves when the engine
writes, so a hand edit would stay hidden if it were read from the cache.

From the panel it is in the **Integrity** tab, with the list of problems and a
button to fix them. `CHECK` works with read permission; `REPAIR` needs write.

### Views

A view is a named, stored `SELECT`. It is queried like a table and always
returns current data because **it stores no results**: it is resolved on every
query.

```sql
CREATE VIEW v_active_customers AS
    SELECT id, name, balance FROM customers WHERE balance > 0;

CREATE VIEW IF NOT EXISTS v_totals AS
    SELECT c.name AS customer, SUM(o.total) AS spent
    FROM   customers c INNER JOIN orders o ON o.customer_id = c.id
    GROUP  BY c.name;

SELECT * FROM v_totals WHERE spent > 100 ORDER BY spent DESC;

SHOW VIEWS;
DROP VIEW v_totals;
```

It accepts everything a `SELECT` does: `JOIN`, `GROUP BY`, `HAVING`, subqueries,
and **other views** (up to 8 levels; beyond that it is cut, which is what stops
two views referring to each other from hanging the engine).

Rules:

- They are **read-only**. `INSERT`, `UPDATE` and `DELETE` on a view are rejected
  with an explicit message.
- They are dropped with `DROP VIEW`, not `DROP TABLE`. Dropping one loses no
  data: a view has none of its own.
- A view cannot have the same name as a table, nor the other way round.
- They are stored in `data/<db>/_views.json`, with the `SELECT` text as you
  wrote it. It is parsed again every time it is used.

What they do **not** do: speed anything up. A view over a three-table `JOIN`
walks the three tables every time you query it. They exist to avoid repeating
SQL and to name a complicated query, not to go faster.

### Primary key on an existing table

```sql
ALTER TABLE notes ADD PRIMARY KEY (ref, line);
ALTER TABLE notes DROP PRIMARY KEY;
```

It can only be created if the table **does not already have** a primary key: to
change it, drop it first and then add the new one. The chosen columns become
`NOT NULL`.

Before creating it the current data is checked: if any of those columns has
nulls, or there are duplicate combinations, the statement fails and nothing is
touched.

`DROP PRIMARY KEY` is not allowed if the key is `AUTOINCREMENT`: that requires
recreating the table. `AUTOINCREMENT` can only be set at creation.

### Constraints on an existing table

```sql
ALTER TABLE orders ADD CONSTRAINT uq_orders_ref UNIQUE (ref);
ALTER TABLE orders ADD UNIQUE (ref);                       -- named uq_orders_ref automatically

ALTER TABLE orders ADD CONSTRAINT fk_orders_customer
      FOREIGN KEY (customer_id) REFERENCES customers(id)
      ON DELETE CASCADE ON UPDATE NO ACTION;

ALTER TABLE orders DROP CONSTRAINT fk_orders_customer;
```

Before saving the constraint **the existing data** is checked: if a unique key
finds duplicate values, or a foreign key finds rows pointing at a non-existent
parent, the statement fails and the structure is not touched. Rows with `NULL`
in the columns involved do not count, as in SQLite.
