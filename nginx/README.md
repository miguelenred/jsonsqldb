# jsonSQLDB on nginx

**Read this before publishing anything.** The project ships `.htaccess`
(Apache) and `web.config` (IIS) in every folder that must be closed to the
browser. **nginx reads neither.** If you copy the project to an nginx server
without applying the rules in this folder, anyone can request

```
https://yourserver/jsonsqldb/data/mydb/customers.json
```

and download the whole table, with all its data, without authenticating.

This is not a flaw in nginx or in the project: every server has its own
configuration system, and nginx centralises it in one file instead of spreading
it over folders.

## What is here

| File | What it is |
|---|---|
| `jsonsqldb.conf` | The blocking rules, ready to include |
| `README.md` | This |

## Installation

**1. Copy the rules file** to where nginx keeps its snippets:

```bash
sudo cp nginx/jsonsqldb.conf /etc/nginx/snippets/jsonsqldb.conf
```

**2. Include it inside the `server` block** of your site, usually in
`/etc/nginx/sites-available/yoursite`:

```nginx
server {
    listen 443 ssl;
    server_name yourserver.com;

    root /var/www/html;
    index index.php index.html;

    ssl_certificate     /etc/letsencrypt/live/yourserver.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourserver.com/privkey.pem;

    # jsonSQLDB
    include snippets/jsonsqldb.conf;

    # ... the rest of your configuration
}
```

**3. Check and reload:**

```bash
sudo nginx -t && sudo systemctl reload nginx
```

## What to adjust in `jsonsqldb.conf`

Three things, each marked with a comment in the file.

**The project path.** The rules assume the project is at `/jsonsqldb` inside
the site, i.e. reached at `https://yourserver/jsonsqldb/`. If you put it
elsewhere, replace `/jsonsqldb` with your path in **every** `location` block. If
the project is the root of the site, drop the prefix altogether and leave the
paths starting with `/`.

**The PHP-FPM socket.** The file points at `unix:/run/php/php8.3-fpm.sock`.
Check which one you have:

```bash
ls /run/php/
```

If you run PHP over TCP instead of a socket, replace that line with
`fastcgi_pass 127.0.0.1:9000;`.

**The timeout.** `fastcgi_read_timeout 300;` is five minutes. If you work with
large tables and some query takes longer, raise it; bear in mind that
`TIME_LIMIT` in `api/jsonsqldb_api_config.php` limits on its side too, and the
lower of the two wins.

## What the rules block

| Blocked | Why |
|---|---|
| `data/`, `logs/`, `engine/`, `docs/`, `tests/`, `nginx/` | Data, logs, engine code and documentation |
| `jsonsqldbadmin/lib/`, `vistas/`, `datos/` | Panel internals: users with their hashes, and the audit trail |
| `config.php`, `*_config.php`, `*.dist.php` | API keys and HMAC secrets |
| `*.json`, `*.md`, `*.log`, `*.crt`, `*.pem`, `*.key`… | Tables are `.json` files: this is the key rule |
| Hidden files (`.git`, `.env`, `.htaccess`) | The usual |
| Any `.php` that is not the endpoint or the panel | The rest are not entry points |

Only two PHP files remain reachable, which are the only ones that should be:

- `api/jsonsqldb_api.php` — the API endpoint
- `jsonsqldbadmin/index.php` — the panel

And the static files in `jsonsqldbadmin/assets/`, served without going through
PHP.

## Check after installing

None of these four requests must return content. All must answer 404:

```bash
curl -o /dev/null -s -w "%{http_code}\n" https://yourserver/jsonsqldb/data/
curl -o /dev/null -s -w "%{http_code}\n" https://yourserver/jsonsqldb/api/jsonsqldb_api_config.php
curl -o /dev/null -s -w "%{http_code}\n" https://yourserver/jsonsqldb/jsonsqldbadmin/datos/usuarios.json
curl -o /dev/null -s -w "%{http_code}\n" https://yourserver/jsonsqldb/engine/Database.php
```

And these two must work:

```bash
# The API rejects the GET, but answers: that means PHP is running it
curl -s https://yourserver/jsonsqldb/api/jsonsqldb_api.php
# → {"error":"Método no permitido"}

# The panel returns HTML
curl -o /dev/null -s -w "%{http_code}\n" https://yourserver/jsonsqldb/jsonsqldbadmin/
# → 200
```

If the first test returns a table's JSON instead of a 404, the rules are not
being applied: check that the `include` is **inside** the right `server` block
and that the path prefix matches.

## Write permissions

With nginx, PHP usually runs as `www-data`. These three folders need write
access:

```bash
sudo chown -R www-data:www-data data logs jsonsqldbadmin/datos
sudo chmod -R 750 data logs jsonsqldbadmin/datos
```

The rest of the project can stay read-only (`755` for folders, `644` for
files).

## One more recommendation

If only you will use the panel, the most effective thing is not to expose it to
the Internet. Two ways, from more to less restrictive:

```nginx
# Only from the local network or the VPN
location ~ ^/jsonsqldb/jsonsqldbadmin/ {
    allow 192.168.1.0/24;
    allow 10.8.0.0/24;
    deny  all;
    # ... followed by the fastcgi block from jsonsqldb.conf
}
```

Or use `ADMIN_IPS_PERMITIDAS` in `jsonsqldbadmin/config.php`, which does the
same from PHP and travels with the project. Both at once do no harm.
