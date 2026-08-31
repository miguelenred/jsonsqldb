# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Given that the only supported way in is the HTTP API, the public surface for
versioning purposes is: the API request and response format, the SQL dialect, the
configuration constants, and the on-disk format of `data/`.

## [2.4.0] - 2026-08-31

`SELECT COUNT(*)` no longer reads the table. Nothing breaking, no data conversion.

### Changed

- **`SELECT COUNT(*) FROM tabla` — with nothing else in the query — answers from
  the row count, not from the rows.** The engine writes one row per line, so
  counting is reading lines: no `json_decode`, no materialised table, and the
  memory peak is one line. On 100,000 rows it went from 180 ms to 22 ms, and it
  no longer depends on the table fitting in memory.

  The shortcut steps aside for anything it cannot answer by counting: a `WHERE`,
  `GROUP BY`, `HAVING`, `DISTINCT`, `ORDER BY`, `LIMIT`/`OFFSET`, more than one
  column or table, a view, a CTE, `COUNT(col)`, or a trigger counting in the
  middle of a write, where the rows that matter are in memory and not on disk
  yet — that last one is covered by `tests/f3_escrituras.php`, which failed until
  the shortcut learned to step aside. A part file not in the one-row-per-line
  format (edited by hand, or compacted) falls back to counting by decoding, same
  as reading does. On a canonical-looking file with a corrupt row the count
  includes it; detecting that is `INTEGRITY CHECK`'s job, not `COUNT(*)`'s.

- `SHOW TABLES` benefits from the same counting, since it reports row counts
  through the same code path.

- **A write no longer serialises the table it is about to change into the
  cache.** The read that precedes every `INSERT`, `UPDATE` or `DELETE` was
  storing the whole table in the cache under the current revision — and the
  write then retired that revision and cached the new rows itself, so the store
  was thrown away within the same operation. On tables under the cache cap it
  was the table serialised and written to disk once per write, for nothing.
  Single-row writes on a 15,000-row table got 10-20 % faster; tables above
  `JSONSQLDB_CACHE_MAX_FILAS` never paid this and are unchanged. Reads cache
  exactly as before.

- **README trimmed by a fifth** (48 KB -> 39 KB): the deep dives on locking,
  journalling, admin features and test internals now summarise and point to
  `docs/`, which already had them in full. While at it, the index section still
  said the whole index is rebuilt on every write, which stopped being true in
  2.3.0.

## [2.3.0] - 2026-08-31

Faster writes on tables with indexes. Nothing breaking, no data conversion.

### Added

- **`tests/f10_indices_incrementales.php`**, a suite of its own for the change
  below. It has one because the risk is not symmetric: an extra entry in an index
  only makes a query slower, since the `WHERE` is applied again to the rows that
  are read, but a missing one returns incomplete results with nothing to show for
  it — no error, no warning, just fewer rows. Every check compares the extended
  index against rebuilding it from scratch, and every query against the same
  query written so the index cannot be used.

  Removing either safeguard on its own does not turn it red, because the two
  cover each other; removing both does, with `sobran 396 posiciones`. That was
  checked, not assumed.

### Fixed

- **The scaling tests for bulk writes were unreliable, and weaker than they
  looked.** Two separate problems, both found because CI failed on PHP 8.1 with
  `el coste por fila se multiplicó por 12.7` while the code was fine.

  They timed one run per size, and the smaller size ran first — so it paid the
  warm-up that the larger one did not. Locally the same measurement varied
  thirteenfold between runs. They now take the fastest of several runs and
  discard the first: noise can only add time, so the fastest run is the one
  closest to the real cost.

  And they compared 1,000 rows against 4,000, where the fixed costs — rewriting
  the parts, rebuilding the indexes — hid the quadratic term. Removing the
  `posicionEn` shortcut on purpose, which makes the update quadratic again, did
  **not** turn them red: they were passing whatever the code did. Against 8,000
  rows it now fails with a factor of 4.7, which is the point of having them.

### Changed

- **An index is extended instead of rebuilt when a write only appends.**
  Rebuilding it was 67 % of what a single-row `INSERT` costs on a large table —
  measured, `Indexes::construir` plus `clave` plus `trozo` — and it is repeated
  work: if nothing moved, the keys of the existing rows are the same ones.

  It is only reused when it can be shown to still fit: the write appended at the
  end and touched no scattered positions, the index on disk is readable and for
  these same columns, its revision is exactly the one before this write, and it
  claimed to hold as many rows as there were positions. Any doubt and it is
  rebuilt.

  Measured: a single-row `INSERT` on 100,000 rows went from 363 ms to 321 ms, and
  on 50,000 from 180 ms to 156 ms. Less than the 67 % ceiling, because a write
  does other things too and checking the index files costs a read of each.

### Note

Reading each part through its own cache on full scans was tried and dropped. It
saved about 6 ms on a 50,000-row table above the cache cap, but made writes worse
— `leerFilas()` runs during writes, and caching each part means serialising it —
and it broke `REPAIR KEYS`. The measurement said yes and the test suite said no.

## [2.2.1] - 2026-08-31

One durability fix. Nothing breaking, no data conversion.

### Fixed

- **Renaming a file put its contents on disk, but not its name.** Every write
  ends in `rename()`, and the `fsync()` before it covers the file's contents —
  not the directory entry that gives it its name. That entry can sit in the
  operating system's cache, so a power cut in between could leave the data
  written and the file missing from its directory, or the name still pointing at
  the old inode. The same applies to deleting a surplus part, and to creating and
  removing the journal folder.

  On ext4 with `data=ordered` it usually comes out right because of how writes
  are ordered, but POSIX does not promise it, and "usually" is not what this
  engine claims. The directory is now synced after renaming, after deleting a
  part, and around the journal folder.

  **How it is done matters.** `fsync()` on the handle from `opendir()` returns
  false and does nothing — quietly, so it would have looked like a fix while
  changing nothing. The directory has to be opened with `fopen($dir, 'r')`.
  `f1_nucleo.php` checks both, and fails if the working one stops working or the
  useless one starts.

  On Windows `fopen()` on a directory does not work, so this does nothing there —
  which is the platform where `rename()` is not atomic either, and that is what
  the journal is for. On PHP 8.0 there is no `fsync()` at all, a limitation the
  documentation already states.

  Cost: one `fsync` per write operation. Measured against 2.2.0 it does not undo
  that release's gains — a single-row `INSERT` on 50,000 rows is 130 ms against
  149 ms in 2.1.1.

  Found by an external review of 2.2.0, which flagged the gap and proposed the
  `opendir()` version that does not work.

## [2.2.0] - 2026-08-30

Table-level locking for writes that can propagate, less memory and faster
queries. Nothing breaking, no data conversion.

### Added

- **`tests/benchmark.php`.** A reproducible benchmark in the repository, so the
  figures in the README can be checked on your own machine instead of taken on
  trust. Fixed seed, median of several runs rather than the mean, and a `csv`
  mode for comparing two versions. It is not a test: it neither passes nor fails,
  it measures.

### Fixed

- **The documentation index still said version 1.10.1**, three releases behind.
  The number is gone from it: written by hand it falls behind without anyone
  noticing, which is exactly what happened. It points at `VERSION` and the
  changelog instead.

- **The version was read in two independent places.** The panel had its own
  reader; it now asks the engine, and only falls back to reading the file
  directly because it does not load the engine — it talks to it over HTTP.
  `f1_nucleo.php` checks that the engine, the panel, the `VERSION` file and the
  first heading of this changelog all say the same thing.

- **`Config::version()` was added in this release and never called**, which made
  it dead code by the strictest reading. The API now returns it in an
  `X-JsonSQLDB-Version` header, which is useful for telling which version
  answered without opening an FTP session.

- **Recovery copied a leftover temporary file into the data folder.** It restored
  everything it found in the journal folder, skipping only the manifest — so a
  process killed while writing the manifest left its
  `manifiesto.json.<pid>.tmp` there, and recovery put it among the tables. It now
  skips temporaries too. Restoring only what the manifest lists would be more
  precise, but an incomplete list would lose files, and here prudence beats
  precision.

  CI caught this on one crash out of twelve, so `f9_journal.php` now builds the
  situation by hand instead of waiting for luck: it fails if the file reappears.

- **A read-only API key could not run `SHOW INDEXES`.** The list of statements a
  `lectura` key may execute is kept by hand, and `show_indexes` was never added
  when indexes arrived in 2.0 — even though it reveals nothing beyond the
  structure, which the other `SHOW` statements already do. `f4_api.php` now walks
  every `SHOW` with a read-only key, so adding one to the parser and forgetting
  the list shows up as a failure.

- **`composer test` did not run the index and journal suites.** The script listed
  f1 to f7, so a contributor running it never exercised `f8_indices.php` or
  `f9_journal.php` — the two that cover indexes and crash recovery.

- **The header comment in `Storage.php` still described `_revs.json`** as the
  per-table revision counter. Since 2.0 each table has its own
  `<table>.rev.json`, and the shared file is only read for databases written by
  an earlier version.

- **The API permission matrix is now written out in the documentation**, one row
  per statement and one column per permission, instead of being implied. That
  list is kept by hand in the code, and the `SHOW INDEXES` omission above is what
  happens when it is not visible anywhere.

- **`Config::VERSION` was dead and stuck at `1.0.0`.** Nothing read it, so it
  drifted several releases without anyone noticing. It is now
  `Config::version()`, reading the `VERSION` file: one place that can state the
  version instead of two that can disagree.


### Changed

- **`ORDER BY … LIMIT` no longer sorts everything to return a handful of rows.**
  It keeps only the best N as it goes, in a heap of fixed size. On 50,000 rows,
  `ORDER BY saldo DESC LIMIT 20` went from **457 ms and 63 MB to 169 ms and
  38 MB**, and with `LIMIT 1000` from 443 ms to 201 ms.

  The delicate part was proving it returns the same thing: ties break by the
  row's original position, which is exactly what the stable sort did, so the rows
  and their order are identical to sorting everything and slicing. Checked
  against the full sort over 11 cases built with plenty of ties on purpose,
  including `LIMIT 0`, an `OFFSET` past the end of the table, and a `LIMIT`
  larger than the table.

- **A write that can propagate no longer locks the whole database.** Writing to a
  table does not always stay in it: a foreign key with `ON DELETE CASCADE` drags
  child rows and a trigger can write anywhere, and that was enough to take the
  exclusive database lock — so any other write waited, even to tables with no
  relation to it at all.

  The engine now works out the set of tables the write could reach before locking
  anything: foreign keys in both directions and transitively, plus wherever the
  triggers write, parsing their SQL rather than pattern-matching it. Only those
  are locked. Two padre/hija groups with no relation between them now write at
  the same time.

  Two things make it safe. **All the locks are taken up front**, because asking
  for one more halfway through a write is exactly how deadlocks happen. And they
  are taken **in alphabetical order**, so two processes needing the same tables
  ask in the same sequence: one waits for the other instead of both waiting
  forever. It falls back to the database lock the moment the set cannot be
  stated — a trigger whose SQL will not parse, an `INSERT ... SELECT`, any schema
  change, or more than eight tables.

  The journal follows: its scope is the first table of the set, the manifest
  lists them all, and recovery takes the exclusive lock of every one of them —
  without waiting — before undoing anything.

  `f7_concurrencia.php` checks the two halves of the claim: two unrelated groups
  overlap, and two writes to the same group do not. It measures overlap rather
  than total time, because on a single-core machine two processes take the same
  wall time whether they run together or in turn.

- **A write only rewrites the parts that can have changed.** Inserting one row
  into a table spread over a hundred part files rewrote all hundred. The writer
  now tracks which positions changed and whether the rest shifted, and passes
  that down. Writes are 20–25 % faster and the gap grows with the table: on
  100,000 rows a single-row `INSERT` went from 384 ms to 294 ms.

  Writes are 20–25 % faster and the gap grows with the table: on 100,000 rows a
  single-row `INSERT` went from 272 ms to 221 ms, an `UPDATE` from 282 ms to
  231 ms, and an `UPDATE` of the last twenty rows from 316 ms to 213 ms.

  It falls back to rewriting everything at the slightest doubt: when the caller
  did not say what changed, or when `JSONSQLDB_FILAS_POR_PARTE` is not the one
  the table was written with — that one matters more than it looks, because
  changing it moves the boundaries, so a part left untouched ends up holding rows
  that are no longer its own. The part size is now noted in `<table>.rev.json`,
  and the count of existing parts comes from the files on disk rather than from
  arithmetic, because that is the one figure that cannot lie.

  Two tests guard it. `f3_escrituras.php` runs ten kinds of write, forces a full
  rewrite afterwards with the same rows, and demands that not a single byte
  changes. `f9_journal.php` covers the part-size change across processes, and it
  was written the hard way: the first version passed with the safeguard removed,
  because the cache was serving the correct rows under the new revision and
  hiding the damage on disk. Reading past the cache, removing the safeguard
  leaves 160 rows out of 200 — which is the point of having the test.

- **The source rows are released while the result is built.** They used to live
  alongside the finished result until the loop ended, which is two copies of the
  same data at the peak. The order keys are no longer built either when there is
  no `ORDER BY` to use them.

- **Writing a part no longer builds its JSON in memory first.** It is written row
  by row to the file, so the row array, the complete string and each row's
  `json_encode` no longer coexist. Same guarantees as before — temporary file,
  `fsync`, `rename` — and the output is identical byte for byte.

### Note

The queries that were already fast stay the same, within measurement noise: this
release moves `ORDER BY` and writes, and touches nothing else. What is left for
memory is the query engine materialising whole tables in arrays — turning
`Select::cargar()` into a generator, and having `agrupar()` keep aggregation
state instead of every row of every group. Both are large enough to deserve their
own release.

## [2.1.1] - 2026-08-30

Fixes over 2.1.0. Nothing breaking. Most of these come from external reviews of
2.1.1 and from running it on a real shared host; each was reproduced here before
being changed, except the temporary-file sweep, which is noted below.

### Fixed

- **The concurrency suite failed on slow machines for no good reason.** It
  compared total wall times against a fixed 1.7× margin, and every measurement
  includes the cost of starting a PHP process — a fixed addend that is a few tens
  of milliseconds here and 150 or more on a modest shared host. Past that point,
  two writes that really did serialise came in under the margin and the test
  called it a failure. It now measures the process startup separately and takes
  the margin over the time the lock is actually held, and prints all three
  numbers so a failure says which one drifted. Reported from an external
  environment where it gave 3 failures out of 20 with nothing wrong.

- **`configurar.php` now creates the configuration files as `0600`.** They hold
  every key and secret in the system, and with the usual umask they came out
  `0644` — readable by the other users of the machine, who on shared hosting are
  strangers. If the permissions cannot be set it says so instead of staying
  quiet.

- **An I/O failure on the API state file reported "rate limit exceeded".** It
  still closes the door, which is right, but the message sent whoever read it to
  look in the wrong place. It now says the service is unavailable.

- **Clearing a table's part caches in APCu issued 512 deletions per write**,
  covering a fixed ceiling of parts whether they existed or not — and a table
  past that ceiling kept entries that were never cleared. It now clears exactly
  the parts the table has or is about to have.

- `config.php` pointed at `docs/02-seguridad.md`, which does not exist.

- The `[2.0.1]` section of this file was removed: it was never released, and its
  contents shipped inside 2.1.0, so it only duplicated them.

- **Sweeping orphaned temporary files no longer depends on comparing process
  ids.** After a `SIGKILL` a write can leave its `.tmp` behind, and the next
  write to that table removes it — it holds the table's exclusive lock, so any
  temporary it finds is someone else's leftover. It skipped files whose name
  ended with its own `.<pid>.tmp`, which is both unnecessary — the sweep runs
  before writing anything, so there is no temporary of its own in flight — and
  fragile, because one process id can be a suffix of another's. The sweep also
  reaches the shared files now (`_views.json` and friends): holding the shared
  database lock means nobody can hold the exclusive one, so nobody is writing
  them. Only other tables' temporaries are left alone, because those may be live.

  This was reported by CI on PHP 8.4 only, in one run out of twelve, as
  "temporary files were not swept". **It has not been reproduced here** — the
  crash suite needs the timing of a kill to land in a specific window, and 8.4 is
  not available in this environment — so this removes the one mechanism that
  could plausibly cause it rather than a confirmed diagnosis. The test now names
  the files that were left, so a recurrence says which write produced them.

- **Exporting a database and restoring it failed.** The importer checks that
  every `.json` in the ZIP has the name of a table file, and its list of allowed
  suffixes was never updated for the two file types 2.0 introduced:
  `<table>.rev.json` and `<table>.idx.<name>.json`. The exporter puts everything
  in the folder into the ZIP, so a ZIP the panel had just produced was rejected
  by the panel itself with "a name that is not a table's". Restores of ZIPs from
  earlier versions keep working.

  It went unnoticed because the round-trip test skips itself when the `zip`
  extension is missing, which it was on the machine this was developed on: it
  printed "(omitida: falta la extensión zip)" and counted as passing, and only
  CI, which has the extension, ever ran it.

## [2.1.0] - 2026-08-30

Sets up in one command, refuses to start with the example keys, and bulk writes
and point lookups are several times faster. Nothing breaking.

### Added

- **`configurar.php`.** One command creates both configuration files with random
  keys already in place, keeping the panel's key and secret matching its account
  in the API. `--local` also allows plain HTTP so you can try it on your own
  machine. It never overwrites an existing configuration. Doing it by hand meant
  nine values across two files, two of them duplicated, with no clear error when
  you got it wrong.

### Fixed

- **Leaving the example keys in place used to work.** The two templates carry the
  same `CHANGE_ME_` placeholders on both sides, so forgetting to replace them
  left a working install — with a key and an HMAC secret that are published in
  this repository. The API and the panel now refuse to start while any remain,
  and say how to generate proper ones.

- **The HTTPS refusal now says what to do about it.** Both the API and the panel
  reject plain HTTP, which is the first wall anyone hits installing on their own
  machine, and the message was just "only accepts HTTPS". It now names the two
  constants and the two files, and reminds you to put them back before
  publishing. The README covers it too, which it did not.

- **The admin panel refused to restore a ZIP when served over IPv6.** Restoring
  requires the panel and the engine to be on the same machine, decided by
  comparing hosts. An IPv6 address arrives as `[::1]:8080`, and taking everything
  before the first `:` returned `[`. A bare `::1` came out empty, and two empties
  compared equal, which would have wrongly allowed it.

- **A failing panel test now says what went wrong.** The diagnostics printed the
  first 160 characters of the page with tags stripped, which is the stylesheet.

### Changed

- **Bulk `UPDATE` and `DELETE` were quadratic.** Three costs at once: each
  affected row was located by scanning the table for a match by value;
  `Catalog::tablas()` listed the directory once per row, because foreign-key
  propagation asks "does anyone reference me?" for each one; and pulling the
  table into a local variable before writing to it triggered PHP's copy-on-write,
  copying every row on every iteration. `DELETE` also compacted with
  `array_values()` per row, moving every following row and invalidating the
  position just found. Rows are now found by the position they already had, an
  `isset` in the normal case, with the old scan as the fallback for when a
  trigger has moved things. On 8,000 rows: `UPDATE` **1,244 ms → 53 ms**,
  `DELETE` down to **11.7 ms**. `f3_escrituras.php` measures the growth so it
  cannot creep back.

- **Point lookups cache the part they read.** The whole-table cache is no use
  here — the point of an index is not reading the whole table — so every lookup
  re-decoded its part to keep one row. On 20,000 rows a primary key lookup went
  from **11.2 ms to 2.6 ms**, and by unique from 13.0 ms to 2.5 ms.

- **The API's per-request state went from three file rewrites to one.** Checking
  the global block, registering the nonce and counting the request each read,
  decoded, modified and rewrote the whole state file under an exclusive lock, and
  that file grows with traffic, so latency got worse on its own. The check is now
  read-only, and the nonce and the count share one transaction. With 100 requests
  in the window the state cost per request went from **0.70 ms to 0.21 ms**, and
  the file is a third of the size. A request rejected for database permissions
  now consumes rate-limit quota, which for an anti-abuse limit is the wanted
  behaviour.

- **The cache is no longer written atomically, and skips tables over
  `JSONSQLDB_CACHE_MAX_FILAS` rows (20,000 by default).** It is regenerable and
  its key carries the table revision, so a half-written file just fails to
  unserialise and counts as a miss — which the read path already handled. That
  removes an `fsync` the size of the table from every write, and the memory spike
  from serialising large tables.

- The credit for the four performance findings in this release goes to an
  external review by arena.ai, which measured them on the 2.0.0 release. Each was
  verified independently here before being applied.

## [2.0.0] - 2026-08-27

Major version because the API request format changes in a way that breaks
existing clients. See the first entry under *Changed*.

### Added

- **Indexes.** `CREATE INDEX name ON t (a, b)`, `DROP INDEX name [ON t]` and
  `SHOW INDEXES [FROM t]`. Primary keys and unique constraints get one
  automatically, named `auto_<columns>`. An index stores the positions of the
  rows holding each value, which tells the engine which part files to decode: a
  primary key lookup over 50,000 rows went from 107 ms and 50 MB of peak memory
  to 17 ms and 19 MB.

  Composite indexes are used left to right — `(a, b)` serves a lookup on `a`, or
  on `a` and `b`, but not on `b` alone. Only `=` and `IN` against literals, and
  only in the top-level `AND` chain of a `WHERE`: anything under a `NOT`, a
  top-level `OR`, `IS NULL` and `NOT IN` are deliberately left alone, because an
  index there would change the answer instead of just finding it faster.

  Index keys follow the engine's equality rather than PHP's, so `5`, `'5'` and
  `'5.0'` share a key and looking up a number still finds a row that stored it as
  text. The whole index is rebuilt on every write, because saving re-packs the
  rows from zero and a single `DELETE` moves every row after it into a different
  part. Each file records the revision it belongs to; a mismatch makes the engine
  ignore it and scan, so a stale index can cost speed but never correctness.

  Indexes only help reads. Writes get slower, since a table with indexes rewrites
  their files too. `JSONSQLDB_INDICES` turns the whole thing off.

- **The admin panel lists, creates and deletes indexes** on the table structure
  screen. Automatic ones are shown but cannot be deleted on their own.

### Fixed

- **The journal's copies were not forced to disk, which is exactly what a power
  cut needs.** `copy()` leaves the contents in the operating system's cache. That
  survives the process dying — the cache belongs to the system, not to it — but
  not the power going out. The manifest *was* flushed, so a cut could leave a
  perfectly valid manifest pointing at copies that were empty or half written,
  and recovery would then restore those over data that was intact. Copies are now
  streamed and `fsync()`ed before the manifest is written, and restoring does the
  same, so an interrupted recovery leaves something the next one can repeat.

- **The manifest now records the size of every copy, and recovery checks it.** If
  a copy does not measure what it should, the engine refuses to touch anything
  and says so, leaving the journal in place to be looked at. Restoring blindly
  would destroy data that might be perfectly fine, and deleting the journal would
  throw away the only copy left.

- **A journal could be lost to a race between two writers.** Creating
  `.tx/<scope>/` makes `.tx/` first and the scope directory second, and another
  process finishing its own journal could sweep the empty `.tx/` in between. The
  second `mkdir` then failed and the whole write was lost. It is retried now.
  Found by the concurrency suite, which lost five rows out of forty about one run
  in five; it now also reports what a failing child process said instead of
  silently counting rows that never arrived.

- **A boolean and its number produced different index keys.** The engine
  compares booleans as text, so `true` and `1` — which it considers the same
  value — hashed to different keys, and looking up `1` would not have found a row
  storing `true`. Booleans are now normalised to numbers before the key is built.
  Where the engine's own equality cannot be reproduced exactly (it is not
  transitive: `true == 1` and `1 == '1.0'`, but `true != '1.0'`), the key errs
  towards returning *more* candidate rows, because the `WHERE` filters those out
  afterwards while a missing row is never noticed.

- **`REPAIR KEYS` deleted the indexes of the table it repaired.** It rewrote the
  rows without declaring them, and a write that declares no indexes removes the
  files of any it finds. Results stayed correct — without an index the table is
  scanned — but repairing a foreign key has no business making the table slower.

- **A write to a table spread over several files was not crash-safe.** The
  journal decided by counting *tables*, but the unit that has to be atomic is
  *files*, and one table is rarely one file: past `JSONSQLDB_FILAS_POR_PARTE`
  rows it is several parts, with indexes it is one file per index, and an
  `INSERT` into a table with `AUTOINCREMENT` also rewrites the schema file. A
  power cut between two renames left some parts new and some old — and since
  parts are split by position, that did not lose "a few rows", it threw the
  table out of alignment from the cut onwards. Any write touching more than one
  file now runs under a journal.

- **The journal is scoped to the lock the write holds**, `.tx/_base/` or
  `.tx/<table>/`, so making single-table writes crash-safe did not cost the
  concurrency that the table lock buys: two writes to different tables still run
  at the same time. Recovery of a table journal asks for that table's lock
  without waiting — if it cannot get it, a live write owns the journal and there
  is nothing orphaned to undo.

- **An interrupted journal could corrupt the table it was protecting.** The
  manifest is written after the copies, so a process killed while copying left a
  half-written file with no manifest — and recovery restored it over the intact
  original. With no readable manifest the copies are now discarded instead:
  copying happens before anything is modified, so if it did not finish, nothing
  was touched. The manifest is also deleted first when clearing a journal, so a
  crash during cleanup cannot leave a manifest pointing at a set of copies that
  is already half gone.

- **Reads did not take the table lock**, so a reader could see half a write. A
  `SELECT` took only the shared database lock while a write to one table held the
  table lock, and the two could run at once. Harmless when a table was one file
  and a single atomic rename; wrong as soon as it spans several, where a reader
  could pick up the first part already new and the second still old. Reads now
  take the shared lock of every table they touch — shared locks do not block each
  other, so reads still run together and only wait on a write to that same table.

- **The revision counter is now per table.** All tables shared `_revs.json`, and
  two writes to different tables — which run at the same time by design — each
  read it and rewrote it whole, so whichever finished last erased the other's
  bump and left that table's cache serving rows from before the write, with no
  visible symptom. Bases created with earlier versions keep reading the old file
  until each table is written once.

- **The revision is now written before the data, not after.** A crash between the
  two leaves a new revision over old data: nothing is cached under that revision,
  so the next read goes to the file and sees the truth. The other order left new
  data under an old revision, with the cache serving what was there before —
  which is not detectable afterwards.

- **Orphaned temporary files are swept.** A process killed with `SIGKILL` never
  runs the `finally` that removes its temporary, so it stayed on disk. Every
  write now clears any temporary of that table left by another process, which it
  can do safely because it holds the table's exclusive lock; taking the database
  lock clears the rest.

- **The memory guard now also checks the memory PHP has requested from the
  system**, not only what is in use. The limit applies to the former, and the gap
  — blocks already requested but too fragmented to reuse — is not negligible on a
  small `memory_limit`: with 16 MB the process hit PHP's fatal with 13 MB in use.
  The reserve kept before cutting also never drops below a fraction of what is
  already used, to cover an array doubling, which costs about as much as the
  array already occupies and cannot be predicted from how the previous rows grew.

### Changed

- **A join rebuilt the list of every inner row on each outer row, and threw it
  away.** `array_keys($internas)` sat inside the loop over outer rows and was
  overwritten straight after whenever there was a hash index — which is the
  normal case. A join of 30,000 by 20,000 rows built a 20,000-element array
  thirty thousand times for nothing. The aggregate join in the benchmark went
  from **673 ms to 95 ms**.

- **Building an index no longer goes the long way round for the common values.**
  `Indexes::trozo()` was almost half the cost of an `INSERT`, because it is
  called once per row and column and every value went through `esNumerico()` and
  `aNumero()`. Integers and non-numeric strings — primary keys, emails, cities —
  now take a direct path to the same result. An `INSERT` of one row went from
  **117 ms to 90 ms**.

- **`Valor::comparar()` short-circuits two numbers and two strings.** It is the
  single hottest function in the engine: an `ORDER BY` over 20,000 rows calls it
  around three hundred thousand times, and it was doing four function calls to
  reach a comparison that `<=>` or `strcmp` answers directly. The shortcuts give
  the same result — verified exhaustively over 1,225 pairs against the long path,
  including `NAN`, `INF`, `'0x1A'`, `' 5 '` and booleans. `ORDER BY … LIMIT` went
  from **177 ms to 109 ms** and a filtered scan from 39 ms to 22 ms.

- **`IN (SELECT …)` was quadratic, and is now linear.** The list of values was
  scanned in full for every row, so 30,000 rows against 2,000 values meant sixty
  million comparisons: 13.4 seconds where SQLite took 8 ms. When the values are
  the same for every row — a subquery that does not look outwards, or a list of
  literals — they are now grouped by key once and each row costs a lookup. On the
  benchmark that is **13,364 ms → 105 ms**, and doubling the data now doubles the
  time instead of quadrupling it.

  The key only narrows the candidates; equality is still decided by
  `Valor::comparar`, because two different values can share a key and the answer
  has to be the same as before rather than merely similar. Correlated subqueries
  change with each row and have nothing to reuse, so they are scanned as before.

- **`JSONSQLDB_FILAS_POR_PARTE` now defaults to 1,000 instead of 5,000.** It sets
  how much an indexed lookup can skip: the index says which positions hold the
  rows and therefore which parts, so with large parts you decode a lot to get a
  little. With 20,000 rows and parts of 5,000 there are only four files and
  almost any lookup touches them all. Measured on the benchmark table: a primary
  key lookup goes from **8.56 ms to 4.46 ms** and an `IN` of ten keys from
  **46.11 ms to 9.44 ms**. Full scans are unaffected, and writes cost between 4 %
  and 15 % more depending on table size. Going below 1,000 barely improves reads
  and makes writes noticeably worse.

  Existing tables keep whatever size they were written with until the next write
  to them; nothing has to be converted.

- **BREAKING: the HMAC signature now covers the database name.** The formula
  goes from

  ```
  "+" . api_key . "|" . timestamp . "|" . sql . params . "¿"
  ```

  to

  ```
  "+" . api_key . "|" . db . "|" . timestamp . "|" . sql . params . "¿"
  ```

  The `db` field was outside the signature, so a legitimate signed request could
  be captured, have its `db` changed, and be replayed against a different
  database — the signature stayed valid because it did not cover the field. For
  an API key with access to more than one database, that was enough to run a
  statement where it did not belong.

  **Every client signing with the old formula stops working** and has to be
  updated. The four bundled clients (PHP, Python, PowerShell and the admin
  panel) already use the new one. For statements that target no database
  (`SHOW DATABASES`, `CREATE DATABASE`) the empty string is signed, which is what
  the clients send.

- **Rows are read one at a time when the query will not keep them all.** The
  files are one row per line, so a query that discards most rows no longer holds
  the whole file and the whole decoded array at once. `SELECT * FROM t LIMIT 50`
  over 50,000 rows went from 56 ms and 50 MB to 3.6 ms and 3.6 MB. When every row
  is wanted the file is still decoded in one go, which is about 25 % faster and
  costs no extra memory, since the rows were going to be held anyway.

- **`LIMIT` is pushed into the read** when there is no `WHERE` and no `JOIN`.
  With either of those the surviving rows are not the first ones, so the read
  cannot stop early.

- **`SELECT COUNT(*)` and `SHOW TABLES` no longer build the rows.** `SHOW TABLES`
  loaded every table in the database into memory just to count its rows.

- **Rows are no longer held twice while a table is prepared for a query.**
  Loading a table copies every row with its columns prefixed by the table alias,
  and both full copies were kept until the loop finished. The original is now
  released row by row: `SELECT COUNT(*)` over 50,000 rows went from 50 MB of peak
  memory to 32 MB. That loop was also outside the memory guard's watch, so it
  could reach PHP's fatal without the engine getting a chance to stop first.

- **The cache is skipped when memory is tight.** Storing a table means
  serialising it, which holds it twice for a moment. Past half of `memory_limit`
  the engine gives up the cache rather than risk the query for it.

- **Data and structure are written in a single operation.** They were two writes,
  each raising the revision and rebuilding the indexes, so every `INSERT` into a
  table with `AUTOINCREMENT` did that work twice and left an instant with new
  rows and an old schema.

- `CREATE UNIQUE INDEX` is rejected with an explanation rather than silently
  accepted: an index here only speeds up lookups, and uniqueness is what
  `ALTER TABLE … ADD UNIQUE` is for — which creates its own index anyway.

- **New on-disk files.** `<table>.rev.json` per table, and
  `<table>.idx.<name>.json` per index. `_revs.json` is no longer written. Nothing
  has to be migrated by hand: an existing database is read as it is, and each
  table moves over on the first write to it, carrying on from the revision the
  old `_revs.json` had rather than starting from zero — otherwise a stale cache
  entry could be taken for a current one. Indexes appear at that same moment.

  One case needs care and is handled: a database left with a **pending journal
  from before 2.0**. Journals used to keep their copies loose inside `.tx/`, with
  no scope directory, and recovery now looks for subdirectories — so an old
  journal went unnoticed, and an operation interrupted before the upgrade was
  never rolled back. Those are now picked up and undone like any other.

- **New test suite `f8_indices.php`** (57 checks), which validates almost
  everything by comparing the indexed query against the same condition rewritten
  so the index cannot be used. It covers booleans, dates and decimals; strings
  with quotes, newlines, emoji and shapes that look like index keys themselves;
  and composite keys that would collide if the format did not record where each
  part ends (`('x','yz')` against `('xy','z')`).

- **`f6_cortes.php` went from 5 checks to 29.** It now kills processes during a
  write to a table spread over part files with indexes; during each kind of
  operation in turn (`UPDATE` of every row, `UPDATE` of an indexed column, an
  `INSERT` that adds a part, a `DELETE` that removes one, `CREATE INDEX`,
  `DROP INDEX`, four kinds of `ALTER TABLE`, `CREATE TABLE`, `DROP TABLE`); and
  it builds damaged journals by hand to cover the states a power cut produces but
  a `SIGKILL` cannot — a truncated copy under a valid manifest, a journal with no
  manifest, a `COMMITTED` one, two table journals at once, a revision file that
  is ahead of the data, and a missing one. After every kill it demands that no
  row be left mixed between the old and new value, that the indexes agree with
  the data, that the cache agrees with the files, and that nothing is left over.

- **New test suite `f9_journal.php`** (34 checks), exhaustive where
  `f6_cortes.php` is only a sample. Killing processes is realistic but it
  samples: where the kill lands is luck. This one opens a real journal on a table
  spread across ten files and builds by hand **every** state the write could have
  been interrupted in — first file replaced and the rest not, first two, first
  three, all of them; the same with files truncated, and again with them deleted;
  each under both journal scopes. Sixty-six states, each demanding that every file
  return to its exact original bytes.

  It also pins the two invariants the scheme rests on: that the journal copies
  everything a write touches, across eleven kinds of write; and that writes which
  skip the journal really do touch one file. To tell whether a write journalled
  without guessing, it drops a *file* named `.tx` where the directory would go, so
  any attempt to journal fails and a write that succeeds is one that never tried.

  It also covers upgrading: a database in the old format is read without being
  written to, its first write produces the new files without reusing revision
  numbers, and a journal left pending by the previous version is undone.

- `f7_concurrencia.php` gained a reader running against a writer on a partitioned
  table, which is the case the shared table lock exists for.

## [1.10.1] - 2026-08-26

### Added

- **Data is flushed to disk before the rename.** Writes were atomic in the sense
  that the file was replaced in one step, but the contents could still be sitting
  in the operating system's cache: a power cut could leave the new file empty or
  half written even though the rename had already happened. Every write now
  flushes and calls `fsync()` before the rename. `fsync()` exists from PHP 8.1;
  on 8.0 the buffer is flushed, which is as far as that version goes.

- **The memory guard checks a file before reading it.** A file is materialised in
  one instruction, so checking every 512 rows never got the chance to intervene:
  a large table exhausted memory inside `json_decode()`. The size is now
  estimated before opening. It is a heuristic — how much data expands depends on
  its shape — so it narrows the window rather than closing it; closing it would
  mean reading in chunks instead of whole, which is a change to the storage
  layer.

- **Two tests looked for the log files by today's date**, which broke near
  midnight: the file is named by the API process, which sets the timezone from
  `config.php`, so it can already be on the next day relative to whoever checks.
  They now match by pattern. The engine behaved correctly; only the tests were
  time-dependent.

- **A partially deleted database is now reported.** `DROP DATABASE` ignored
  errors while removing files, so a permission problem could leave a directory
  half deleted, looking like it existed but unusable. It now says what could not
  be removed.

### Fixed

- **Several documents still described the engine as it was before 1.9.0.** An
  audit found the same kind of debt in more places than the locking section
  fixed above: the tables of unsupported features in `docs/02-consultas.md` and
  the README still listed **correlated subqueries, `INTERSECT`, `EXCEPT` and
  CTEs as unsupported**, when all four have worked since 1.9.0 — and the README
  contradicted itself, listing them as supported a few paragraphs earlier. Only
  `WITH RECURSIVE` is genuinely out.

  Also corrected: the README's Concurrency section still described the old
  database-wide lock; `docs/01-nucleo.md` had a "pending phases" section listing
  work finished long ago; the configuration table was missing six constants
  (`JSONSQLDB_CONEXION_DIRECTA`, the two memory ones, the journal one and the two
  collation ones); `MEMORIA` was missing from the list of error types; atomic
  writes were described without the `fsync` added in this same release;
  `composer.json` ran seven suites instead of nine; and `SECURITY.md` claimed to
  support "1.0.x".

- **The documentation still described the old locking.** Section 3 of
  `docs/01-nucleo.md` said the lock covered the whole database and that writes
  serialised against each other, which stopped being true in 1.9.0 and
  contradicted the section further down that describes the two levels. The same
  outdated sentence was in `Storage.php`'s header comment. Both corrected.

- **The memory guard aborted the query that came after a big one.** It was
  measuring `memory_get_usage(true)`, the memory PHP has requested from the
  operating system — which includes blocks that are already free and kept for
  reuse. After a large query that number stays high (28 MB reserved with only
  1.5 MB actually in use), so the next query, however small, was stopped before
  it began.

  It now measures the memory actually in use, which is what grows and eventually
  meets the limit. Verified at seven different memory limits, and the test now
  runs a second, small query after the abort — the exact case that failed.

  This is what broke CI on PHP 8.2 and above while passing on 8.0 and 8.1: how
  much the allocator keeps in reserve differs between versions.

- The guard also stops when **another jump the size of the last one would not
  fit**. Getting this right took three attempts, and each one failed on different
  PHP versions:
  - PHP doubles an array's hash table as it grows, so between two checks the
    usage can jump past the remaining margin.
  - The reserve is computed **per row checked, not per batch**: a single 4 KB row
    can take more than a whole batch of small ones.
  - Checks happen every 512 rows while there is room and every 8 once past half
    the limit. A check costs 0.01 µs, so tightening up where it matters does not
    show in the benchmarks.
  - The result-building loop was not being watched at all — with large rows the
    query died there, not in the join.

  Verified at eight memory limits with small rows and seven with 4 KB rows.

- **And underneath all of that, a safety net that does not depend on guessing
  right.** Predicting consumption is a heuristic — PHP allocates in bursts and
  how much differs between versions — so no estimate is infallible. The engine
  now sets aside two megabytes at the start and registers a shutdown function.
  If PHP does run out of memory, that function releases the reserve, which frees
  room to work, and reports it: the API answers with an ordinary JSON error
  instead of an empty or truncated body.

  Shutdown functions run even after a fatal error. That is the difference: the
  predictive guard stops earlier and with a better message, but the net works
  even when the guard does not. Registered regardless of
  `JSONSQLDB_MEMORIA_VIGILAR`, and there is a test that disables the guard on
  purpose to prove the net alone is enough.

## [1.10.0] - 2026-08-26

### Added

- **Memory guard.** A query whose result does not fit in `memory_limit` used to
  die with PHP's fatal error: not catchable, no `finally`, and the client got a
  broken response instead of a message. The engine now checks every 512 rows and
  stops at 85 % of the limit with an ordinary error (`sqlState` `MEMORIA`)
  explaining what happened and what to do. The query still fails — what does not
  fit does not fit — but the process stays alive and the API answers properly.
  Controlled by `JSONSQLDB_MEMORIA_VIGILAR` and `JSONSQLDB_MEMORIA_MARGEN`.
  - The engine deliberately does **not** raise `memory_limit` by itself. That
    limit exists so one request cannot take down the others; raising it silently
    would take a decision that belongs to whoever runs the server.

- **Faster reads.** Two optimisations that need no change to the storage format
  and no configuration:
  - **Fast path for simple comparisons.** A `WHERE column = value` (and `<>`,
    `<`, `<=`, `>`, `>=`) is now resolved without going through the general
    expression evaluator on every row. Measured over 2,000 rows: a primary-key
    lookup drops from 2.9 ms to 1.7 ms. `UPDATE` and `DELETE` use the same path.
  - **Early stop on `LIMIT`.** When a query has `LIMIT` and no `ORDER BY`,
    `GROUP BY`, `DISTINCT` or aggregates, scanning stops as soon as enough rows
    are found. `WHERE ... LIMIT 10` over 2,000 rows drops from 4.0 ms to 1.4 ms.
    With `ORDER BY` it cannot stop early, because the last row of the table might
    be the first of the result.

  Multi-row `INSERT` already existed and is by far the biggest lever: 2,000 rows
  in one statement take ~30 ms against ~5,000 ms as 2,000 separate statements,
  **180× faster**. It is now documented prominently, because a benchmark that
  inserts row by row measures the wrong thing.

### Fixed

- **`$externa` was used outside its scope** in two places added by the correlated
  subquery work in 1.9.0: the extra `ON` condition of a join and the `GROUP BY`
  grouping. It produced a PHP warning on every query that joined and grouped at
  the same time.

## [1.9.0] - 2026-08-26

### Added

- **Table-level write locks.** There are now two levels — the database and the
  table — always acquired in that order, which is what makes a deadlock
  impossible. Reads and single-table writes take a shared lock on the database;
  anything that can touch more than one table takes the exclusive one.
  - Two writes to different tables now run in parallel, and a write no longer
    blocks reads of other tables. This restores the behaviour originally
    specified: a `SELECT` should wait for writes **to its table**, not to the
    whole database.
  - The decision is deliberately suspicious: a foreign key, another table
    referencing this one, a trigger, or an `INSERT ... SELECT` all fall back to
    the database lock, which waits for every pending write on every table
    involved. When in doubt, the database lock — too much locking only costs
    parallelism, too little costs data.
  - `tests/f7_concurrencia.php` measures this with real simultaneous processes.

- **Correlated subqueries.** The subquery can now read columns from the enclosing
  query, which is how `EXISTS` is normally written:

  ```sql
  SELECT n FROM clientes c WHERE EXISTS (SELECT 1 FROM pedidos p WHERE p.cid = c.id);
  SELECT n, (SELECT SUM(total) FROM pedidos p WHERE p.cid = c.id) AS gastado FROM clientes c;
  ```

  Uncorrelated subqueries keep running once and being cached; correlated ones are
  cached per outer row, which is the most that can be reused.

- **`INTERSECT` and `EXCEPT`**, completing `UNION`. They chain with each other
  and with `UNION`, and the trailing `ORDER BY` and `LIMIT` still apply to the
  whole.

- **Common table expressions**: `WITH nombre AS (SELECT ...) SELECT ...`. Several
  can be declared at once and each can use the previous ones. `WITH RECURSIVE`
  is rejected with an explicit message.

- **The admin panel can restore a database from a ZIP backup**, the mirror of the
  export. It writes the engine's files directly, so it only appears when the
  panel and the API share a host; across machines the SQL dump is the way, as
  before.
  - Everything is validated before anything is touched: only `.json` files (plus
    `.htaccess` and `web.config`) are restored, table names are checked against
    the engine's own rule, and the contents must be valid JSON with the shape the
    engine expects.
  - **A ZIP containing a path that escapes the destination folder is rejected
    whole**, without writing anything. That is the classic attack against ZIP
    imports, and there is a test that builds such an archive and checks nothing
    lands outside.
  - The current contents are moved aside before writing, and put back if the
    restore fails halfway.

- **Optional read-only API key for the panel** (`ADMIN_API_KEY_LECTURA`). When
  set, the panel signs with it for users whose role is read-only, so the engine
  itself refuses writes: until now the only thing stopping a read-only user was
  a check in the panel's own code. Leaving it empty keeps the previous behaviour.

### Changed

- **`RAISE` now only accepts `ABORT`.** `FAIL`, `ROLLBACK` and `IGNORE` were
  parsed and then all treated as `ABORT`. `ROLLBACK` cannot mean what it means in
  SQLite because there are no multi-statement transactions, so promising it was
  wrong. They are rejected with a message explaining why.

- CI now also runs on **PHP 8.5**, the current stable branch, and runs the
  concurrency suite.

### Documentation

- **The documentation no longer claims to be "SQLite compatible".** It says what
  is true: the dialect resembles SQLite's and takes most of its decisions from
  it, but there are constructs SQLite has and this does not, others borrowed from
  MySQL, and concrete behavioural differences — all of them now listed. The
  biggest is type comparison: here a text compared against a number is converted
  (`'12abc'` is 12), whereas SQLite applies the declared column's affinity. That
  difference is deliberate, and this documentation is the reference, not
  SQLite's.

## [1.8.0] - 2026-08-25

### Added

- **`UNION` and `UNION ALL`.** All parts must return the same number of columns;
  the result takes its column names from the first part and the others contribute
  by position. A trailing `ORDER BY` and `LIMIT` apply to the whole union, not to
  the last part — at that point the source tables are gone, so the `ORDER BY`
  accepts result column names or positions (`ORDER BY 1`). Each part keeps its
  own `WHERE`, `GROUP BY` and joins.

- **`GROUP_CONCAT(col)` and `GROUP_CONCAT(col, separator)`.** Honours `DISTINCT`
  and skips `NULL`s like the other aggregates. Unlike SQLite, the concatenation
  order is defined: it follows the rows of the group.

- **`CONCAT(a, b, ...)`.** Does not exist in SQLite, where `||` does the job; it
  is here for people coming from MySQL, with MySQL's semantics — if any argument
  is `NULL`, the result is `NULL`.

- **`FULL JOIN` / `FULL OUTER JOIN`.** Brings the unmatched rows from both
  sides, padded with `NULL`s. Neither SQLite nor MySQL 5 have it.

- **`REGEXP` and `RLIKE`** (MySQL's operator; in SQLite `REGEXP` exists but has
  to be supplied by the host program). `NOT REGEXP` works too, `NULL` propagates
  as `NULL`, and it is **case-sensitive** — use `(?i)` at the start of the
  pattern for the opposite. An invalid pattern, or one so expensive that the
  regex engine gives up on a long text, produces a clear error instead of a
  hung process.

- **`CAST(expr AS type)`.** Accepts the same type names as `CREATE TABLE`,
  aliases included, and tolerates a length that it ignores
  (`CAST(x AS VARCHAR(10))`). `NULL` stays `NULL`, `INTEGER` truncates towards
  zero rather than rounding, and `DATETIME` validates the date instead of
  inventing one.

- **`EXISTS` and `NOT EXISTS`** are now implemented. The README listed them among
  the supported operators, but the parser did not know them: any query using
  `EXISTS` failed with a syntax error. They work with non-correlated subqueries.

### Fixed

- **`5 % 0.4` crashed the request** with PHP's `DivisionByZeroError` instead of
  returning a value. The zero check ran before the cast to integer, so a divisor
  like `0.4` passed it and then became `0`. Any query could trigger it, and the
  error escaped as a fatal rather than a controlled engine error. It now returns
  `NULL`, as SQLite does.

- **`SUBSTR` with index 0 returned one character too many.** In SQL position 0
  is the gap before the first character, so `SUBSTR('abcdef', 0, 3)` covers
  positions 0, 1 and 2, of which only two exist: the answer is `ab`, not `abc`.
  The window is now computed once and clipped once, which also fixed
  `SUBSTR('abcdef', 2, -2)` — the characters *preceding* a position.

- **`DATE(NULL)`, `TIME(NULL)` and `DATETIME(NULL)` returned the current date
  and time** instead of `NULL`. A `?? 'now'` was treating "no argument" and "a
  `NULL` argument" as the same thing. `DATE()` with no arguments still returns
  now, as it should.

- **Impossible dates were being silently corrected.** `DATE('2026-02-30')`
  returned the 2nd of March, `DATE('2026-13-40')` jumped to 2027, and
  `'0000-00-00'` produced a year -1. The check only validated the format with a
  regular expression and PHP then normalised the overflow. Dates are now checked
  against the calendar, so those are rejected — including when writing to a
  `DATETIME` column, which accepted them before.

- **The Actions workflow now declares `permissions: contents: read`.** Without an
  explicit block the `GITHUB_TOKEN` inherits the repository's permissions, which
  on older repositories means write access. Reported by CodeQL.

- **Static analysis clean.** The whole engine, API and panel now pass PHPStan at
  level 6 with no real findings. Fixing them turned up:
  - `Valor::aNumero()` added `0` to a string extracted by a regular expression.
    It always held digits in practice, but under PHP 8 a non-numeric string in
    that position raises a `TypeError`; the conversion is now explicit.
  - `Database::consultar()` used two variables that only one branch initialised.
    A new branch that set neither would have produced a PHP warning and a wrong
    log entry.
  - The API did not check that `$API_KEYS` exists before using it; an incomplete
    configuration produced a fatal error instead of a message.
  - One dead public method removed (`Catalog::exigirQueNoSeaVista()`): the same
    protection ended up implemented in `Writer::ejecutar()` and this one was never
    called from anywhere. Static analysis does not flag unused public methods, so
    it took a separate pass over the call graph to find it.
  - Three provably dead checks removed (`is_array` on a constant already tested
    with `defined`, `is_string` on a typed parameter, `array_values` on a list),
    and two impossible comparisons in date parsing.

- **Correlated subqueries now say so.** They were failing with
  `Columna desconocida: 'u.id'`, which sent you looking for a typo. The message
  now explains that correlated subqueries are not supported and suggests a
  `JOIN`. They are also listed in the "what is not supported" table.

### Documentation

- New section explaining **where each construct comes from**. The dialect is
  mainly SQLite's, but `CONCAT`, `REGEXP`/`RLIKE` and `LIMIT n, m` come from
  MySQL, and `CAST` and `FULL JOIN` are standard SQL. Anything not listed
  behaves as in SQLite.

- The Python client documents why its `HMAC-SHA256` is not the weak-password-
  hashing problem CodeQL reports: it signs a message with a shared key, which is
  exactly what HMAC is for. Panel passwords use bcrypt, which is the right tool
  for that job.

- `RIGHT JOIN` was listed as unsupported in an earlier draft of the docs. It has
  always worked; the table of unsupported features has been corrected. `FULL
  JOIN` is the one that is genuinely missing.

### Changed

- Tokens carry a precise type declaration (`@phpstan-type`), and the parser's
  state-dependent helpers are marked `@phpstan-impure`. This is what makes the
  static analysis able to follow the parser instead of guessing, so future
  analysis findings are real ones.
- The PHPStan configuration is **not** committed: it is a development tool and
  the project must need nothing beyond PHP itself. What does stay in the source
  are the `@phpstan-type` and `@phpstan-impure` annotations, which are ordinary
  PHPDoc, cost nothing at runtime, and are what makes such an analysis able to
  follow the parser instead of guessing.

## [1.7.0] - 2026-08-24

### Fixed

- **Unsupported SQL is now rejected instead of silently ignored.** These were
  parsed, accepted and then quietly dropped, so the statement looked correct and
  behaved as something else entirely:
  - `INSERT OR IGNORE` and `INSERT OR REPLACE` behaved as a plain `INSERT`, with
    no conflict handling at all.
  - `CREATE TEMP TABLE` and `CREATE TEMPORARY TABLE` created a **permanent**
    table. This was the dangerous one: data you believed was temporary stayed on
    disk.
  - `WITHOUT ROWID` was accepted and ignored.

  All four now raise a `SYNTAX` error explaining what to do instead. The rule
  from here on: **if a statement is accepted, it does exactly what it promises;
  otherwise it is rejected with a clear error.**

- **`RANDOM()` now returns a signed 64-bit integer**, like SQLite's `random()`.
  It was returning a roughly 32-bit range.

### Documentation

- New section in `docs/02-consultas.md` listing **what is not supported** and the
  behavioural differences from SQLite (`DECIMAL` as a rounded float, collation in
  `ORDER BY`, accent-sensitive `LIKE`), so the supported subset is stated rather
  than discovered.

## [1.6.0] - 2026-08-24

### Added

- **Crash recovery test with real process kills** (`tests/f6_cortes.php`). The
  existing journal tests build the `.tx` directory by hand and check that undoing
  works; this one kills a child process with `SIGKILL` while it is running a
  cascading `DELETE`, then reopens the database and requires it to be in one of
  the two valid states — the delete fully applied or fully undone. Anything in
  between is corruption. It reports how many kills landed inside the write
  window, so a run that never hit it says so instead of quietly passing.

### Fixed

- **The panel's ZIP backup no longer offers itself when the engine is on another
  machine.** It reads the engine's files straight from disk, so it only works
  when the panel and the API share a host. The panel now compares the host of
  `ADMIN_API_URL` with its own: if they differ, the button is hidden and the
  reason is explained, instead of silently copying whatever happened to be in the
  local `data/` directory. The SQL dump goes through the API and works between
  machines, as before.

## [1.5.0] - 2026-08-21

### Added

- **Direct engine access, off by default.** PHP code on the same server can use
  the engine without going through HTTP, once
  `JSONSQLDB_CONEXION_DIRECTA` is set to `true` in `config.php`. Until then, any
  attempt to instantiate `Database` outside the API is rejected with an explicit
  message.
  - **For experienced developers only**, and both `config.php` and the README say
    so plainly. A direct connection is always equivalent to an `admin` key —
    there is no way to restrict it to one database or to read-only — and it
    bypasses HMAC authentication, the rate limit, replay protection and the IP
    allow-list. Security becomes entirely the developer's responsibility.
  - Queries are still logged, exactly as through the API, with the `ip` field set
    to `"local"` since there is no HTTP request to take an address from.
  - Bound parameters still work and are still the only protection against
    injection.

- **`composer.json`,** so the project can be installed from Packagist:

  ```bash
  composer require miguelenred/jsonsqldb
  ```

  It gives PSR-4 autoloading for the `JsonSQLDB\` namespace, so
  `engine/bootstrap.php` is not needed. Its `require` section contains PHP itself
  and nothing else: **there are no dependencies to download**. Copying the folder
  and requiring `engine/bootstrap.php` by hand remains equally supported.

### Documentation

- The README no longer says "Composer is not used", which was accurate but
  confusing next to a `composer.json`. It now states the actual point: the
  project uses **no third-party libraries at all**, and Composer is merely an
  optional way to install it.

## [1.4.0] - 2026-08-21

### Added

- **The journal now covers data writes that touch more than one table**: a
  `DELETE` with `ON DELETE CASCADE`, an `UPDATE` with `ON UPDATE SET NULL`, or a
  trigger writing into another table. This closes the gap left open in 1.3.0.
  - Changes are accumulated in memory and flushed at the end, so at that point
    the engine knows exactly which tables are involved and opens the journal only
    when there are two or more.
  - Single-table writes are **not** journalled: that would mean copying the whole
    data file on every `INSERT`, and the atomic rename already covers them.
    Controlled by `JSONSQLDB_JOURNAL_DATOS`, `true` by default.
  - Measured: a cascading `DELETE` across 2,500 rows in two tables takes 3.3 ms
    with the journal in place.
  - Still not covered: grouping several statements into one unit of work. There
    is no `BEGIN`/`COMMIT`.

- **Python example client** (`api/cliente_ejemplo.py`), standard library only —
  no `pip`, no `requests`. Same bound parameters, same signature and the same
  certificate options as the PHP and PowerShell clients. Requires Python 3.7+.
  A test checks that Python and PHP compute the same token for an identical
  request, so the two can never drift apart.

### Changed

- **The global `HMAC_SECRET` is gone.** `hmac_secret` is now mandatory on every
  entry of `$API_KEYS`. In 1.3.0 it was optional, with `HMAC_SECRET` acting as a
  fallback; keeping both meant explaining a precedence rule in three places for
  no benefit. An account without `hmac_secret` cannot sign anything: the API
  answers "Configuración incompleta" naming the account and what it is missing.

### Upgrading from 1.3.0

Give every entry in `$API_KEYS` its own `hmac_secret`, remove the `HMAC_SECRET`
line, and make sure each client carries its account's secret. Nothing else
changes.

## [1.3.0] - 2026-08-20

### Added

- **Referential integrity check and repair.** `CHECK KEYS [FROM table]` reports
  rows whose foreign key points at a value that no longer exists in the parent
  table; `REPAIR KEYS [FROM table]` additionally sets those keys to `NULL` where
  the column allows it. The engine enforces foreign keys on every write, so this
  cannot happen through SQL — it happens when someone edits a `.json` by hand or
  restores one table's backup without the other.
  - **Never deletes rows.** A key in a `NOT NULL` or primary key column is
    reported and left alone: what to do with that row is your decision.
  - Reads straight from disk, bypassing the cache. The cache is invalidated by a
    revision counter that only moves when the engine writes, so a hand edit would
    otherwise stay hidden.
  - jsonSQLDBadmin has an **Integridad** screen with the report and a repair
    button. `CHECK` needs read permission, `REPAIR` needs write.

- **Crash-safe schema changes.** `CREATE TABLE`, `DROP TABLE` and every
  `ALTER TABLE` now run under a journal: the files they are about to touch are
  copied to `data/<database>/.tx/` first, along with a manifest. On success the
  directory is removed; if the process dies mid-operation the directory survives,
  and its presence is the signal that something did not finish — the next time
  the database is opened, the copies are restored and everything is back as it
  was.
  - The manifest is marked `COMMITTED` before the directory is deleted, so a
    crash in that last instant does not undo an operation that actually finished.
  - Checking for a pending journal costs one `stat` (half a microsecond), once
    per request, when the lock is taken.
  - Not covered: data writes spanning several tables, such as a `DELETE` with
    `ON DELETE CASCADE`.

### Changed

- **API keys are configured differently.** Entries in `$API_KEYS` are now keyed
  by account name, with the key itself in a `key` field, and the secret field was
  renamed from `secreto` to `hmac_secret`:

  ```php
  'My application' => [
      'key'         => '...',
      'permiso'     => 'escritura',
      'bases'       => ['mydb'],
      'hmac_secret' => '...',
  ],
  ```

  The old shape (keyed by the API key, with `nombre` and `secreto`) is **not**
  supported: rewrite the entries. Nothing else changes — the request format, the
  signature and the clients are the same.

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

[1.10.1]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.10.1
[1.10.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.10.0
[1.9.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.9.0
[1.8.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.8.0
[1.7.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.7.0
[1.6.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.6.0
[1.5.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.5.0
[1.4.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.4.0
[1.3.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.3.0
[1.2.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.2.0
[1.1.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.1.0
[1.0.0]: https://github.com/miguelenred/jsonsqldb/releases/tag/v1.0.0
