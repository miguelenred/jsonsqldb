# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Given that the only supported way in is the HTTP API, the public surface for
versioning purposes is: the API request and response format, the SQL dialect, the
configuration constants, and the on-disk format of `data/`.

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

[1.2.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.2.0
[1.1.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.1.0
[1.0.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.0.0
