# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| Latest 1.x release | Yes |
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
