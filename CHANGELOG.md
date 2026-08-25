# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Given that the only supported way in is the HTTP API, the public surface for
versioning purposes is: the API request and response format, the SQL dialect, the
configuration constants, and the on-disk format of `data/`.

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

[1.8.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.8.0
[1.7.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.7.0
[1.6.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.6.0
[1.5.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.5.0
[1.4.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.4.0
[1.3.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.3.0
[1.2.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.2.0
[1.1.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.1.0
[1.0.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.0.0
