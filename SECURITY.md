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

## Before you deploy

These are the things that actually matter, in order.

1. **Change `HMAC_SECRET` and every API key** in
   `api/jsonsqldb_api_config.php`. The values in the `.dist` templates are
   placeholders, not defaults — the project ships with `CHANGE_ME_` strings on
   purpose, so an unconfigured install fails loudly instead of running with a
   known secret.
2. **Give each application its own API key**, restricted to the databases it
   needs (`'bases' => ['thatdb']`) and to the lowest permission level that works
   (`lectura` < `escritura` < `admin`).
3. **Serve everything over HTTPS** and set `EXIGIR_HTTPS` to `true`. The HMAC
   signature stops anyone tampering with a query; it does nothing to stop them
   reading it.
4. **Check that your web server is actually blocking the private folders.**
   Apache and IIS are covered by the bundled `.htaccess` and `web.config`.
   **nginx reads neither** — you must install the rules from `nginx/`. Verify it:

   ```bash
   curl -o /dev/null -s -w "%{http_code}\n" https://yourserver/jsonsqldb/data/
   # must be 403 or 404, never 200
   ```

5. **Restrict the admin panel.** `ADMIN_IPS_PERMITIDAS` limits which IPs can even
   reach the login screen. If only you use it, this is the single most effective
   measure available.
6. **Turn on `RATE_LIMIT_ACTIVO` and `ANTI_REPLAY_ACTIVO`** if the API is
   reachable from the internet.
7. **Set `DEVOLVER_ERRORES` to `false`** in production. With `true`, engine error
   messages reach the client — convenient while developing, too talkative
   afterwards.
8. **Only enable `CONFIAR_EN_PROXY` if there really is a trusted proxy in front.**
   With it on, the API believes the `X-Forwarded-For` and `X-Forwarded-Proto`
   headers; if nothing is setting them, anyone can forge their IP and bypass both
   the allow-list and the rate limit.

9. **Check who else on the server can read the data.** Folders are created as
   `0775` and files with PHP's default mode, so what the others can do depends
   on the server's `umask`. On shared hosting, PHP and your FTP user often need
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
- Only one statement is accepted per request, so statement chaining is not
  possible.
- Permissions are checked against the parsed statement type, not against pattern
  matching on the SQL text.
- Panel passwords are hashed with bcrypt; sessions expire on inactivity; every
  form carries a CSRF token; repeated failed logins lock the IP out.
- All panel output is escaped with `htmlspecialchars`.
- Security headers are sent on both the API and the panel, including a
  `Content-Security-Policy` restricted to `self` (the panel loads no external
  resources).
