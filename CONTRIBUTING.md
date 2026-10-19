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
composer test                   # all 27 suites; or one by one:
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
- **All 27 suites green**, and update the expected counts in the README and in
  `docs/` if you added checks.
- **Documentation updated.** `docs/` is part of the project, not an afterthought.
- **No new dependencies.** Not needing Composer, extensions or a database server
  is the entire point of this project. A pull request that adds a dependency will
  be declined regardless of how good the feature is.
- **Style:** follow what is already there. Four spaces, `declare(strict_types=1)`,
  Spanish names and comments, no unused variables or functions. In `engine/`
  (namespace `JsonSQLDB`), the functions PHP compiles to an instruction of its
  own are called with a backslash: `\strlen()`, `\count()`, `\is_int()`,
  `\is_string()`, `\in_array()`, `\sprintf()` (since PHP 8.4) and the rest of
  that list (the one in `tests/f1_nucleo.php`, which fails on one without it). Without it, in a
  namespace, each is an ordinary call: about a hundred machine instructions
  more, and up to 29 % of the work of a query (see the CHANGELOG of 2.10).
- **What every request loads stays small.** Without OPcache PHP compiles, in
  every request, each file of the engine it loads, so what only some
  statements use goes to a class of its own, which the autoloader loads when
  one of them needs it (`WriterAvanzado`, `StorageEntera`, `EvaluatorIn`…:
  static methods that receive the object, as their headers say). When PHP
  compiles a closure it does not know which class `self` is, so it makes a
  `self::` call the generic way and looks a `self::` constant up on every
  use; with the name of the class it does neither, but without OPcache each
  place where it is written costs about 1,200 more instructions to compile,
  in every request that loads the class. So the name of the class goes only
  in a closure that runs for many rows in the statements that load it (the
  rows of an `INSERT` in `WriterInsercion`); elsewhere, `self::` (measured in
  2.10: see the CHANGELOG).
  `tests/f1_nucleo.php` fails if a common statement loads a class it does not
  use or compiles more than its limit (pieces of code, without spaces or
  comments); when a change needs more, raise the limit and say why in the
  CHANGELOG.
- **Languages:** code (classes, functions, variables), comments and the engine's
  messages are in Spanish; the documentation (README, `docs/`, CHANGELOG,
  SECURITY) is in English; the panel speaks Spanish and English, and every text
  it shows needs its English version in `jsonsqldbadmin/idiomas/en.php`
  (`tests/f15_idiomas.php` checks it). Please write each part in its language.
- **A new panel action** is a function `accion_<name>` in
  `jsonsqldbadmin/lib/acciones.php`, which `ejecutarAccion()` calls by its name.
  It needs an administrator unless it is one of the three listed there, which
  check it themselves; `tests/f5_admin.php` tries every action with a read-only
  user.
- **Do not commit** `api/jsonsqldb_api_config.php` or `jsonsqldbadmin/config.php`.
  They are gitignored for a reason.

## Things that are deliberately not supported

Before proposing these, know that they were considered and rejected:

- **Adding or removing `AUTOINCREMENT` on an existing table.** It requires
  rebuilding the table; doing it silently would be worse than refusing. Raising
  its counter is supported (2.10): `ALTER TABLE t AUTO_INCREMENT = n`.
- **Transactions that span requests** (`BEGIN` in one call, `COMMIT` in
  another). A client that hangs in between would leave the database locked for
  everyone; a transaction is one `lote` or one `transaccion()`.
- **`INSTEAD OF` triggers and `UPDATE OF columns`.**
- **`WITHOUT ROWID`, `ZEROFILL`, `ON UPDATE CURRENT_TIMESTAMP`, `FULLTEXT` and
  `SPATIAL`.** Each one is an error that says it is not supported, rather than
  something silently ignored.
