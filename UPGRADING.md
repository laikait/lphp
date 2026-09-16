# Upgrading

What to change in an application when moving between versions, newest first.
Every breaking change listed in [`CHANGELOG.md`](CHANGELOG.md) has a section
here; what counts as breaking is set by [`STABILITY.md`](STABILITY.md).
Internal classes can change without an entry. The one exception is a persisted
format — a queued job, a session record — which always gets one.

Each entry says **what changed**, **who is affected** and **what to do**, in that
order. An entry that cannot say who is affected is not finished.

## To the next release, from the tree committed as "Phase 23-25"

No version has been released yet. These notes are for code written against the
repository as it stood at that commit, and they become the 0.1.0 notes.

### PHP 8.2 is the minimum

- **Changed:** `composer.json` requires `php` `^8.2`; it was `^8.1`.
- **Affected:** a host running PHP 8.1. Composer refuses to install there, and
  the development server fails on every request.
- **Do:** upgrade the host to PHP 8.2 or newer before upgrading the framework.

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
- **Do:** add `php bin/console cache:warm` after `cache:clear` in the deployment.
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
