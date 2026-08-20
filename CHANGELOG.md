# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Given that the only supported way in is the HTTP API, the public surface for
versioning purposes is: the API request and response format, the SQL dialect, the
configuration constants, and the on-disk format of `data/`.

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

[1.0.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.0.0
