# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| Latest 2.x release | Yes |
| Anything older | No — upgrade first |

Only the most recent release gets fixes. The project is small enough that
maintaining several branches would mean testing none of them properly.

## Reporting a vulnerability

**Please do not open a public issue for security problems.**

Report them privately through GitHub's *Report a vulnerability* button on the
Security tab of this repository, or by email to `miguel@miguelenred.es`. You will get an
acknowledgement within a few days.

Please include what you did, what happened, and what you expected. A proof of
concept helps a lot; a working exploit is not required.

## Fixed advisories

- **2.7.3 — example client keys could be downloaded.** Up to 2.7.2,
  `php configurar.php` wrote the key and secret of the *Clientes de ejemplo*
  account into `api/cliente_ejemplo.py`, `.ps1` and `.php`, and the web server
  served the first two as plain text: anyone could read a key with write access
  to the `pruebas` database. 2.7.3 no longer writes keys into those files and
  blocks them on Apache, IIS and nginx. **If you ran `configurar.php` with an
  earlier version, change the key and secret of that account** in
  `api/jsonsqldb_api_config.php` (or delete the account) and check the request
  log for its use.
- **2.7.3 — the API could be locked for everyone.** Up to 2.7.2, 30 requests
  with an invented API key, from anywhere, closed the API to every client for up
  to 24 hours. 2.7.3 blocks only the IP that fails.

- **2.7.3 — the panel could send its signed requests to a host taken from the
  request.** With `ADMIN_API_URL` empty (the default) and the API connection,
  the panel worked out the API's address on every request from the `Host`
  header, which the client sends: whoever could tamper with it (a misconfigured
  proxy, DNS rebinding) could receive the signed request with the API key. The
  setup wizard and the Configuration page now always write the address down,
  and the Configuration page warns while it is still empty. **If your
  `jsonsqldbadmin/config.php` has `ADMIN_API_URL` empty, save the Configuration
  page once** (or write the URL by hand).
- **2.7.3 — ZIP paths.** A ZIP with a path component `..`, `.` or empty is now
  rejected as a whole, and every destination is checked to be inside the
  database's folder. An external audit reported paths ending in `..` as a way
  out of the folder; they were in fact never written (only `.json`,
  `.htaccess` and `web.config` files are), but they were accepted silently.

- **2.7.3 — a panel published before being set up could be taken over.** The
  setup wizard let whoever reached it first create the administrator. It now
  asks for an installation code that is only on the server (see docs/05-admin.md),
  and `configurar.php` only runs from the command line and is refused over HTTP.
- **2.7.3 — other hardening from an external audit of 2.7.2**: the ZIP copy and
  restore of a database now take the engine's exclusive lock on it, so a copy
  is never a mix of two moments and a restore never races a write; panel users
  are changed under one lock (no lost update); the login form has a CSRF token;
  the panel's Content-Security-Policy no longer allows inline scripts (each one
  carries a per-response nonce); and the panel warns when it runs on PHP 8.0,
  where a confirmed write is not durable across a power cut. API errors keep
  HTTP 200 with an `error` key (docs/04-api.md explains why).

- **2.9.0 — a restored ZIP could make the engine delete files of the
  database's folder.** Up to 2.8.0, the panel restored files under `.tx/` and
  `.lote/`, which the engine takes, on opening the database, for a write cut
  short: a ZIP made for it could delete or replace the folder's `.htaccess`
  and `web.config`. Only an administrator can restore a ZIP. **If you
  restored a ZIP you did not make yourself, check that each database folder
  still has its `.htaccess` and `web.config`.**
- **2.9.0 — SQL dumps for other engines could carry SQL inside a comment.**
  Up to 2.8.0, a `CHECK`, a default, or a view or trigger written as a
  comment because the other engine does not accept it kept its line breaks,
  and loading the dump ran what followed one. **Review a dump made with an
  earlier version before loading it into MySQL, PostgreSQL or SQL Server.**
- **2.9.0 — other fixes of the security review of 2.9**: signing in to the
  panel cleared the failed attempts of the whole IP, so one account could
  keep guessing the password of another; attempts at the same time went past
  the limit; the setup wizard, which anyone can open before installation, made
  the server request an address built from the request's `Host` header; a link
  to `?idioma=en` changed the language saved for whoever was signed in; the API
  accepted a captured request again when the client's clock ran ahead, and
  told an unknown key apart from a wrong signature; the PHP example client's
  shortcut accepted a self-signed certificate from any server; a request whose
  user agent, error or SQL had bytes that are not valid UTF-8 was left out of
  the API's log and of the query log; the triggers' reads of other tables were
  not locked; a key that can only read could fill the disk through the result
  cache, and hold the whole database for everyone with a `lote` of heavy
  reads; changing your own password in the panel did not ask for the current
  one; the sign-in page and the setup wizard showed the exact PHP version and
  the server's full paths; a value saved from the Configuration page could end
  up as PHP code in `config.php`; and an IPv6 client could get around the
  request limit and the lockout by changing address (an IPv6 address now
  counts by its /64). See the CHANGELOG.
- **2.10.0 — the panel's record of failed sign-ins had no limit.** Up to
  2.9.0, `jsonsqldbadmin/datos/intentos.json` kept an entry per IP for up to
  four times the lockout, and every sign-in reads it and rewrites it when it
  counts: requests from many addresses at once made it grow without bound, and
  each sign-in slower. It now keeps at most 1,000 IPs, the locked-out ones first
  and then the most recent.
- **2.10.0 — a range in an allow-list without its number of bits let every
  address in.** Up to 2.9.0, an entry such as `10.0.0.0/` in the API's
  `IPS_PERMITIDAS` or in the panel's allow-list (one slash too many) was taken
  as `/0`, and `10.0.0.0/24x` as `/24`. An entry that is not valid now lets
  nobody in, and the setup wizard and the Configuration page do not save one.
  **If an allow-list of yours has an entry ending in `/`, it was open to every
  address: check the API's request log.**
- **2.10.0 — the panel's users file could be read by other users of the
  machine.** Up to 2.9.0, `jsonsqldbadmin/datos/usuarios.json` (the bcrypt
  hashes of the panel's passwords) and `intentos.json` (addresses and user
  names tried) were created with the umask's permissions, readable by every
  account of a shared server where the umask lets everyone read. They are now
  created as `0600`, and so is `copias.json`; one written before keeps its
  permissions until the panel writes it again. **If PHP runs as your own user
  on a shared server, run `chmod 600 jsonsqldbadmin/datos/*.json` after
  upgrading.**
- **2.10.0 — the root `.htaccess` turned PHP on in `data/`.** Up to 2.9.0, a
  block meant to keep anything in the data folder from running had
  `php_flag engine on`. The folder refuses every request anyway, so there is
  no known way to use it; `data/`, `logs/` and the panel's `datos/` now turn
  PHP off where Apache runs it as a module.

## Before you deploy

These are the things that actually matter, in order.

1. **Change every API key and its `hmac_secret`** in
   `api/jsonsqldb_api_config.php` (`php configurar.php` does it with random
   values). The values in the `.dist` templates are placeholders, not defaults —
   the project ships with `CHANGE_ME_` strings on purpose, so an unconfigured
   install refuses to work instead of running with a known secret. Each account
   has its own secret: an application that leaks its own cannot sign as
   another, the admin one included.
2. **Give each application its own API key**, restricted to the databases it
   needs (`'bases' => ['thatdb']`) and to the lowest permission level that works
   (`lectura` < `escritura` < `admin`).
3. **Serve everything over HTTPS** and keep `EXIGIR_HTTPS` and
   `ADMIN_EXIGIR_HTTPS` on, as they come (`php configurar.php --local` turns
   them off for a development machine; turn them back on before publishing).
   The HMAC signature stops anyone tampering with a query; it does nothing to
   stop them reading it.
4. **Check that your web server is actually blocking the private folders.**
   Apache, LiteSpeed Enterprise and IIS are covered by the bundled `.htaccess`
   and `web.config`. **nginx and OpenLiteSpeed read neither** — you must
   install the rules from `nginx/` or follow `litespeed/README.md`. Verify it:

   ```bash
   curl -o /dev/null -s -w "%{http_code}\n" https://yourserver/jsonsqldb/data/
   # must be 403 or 404, never 200
   ```

   The panel's home page and its Configuration page show administrators the
   same check (the home page since 2.10; see
   [docs/05-admin.md](docs/05-admin.md), section 7).

5. **Restrict the admin panel.** `ADMIN_IPS_PERMITIDAS` limits which IPs can even
   reach the login screen. If only you use it, this is the single most effective
   measure available.
6. **Keep `RATE_LIMIT_ACTIVO` and `ANTI_REPLAY_ACTIVO` on**, as they come, if
   the API is reachable from the internet.
7. **Keep `DEVOLVER_ERRORES` off** in production, as it comes. With `true`,
   engine error messages reach the client — convenient while developing, too
   talkative afterwards.
8. **Only enable `CONFIAR_EN_PROXY` (API) or `ADMIN_CONFIAR_EN_PROXY` (panel) if there really is a trusted proxy in front.**
   With it on, the API believes the `X-Forwarded-For` and `X-Forwarded-Proto`
   headers; if nothing is setting them, anyone can forge their IP and bypass both
   the allow-list and the rate limit. Since 2.9 the IP taken is the last one in
   `X-Forwarded-For`, the one your proxy adds; up to 2.8.0 it was the first,
   which the client writes, and that let a client choose its IP even behind a
   real proxy.

9. **Check who else on the server can read the data.** Folders are created as
   `0775` and files with PHP's default mode, so what the others can do depends
   on the server's `umask`; only the configuration files, the installation
   code and the panel's users, sign-in attempts and backup schedules are
   created as `0600` (the last three since 2.10). On shared hosting, PHP and your FTP user often need
   to share the group, which is why the project does not force tighter modes.
   If PHP runs as its own user, `umask 0027` in its pool (or `chmod -R o-rwx` on
   `data/`, `logs/` and `jsonsqldbadmin/datos/`) keeps other accounts out; even
   better, put `data/` outside the public folder (`JSONSQLDB_DATA_PATH`).
10. **Copy the example clients out of `api/` before using them** in your own
    application, and give it a key of its own. Since 2.7.3 they read their key
    from environment variables and the web server refuses to serve them.

## What the project does by default

- Values are never concatenated into SQL. Bound parameters are placed into the
  parsed syntax tree as literals.
- The API signs the SQL and the parameters joined without a separator. Cutting
  the same signed text at another point gets nothing new in: the parameters
  must be a JSON list of simple values reaching the end, with exactly one value
  per `?`, and a valid list has no shorter ending that is a list too. The only
  other cut that passes turns a JSON list the SQL itself ends with (in a
  comment, say) into the values — still text the client signed, bound as data
  (docs/04-api.md).
- A statement is one statement: a second one after a `;` is refused, so
  statement chaining is not possible. A `lote` (2.9) is a list of them sent on
  purpose, each parsed on its own and checked against the key's permission.
- Permissions are checked against the parsed statement type, not against pattern
  matching on the SQL text.
- Panel passwords are hashed with bcrypt; sessions expire on inactivity; every
  form carries a CSRF token; repeated failed logins lock the IP out.
- All panel output is escaped with `htmlspecialchars`.
- Security headers are sent on both the API and the panel, including a
  `Content-Security-Policy`: `default-src 'none'` on the API, which only
  returns JSON, and restricted to `self` on the panel, which loads no external
  resources and runs only its own script files and the inline scripts that
  carry the response's nonce.
