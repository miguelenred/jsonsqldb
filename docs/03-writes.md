# jsonSQLDB — Part 3: writes, constraints and triggers

`INSERT`, `UPDATE`, `DELETE` and all the DDL through SQL, with validation of
types, `NOT NULL`, primary key, `UNIQUE`, foreign keys, and trigger execution.

## 1. Usage

```php
$db = new Database('mydb');

$db->consultar("INSERT INTO customers (name, email) VALUES ('Ana', 'ana@example.com')");
// ['success' => true, 'filas' => 1, 'mensaje' => '1 fila(s) insertada(s)']
```

`SELECT` returns the list of rows; every other statement returns
`['success' => true, 'filas' => n, 'mensaje' => '...']`.

A write to one table takes that table's **exclusive lock**; reads of other
tables and writes to other tables carry on meanwhile. A write that can reach
other tables (foreign keys, triggers that write to them) locks that group; one
that reads other tables (`INSERT … SELECT`, a subquery, `UPDATE … FROM` or
`JOIN`, `DELETE … USING`, or a trigger whose statements read another table,
2.9), structure changes and transactions lock the whole database. See
[01-core.md §3](01-core.md#3-concurrency-two-lock-levels).

## 2. Supported statements

### INSERT

```sql
INSERT INTO customers (name, email) VALUES ('Ana', 'ana@example.com');
INSERT INTO customers (name, city) VALUES ('Luis', 'Madrid'), ('María', 'Valencia');
INSERT INTO customers VALUES (100, 'Marta', 'marta@example.com', 25.5, 'Alicante', '2026-05-01');
INSERT INTO customers (name, balance, city) VALUES ('Sara', 10 * 3 + 0.5, DEFAULT);
INSERT INTO copy (name, city) SELECT name, city FROM customers WHERE city = 'Madrid';
-- From MySQL (2.9): one row with SET, a row with all its defaults, and IGNORE (= OR IGNORE)
INSERT INTO customers SET name = 'Eva', city = 'Elche';
INSERT INTO log DEFAULT VALUES;
INSERT IGNORE INTO customers (id, name) VALUES (1, 'Ana');
```

### Upsert: when the row already exists (2.8)

As in SQLite, a row that would collide with the primary key or a `UNIQUE`
constraint can be ignored, replaced or turned into an update:

```sql
-- Skip the rows that collide
INSERT OR IGNORE INTO customers (email, name) VALUES ('ana@example.com', 'Ana');
INSERT INTO customers (email, name) VALUES ('ana@example.com', 'Ana') ON CONFLICT (email) DO NOTHING;

-- Delete the rows it collides with and insert this one (REPLACE INTO is the same)
INSERT OR REPLACE INTO customers (id, email, name) VALUES (7, 'ana@example.com', 'Ana');

-- Update the row it collides with: excluded.col is the value that was to be inserted
INSERT INTO stock (ref, qty) VALUES ('A1', 5)
  ON CONFLICT (ref) DO UPDATE SET qty = qty + excluded.qty
  WHERE excluded.qty > 0;
```

`ON CONFLICT (cols)` has to name the columns of the primary key or of a `UNIQUE`
constraint; without them, any of them counts. A collision with a key other than
the named one is still an error. `DO UPDATE` goes through everything an `UPDATE`
does: types, `NOT NULL`, the other unique keys, foreign keys and the table's
`UPDATE` triggers. `OR REPLACE` deletes the rows it collides with applying their
foreign key actions (`ON DELETE CASCADE`…), without `DELETE` triggers, as SQLite
does by default. Rows with `NULL` in a unique key never collide. MySQL's
`INSERT IGNORE` and `ON DUPLICATE KEY UPDATE a = VALUES(a) + 1` are accepted as
they are (2.9): they are `OR IGNORE` and `ON CONFLICT DO UPDATE`, with
`VALUES(col)` being the proposed value (`excluded.col`).

`OR IGNORE` also skips a row that breaks a `NOT NULL` or a `CHECK`, after its
`BEFORE INSERT` triggers, as SQLite does (2.10: it was an error, and no row of
the statement went in); the others go in. `ON CONFLICT … DO NOTHING` only
looks at the unique keys, also as in SQLite: such a row is still an error.
With `OR REPLACE` (and `REPLACE INTO`), a `NULL` for a `NOT NULL` column
takes the column's default, also after the `BEFORE` triggers, as in SQLite
(2.10: it was an error); without a default it is still an error, and so is a
row that breaks a `CHECK`.

A row that does not go in — ignored, or the one a `DO UPDATE` turns into an
update — does not use up an id of an integer primary key without
`AUTOINCREMENT`: the next one is still the largest + 1, as in SQLite (2.10: a
statement with other rows going in used one up for it). With
`AUTOINCREMENT` it uses one up, also as in SQLite, as long as some row of the
statement goes in; when none does, the counter is not written, so as not to
write the table for nothing: there SQLite leaves a gap and this engine does
not. The numbers are unique and increasing in both.

### RETURNING: the rows written (2.8)

`INSERT`, `UPDATE` and `DELETE` accept `RETURNING`, like a `SELECT` list, and
then return those rows instead of the summary — the way to know the id of a new
row without a second query, which another client could have changed in between:

```sql
INSERT INTO customers (name, email) VALUES ('Ana', 'ana@example.com') RETURNING id;
UPDATE stock SET qty = qty - 1 WHERE ref = 'A1' RETURNING ref, qty;
DELETE FROM sessions WHERE expires < CURRENT_TIMESTAMP RETURNING *;
```

`INSERT` returns the rows inserted, and the ones updated by `DO UPDATE` (not the
ignored ones); `UPDATE` the rows as they are after it; `DELETE` the rows as they
were. The order of the rows is not defined, as in SQLite. The list is worked
out before anything is written (2.10), so an error in it — a column that does
not exist, also when no row changes, or a function that fails — leaves the
table as it was, as in SQLite (before, the rows stayed written). A subquery in
it sees the tables as the statement leaves them (2.10; before, it was a PHP
error), where SQLite works it out as each row goes, so with several rows the
two can differ. A trigger cannot use `RETURNING`, as in SQLite (2.10; its
rows were added to those of the statement that fired it).

### UPDATE and DELETE

```sql
UPDATE customers SET balance = balance + 100, city = UPPER(city) WHERE id = 3;
DELETE FROM orders WHERE date < '2026-01-01';
```

Without `WHERE` they affect the whole table.

As in MySQL and SQLite (2.9), they take `ORDER BY` and `LIMIT n [OFFSET m]`
after the `WHERE`: only those rows change, in that order (which is also the
order their triggers fire in). `RETURNING` goes before or after them. A
negative `LIMIT` means no limit.

```sql
UPDATE queue SET taken = 1 WHERE taken = 0 ORDER BY created LIMIT 10 RETURNING id;
DELETE FROM log ORDER BY date LIMIT 1000;
```

The table can have an alias (2.9): `UPDATE customers AS c SET … WHERE c.id = 3`.

#### UPDATE OR IGNORE and UPDATE OR REPLACE (2.10)

As in SQLite, what to do with a row whose new values would break a
constraint:

```sql
-- Leave as they were the rows that would break a NOT NULL, a CHECK or a unique key
UPDATE OR IGNORE customers SET email = LOWER(email);
-- Delete the other rows with that unique key; a NULL in a NOT NULL column takes its default
UPDATE OR REPLACE customers SET email = 'ana@example.com' WHERE id = 3;
```

- `OR IGNORE` skips the row after its `BEFORE UPDATE` triggers, without its
  `AFTER` ones; the others change. A foreign key it would break is still an
  error, as in SQLite.
- `OR REPLACE` deletes the rows it collides with, applying their foreign key
  actions, without `DELETE` triggers, as `INSERT OR REPLACE`. A `CHECK`, or a
  `NULL` in a `NOT NULL` column without a default, is still an error.
- `OR ABORT` is the usual behaviour; `OR FAIL` and `OR ROLLBACK` are an error,
  as in an `INSERT`: every statement here is all or nothing.
- With several rows, which ones collide can depend on the order they are
  changed in, which is not defined: SQLite goes by `rowid`, this engine by the
  place of each row in the table. Each row changes once: in SQLite, a row whose
  `INTEGER PRIMARY KEY` moves onto one the same statement still has to change
  is changed again.

#### With other tables (2.9)

The rows to change, and the values, can come from other tables, in the forms
of SQLite and PostgreSQL and in those of MySQL:

```sql
-- SQLite and PostgreSQL
UPDATE stock SET qty = stock.qty - o.qty FROM orders o WHERE o.ref = stock.ref AND o.day = '2026-10-08';
DELETE FROM sessions USING users u WHERE u.id = sessions.uid AND u.blocked = 1;
-- MySQL
UPDATE stock s JOIN orders o ON o.ref = s.ref SET s.qty = s.qty - o.qty WHERE o.day = '2026-10-08';
DELETE s FROM sessions s JOIN users u ON u.id = s.uid WHERE u.blocked = 1;
DELETE FROM sessions USING sessions JOIN users u ON u.id = sessions.uid WHERE u.blocked = 1;
```

- The join is computed once, with the `SELECT` engine and the data as it was
  before the statement, and then each row of the table is written as usual:
  `CHECK`, `NOT NULL`, unique and foreign keys, cascades, triggers and
  `RETURNING` (only columns of that table) work as in any `UPDATE` or `DELETE`.
  Subqueries, views, `LEFT JOIN` and the other joins can be used.
- If a row matches several rows of the other tables, it is changed once, with
  the **first** match of the join. SQLite and PostgreSQL say that which one is
  used is not defined; MySQL also uses the first.
- **One table is written.** In `UPDATE … JOIN … SET`, it is the table of the
  columns written with their table in front (`SET s.qty = …`); if none has it,
  the first one. Columns of two tables in the `SET`, or `DELETE t1, t2 FROM …`,
  are an error that says so.
- `ORDER BY` and `LIMIT` are not accepted with other tables (neither in MySQL).
- The tables of the `FROM` and of the `JOIN` are joined by hash on the
  equalities between their columns, as in a `SELECT`: changing 5,000 rows of a
  table of 20,000 from another table takes 95 ms (the same with subqueries,
  `SET x = (SELECT …) WHERE id IN (SELECT …)`, takes 14.7 s).

`TRUNCATE TABLE t` (MySQL, 2.9) deletes all the rows without firing triggers
and puts the `AUTOINCREMENT` counter back at 1; if another table has a foreign
key to it, it is an error (use `DELETE FROM`), while a foreign key of the table
to itself does not stop it, as in MySQL. It does not read the rows: the table
is written empty, together with its structure, in a single write.

When no index can answer the `WHERE`, they go through the table part by part
and keep in memory only the rows that match (2.8): an `UPDATE` of a few rows of
a large table does not load it whole. With triggers on the table, a foreign
key to itself or a unique key without an index, the table is loaded as before.

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

- Table names are not case-sensitive, as in SQLite (2.10): `Orders`, `ORDERS`
  and `orders` are the same table, written to disk as it was created, and
  creating `orders` when `Orders` exists is an error that names it. Before, they
  were different tables, which on Windows and macOS were the same files. Two
  tables of an earlier version whose names only differ in case still work,
  written exactly; written otherwise, the error names both.
- Column constraints: `PRIMARY KEY [AUTOINCREMENT]`, `NOT NULL`, `UNIQUE`,
  `DEFAULT value`, `REFERENCES table(col) [ON DELETE ...] [ON UPDATE ...]`.
- Table constraints: `PRIMARY KEY (cols)` (composite keys allowed),
  `UNIQUE (cols)`, `FOREIGN KEY (cols) REFERENCES ...`, with `CONSTRAINT name`.
  Two unique keys of a table cannot share a name, and that includes the names
  the keys without one get: `PRIMARY` for the primary key and
  `uq_<table>_<columns>` for an unnamed `UNIQUE` (`uq_t_a_b` for `(a, b)`;
  2.9; before, one of the
  two stopped being checked). The same name on the same columns is accepted,
  as before.
- `CHECK (expression)` on a column or on the table, `[CONSTRAINT name] CHECK
  (...)` (2.9): every row written by `INSERT`, `UPDATE`, `UPSERT` or a cascade
  must not make it false; `NULL` passes, as in SQLite and MySQL. No parameters
  or subqueries in it. A column used in a `CHECK` cannot be dropped or renamed.
  `ALTER TABLE t ADD [CONSTRAINT name] CHECK (…)` first checks the rows already
  there; `ALTER TABLE t DROP CHECK name` (or `DROP CONSTRAINT name`) removes it.
- `ENUM('a', 'b', …)` (2.9, from MySQL): a text that only takes those values,
  matched without regard to case and stored as written in the list; with
  `NOT NULL` and no `DEFAULT`, the first one is the default. Unlike MySQL, it
  sorts as text, not by its position in the list.
- One `AUTOINCREMENT` per table and only on `INTEGER`.
- An integer primary key of one column without `AUTOINCREMENT`
  (`id INTEGER PRIMARY KEY`) numbers itself as in SQLite (2.9): an `INSERT`
  that leaves it out or gives `NULL` gets the largest id + 1, from the range
  its index keeps for each piece, without opening a file, or from the keys of
  the index when the statement has already removed or changed rows (2.10: it
  read the rows of the largest keys, which in a table not in memory meant
  decoding a part; `StorageMayores`). The id of a deleted row that was the
  largest can come back; use `AUTOINCREMENT` if an id must never be reused.
  Its counter also travels in the panel's dumps (2.10), so a copy does not
  reuse them either.
- `DEFAULT` accepts a fixed value, `CURRENT_TIMESTAMP`, `CURRENT_DATE`,
  `CURRENT_TIME` or an expression in parentheses (`DEFAULT (upper('x'))`), as in
  SQLite (2.8). The latter are worked out each time a row is inserted (and with
  `UPDATE … SET col = DEFAULT`); they cannot use columns, subqueries or
  parameters, and a column with one cannot be added to a table that has rows.
  `CURRENT_TIMESTAMP` is the date and time of the server, like `DATETIME('now')`.
- `SHOW CREATE TABLE t` and `SHOW CREATE VIEW v` (2.9) give the statement
  that creates them as they are now (`tabla`/`vista` and `sql`), with a
  `CREATE INDEX` for each index that does not come from a key: run in another
  database, it makes the same structure.
- `COLLATE NOCASE` or `COLLATE RTRIM` in a text column (2.9): how it compares,
  also in its `UNIQUE`, its indexes and the foreign keys that point to it
  ([Part 2](02-queries.md)).
- Not supported: generated columns.
- **From a MySQL dump** (2.9) it also accepts: `UNSIGNED` (a negative value is
  an error, as in MySQL's strict mode; `SHOW COLUMNS` says it in `sin_signo`),
  `COMMENT '…'` and `CHARACTER SET` on a column (they change nothing: here
  everything is UTF-8 text in JSON), `KEY` / `INDEX` and `UNIQUE KEY` inside the
  table (an index; a unique key), the types `TINYTEXT`, `MEDIUMTEXT`,
  `LONGTEXT`, `JSON`, `BINARY`, `VARBINARY`, the `BLOB`s and `UUID` (text), `BIT`
  and `YEAR` (integer), and the table options `ENGINE`, `DEFAULT CHARSET`,
  `COLLATE`, `ROW_FORMAT` and `COMMENT` (accepted, they change nothing) and
  `AUTO_INCREMENT = n` (the first number of the counter). `ZEROFILL`,
  `ON UPDATE CURRENT_TIMESTAMP`, `FULLTEXT` and `SPATIAL` are errors that say
  what to do instead. `CREATE TABLE t2 LIKE t` creates the same structure,
  empty, with its `CHECK`s and without its foreign keys, as MySQL does.
- **Column names** can have spaces or signs, as in SQLite (2.8): from 1 to 64
  characters, no control characters. Such a name is written between double
  quotes: `"unit price"`. Table, index and trigger names are letters, digits
  and `_`, starting with a letter or `_`, because they are part of file names.

### CREATE TABLE … AS SELECT (2.8)

```sql
CREATE TABLE big_customers AS
    SELECT id, name, balance * 1.21 AS with_vat FROM customers WHERE balance > 1000;
```

A table with the result of a query, filled in one write. As in SQLite it has no
primary key, `UNIQUE`, `NOT NULL`, defaults or foreign keys: add them afterwards
with `ALTER TABLE` if you need them. `IF NOT EXISTS`, `WITH` and `UNION` work.

- A column taken as it is from a table keeps its type, length and scale.
- Any other takes its type from its values: `INTEGER` if they are all whole
  numbers, `DOUBLE` if there are decimals, `TEXT` if they are texts, all `NULL`
  or a mix of numbers and texts. SQLite leaves such a column without a type and
  keeps each value as it is; here a column has one type, and each value is
  stored converted to it: in that mix the numbers are stored as text, and the
  whole numbers of a `DOUBLE` column as decimals (2.9; before, they were stored
  as they came).
- An expression without `AS` names its column as in any `SELECT`:
  `SELECT id + 1 FROM t` gives a column `"id + 1"` (SQLite calls it `id+1`, as
  it was typed). Two columns with the same name get `_2`, `_3`…
- The result is not gathered in memory (2.9): its rows are written in batches
  as they come out, inside a transaction, so the statement is still all or
  nothing, as an `INSERT … SELECT` from other tables does (§4).

### ALTER TABLE and DROP TABLE

```sql
ALTER TABLE customers ADD COLUMN phone VARCHAR(20) DEFAULT '-';
ALTER TABLE customers RENAME COLUMN phone TO mobile;
ALTER TABLE customers DROP COLUMN mobile;
ALTER TABLE copy RENAME TO customers_copy;
DROP TABLE IF EXISTS customers_copy;
-- From MySQL (2.9): rename and change the type at once, indexes and foreign keys
ALTER TABLE customers CHANGE name full_name VARCHAR(80) NOT NULL;
ALTER TABLE customers ADD INDEX ix_city (city);
ALTER TABLE customers DROP INDEX ix_city;
ALTER TABLE orders DROP FOREIGN KEY fk_customer;
ALTER TABLE orders AUTO_INCREMENT = 1000;  -- 2.10: raises the counter, never lowers it
DESCRIBE customers;                    -- = SHOW COLUMNS FROM customers
```

`ALTER TABLE t AUTO_INCREMENT = n` (2.10, from MySQL) makes `n` the next value
of the table's `AUTOINCREMENT` if it is larger than the one it has; a smaller
one changes nothing, so an id is never given twice. It is what a dump uses to
carry the counter (see [Part 5](05-admin.md)).

`RENAME TO` also updates the foreign keys of other tables pointing at it. It
is refused, saying which, while a view or a trigger (of any table, its own
included) uses the table (2.9; before, they were left pointing at the old
name and failed when used): their SQL is kept as it was written, and an alias,
a column or a `WITH` table can have the same name, so drop them, rename the
table and create them again with the new name. The name of a view is not
accepted either (2.9; the table was left hidden behind the view). A
column that is part of a composite `UNIQUE` or of a foreign key (own or
another table's) cannot be dropped, and neither can a table that is referenced.

A `CHECK` or `REFERENCES` written on the column of an `ADD COLUMN`, `MODIFY` or
`CHANGE` goes to the table as in `CREATE TABLE` (2.9; before, they were lost
without a word), after checking that the rows already there meet it, with the
new column's `DEFAULT`; if any does not, the table stays as it was. `ADD COLUMN
… UNIQUE DEFAULT x` is refused when the table has two rows or more: they would
all get the same value; and so is `ADD COLUMN … PRIMARY KEY DEFAULT x` (2.9),
unless the table already has a primary key, which the new column then joins.

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
- The body accepts `INSERT`, `UPDATE`, `DELETE` and `SELECT` (such as
  `SELECT RAISE(...)`); any other statement is an error when the trigger is
  created (2.9; before, on every write to the table).
- `NEW.column` is available in INSERT and UPDATE; `OLD.column` in UPDATE and
  DELETE. They are replaced by their value before the statement runs. Using
  `NEW` in a `DELETE` trigger or `OLD` in an `INSERT` one, in the body, the
  `WHEN`, an `IF` or a `SET NEW`, is an error when the trigger is created, as in
  MySQL (2.9; before, the error came on every write to the table).
- `RAISE(ABORT, 'message')` cancels the whole operation: nothing is written.
- **`IF … THEN … ELSEIF … ELSE … END IF`** inside the body, nested if needed
  (2.7.3), as in MySQL and PostgreSQL.
- **`SET NEW.column = expression`** in a `BEFORE INSERT` or `BEFORE UPDATE`
  trigger changes the row before it is written (2.7.3). Several assignments go
  left to right, each one seeing the previous ones, and the value is converted
  to the column's type; the row then goes through the same checks as any other
  (`NOT NULL`, unique keys, foreign keys). Anywhere else it is rejected when the
  trigger is created.

  ```sql
  CREATE TRIGGER trg_products BEFORE INSERT ON products BEGIN
      IF NEW.price < 0 THEN SELECT RAISE(ABORT, 'Negative price');
      ELSEIF NEW.price >= 100 THEN SET NEW.band = 'high';
      ELSE SET NEW.band = 'low';
      END IF;
      SET NEW.name = UPPER(NEW.name);
  END;
  ```
- A trigger sees the changes made by its own statement, even though they are
  not on disk yet.
- An `UPDATE` or `DELETE` works on the rows it chose when it started, each as
  the triggers and cascades of the earlier rows left it, and skips one they
  deleted, as SQLite does (2.9; before, one they had changed was skipped too,
  and a `DELETE` left it in the table). Its `WHERE` is not checked again.
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
prefix is reserved. Since 2.9 two keys whose index would get the same name,
such as `UNIQUE (a, b)` and `UNIQUE (a_b)` (both `auto_a_b`), are refused:
rename one of the columns. An index you create on exactly the columns of one
of those keys serves the key instead, and `SHOW INDEXES` lists it in place of
the `auto_` one, which comes back if you drop it. In a table made so before 2.9, which did not check
`(a, b)`, the second key is checked by reading the table.

A composite index is used **left to right**: one on `(city, age)` serves a
lookup by `city`, or by `city` and `age`, but not by `age` alone; and by `city`
alone fully only when `age` is `NOT NULL` (or in the primary key): a row with
`NULL` in any column of an index is not in it, so the index serves the parts
where every row is in it and the others are read whole (2.10; before, an
`UPDATE` or `DELETE` by `city` missed the rows whose `age` was `NULL` from
2.5.0, and a `SELECT` from 2.7.4). Declare such columns `NOT NULL`, or index
`city` on its own.

Equalities and `IN` against literals in the top-level `AND` chain of the
`WHERE` use an index; since 2.9 also ranges (`<`, `<=`, `>`, `>=`, `BETWEEN`)
on the first column of an index, `COUNT(*)` of such comparisons on one
column, counted in the index, and, on a numeric column that is the first of
an index, `MIN`/`MAX` and `ORDER BY col [DESC] LIMIT n` without `WHERE` —
always with the rule above for the columns after it ([Part 2](02-queries.md),
Performance). `LIKE`, other aggregates, `IS NULL`, `NOT IN`, a top-level `OR`
and anything under a `NOT` scan the table.

Indexes also make writes cheaper: an `INSERT` checks uniqueness against the
index instead of loading the table, and an `UPDATE` or `DELETE` whose `WHERE`
an index can answer reads and rewrites only the parts that hold the affected
rows. What they cost is keeping them: a write to a table with indexes rewrites
the ones that changed. `JSONSQLDB_INDICES` set to `false` disables them
altogether.

`CREATE UNIQUE INDEX name ON t (cols)` (2.9) creates a `UNIQUE` constraint with
that name, which enforces uniqueness and has its own index (if the rows already
repeat a value, it is an error); `DROP INDEX name` drops it. In an index
definition, `DESC` and a prefix length (`email(20)`) are accepted and change
nothing.

## 3. Constraints

| Constraint | When it is checked | What happens if it fails |
|---|---|---|
| Data type | INSERT and UPDATE | error `TYPE` |
| `NOT NULL` | INSERT and UPDATE | error `CONSTRAINT` |
| Primary key / `UNIQUE` | INSERT, UPDATE and rows changed by a cascade (2.9) | error `CONSTRAINT` |
| Foreign key (child side) | INSERT, UPDATE and rows changed by a cascade (2.9); a row without its parent, again when the statement ends (2.10) | error `CONSTRAINT` |
| Foreign key (parent side) | UPDATE and DELETE of the parent | according to `ON DELETE` / `ON UPDATE`; `NO ACTION` when the statement ends, `RESTRICT` at once (2.10) |
| `CHECK` (2.9) | INSERT, UPDATE, upsert and cascades; `ADD CHECK` against the rows already there | error `CONSTRAINT` (`NULL` passes) |
| `ENUM` (2.9) | INSERT and UPDATE | error `TYPE` |

Foreign key actions: `NO ACTION` (default), `RESTRICT`, `CASCADE`, `SET NULL`,
`SET DEFAULT`. Cascades propagate through several levels and fire the triggers
of the child tables. As in SQLite, the parent row is written or deleted first
and its cascades run after it, so the triggers of the child tables already see
it changed, and a row they add pointing at a parent the statement has deleted
is a foreign key error (2.9). The row an `OR REPLACE` removes is the exception:
its cascades run first, because the new row usually brings the same key back.

**`NO ACTION` and a row without its parent are checked when the statement
ends**, as in SQLite (2.10; before, at once, row by row). What counts is how
the statement leaves the tables: an `INSERT OR REPLACE` of a parent that has
children brings its key back, `DELETE FROM` a table that points at itself
takes children and parents together, a row may come before its parent in the
same `INSERT`, a trigger may delete the children of the row being deleted (or
empty their table with `TRUNCATE`), and
the ids of a tree may all change in one `UPDATE`; in 2.9 each of these was an
error. If a child is still without its parent when the statement ends, the
statement fails and leaves nothing, as before. `RESTRICT` is still checked at
once, as in SQLite: the parent cannot go even if the statement would bring it
back. A child row changed by `ON UPDATE CASCADE`,
`SET NULL` or `SET DEFAULT` goes through everything an `UPDATE` does (2.9):
its `BEFORE` and `AFTER UPDATE` triggers, its unique keys (two children that
would end up with the same key are an error, as in SQLite), its own foreign
keys (a `SET DEFAULT` value that is not in the parent is an error) and the
cascade to its own children, also when the key that changes is the one they
point at. Before 2.9 only its types were converted.

**An index on the foreign key columns of the child table** (`CREATE INDEX
ix_orders_customer ON orders (customer_id)`) is not created on its own, as in
SQLite, but it is worth creating when the child table is large: deleting or
changing a customer then finds its orders through the index instead of
loading the whole orders table (2.8); with triggers on the child table it is loaded as before.
The orders the same statement has already changed without loading the table
(a cascade, or an `UPDATE` of the orders whose cascade comes back to them
through a trigger) are looked for among its changes too (2.10: the index did
not have them yet, and a later cascade of their new key missed them, also in
2.9.0).

The keys are compared the same way everywhere: `ADD UNIQUE`, `ADD PRIMARY
KEY`, `ADD FOREIGN KEY`, `MODIFY`, `DROP COLUMN` and `CHECK KEYS` / `REPAIR
KEYS` use the equality of writes (2.9; before, they compared them as typed, so
`REPAIR KEYS` could set to `NULL` a child whose parent the engine itself
finds). Since 2.10 that equality is SQLite's: in a `TEXT` column `'5'` and
`'05'` are two keys, and a number written there is its text (`5` is `'5'`);
in a numeric column `5`, `5.0` and `'5'` are one. A child is compared with the
affinity of the parent's columns: a `TEXT` child pointing at an `INTEGER` key
finds `'7'` under 7. Until 2.9 a `UNIQUE` text column refused `'007'` next
to `'7'`.

If any column of the key is `NULL`, neither uniqueness nor the foreign key is
checked (same rule as standard SQL).

## 4. Atomicity, and what a write costs

A write statement **is applied in full or not at all**. Every change is
accumulated in memory and flushed to the JSON files at the end. If a constraint
fails on the third row of a three-row `INSERT`, none is written. Same with
triggers: if a `RAISE(ABORT)` fires at the deepest level of a cascade, nothing
is left half done.

**A statement whose changes do not fit in memory is written in stretches**
(2.9). When the memory in use passes about half of `memory_limit`, it writes
what it has and goes on; outside a transaction it is first run again inside
one, before writing anything, so that it is still all or nothing. What it
writes then is what it changed in the tables it has not loaded; a table it
has loaded in memory (one with triggers, or the child of a cascade without an
index) is written when it ends, so that its rows keep their positions while
the statement, its triggers and its cascades go on using them (2.10). An `INSERT …
SELECT` from other tables takes their rows as they come out, in batches of up
to 8,192 rows or 8 MB (a sixteenth of `memory_limit` if that is less), without
gathering them all, and an upsert (`ON CONFLICT`, `INSERT OR IGNORE`, `INSERT
OR REPLACE`, `REPLACE`) does not load the table any more: it looks each key up
in the index on disk — through the whole index in memory if it fits in what is
left up to half of `memory_limit`, and if not, key by key in the index pieces
whose filter allows it. With a million rows and 256 MB, an `UPDATE` of every
row takes 24 s and 140 MB; in 2.8.0, one of 100,140 rows spread over the table
ran out of memory.

Files are written with a temporary, `fsync()` and `rename`, so a `.json` cannot
be left half written even if the power goes. And because a write almost never
touches a single file — a table over `JSONSQLDB_FILAS_POR_PARTE` rows lives in
several parts, each index is a file, and every write rewrites the revision
file — the whole set is put in place under a **redo journal**: every file is
written to a temporary first, a manifest lists the renames, and a power cut in
the middle is finished when the database is next opened (inside a transaction,
since 2.10, the files the transaction keeps do that job, and the statement
writes no journal). Details in [01-core.md §6](01-core.md).

What a write reads and rewrites, since 2.5:

| Statement | Reads | Rewrites |
|---|---|---|
| `INSERT` (no triggers, unique constraints indexed) | the last part and the unique indexes | the last part (plus new parts), the last piece of each index, `rev.json` |
| `UPDATE ... WHERE key = ?` (no triggers) | the parts of the candidate rows | those parts, the matching pieces of the indexes whose columns changed, `rev.json` |
| `DELETE ... WHERE key = ?` (no triggers) | the parts of the candidate rows | those parts, with a hole in place of each deleted row (2.9), the index pieces of those rows, `rev.json` |
| `UPDATE`/`DELETE` with a `WHERE` no index answers (no triggers, unique constraints indexed; 2.8) | every part, one at a time, keeping only the rows that match | the parts that changed, the index pieces that changed, `rev.json` |
| anything else (triggers, self-referencing key, unique constraint without index, `UPDATE`/`DELETE` without `WHERE`) | the whole table | the parts that changed, the index pieces that changed, `rev.json` |

An index is stored in one piece per part of the table (2.6), so a write
rewrites the pieces of the parts it touched and leaves the rest alone.

Measured with `php tests/benchmark.php` and 2.10 (on-disk cache, one core,
median of seven runs, and of five on 100,000): a one-row `INSERT` 6.3 ms and
5.5 MB, an `UPDATE` by key 4.8 ms and 6.2 MB on 20,000 rows, where 2.9.0 took
7.2 and 5.7 ms in the same rounds; 6.6 ms / 6.1 MB and 5.4 ms / 6.7 MB on
100,000, where 2.9.0 took 8.1 and 6.4 ms. On their own (one-row writes in a
process, the two versions taking turns, on 200,000 rows), `INSERT` 4.68 ms
against 6.50, `UPDATE` 4.35 against 5.33 and `DELETE` 4.40 against 6.39, with
the same five `fsync` calls (see the CHANGELOG, 2.10, *Faster*). While preparing 2.10, a `DELETE` by key of a row
without children: 7.1 ms on a table of 60,000 rows (the best of seven
rounds). The cost of a write no longer grows with the
size of the table nor with the size of its indexes; what remains is checking
uniqueness, which for a numeric key looks at the pieces whose range can hold
it (a new id is rejected as unique without opening any) and for a text key,
since 2.9, at the keys of the one piece whose filter allows it (pieces written
before 2.9 have no filter and are read whole). Since 2.9 a `DELETE` leaves a
hole instead of moving the rows behind it: on 60,000 rows with six indexes,
deleting rows at five places took 443, 615, 26, 221 and 199 ms in 2.8.0 and
42, 24, 28, 20 and 20 ms in 2.9.

An `INSERT` appends its rows to the text of the last part and an `UPDATE`
replaces the lines of the rows it changes, without decoding or re-encoding the
rest of the part (2.7.1); the file ends up byte for byte as if written whole. An
`UPDATE` or `DELETE` that depends only on each row does its work with the
table's shared lock and locks the table only to commit ("writes by part", see
[01-core.md](01-core.md#writes-by-part-27)).

On a real disk the cost is the `fsync` calls, not the encoding: five per write
since 2.7 (the part, `rev.json`, the manifest, the `.tx/` folder and the
database folder; six when it fills the last part and also opens a new one),
down from ten in 2.6. Index pieces are written without one,
since they can be rebuilt from the rows if a crash loses them. With an `fsync`
of 3 ms, typical of a shared host, a one-row `INSERT` took about 28 ms when
measured in 2.7; see
[01-core.md](01-core.md#several-files-the-redo-journal).

The comparison to keep in mind: one `INSERT` with 2,000 `VALUES` costs about
the same as one `INSERT` with one row. Batch them.

### Transactions (2.9)

Several statements that must happen together, or not at all, go in a
transaction. With the direct connection:

```php
$db->transaccion(function (Database $db) {
    $db->consultar('UPDATE accounts SET balance = balance - 100 WHERE id = 1');
    $db->consultar('UPDATE accounts SET balance = balance + 100 WHERE id = 2');
});
```

Everything the function does to the database happens, or nothing does. Inside,
each statement sees what the previous ones did; other processes see nothing
until it ends. An exception that leaves the function undoes it all, and the
exception goes on. A statement that fails inside leaves nothing of its own: if
the function catches its error, the rest goes on and is committed. The
exception is a statement too large for memory, which writes as it goes (in
stretches, 2.9): if it fails after writing one, the transaction can only be
undone, as when a statement leaves part of its files in place or one of them
half written (2.10: if the error was caught, it was committed with the
statement half done). From then on every statement of the transaction is an
error that says so, reads too (2.10; before, they went on and saw the half
written data). A statement whose only file cannot be renamed into place
leaves it as it was, and the transaction goes on. If undoing cannot put an
original back (a file held open by another process on Windows), the
connection leaves the transaction anyway, and its next statement, or any
other process, finishes undoing it before going on (2.10; before, that
connection stayed inside it, and what it wrote afterwards was undone too). `$db->lote([...])` does the same with
a list of statements (each a string, or `[sql, params]`) and returns one
result per statement. A `BEGIN` (or `START TRANSACTION`) as its first statement
and a `COMMIT` (or `END`) as its last are taken and do nothing more; a
`ROLLBACK` as its last runs the list and undoes all of it. Anywhere else, and
as a statement of their own, they are an error that says why.

The API takes a list as the `lote` field ([04-api.md](04-api.md)), and the
panel's SQL console runs several statements separated by `;` as one
transaction.

- **It locks the whole database** from start to end, which is what keeps
  others from seeing it half done. Keep it short: reads and writes of every
  other process wait for it. A `lote` that only reads (2.9) does not: each of
  its statements takes the shared lock and releases it, as a single `SELECT`,
  others read at the same time, and nothing is written to undo. Between two of
  its statements another process may write, so they do not all see the same
  data.
- **Transactions never span requests.** There is no `BEGIN` in one call and
  `COMMIT` in the next: a client that hangs in between would leave the database
  locked.
- **If the process dies halfway**, the transaction is undone when the database
  is next opened. How it is kept is in
  [01-core.md §6](01-core.md#several-files-the-redo-journal). Its statements
  write no journal of their own (2.10): the originals the transaction keeps
  undo them, so each one saves writing a manifest and its `fsync`s. Nor do
  they force their files to disk: committing does, once per file, before the
  point of no return. Nor do they write the shared cache
  (APCu or `.cache/`), which the next statement left stale: only this
  process's. And a `UNIQUE` on a text is looked up in the text of the index
  pieces in every statement, where from the ninth on 2.9 gathered the whole
  index in memory again for each one. A transaction of 100 one-row `INSERT`s
  into a table of 30,000 rows with a `UNIQUE` text makes 10 `fsync` calls (505
  in 2.9.0) and takes about 87 ms instead of 957 with 2.9.0, with a peak of
  4.2 MB instead of 6.9; 50 `UPDATE`s by key, about 88 ms instead of 200
  (medians of 12 runs, the two engines taking turns, on the same machine).
  Once a statement has replaced a file, the next ones write it in its
  place, without a temporary file or a rename (2.10): its original is already
  safe in `.lote/`, nobody else reads the database meanwhile, and committing
  forces it to disk with the rest. See
  [01-core.md §6](01-core.md#several-files-the-redo-journal). If a file cannot
  be put in its place half-way through a statement (on Windows, one another
  program holds open), the transaction can only be undone: it takes no more
  writes, and it is undone when the function returns, with an error.
- **`REPAIR TABLES FORGET n` takes effect when it is committed**, so a
  transaction undone leaves the row pending. `REPAIR TABLES` itself is refused
  inside one: what it sets aside is recorded outside the transaction.
- **They do not nest**: `transaccion()` inside another is an error.
- A single statement works exactly as before: it is already atomic on its own.

## 5. Files of this part

| File | Responsibility |
|---|---|
| `engine/Show.php` | executes the SHOW statements |
| `engine/Indexes.php` | index keys and definitions, and what reads and writes share about them |
| `engine/IndexesBusqueda.php` | which conditions of a `WHERE` an index can resolve, and with which index (2.9) |
| `engine/IndexesEscritura.php` | what keeps an index up to date when writing: the summary of each piece, its keys by position, the keys that come, change or go with the rows (2.10) |
| `engine/Writer.php` | what every write shares: constraints, the foreign keys of a row, triggers |
| `engine/WriterInsercion.php` | `INSERT`: the new rows, their unique keys and their integer primary key or `AUTOINCREMENT` (2.10; in `Writer` before) |
| `engine/StorageMayores.php` | the largest values of a numeric index, without reading rows: the next id of an `INSERT` that leaves it out (2.10) |
| `engine/WriterAvanzado.php` | `RETURNING`, and the check that what `RANDOMBLOB` and `ZEROBLOB` give is not stored as it is (2.10; in `Writer` before) |
| `engine/WriterCambios.php` | `UPDATE` and `DELETE` (2.9) |
| `engine/WriterCambiosEspeciales.php` | `TRUNCATE`, and an `UPDATE` or a `DELETE` that joins other tables (2.10; in `WriterCambios` before) |
| `engine/WriterForaneas.php` | foreign keys: that the parent row exists, and the `ON DELETE` and `ON UPDATE` actions (2.9) |
| `engine/WriterConflictos.php` | `OR REPLACE`, `OR IGNORE`, `REPLACE INTO`, `ON CONFLICT … DO UPDATE` and `ON DUPLICATE KEY UPDATE` (2.9); what `UPDATE OR REPLACE` borrows from them (2.10) |
| `engine/WriterTriggers.php` | the body of triggers: `WHEN`, their statements, `IF` blocks and `SET NEW` (2.9) |
| `engine/SentenciaGrande.php` | a write that does not fit in memory and is run again inside a transaction, in stretches (2.9) |
| `engine/WriterDdl.php` | the statements that are not `INSERT`, `UPDATE` or `DELETE`: `CREATE TABLE` (also `AS SELECT`), `DROP TABLE`, `ALTER TABLE`, indexes, views, triggers, `REPAIR` and `VACUUM` (2.9) |
| `engine/Integrity.php` | CHECK KEYS and REPAIR KEYS; CHECK INDEXES and REPAIR INDEXES (2.10, the work is in `StorageMantenimiento.php`) |
| `engine/ParserEscritura.php` | `INSERT`, and what it shares with `UPDATE` and `DELETE`; extends `Parser` (2.9) |
| `engine/ParserCambios.php` | `UPDATE` and `DELETE` (2.10; in `ParserEscritura` before) |
| `engine/ParserEscrituraAvanzada.php` | the `SET NEW` of triggers, and the `FROM` of an `UPDATE` and the `USING` of a `DELETE` with other tables (2.10; in `ParserEscritura` before) |
| `engine/ParserSentencias.php` | DDL, triggers and `RAISE`, `SHOW`, `CHECK`, `REPAIR`, `EXPLAIN` and the rest; extends `ParserEscritura` (2.9) |
| `engine/Database.php` | decides the lock scope of each statement |
| `engine/CatalogBloqueos.php` | the tables a write that can reach several has to lock: its foreign keys both ways and what its triggers write (2.10; in `Catalog` before) |
| `tests/f3_escrituras.php` | 122 checks, transactions among them |
| `tests/f5_esquema.php` | 125 checks, `CREATE TABLE … AS SELECT` among them |

## 6. Tests

```
php tests/f1_nucleo.php       → OK: 76
php tests/f2_parser.php       → OK: 86
php tests/f2_select.php       → OK: 165
php tests/f3_escrituras.php   → OK: 122
php tests/f5_esquema.php      → OK: 125
php tests/f8_indices.php      → OK: 93
php tests/f10_indices_incrementales.php → OK: 22
php tests/f21_compatibilidad.php → OK: 132
```

---

## Appendix: structure through SQL (SHOW and constraints)

Added for the panel, but usable from any application.

### Querying the structure

They return rows like a `SELECT`, and **read** permission is enough:

| Statement | Returns |
|---|---|
| `SHOW DATABASES` | `base` |
| `SHOW TABLES` | `tabla`, `columnas`, `filas`, `creada`, `autoincremento` (2.10: the next value of its `AUTOINCREMENT`, or `null`) |
| `SHOW VIEWS` | `vista`, `sql`, `creada` |
| `SHOW SCHEMA t` (or `SHOW COLUMNS FROM t`) | `columna`, `tipo`, `longitud`, `escala`, `pk`, `auto`, `notnull`, `unico`, `defecto`, `defecto_calculado` (2.8), `sin_signo` (2.9), `valores` (2.9: an `ENUM`'s list, or `null`), `colacion` (2.9: `NOCASE`, `RTRIM` or `null`) |
| `SHOW CREATE TABLE t` / `SHOW CREATE VIEW v` (2.9) | `tabla` or `vista`, `sql` |
| `SHOW KEYS FROM t` | `tipo` (`PRIMARY`/`UNIQUE`/`FOREIGN`/`CHECK`), `nombre`, `columnas`, `tabla_destino`, `columnas_destino`, `on_delete`, `on_update`, `expresion` (2.9: a `CHECK`'s expression; `nombre` is empty for an unnamed one) |
| `SHOW INDEXES [FROM t]` | `indice`, `tabla`, `columnas`, `automatico` |
| `SHOW TRIGGERS [FROM t]` | `nombre`, `tabla`, `timing`, `evento`, `cuando`, `sql` |

In `SHOW KEYS` and `SHOW INDEXES`, `columnas` and `columnas_destino` list the
columns separated by commas; a name with a comma or a double quote in it goes
between double quotes, with its quotes doubled, as in CSV (2.9; before, it
could not be told apart from two columns, and an export split it in two).

### Modifying an existing column

```sql
ALTER TABLE customers MODIFY COLUMN balance DECIMAL(10,2) NOT NULL DEFAULT 0;
ALTER TABLE customers MODIFY COLUMN code VARCHAR(20) UNIQUE;
```

`MODIFY COLUMN` changes the type, length, decimals, `NOT NULL`, `UNIQUE` and
`DEFAULT` of an existing column, and MySQL's `CHANGE [COLUMN] old new type …`
(2.9) does the same and renames it at once. The data is converted to the new
type. `ALTER COLUMN` (PostgreSQL's and SQL Server's form) is not accepted.

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

The check **reads from disk**. Orphan keys almost always come from a hand
edit; since 2.9 the engine notices those on its own and keeps its answers true
to the files, and `CHECK TABLES` / `REPAIR TABLES` deal with files a hand edit
broke (see [Editing the files by hand](01-core.md#editing-the-files-by-hand-29)).
`CHECK INDEXES` and `REPAIR INDEXES` (2.10) do the same for the indexes:
compare each one with the rows of its table, and rebuild the ones that do not
match (same page).

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
- The tables and views it uses must exist when it is created (2.10), as in
  MySQL and PostgreSQL (SQLite creates it and fails when it is used): «La
  vista 'v' usa 't', que no es una tabla ni una vista de la base: créala
  antes». Names from its own `WITH` do not count. Dropping a table a view uses
  is still allowed; the view then fails when used, until the table is back.
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
