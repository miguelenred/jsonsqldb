# jsonSQLDB on LiteSpeed

There are two LiteSpeed servers and they behave very differently with this
project. Check which one you have (`OpenLiteSpeed` says so in its admin
console; commercial hosting with cPanel or DirectAdmin usually runs the
Enterprise edition):

| Server | Status | What you have to do |
|---|---|---|
| **LiteSpeed Enterprise** (LSWS) | Works out of the box, like Apache | Nothing. It reads the `.htaccess` files the project ships and applies them |
| **OpenLiteSpeed** (OLS) | **Needs manual setup**, like nginx | OLS reads `.htaccess` only for rewrite rules, and only at startup. The access rules **must** go in the virtual host: see below |

What the engine needs from the server is the same in both: PHP 8.0 or later
run through LSAPI (`lsphp`), which supports everything the engine uses —
`flock()` locks, `fsync()`, atomic `rename()`, and APCu if the extension is
enabled for `lsphp`. Nothing in the code is server-specific; only the
protection of the private folders is.

> These notes come from LiteSpeed's documented behaviour, not from a run of
> the test suite on a LiteSpeed server. Whatever edition you use, **run the
> checks at the end of this file** after deploying: they take a minute and
> settle the question for your installation.

## LiteSpeed Enterprise

LSWS is a drop-in replacement for Apache and reads `.htaccess` per request,
including the directives this project relies on: `<FilesMatch>`,
`Require all denied` and `Order`/`Deny` (both are present, so either edition
of the access syntax applies), `Options -Indexes`, `RewriteRule … [F]`, and
`php_flag`. The `<IfModule>` guards around them are there for Apache 2.2 versus
2.4; LSWS applies the directives inside them, and since both branches deny,
the result is the same.

Two things worth checking in the LiteSpeed admin console or the hosting
panel:

- **`.htaccess` processing is enabled** for the virtual host (*Allow Override*
  under the vhost's *General* settings). On shared hosting it always is.
- **PHP runs as `lsphp` 8.0 or later**, and if you want the shared-memory
  cache, the `apcu` extension is enabled for that `lsphp` version. Without it
  the engine uses its on-disk cache; nothing to configure.

## OpenLiteSpeed

OLS does **not** apply access-control directives from `.htaccess`
(`Deny from all`, `Require all denied`, `<FilesMatch>`, `Options`,
`php_flag`). It can load *rewrite rules* from `.htaccess` when *Auto Load from
.htaccess* is enabled in the vhost's *Rewrite* settings, but only when it
starts, so every change needs a restart, and a rule that fails to load fails
silently. Relying on that for the security of your data is a bad idea, so do
what the nginx setup does: put the rules in the virtual host, where they are
explicit and reloaded when you say so.

**1. Open the vhost's *Rewrite* tab** in the OLS admin console (port 7080 by
default), set *Enable Rewrite* to *Yes*, and paste this into *Rewrite Rules*.
The paths assume the project lives at `/jsonsqldb` inside the site; adjust the
prefix if it does not, or drop it if the project is the site's root.

```apache
RewriteEngine On

# Internal folders: data, logs, engine, documentation, tests, server notes,
# and the panel's internals
RewriteRule ^/jsonsqldb/(data|logs|engine|docs|tests|nginx|litespeed)(/|$) - [F,L]
RewriteRule ^/jsonsqldb/jsonsqldbadmin/(lib|vistas|datos)(/|$) - [F,L]

# Configuration files, at any level
RewriteRule ^/jsonsqldb/.*(^|/)(config\.php|[^/]*_config\.php|[^/]*\.dist\.php)$ - [F,L]

# Extensions that must never be served. .json is the data: this is the one
# rule that matters most. The panel's assets are .css, .js and .woff2.
RewriteRule ^/jsonsqldb/.*\.(json|md|log|lock|cache|tmp|dist|sample|bak|old|inc|ini|sql|crt|pem|key)$ - [F,L]

# Hidden files (.htaccess, .git, .env...)
RewriteRule ^/jsonsqldb/.*/\. - [F,L]
```

**2. Turn directory listings off**: vhost → *General* → *Index Files* →
*Auto Index* = *No* (that is the default).

**3. Restart gracefully** (*Actions → Graceful Restart*) — OLS applies vhost
rewrite rules on restart, not on save.

If you prefer the rules in the config file, they go in the vhost's
`vhconf.conf` inside a `rewrite { … }` block with `enable 1` and the same
`rules` text.

Alternatively, a *context* per folder with `accessControl { deny * }` blocks a
folder outright; the rewrite rules above are the shorter route and cover the
file extensions too, which contexts do not.

## Check after installing

Whichever edition, none of these must return content. All must answer 403 or
404:

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

If the first four return content — a table's JSON, a PHP source — the rules
are not being applied: on LSWS check that `.htaccess` processing is allowed for
the vhost; on OLS check that the rules are in the vhost, that rewriting is
enabled, and that the server was restarted.

## Write permissions

`lsphp` usually runs as the site's user (`nobody`/`www-data` on a manual
install, the account's user on cPanel and DirectAdmin). These three folders
need write access for that user:

```bash
chown -R <user>:<group> data logs jsonsqldbadmin/datos
chmod -R 750 data logs jsonsqldbadmin/datos
```

The rest of the project can stay read-only.

## A note on APCu with `lsphp`

APCu's shared memory is shared between the children of one `lsphp` parent.
Under LSWS on cPanel or CloudLinux each account gets its own process group, so
the cache is shared within your site, which is what you want. If your setup
runs each `lsphp` process independently (some manual OLS configurations), each
process keeps its own copy of the cache and the hit rate drops; the engine
still works, since every entry is regenerable and the disk cache is the
fallback when APCu is absent. If in doubt, the safe choice is to leave APCu
disabled for `lsphp` and let the engine use `.cache/` on disk.
