# 05 — jsonSQLDBadmin (administration panel)

A web panel to administer jsonSQLDB from the browser. Pure PHP, no Composer and
nothing from outside: Bootstrap and the icons are in `jsonsqldbadmin/assets/`.

The panel **never touches the engine or the data files**: everything goes
through the API, signed with HMAC and with bound parameters. If you move the
API to another server tomorrow, the panel still works by changing one
constant.

```
browser  →  jsonsqldbadmin/index.php  →  api/jsonsqldb_api.php  →  engine/  →  data/
```

## 1. Installation

1. Upload the `jsonsqldbadmin/` folder with the rest of the project.
2. Open `jsonsqldbadmin/config.php` and check that `ADMIN_API_KEY` and
   `ADMIN_HMAC_SECRET` are **the same** as in `api/jsonsqldb_api_config.php`
   (`php configurar.php` writes both files with matching values). If you change
   one, change the other.
3. Open `https://yourserver/jsonsqldb/jsonsqldbadmin/`. **The first time the
   panel is opened, and only the first, it asks you to create the administrator
   user**: you choose the name and password right there. There is no default
   password and no factory user, so there is nothing to change afterwards and
   no risk of leaving `admin/admin` in place. The password is stored with bcrypt
   and needs at least 10 characters.

   Once that user exists, the sign-up screen disappears and the panel asks for
   user and password like any other. If you ever lose access, delete
   `jsonsqldbadmin/datos/usuarios.json` and the panel will ask you to create the
   administrator again.
4. Check that `jsonsqldbadmin/datos/` is writable: users and the audit trail
   are stored there.

`ADMIN_API_URL` can stay empty: the panel derives the API URL from its own
(`../api/jsonsqldb_api.php`). Fill it in only if the API is on another domain or
path.

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
  `NEW.column`, `OLD.column` and `RAISE(ABORT, 'message')` are valid.
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

**Export** — **CSV** and **INSERT** buttons on the data screen (exports the
whole table, with the ordering you have set, not just the visible page) and on
the SQL editor's result (exports what the query returned).

- The CSV carries a UTF-8 BOM so Excel does not break accents, and uses `;` as
  separator (`ADMIN_CSV_SEPARADOR`, change it to `,` for other tools). Nulls
  come out as an empty cell.
- INSERT generates one statement per row, with quoted names, single quotes
  doubled and nulls as `NULL`. It can be re-run as it is in the SQL editor of
  another database.
- When exporting a query result, the table name of the INSERTs is taken from
  the first `FROM` of the statement; if there is none, it is called `consulta`.
- The cap is `ADMIN_EXPORT_MAX` rows (100,000 by default) so PHP does not run
  out of memory. If you exceed it, the panel says so and you narrow it with
  `WHERE` or `LIMIT`.

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

- `config.php`, `lib/`, `vistas/` and `datos/` are blocked by `.htaccess` and
  `web.config`. Only `index.php` and `assets/` are served. **On nginx those
  files do not apply**: install the rules from the project's `nginx/` folder,
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

## 6. Configuration

| Constant | Default | Purpose |
|---|---|---|
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
| `ADMIN_EXIGIR_HTTPS` | `false` | reject access over HTTP |
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
| `ADMIN_EXPORT_MAX` | `100000` | row cap per export |
| `ADMIN_RUTA_DATOS_MOTOR` | empty | the engine's `data/` folder, for the ZIP copy |

## 7. Files

| Path | What it is |
|---|---|
| `jsonsqldbadmin/index.php` | single entry point: session, router and actions |
| `jsonsqldbadmin/config.php` | configuration |
| `jsonsqldbadmin/lib/Api.php` | signed calls to the API |
| `jsonsqldbadmin/lib/Auth.php` | users, session, IP lockout and CSRF |
| `jsonsqldbadmin/lib/Audit.php` | audit trail |
| `jsonsqldbadmin/lib/Exportar.php` | export to CSV, INSERT statements and ZIP |
| `jsonsqldbadmin/lib/Store.php` | reading and writing of the panel's JSON files |
| `jsonsqldbadmin/lib/util.php` | escaping, URLs, messages and validation |
| `jsonsqldbadmin/lib/acciones.php` | every action that changes something |
| `jsonsqldbadmin/vistas/` | pages |
| `jsonsqldbadmin/assets/` | Bootstrap 5.3.3 and Icons 1.11.3, local |
| `jsonsqldbadmin/assets/panel.js` | enables the column fields according to type |
| `jsonsqldbadmin/datos/` | `usuarios.json`, `intentos.json`, `auditoria-*.json` |
| `tests/f5_admin.php` | 119 checks driving the real panel |

## 8. Tests

`tests/f5_admin.php` starts two PHP built-in servers (one for the panel and one
for the API, so they do not wait for each other) and drives the panel with real
cookies and CSRF tokens: installation, login, databases, tables, columns, keys,
triggers, data, SQL editor, read-role permissions and audit. It uses a temporary
folder, so it does not touch your data.

```
php tests/f5_admin.php     → OK: 119
```

It needs the cURL extension. On Windows with XAMPP, enable it in `php.ini`
(`extension=curl`).
