# Contributing

Thanks for taking a look. A few things worth knowing before you start.

## About this project

The implementation was written with several AI models from a specification and
under review by the author — see [AUTHORS](AUTHORS). That does
not change how contributions work, but it does explain why the code style and the
comments are as consistent as they are, and why the comments are in Spanish.

## Reporting a bug

Open an issue with:

- What you did, what happened, and what you expected.
- Your PHP version (`php -v`) and web server.
- The smallest SQL statement or sequence of steps that reproduces it.
- Whether the test suites pass on your machine.

**Security problems do not go in issues** — see [SECURITY.md](SECURITY.md).

## Proposing a change

Open an issue first for anything beyond a typo. It is much less frustrating to
agree on the approach before code exists than after.

## Running the tests

No dependencies, no setup beyond the configuration files:

```bash
php configurar.php --local      # both configuration files, with random keys, allowing HTTP
composer test                   # all 26 suites; or one by one:
php tests/f1_nucleo.php
php tests/f3_escrituras.php
php tests/f5_admin.php
```

Every suite must end with `FALLOS: 0`. They use temporary directories and never
touch `data/`. The ones that load dumps into MySQL or PostgreSQL, run
PowerShell scripts or open earlier versions from the git tags check more where
those are available, and say what they skip; CI has them all.

`f4_api.php` reads the API keys from your configuration file, so it keeps working
after you change them — which you should. `f5_admin.php`, `f11_asistente.php`
and `f20_memoria.php` need the cURL extension.

## What a pull request should look like

- **Tests for the change.** Every suite is a plain PHP file with `chk()` calls;
  follow the surrounding style and add cases to the file that fits.
- **All 26 suites green**, and update the expected counts in the README and in
  `docs/` if you added checks.
- **Documentation updated.** `docs/` is part of the project, not an afterthought.
- **No new dependencies.** Not needing Composer, extensions or a database server
  is the entire point of this project. A pull request that adds a dependency will
  be declined regardless of how good the feature is.
- **Style:** follow what is already there. Four spaces, `declare(strict_types=1)`,
  Spanish names and comments, no unused variables or functions.
- **Do not commit** `api/jsonsqldb_api_config.php` or `jsonsqldbadmin/config.php`.
  They are gitignored for a reason.

## Things that are deliberately not supported

Before proposing these, know that they were considered and rejected:

- **Changing `AUTOINCREMENT` on an existing table.** It requires rebuilding the
  table; doing it silently would be worse than refusing.
- **Transactions that span requests** (`BEGIN` in one call, `COMMIT` in
  another). A client that hangs in between would leave the database locked for
  everyone; a transaction is one `lote` or one `transaccion()`.
- **`INSTEAD OF` triggers and `UPDATE OF columns`.**
- **`WITHOUT ROWID`, `ZEROFILL`, `ON UPDATE CURRENT_TIMESTAMP`, `FULLTEXT` and
  `SPATIAL`.** Each one is an error that says it is not supported, rather than
  something silently ignored.
