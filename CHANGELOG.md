# Changelog

Every release, newest first. The format is
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/spec/v2.0.0.html) as described in
[Versioning and releases](docs/contributing/releases.md#versioning-and-releases).

Breaking changes are listed under **Changed** or **Removed** and each one has a
matching section in [`UPGRADING.md`](UPGRADING.md). What is public, and how
public, is in [`STABILITY.md`](STABILITY.md).

## [Unreleased]

### Changed

- **`chunk()` walks by key, not by offset.** Each batch starts after the last
  row of the one before, in the query's order with the key added last, so every
  batch costs the same and a callback that deletes or updates rows no longer
  makes the walk skip or repeat any. A chunk always has a total order now.
- **Modules are flat.** A module is any folder under `modules/` with a
  `module.php`, named whatever its author likes; its id is the folder name
  (`Billing`, `Shared`). The plugin and gateway kinds, `modules/Plugins/` and
  `modules/Gateways/` are gone, so `cache:warm` no longer records roots that do
  not exist. Templates are `@Billing/`, assets `/assets/module/Billing/` through
  `asset()->module()`, configuration `config/Billing.php`, translations
  `Billing.key`. `modules.paths` is a list of places to look, and an entry with
  its own `module.php` is a single module. Breaking: see
  [`UPGRADING.md`](UPGRADING.md#modules-are-flat-modulesname-no-plugins-or-gateways).

### Added

- **Localization.** Translations as PHP files: the application's in `lang/`,
  each module's in its own `lang/`, keyed `shared.*`, `plugin.<Name>.*` and
  `gateway.<Name>.*`. The Twig `local` filter, `TemplateView::local()` and an
  injectable `Localization` service with `:name` parameters. The locale comes
  from the `language` cookie, then the visitor's country through
  `lang/countries.php`, then `Accept-Language`, then `en`; country detection
  reads a CDN header only from `http.trusted_proxies`
  (`localization.country_header`) and is replaceable through
  `CountryResolver`. A local MaxMind GeoLite2/GeoIP2 Country or City
  database (`localization.maxmind_database`, optional `maxmind-db/reader`)
  answers after the header. Localized responses carry `Vary`. See
  [Localization](docs/reference/localization.md).
- `Request::fromTrustedProxy()` is public.
- **`MEMORY_LIMIT`** (`app.memory_limit`) sets PHP's `memory_limit` at boot, in
  PHP's notation; a value PHP would not understand, or one below what the
  process already uses, stops the boot. `queue:work --memory=128M`
  (`QUEUE_MAX_MEMORY`) stops a worker between jobs at that size, and defaults to
  80% of `memory_limit`.
- **`GET /health`** in the Shared module: database, cache, queue and disk
  checks as JSON, 200 or 503, for load balancers and uptime monitors.
- **Cursor pagination.** `Query::cursor()` and `cursorInto()` return a
  `CursorPage` with `nextCursor()` and `previousCursor()`: keyset pagination
  that seeks through the index instead of skipping with `OFFSET`, costs the
  same at any depth and runs no count. See
  [Two ways to paginate](docs/reference/data.md#two-ways-to-paginate).
- **`MAX_EXECUTION_TIME`** (`app.max_execution_time`) sets how long a web
  request may run, in seconds. Console commands and queue workers keep their own
  limits.
- **Whoops debug page.** With `APP_DEBUG` on and `filp/whoops` installed (a dev
  dependency), a browser error renders as a Whoops page, with cookies,
  environment values and secret-looking fields masked and file links for
  `APP_EDITOR`. Without it, or if it fails, the built-in page renders as
  before. See [Errors](docs/reference/errors.md#the-debug-page-whoops).

## [2.1.2] - 2026-09-20

The first release. Every public API in it is **Experimental**: a minor release
may change it, always with an upgrade note. Nothing is Stable yet — see
[`STABILITY.md`](STABILITY.md) for why, and for what is expected to be promoted.

### Added

- **Modules.** Discovery of `shared`, `plugins/*` and `gateways/*`; `module.php`
  closures over `ModuleContext`; a Discover → Load → Resolve → Register → Boot
  lifecycle replayed by category; dependencies with version constraints,
  optional dependencies and `modules.disabled`.
- **Kernel.** One bootstrap for HTTP and the console; a pure `HttpKernel`; a
  container with autowiring and a write-only `ServiceRegistrar`; a two-tier router
  (hash map, then segment trie); a dispatcher taking three handler forms.
- **Hooks and filters** with deterministic ordering, snapshot iteration, a
  recursion cap and a debug-mode null guard, and ten closed global helpers.
- **Model, schema, data and database** as separate layers: change-tracked models
  with explicit relations and read models; typed schemas that validate,
  serialise and deserialise; repositories and lazy immutable queries over a
  five-method `DataSource`; `ArraySource` and `SqlSource`; `BulkWrites`;
  connections with savepoint-nested transactions.
- **Assets** through four URL namespaces, served from inside the denied module
  tree with traversal, extension and symlink checks, content-hash versioning and
  manifests; an asset request boots no module.
- **Templates**, engine-neutral, with Twig and PHP engines, module namespaces
  and a site override rule. `templates/` ships a Twig layout, a home page for
  `/` and error pages for 404 and every other status.
- **REST** conventions on the same kernel: content negotiation with quality
  values, one JSON error document, `ApiResponse`, and prefix versioning whose
  route metadata an application's own `dispatch.response` filter turns into
  `Deprecation` and `Sunset` headers.
- **Console** with declared arguments and options, typed binding, meaningful exit
  codes and generated help; 35 framework commands, none of which writes code.
- **Errors** rendered by audience (browser, API, console) with disclosure rules,
  and **logging** as a listener, with redaction, retiring writers and file,
  stream, syslog and database destinations.
- **Configuration** from defaults, `config/*.php` and the environment, with a
  cache that notices environment changes; a **cache** with array, file,
  database and null stores.
- **Queue and worker** with sync, memory, file and database stores, retries with capped
  exponential backoff and a failed list; a **scheduler** driven by one cron line,
  with overlap locks.
- **Security**: opt-out CSRF with signed tokens, rate limiting, request size
  limits, upload policies, security headers, `Secret`, `security:check`.
- **Sessions** without `$_SESSION`: lazy, change-logged commits, regeneration
  with a grace window, file, database and memory stores.
- **Authentication and authorization**: a two-lookup `UserProvider`, session and
  bearer-token authentication, capabilities and roles as data, and a decision
  filter that may refuse but never grant.
- **Performance**: `cache:warm` as the production boot path, and `composer bench`
  covering every subject in specification §50.
- **Observability**: request and correlation ids across the queue, log
  enrichment, an opt-in profiler reporting to the log and `Server-Timing`, and
  slow-query warnings.
- **Documentation**: `docs/` — a getting-started tutorial, task guides for
  application developers, a reference page per subsystem, operations and
  troubleshooting pages, and a contributors' guide — plus `STABILITY.md`,
  `UPGRADING.md` and this file.
- **`nginx:make`** writes an nginx server block to `nginx.conf`, rooted at
  `public/`.
- **System operations** (`engine/System`): structured command execution with no
  shell, explicit bash scripts, background processes, owned crontab blocks,
  systemd services, a policy-bound server filesystem, permissions, and system
  information. Deny by default throughout: command allowlists, service and
  filesystem policies, `system.*` capabilities, a concurrency limit and an HTTP
  timeout cap. Every change and refusal is audited to the `audit` log channel.
  Configured under `system.*`; six `system:*` console commands. See
  [System operations](docs/reference/system.md).
- **MCP** (`engine/MCP`): Model Context Protocol tools, resources and prompts
  that modules declare in `module.php` with `$module->mcp()`, served over STDIO
  (`mcp:stdio --user=`) and, when `mcp.transports.http` is on, over HTTP with
  bearer tokens only. Permissions are auth capabilities, and a refused
  capability is indistinguishable from a missing one. Tool input is checked
  against a strict JSON Schema subset that fails closed. Results are plain data
  built explicitly. `mcp.*` hooks and filters never run ahead of the security
  checks. The `mcp` log channel records calls without their payloads.
  `mcp:list` shows what is exposed. Configured in `config/mcp.php`, where
  everything is opt-in except STDIO, which opens nothing. See
  [MCP](docs/reference/mcp.md).
- **A dialect per database.** `MySqlGrammar`, `PostgresGrammar`,
  `SqliteGrammar` and `SqlServerGrammar` each override only where their database
  differs — quoting, paging, savepoints, the placeholder limit — and any other
  driver gets standard SQL. `Connection::supports(Capability::Savepoints)` says
  what a database can do. Only the savepoint capability exists so far; each
  later feature adds its own. The dialect tests run on every database a test run
  can reach: SQLite always, and MySQL, PostgreSQL or SQL Server when
  `DB_TEST_*_DSN` names one. CI starts all three.
- **A SQL query builder.** `$connection->table('invoices')` returns an immutable
  `QueryBuilder` with `select()`, `where()`/`orWhere()` (including groups in a
  closure), `whereNull()`, `whereIn()`, `whereBetween()` and their negations,
  `orderBy()`, `limit()` and `offset()`. It runs nothing until `get()`,
  `first()`, `cursor()`, `count()` or `exists()`. Values are always bound. Names
  such as `orders.customer_id` or `total AS amount` are checked part by part.
  Operators and directions come from a fixed list. `RawExpression` is the one
  place hand-written SQL goes, with its own bindings. `Data\Query` is
  unchanged: AND only and no joins, the same on every `DataSource`.
- **Writes through the query builder.** `insert($row, $key)` returns the
  generated key; `insertMany()` counts what it wrote; `update()` and `delete()`
  refuse to run without a condition (`updateAll()` and `deleteAll()` say "every
  row" explicitly); `upsert()` where the database has one. A write refuses order,
  limit, offset and aliases, which not every database can honour. Rows of a
  many-row write must name the same columns. `RawExpression` values are
  accepted in single-row writes. New capabilities `Returning` and `Upsert`.
- **Joins, grouping and aggregates in the query builder.** `join()`,
  `leftJoin()` and `rightJoin()` (where `Capability::RightJoin` allows), with ON
  conditions in a `JoinClause`. Also `whereColumn()`, `groupBy()`, `having()` and
  `orHaving()`. `Aggregate` is COUNT, SUM, AVG, MIN or MAX of a checked column:
  selectable, usable in `having()`, and available as the `sum()`, `avg()`,
  `min()` and `max()` terminals. `count()` on a grouped query counts the groups.
- **Transaction isolation levels and retry.** `transaction($callback,
  isolation: IsolationLevel::Serializable, retries: 3)`. Each dialect sets the
  level where its database needs it (before `BEGIN` on MySQL and SQL Server,
  resetting SQL Server's session afterwards; inside the transaction on
  PostgreSQL). A level the database cannot give is refused, never substituted.
  Retries fire only on deadlocks and serialization failures (SQLSTATE 40001 or
  40P01, and MySQL 1213), with a short jittered backoff.
  `Connection::isRetryable()` makes the same judgement. Both options belong to
  the outermost transaction only.
- **Migrations.** Each module keeps its own in `Database/Migrations/`, one file
  per change, named `YYYY_MM_DD_HHMMSS_what_it_does.php` and returning a
  `Migration` (or a `Reversible`, with a `down()`) written with the table
  builder. `migrate` runs what is pending, in module dependency order and then
  file order, as one batch; `--pretend` prints each migration's SQL for this
  database and runs nothing. `migrate:status` lists every migration, and
  `migrate:rollback` undoes the last batch (or `--batches=N`) newest first,
  refusing before it starts if anything in range has no `down()` or no file,
  and asking for `--force` in production. Where the database rolls structure
  back, a migration and its record commit together; MySQL's partial failures
  are reported as such. A lock in the database lets only one run migrate at a
  time, across machines. The tracking table is `database.migrations.table`
  (`migrations`). In a test, `$this->migrate($app)` runs them. The getting-started
  tutorial and the storing-data, testing and users guides now create their tables
  with migrations, and migrations are gone from "What is not built".
- **Seeders.** A module's `Database/Seeders/*.php` files each return a
  `Seeder`, whose `run(Connection $db)` writes through the query builder.
  `db:seed` runs them in module order and then file order, or one module's with
  `--module=<id>`, and asks for `--force` in production. Every seeder is loaded
  before any runs, and each runs in its own transaction. Nothing records that a
  seeder ran, so each should look before it inserts.
- **`migrate` creates the session table.** While `session.store` is
  `database`, the framework's own migration runs before any module's and makes
  the table under `session.table`. A table made earlier by hand is kept and
  recorded. `session:table` and `DatabaseStore::ddl()` are gone. The store now
  writes through the query builder and runs on all four databases, SQL Server
  included; its conformance suite runs on each in CI.
- **A database log writer.** Naming `database` in `logging.writers` writes each
  record as a row: `logged_at` in UTC, the level as its RFC 5424 code and its
  name, the channel, the message and the context as JSON. Its table comes from
  `migrate` (`logging.database.table`, `logs`, on `LOG_CONNECTION`), and
  `retention_days` deletes older rows. On MySQL, PostgreSQL and SQL Server it
  writes through a connection of its own, so an application rolling back does
  not take the record of why with it; on SQLite, with one writer at a time, it
  shares the application's. Tested on all four databases, in CI too.
- **Database cache and queue stores.** `CACHE_STORE=database` keeps entries in a
  table every machine shares, and `QUEUE_STORE=database` keeps jobs in one that
  workers on any number of machines take from. Their tables come from `migrate`,
  like the session table: `cache.table` (`cache`) and `queue.table` (`jobs`), on
  `cache.connection` and `queue.connection`. A job is claimed by a locked read
  and then an `UPDATE` that repeats "not reserved", so two workers never take the
  same one. Both are built on the query builder, pass their conformance suites
  on MySQL, PostgreSQL, SQLite and SQL Server, and run on each in CI.
  `cache:clear --expired` sweeps the database store as it does the file store,
  through the new `PrunableStore` interface.
- **Row locks in the query builder.** `lockForUpdate()` keeps the rows read
  locked until the transaction ends: `FOR UPDATE` on MySQL and PostgreSQL,
  `UPDLOCK` on SQL Server, nothing on SQLite. It is refused outside a transaction
  and on grouped queries.
- **String keys in the table builder.** `->primary()` makes a column the table's
  key, for a table whose key is not a counted `id()`.
- **A table builder.** `$connection->tables()->create('invoices', fn (Table $t) => ...)`
  describes a table once with chained methods (`id`, `integer`, `bigInteger`,
  `decimal`, `string`, `text`, `boolean`, `date`, `dateTime`, `binary`,
  `timestamps`; `nullable`, `default`, `unique`, `index`; foreign keys with
  their actions). Each dialect writes its own DDL, and what some database
  cannot do is refused before anything runs. MySQL tables are InnoDB and
  utf8mb4, and SQL Server's unique indexes admit many NULLs, as elsewhere.
  `drop()`, and `raw()` for hand-written SQL scoped to named drivers. New
  capability: `TransactionalDdl`.
  `alter()` changes a table that exists: it adds columns, indexes and foreign
  keys, and drops or renames columns and drops indexes and keys by the names
  the builder gave them, in a fixed order (drops, then renames, then additions).
  A column added to a table with rows needs `nullable()` or a default. What
  SQLite's `ALTER TABLE` cannot do (dropping a foreign key, or adding one to a
  column that is already there) is refused with nothing run, never done by
  rebuilding the table. Renaming a column needs MySQL 8.0 or MariaDB 10.5.2;
  on an older server the rename is refused with nothing run, not left to fail
  as a syntax error.
- **`database.*` hooks.** An application now fires
  `database.query.failed`, `database.transaction.committed`,
  `database.transaction.rolled_back` (with its cause) and
  `database.transaction.retrying` (with the attempt about to run). The database
  layer announces these through `Connection::listen()` and
  `ConnectionManager::listen()`, and the bootstrap turns them into hooks, so the
  layer still works without the application. Hooks fire once the event has
  happened: a listener that throws cannot undo a commit, trigger a retry, or
  replace a database failure.
- **The statement observer counts.** `observe()` callbacks also receive how many
  values were bound and how many rows came back or changed, never the values.
  Observers written for three arguments still work. The slow-query warning logs
  both counts.
- **A database connection may be configured by its parts.** In
  `config/database.php`, `driver`, `host`, `port`, `database` and `charset` are
  assembled into the DSN for mysql, pgsql, sqlite and sqlsrv; a `dsn` still wins
  when given, and is required for any other driver. A part containing `;` (or,
  for a host, `,`) is refused rather than escaped.

### Changed

- **`templates/` is the site's views, with no switchable themes.** A name is a
  path under it: `render('customer/profile')` is `templates/customer/profile.twig`,
  and a module override is `templates/plugin.<Name>/…`. The site's static files
  are `templates/assets/`, still served as `/assets/template/…`, and are never a
  view. `APP_TEMPLATE` and `templates.active` are gone.

- **The document root is `public/`.** `index.php` and the application's own
  `assets/` moved into it; everything else — `engine/`, `modules/`, `config/`,
  `vendor/`, `.env` — is out of reach of any URL, so the lists of directories and
  files that `.htaccess`, the development router and `nginx:make` used to deny
  are gone. Where the document root cannot be changed, the project's `.htaccess`
  forwards every request into `public/`, and URLs keep their old form.
  `composer serve` runs `php -S 127.0.0.1:8080 -t public server`, and
  `security:check` fails if `public/` holds PHP besides `index.php`.
- **The console is `laika` at the project root**, not `bin/console`: run
  `php laika <command>`. Every message, help screen and generated cron line
  names the new path.
- **Paths in any language route reliably.** The request path and every declared
  route path are normalised to NFC, so both spellings of `é` or Bengali `য়`
  reach the same route; route constraints are matched as UTF-8, so `\p{L}` means
  a letter in any script; `url()` percent-encodes fixed segments outside ASCII as
  it already did parameters. `ext-intl` is now required.
- **PHP 8.2 or newer is required.** Support for 8.1, which reached end of life
  in December 2025, is dropped.
- **Module directories are `modules/Shared`, `modules/Plugins` and
  `modules/Gateways`**, autoloaded through one PSR-4 root, `App\Modules\` →
  `modules/`. Directory names now match their namespace segment, which Linux
  requires.
- **The shipped `shared` module is the front page and nothing else.** It
  answers `/` and binds `DataSource`. The demo accounts (`ada` and `grace`,
  password `secret`, and a fixed API token that logged in as an administrator),
  `POST /login`, `POST /logout`, `GET /me`, `GET /users`, the `member` and
  `administrator` roles, the `X-Engine` and API version headers, and the
  `money.php` template are gone. A fresh installation has no accounts: nobody
  can log in until an application binds its own `UserProvider`. The demo lives
  on in `tests/Fixtures/Showcase/Shared`, where the tests use it.
- **`composer stan` analyses `modules/`**, at the same level 8 as `engine/` and
  `tests/`. It never did, so an application's own modules were not
  type-checked by the gate at all.
- **A statement's observed time includes reading its rows.** For `select()`,
  `selectOne()` and `scalar()`, the observer and the slow-query warning now
  time until the rows are fetched, not only until the statement ran. A cursor
  is still timed until it runs.
- **Any path may be a route**, including `/templates` or `/config/app`: with the
  document root at `public/`, no path names a file outside it, and a real file
  answers exactly like a missing one.

### Fixed

- **Binary data could not be written on SQL Server.** A stream was sent as
  text, and SQL Server refuses text in a `VARBINARY` column. It is now bound
  with the driver's binary encoding, and comes back byte for byte, as on the
  other three databases.
- **A new visitor's first form was refused as a CSRF mismatch.** With no
  `XSRF-TOKEN` cookie yet, every call to `Csrf::token()` signed a new token, so
  a handler that put one in its form and the guard that set the cookie handed
  the browser two different ones. `token()` now issues one token per request,
  which the form and the cookie share, and forgets it when the next request
  begins. After a login, a page rendered in the same response carries the
  rotated token that its cookie does.
- **Inserts on PostgreSQL could report another table's key, or abort the
  transaction.** PDO's last-insert id there is `LASTVAL()`: the last value of
  whichever sequence the session used. So it was stale after an insert into a
  table without a sequence, and inside a transaction that had used none, it
  raised an error that aborted the whole transaction. Repositories on
  PostgreSQL were affected. The generated key now comes back from the INSERT
  itself (`RETURNING`, and `OUTPUT INSERTED` on SQL Server).
  `Connection::insert()` never asks PostgreSQL for `LASTVAL()`: without
  `RETURNING` it returns null.
- **SQL Server queries with a limit or offset failed.** The grammar wrote
  `LIMIT`, which SQL Server does not have; it now writes `OFFSET … FETCH`, with
  `ORDER BY (SELECT NULL)` when the query has no order of its own.
- **Nested transactions failed on SQL Server**, which saves a transaction rather
  than setting a savepoint and has no release. Savepoint statements are now the
  grammar's, in each driver's dialect. Oracle is no longer claimed to support
  them; its savepoints have no release either, and none are written for it.
- **A failing rollback replaced the exception that caused it.** The callback's
  exception now always propagates from `transaction()`. A failed rollback closes
  the connection, so the database discards what was not committed. Any outer
  level still open is refused every statement and commit until it too has
  rolled back, where before it could go on writing in autocommit mode.
- **`transaction()` committed the wrong level after an unbalanced callback.** A
  callback that called `begin()` without finishing it, or finished a transaction
  it had not begun, is now rolled back and reported.
- **`disconnect()` inside a transaction discarded it silently.** It still
  closes, and then throws, naming the connection and the depth.
  `disconnectAll()` closes every connection before reporting.
- **Floats lost digits and dates could not be bound.** A float was written at
  PHP's `precision`, so `0.1 + 0.2` was stored as `0.3`; it is now bound with the
  digits it needs to read back unchanged, under any locale. `DateTimeInterface`
  values bind as `Y-m-d H:i:s[.u]`, stream resources as binary large objects,
  and anything else unbindable is refused by position and type, never by value.
- **`composer serve` routed some paths to the home page.** For a path naming a
  real directory (`/templates`) or looking like a file (`/customers.json`), PHP's
  built-in server reported the path as the script name, so the whole path became
  the base path. The development router now sets `SCRIPT_NAME` to `/index.php`.

### Decided against the specification

- **Twig is a required dependency and the default engine**, where §25 and
  invariant 14 call it optional. The project owner's decision: the default
  template's pages are Twig. PHP templates still render through the same
  manager, and the manager itself does not depend on Twig.
- **`index.php` and the application's `assets/` live in `public/`**, where the
  specification's layout puts them at the project root. The project owner's
  decision: a document root holding nothing but the front controller cannot
  expose source through a forgotten deny rule, and a server that cannot change its
  document root still works through the forwarding `.htaccess`.

[Unreleased]: https://github.com/laikait/lphp/compare/v2.1.2...main
[2.1.2]: https://github.com/laikait/lphp/releases/tag/v2.1.2
