# Upgrading

What to change in an application when moving between versions, newest first.
Every breaking change listed in [`CHANGELOG.md`](CHANGELOG.md) has a section
here; what counts as breaking is set by [`STABILITY.md`](STABILITY.md).
Internal classes can change without an entry. The one exception is a persisted
format — a queued job, a session record — which always gets one.

Each entry says **what changed**, **who is affected** and **what to do**, in that
order. An entry that cannot say who is affected is not finished.

## To 2.1.2, from the tree committed as "Phase 23-25"

2.1.2 is the first release, so there is no earlier release to upgrade from.
These notes are for code written against the repository as it stood at that
commit.

### templates/ is the site's views; no more APP_TEMPLATE

- **Changed:** views are found in `templates/` itself, not in
  `templates/<name>/views/`. `render('customer/profile')` is
  `templates/customer/profile.twig`. The site's static files are
  `templates/assets/`, still served as `/assets/template/…`.
  `APP_TEMPLATE` and `templates.active` are gone, and so is
  `asset()->template('<name>', …)` reaching `templates/<name>/assets/`.
- **Affected:** every application with its own pages in `templates/default/`
  or another template directory; a module override in
  `templates/<name>/views/plugin.<Name>/`.
- **Do:** move everything in `templates/<name>/views/` up into `templates/`, and
  `templates/<name>/assets/` to `templates/assets/`. Delete `templates/<name>/`
  and `APP_TEMPLATE` from `.env`. Names in `render()` and in Twig's
  `extends` and `include` do not change. Keep views out of `templates/assets/`: a name
  starting `assets/` is refused.

### PHP 8.2 is the minimum

- **Changed:** `composer.json` requires `php` `^8.2`; it was `^8.1`.
- **Affected:** a host running PHP 8.1. Composer refuses to install there, and
  the development server fails on every request.
- **Do:** upgrade the host to PHP 8.2 or newer before upgrading the framework.

### The document root is public/

- **Changed:** `index.php` is `public/index.php`, and the application's own
  assets moved from `assets/` to `public/assets/`. The root `.htaccess` now only
  forwards into `public/`; the front-controller rules are in `public/.htaccess`.
  `composer serve` runs `php -S 127.0.0.1:8080 -t public server`. `nginx:make`
  writes `root <app>/public`.
- **Affected:** every deployment. A virtual host whose `DocumentRoot` is the
  project directory keeps working only while `.htaccess` is read; a custom
  `nginx.conf`; anything that runs `php -S` by hand; files an application added to
  `assets/`; a custom `.htaccess` rule.
- **Do:** point `DocumentRoot` (or nginx `root`) at `public/` and regenerate
  `nginx.conf` with `php laika nginx:make --force`. Move your own files from
  `assets/` to `public/assets/` — `asset()->core()` URLs do not change. Move any
  custom rewrite rule into `public/.htaccess`. Then run the curl checks in
  [Deployment and security](docs/operations/deployment.md).

### The console is `laika`, at the project root

- **Changed:** `bin/console` is now `laika`, next to `composer.json`.
- **Affected:** every crontab line, systemd unit, supervisor program, deploy
  script or CI step that runs `php bin/console`.
- **Do:** replace `php bin/console` with `php laika` in each of them.
  `composer console` is unchanged.

### ext-intl is required, and route constraints are UTF-8

- **Changed:** `composer.json` requires `ext-intl`; request and route paths are
  normalised to NFC with it. Route constraints are now matched with the `u`
  modifier, and `url()` percent-encodes non-ASCII bytes in fixed segments.
- **Affected:** a host without intl — Composer refuses to install there, and XAMPP
  ships it switched off. A constraint that relied on matching bytes rather than
  characters, such as `.{6}` meant as six bytes, or one containing raw non-UTF-8
  bytes. A test that compared a generated URL containing a raw non-ASCII fixed
  segment.
- **Do:** enable intl (`extension=intl` in `php.ini`, or `apt install
  php8.3-intl`). Write constraints in characters; add `\p{M}` to letter classes
  for scripts with combining vowel signs. Compare URLs in their encoded form.

### Module directories are capitalised

- **Changed:** `modules/shared`, `modules/plugins` and `modules/gateways` are
  `modules/Shared`, `modules/Plugins` and `modules/Gateways`, the defaults of
  `modules.paths` say so, and `composer.json` autoloads all three through one
  root, `App\Modules\` → `modules/`. Module ids (`shared`, `plugins/Billing`),
  template and asset namespaces are unchanged.
- **Affected:** every application with a module, and any `config/modules.php`
  that sets `paths`. On Linux, classes in a lowercase directory are no longer
  found.
- **Do:** rename the directories. On a case-insensitive filesystem (Windows,
  macOS) take two steps, or git records nothing:
  `git mv modules/plugins modules/_Plugins && git mv modules/_Plugins modules/Plugins`.
  Update `paths` if you set it, copy the new `autoload` block into
  `composer.json`, and run `composer dump-autoload`.

### A fresh install ships no demo modules

- **Changed:** `modules/plugins/Example` and `modules/gateways/Example` are no
  longer in `modules/`. They live in `tests/Fixtures/Showcase/`, under
  `App\Tests\Fixtures\Showcase\…`, and `config/plugins/Example.php` went with
  them. The routes they answered (`/customers`, `/api/v1/customers`, `/visits`,
  `/ping`) and the `customer:*` commands are gone from a fresh install.
- **Affected:** anything that called those routes or commands, or imported
  `App\Modules\Plugins\Example\…` or `App\Modules\Gateways\Example\…`.
- **Do:** treat them as a reference to copy, not a dependency. Copying one back
  into `modules/` means renaming its namespace to match its new directory.

### `/` is answered by the shared module

- **Changed:** `GET /` renders the default template's `home` page, declared by
  `modules/Shared` under the route name `home`.
- **Affected:** a module that declares `/` **and** names that route `home` —
  route names are unique, so boot stops with a duplicate-name error.
- **Do:** rename your route. Your `/` still wins: every other module registers
  after `shared`, and the router keeps the last route declared for a path.

### `composer stan` checks your modules

- **Changed:** `phpstan.neon` analyses `modules/` at level 8, alongside
  `engine/` and `tests/`.
- **Affected:** an application whose modules have type errors. `composer stan`
  and `composer check` now report them, and fail, where they passed before.
- **Do:** fix what is reported. There is no baseline to hide it in, by design.
  To defer it, remove `modules` from `paths` in `phpstan.neon` until you can.

### The shared module ships no accounts, login routes or headers

- **Changed:** `modules/Shared` is now one file. It answers `/` and binds
  `DataSource`, and nothing else. Removed: `AccountProvider` (the `ada` and
  `grace` accounts and the fixed API token), `SessionEndpoints` with
  `POST /login`, `POST /logout` and `GET /me`, `GET /users`, the `User` model,
  `UserRepository`, `PaginationSchema`, the `user.list` and `user.impersonate`
  capabilities, the `member` and `administrator` roles, the `X-Engine` and API
  version headers (`ResponseFilters`, `ApiFilters`), the `money.php` template and
  the `shared.currency` and `shared.locale` settings.
- **Affected:** an application still logging in with the demo accounts or
  calling those routes; a module that imports `App\Modules\Shared\…` classes or
  requires `user.*` capabilities or those roles; a client or monitor that reads
  `X-Engine`. With no provider bound, nobody can log in and every protected
  route refuses.
- **Do:** bind your own `UserProvider` and write your login routes, as in
  [Users and permissions](docs/guides/users-and-permissions.md). If you kept
  local changes in `modules/Shared`, merge them into the new `module.php`.
  To keep anything removed, copy it from `tests/Fixtures/Showcase/Shared` and
  rename its namespace to match where you put it.

### Twig is required, and wins over PHP

- **Changed:** `twig/twig` moved from `require-dev` to `require`, and the Twig
  engine is registered before the PHP engine. Where one directory holds both
  `page.twig` and `page.php`, the Twig file now renders. The default template's
  `layout`, `errors/404` and `errors/error` are `.twig` files; the `.php`
  versions are gone.
- **Removed:** `TwigTemplateEngine::isAvailable()` and
  `TemplateException::twigIsNotInstalled()`.
- **Changed:** `TemplateManager::extensions()` lists extensions in engine order
  (`html.twig`, `twig`, `php`, `phtml`) rather than longest first.
- **Affected:** an install run with `composer install --no-dev` from an old lock
  file; a theme or module with both a `.twig` and a `.php` file for one name; a
  PHP page that rendered the old `layout.php` with `content`.
- **Do:** run `composer install` so the lock file picks up Twig. Delete whichever
  of a duplicate pair you do not mean. A PHP page can keep passing `content` to
  `layout` — `layout.twig` prints it when no block replaces it.

### `modules.cache` is gone; `cache:warm` replaces it

- **Removed:** the `modules.cache` setting. A request never writes the module
  discovery cache any more.
- **Affected:** a deployment that set `modules.cache` to `true`. The setting is
  now ignored, so that deployment scans module directories on every request.
- **Do:** add `php laika cache:warm` after `cache:clear` in the deployment.
  It refuses to run with `APP_DEBUG` on. Delete an old `system/Cache/modules.php`:
  its format changed, and it would be ignored anyway.

### A module can no longer filter `asset.response`

- **Changed:** declaring `$module->filter('asset.response', …)` stops boot with a
  message naming the module. An asset request loads no module, so such a filter
  could only ever have run in a test.
- **Affected:** a module that put access control or headers on assets.
- **Do:** serve a file that needs a permission check from a route, with
  `meta(['can' => …])`. Headers for every asset belong in the web server's
  configuration or in an engine listener attached at bootstrap.

### Module lifecycle additions

- **Added:** `ModuleContext::requires()` and `optionally()`, the
  `modules.disabled` setting, and a Resolve stage between Load and Register.
  `version()` now accepts only `MAJOR.MINOR.PATCH`.
- **Affected:** a module whose `version()` is `1.0` or `v1.2.3`; a module that
  uses another module's classes without declaring it (an architecture test now
  fails on that); code that called `ModuleRegistry::ids()` and expected disabled
  modules in the list.
- **Do:** write full versions; add `$module->requires('<id>', '^x.y')` where a
  test names the missing declaration; use `ModuleRegistry::installed()` to
  include disabled modules.

### Observability additions

- **Added:** `X-Request-Id` on every response and `X-Correlation-Id` when it
  differs; `trace`, `request_id` and `correlation_id` on every log record; the
  `observability.*` settings with `APP_PROFILE` and `SLOW_QUERY_MS`.
- **Changed, persisted:** a queued job's envelope gained `correlationId`. Jobs
  queued by the older code still load; the field is read as absent.
- **Affected:** a log pipeline that rejects unknown context keys; a proxy that
  already sets `X-Request-Id` and expects it to survive — incoming ids are
  ignored unless `observability.trust_incoming_ids` is on.
- **Do:** allow the three keys, or turn on `trust_incoming_ids` behind a gateway
  that sets the ids.

### Database behaviour that was wrong, now refused or corrected

- **Changed:**
  - `Connection::disconnect()` inside a transaction still closes, then throws.
    `ConnectionManager::disconnectAll()` closes every connection before throwing
    for the first that had one open.
  - On PostgreSQL, `Connection::insert()` returns the key only when the
    statement has a `RETURNING` clause, and null otherwise. It used to return
    `LASTVAL()`, which could be another table's key.
  - A float is bound with every digit it needs to read back unchanged: `0.1 + 0.2`
    is now stored as `0.30000000000000004`, not `0.3`.
  - Nested transactions on Oracle (`oci`) are refused, as on any driver without
    a grammar of its own.
- **Affected:**
  - a worker or script that called `disconnect()` with a transaction still open,
    which lost that work without a word;
  - code on PostgreSQL that calls `Connection::insert()` with hand-written SQL
    and reads the key it returns. Repositories are not affected: their inserts
    now write `RETURNING` themselves;
  - a test that compared a stored float with its fourteen-digit rounding;
  - an application on Oracle that nested `transaction()` calls.
- **Do:**
  - commit or roll back before disconnecting;
  - add `RETURNING id` to the INSERT, or use `$connection->table('t')->insert($row, 'id')`;
  - round deliberately where a rounded value is meant, or store money in a
    DECIMAL column;
  - on Oracle, flatten the nesting into one transaction.

### Database additions

- **Added:** the SQL query builder (`Connection::table()`), a grammar per
  dialect, `Capability`, transaction isolation levels and retries,
  connections configured by parts, and the `database.*` hooks. The statement
  observer also receives the number of bound values and of rows.
- **Affected:**
  - an observer that declares three parameters still works, because PHP drops
    the extra arguments. One that collects them all (`mixed ...$arguments`) now
    receives five;
  - the observer's time now runs until a read's rows have been fetched, so a
    slow-query threshold may catch a statement it missed before.
- **Do:** nothing for most applications. Count on five arguments in a variadic
  observer, and recheck a slow-query threshold that was tuned tightly.

### Migrations and seeders

- **Added:** the table builder (`Connection::tables()`, with `create()`,
  `alter()` and `drop()`), module migrations in `Database/Migrations/`, and
  seeders in `Database/Seeders/`. The commands are `migrate`, `migrate:status`,
  `migrate:rollback` and `db:seed`. `TestCase::migrate()` runs the migrations in
  a test.
- **Affected:**
  - an application that created its tables by hand. Its databases already hold
    tables that no migration recorded, so a migration that creates one of them
    fails with "already exists";
  - an application whose own table is called `migrations`. The runner keeps
    its records in a table of that name.
- **Do:**
  - write a migration for each table a module owns. Where a database may
    already have the table, make it `if (!$tables->exists('notes')) { ... }`,
    so the migration is recorded without failing. Then retire the install
    command or script that made the table;
  - if `migrations` is taken, set `database.migrations.table` in
    `config/database.php`.

### `session:table` is gone; `migrate` creates the session table

- **Removed:** `php laika session:table`, and `DatabaseStore::ddl()`.
- **Added:** while `session.store` is `database`, `migrate` runs the framework's
  own migration, `framework:2026_09_19_000000_create_sessions`, before any
  module's. The store also runs on SQL Server now: its statements go through the
  query builder, and its row lock through the new `lockForUpdate()`.
- **Affected:** a deploy script that ran `session:table`. A sessions table made
  from its statement stays as it is: the migration sees the table and records
  itself without touching it.
- **Do:** replace `session:table` in deploy scripts with `migrate`. When
  `session.connection` names a database other than the default, run
  `migrate --connection=<that connection>` as well.
