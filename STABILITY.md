# API stability

What an application or a module may build on, and what it may not. Specification
§53 asks for every API to be marked **Experimental**, **Internal**, **Stable** or
**Deprecated**, and warns against promising stability too early. This file is
that marking.

`tests/Architecture/DocumentationTest.php` holds it to the code: every class
under `engine/` must resolve to exactly one level below, every row must still
match something, nothing may be Stable before 1.0.0, and no shipped module — nor
the showcase modules in `tests/Fixtures/Showcase/` — may use an Internal class.

## The four levels

| Level | Meaning | May change in |
|---|---|---|
| **Stable** | The contract. Changing it takes a deprecation first and a major version. | a major release only |
| **Experimental** | Public and meant to be used, but not yet promised. | any minor release before 1.0.0, always with an entry in [`UPGRADING.md`](UPGRADING.md); never in a patch release |
| **Internal** | Wiring. Public only because PHP has no package visibility. | any release, including a patch, with no notice |
| **Deprecated** | Still works, has a replacement, and names the release that removes it. | removed no earlier than the next minor (before 1.0) or major (after) |

**Nothing is Stable yet.** The framework has not yet been used by an
application that was not written alongside it — the demo application of Phase 29
is deferred — and a promise made before that is a promise about guesses. The
first release that promotes anything will say so. What is expected to be
promoted is marked *1.0 candidate* in the notes below; that is an intention, not
a guarantee.

**Nothing is Deprecated yet.** When something is, it gets all four of: a
`@deprecated` tag naming the replacement and the removal version, a row here, a
`Deprecated` entry in [`CHANGELOG.md`](CHANGELOG.md), and a note in
`UPGRADING.md`. It does **not** get `trigger_error(E_USER_DEPRECATED)`: the
error handler turns reported PHP errors into exceptions, so a deprecation notice
would break the request it was meant to warn. A runtime signal, where one is
needed, is a log record on a `deprecation` channel.

### What decides the level

- **Experimental** — what `module.php`, a handler, a command, a job, a template or
  a store implementation is written against: the types those receive, return,
  implement or catch.
- **Internal** — what only `Bootstrap` constructs or only the engine calls:
  kernels, dispatchers, registries behind a collector, resolvers, the framework's
  own commands, the concrete stores an application selects by *name* in
  configuration rather than by class.

A class that is Internal is not worse code. It is code that must stay free to
change, so that the Experimental surface can stay still.

## Surfaces that are not classes

| Surface | Level | Notes |
|---|---|---|
| The `module.php` contract: a closure receiving `ModuleContext` | Experimental | 1.0 candidate |
| The ten global helpers (`add_hook` … `template`) | Experimental | 1.0 candidate; the set is closed |
| Hook and filter **names** ([Lifecycle extension points](docs/reference/hooks-and-filters.md#lifecycle-extension-points)) | Experimental | 1.0 candidate; a test compares that table with what the engine fires |
| Arguments each hook and filter passes | Experimental | adding a trailing argument is not a break; reordering or removing one is |
| Route metadata keys: `auth`, `can`, `csrf`, `rate_limit`, `api`, `version`, `deprecated`, `sunset` | Experimental | |
| Configuration keys and their defaults (`Bootstrap::defaults()`) | Experimental | pinned by `BootstrapTest` |
| Environment variables (`.env.example`) | Experimental | a test fails if one is undocumented |
| Console command names, options and exit codes (0, 1, 2, 127) | Experimental | scripts and cron lines depend on these |
| The JSON error document: `status`, `title`, `message` first, additions under their own keys | Experimental | 1.0 candidate |
| Response headers: `X-Request-Id`, `X-Correlation-Id`, `X-Api-Version`, `Deprecation`, `Sunset`, `RateLimit-*`, `Retry-After` | Experimental | |
| `Server-Timing` and the `profile` log record's shape | Experimental | newest; the most likely to change |
| Asset URLs: `/assets/{core,template,module}/…` | Experimental | 1.0 candidate; URLs end up in caches and emails |
| Module ids (the folder name under `modules/`; `Shared` is fixed), template namespaces (`@<Name>`) and the default template's names (`layout`, `home`, `errors/404`, `errors/error`) with the data they are given | Experimental | |
| Translation files (`lang/<locale>.php`, `lang/countries.php`, a module's `lang/`), key namespaces (`<Module>.`, e.g. `Shared.`, `Billing.`), the `language` cookie and the `local` filter | Experimental | |
| Cache files under `system/Cache/` (`config.php`, `modules.php`) | Internal | rebuilt by `cache:warm`; never read across versions |
| **Persisted formats**: a queued job's envelope (`QueuedJob::toArray()`), a session record, a rate-limit counter | Internal | **with one promise**: a release must read what the previous release wrote, because a deployment does not drain its queue or log everybody out |

## Classes

A row names one class, or a namespace followed by `\*` meaning everything in it
and below it. The most specific row wins: a class beats a namespace, and a longer
namespace beats a shorter one.

| Class or namespace | Level | Notes |
|---|---|---|
| `App\Engine\Asset\*` | Internal | |
| `App\Engine\Asset\AssetException` | Experimental | |
| `App\Engine\Asset\AssetKind` | Experimental | |
| `App\Engine\Asset\AssetManager` | Experimental | 1.0 candidate: `core()`, `template()`, `module()`, `url()` |
| `App\Engine\Asset\AssetReference` | Experimental | |
| `App\Engine\Asset\AssetRegistry` | Experimental | `register()` for directories that are not modules |
| `App\Engine\Asset\AssetSource` | Experimental | |
| `App\Engine\Auth\*` | Internal | |
| `App\Engine\Auth\AccessCollector` | Experimental | |
| `App\Engine\Auth\Account` | Experimental | |
| `App\Engine\Auth\AuthException` | Experimental | |
| `App\Engine\Auth\AuthManager` | Experimental | |
| `App\Engine\Auth\Authenticators\TokenAuthenticator` | Experimental | `fingerprint()`, which a `TokenProvider` looks tokens up by |
| `App\Engine\Auth\Authorizer` | Experimental | its method list is frozen by a test |
| `App\Engine\Auth\Capability` | Experimental | |
| `App\Engine\Auth\Identity` | Experimental | |
| `App\Engine\Auth\Password` | Experimental | |
| `App\Engine\Auth\TokenProvider` | Experimental | |
| `App\Engine\Auth\UserProvider` | Experimental | 1.0 candidate; its method list is frozen by a test |
| `App\Engine\Bootstrap\Bootstrap` | Experimental | `create()`, `settings()` and `defaults()`, for embedding and tests |
| `App\Engine\Cache\*` | Experimental | |
| `App\Engine\Cache\CacheTableMigration` | Internal | run by `migrate` while `cache.store` is `database` |
| `App\Engine\Cache\Stores\*` | Internal | selected by name: `CACHE_STORE=array\|file\|database\|null` |
| `App\Engine\Cli\*` | Internal | |
| `App\Engine\Cli\Command` | Experimental | what `CommandCollector::add()` returns |
| `App\Engine\Cli\CommandCollector` | Experimental | |
| `App\Engine\Cli\ConsoleException` | Experimental | |
| `App\Engine\Cli\Input` | Experimental | |
| `App\Engine\Cli\Output` | Experimental | |
| `App\Engine\Config\*` | Internal | |
| `App\Engine\Config\Config` | Experimental | 1.0 candidate |
| `App\Engine\Config\ConfigurationException` | Experimental | |
| `App\Engine\Config\Env` | Experimental | config files call it |
| `App\Engine\Container\*` | Experimental | |
| `App\Engine\Core\*` | Experimental | `Application` and its execution context, for embedding |
| `App\Engine\Core\HttpKernel` | Internal | |
| `App\Engine\Data\*` | Experimental | |
| `App\Engine\Data\Bulk` | Internal | |
| `App\Engine\Data\BulkWrites` | Experimental | new in the first release |
| `App\Engine\Data\Criterion` | Internal | |
| `App\Engine\Data\Cursor` | Internal | what a cursor string holds; the string itself is opaque |
| `App\Engine\Data\Order` | Internal | |
| `App\Engine\Data\Seek` | Internal | built by `cursor()` and `chunk()` |
| `App\Engine\Database\*` | Experimental | |
| `App\Engine\Database\ConnectionConfig` | Internal | |
| `App\Engine\Database\Grammar` | Internal | the only place SQL is built; `Grammar::for()` picks the dialect |
| `App\Engine\Database\MySqlGrammar` | Internal | |
| `App\Engine\Database\PostgresGrammar` | Internal | |
| `App\Engine\Database\Query\Condition` | Internal | what the builder records and the grammar writes |
| `App\Engine\Database\Query\QueryState` | Internal | |
| `App\Engine\Database\SqlServerGrammar` | Internal | |
| `App\Engine\Database\SqliteGrammar` | Internal | |
| `App\Engine\Dispatch\*` | Internal | |
| `App\Engine\Dispatch\DispatchException` | Experimental | |
| `App\Engine\Error\*` | Experimental | |
| `App\Engine\Error\ErrorHandler` | Internal | |
| `App\Engine\Error\ErrorPage` | Internal | an application's page is a template, not a subclass |
| `App\Engine\Filter\*` | Experimental | |
| `App\Engine\Hook\*` | Experimental | |
| `App\Engine\Http\*` | Experimental | |
| `App\Engine\Http\Negotiator` | Internal | reached through `Request::negotiate()` |
| `App\Engine\Localization\*` | Experimental | |
| `App\Engine\Localization\AcceptLanguage` | Internal | used by `LocaleResolver` |
| `App\Engine\Localization\TranslationLoader` | Internal | used by `Localization` |
| `App\Engine\Logging\*` | Internal | |
| `App\Engine\Logging\Level` | Experimental | |
| `App\Engine\Logging\LogRecord` | Experimental | what a `LogWriter` receives |
| `App\Engine\Logging\LogTableMigration` | Internal | run by `migrate` while `logging.writers` names `database`; its columns are what a query of the log reads |
| `App\Engine\Logging\LogWriter` | Experimental | |
| `App\Engine\Logging\Logger` | Experimental | 1.0 candidate |
| `App\Engine\Logging\LoggingException` | Experimental | |
| `App\Engine\MCP\*` | Experimental | what a tool, resource or prompt implements, returns or throws, and what an `mcp.*` hook receives; see [MCP](docs/reference/mcp.md) |
| `App\Engine\MCP\McpAuthorizer` | Internal | a permission is an auth capability |
| `App\Engine\MCP\McpConfig` | Internal | built by `Bootstrap` from `mcp.*` |
| `App\Engine\MCP\McpRegistry` | Internal | declare with `$module->mcp()`, read with `mcp:list` |
| `App\Engine\MCP\McpServer` | Experimental | `handle()`, for tests |
| `App\Engine\MCP\PlainData` | Internal | |
| `App\Engine\MCP\Prompt\PromptProvider` | Internal | |
| `App\Engine\MCP\Protocol\*` | Internal | |
| `App\Engine\MCP\Protocol\Response` | Experimental | what `mcp.response.created` passes |
| `App\Engine\MCP\Resource\ResourceReader` | Internal | |
| `App\Engine\MCP\Tool\ToolRunner` | Internal | |
| `App\Engine\MCP\Transport\*` | Internal | selected by `mcp.transports` |
| `App\Engine\Migration\*` | Experimental | what a migration or seeder file implements (`Migration`, `Reversible`, `Seeder`) and what a failed run throws |
| `App\Engine\Migration\MigrationFile` | Internal | the name rule is documented; the class is not |
| `App\Engine\Migration\MigrationRepository` | Internal | the tracking table's layout is the runner's |
| `App\Engine\Migration\Migrator` | Internal | run through `migrate`, `migrate:status` and `migrate:rollback` |
| `App\Engine\Migration\SeederFile` | Internal | the name rule is documented; the class is not |
| `App\Engine\Migration\SeedRunner` | Internal | run through `db:seed` |
| `App\Engine\MCP\Validation\*` | Internal | the schema subset is documented; the validator is not |
| `App\Engine\Model\*` | Experimental | |
| `App\Engine\Model\Attributes` | Internal | |
| `App\Engine\Module\*` | Internal | |
| `App\Engine\Module\ModuleContext` | Experimental | 1.0 candidate |
| `App\Engine\Module\ModuleException` | Experimental | |
| `App\Engine\Module\ModuleKind` | Experimental | |
| `App\Engine\Module\ModuleRegistry` | Experimental | `isEnabled()` and friends, from `onBoot` |
| `App\Engine\Observability\*` | Experimental | newest; the most likely to change |
| `App\Engine\Observability\Report` | Internal | |
| `App\Engine\Queue\*` | Internal | |
| `App\Engine\Queue\Job` | Experimental | |
| `App\Engine\Queue\Queue` | Experimental | |
| `App\Engine\Queue\QueueException` | Experimental | |
| `App\Engine\Queue\QueueStore` | Experimental | a conformance suite defines it |
| `App\Engine\Queue\QueuedJob` | Experimental | a persisted format — see above |
| `App\Engine\Queue\QueueTableMigration` | Internal | run by `migrate` while `queue.store` is `database`; its columns are a persisted format too |
| `App\Engine\Routing\*` | Experimental | |
| `App\Engine\Routing\MatchStatus` | Internal | |
| `App\Engine\Routing\RouteMatch` | Internal | |
| `App\Engine\Scheduler\*` | Internal | |
| `App\Engine\Scheduler\Schedule` | Experimental | what `ScheduleCollector` returns |
| `App\Engine\Scheduler\ScheduleCollector` | Experimental | |
| `App\Engine\Scheduler\ScheduleLock` | Experimental | a conformance suite defines it |
| `App\Engine\Scheduler\ScheduleOutcome` | Experimental | |
| `App\Engine\Scheduler\ScheduleResult` | Experimental | what `schedule.finished` passes |
| `App\Engine\Scheduler\SchedulerException` | Experimental | |
| `App\Engine\Schema\*` | Experimental | |
| `App\Engine\Security\*` | Internal | |
| `App\Engine\Security\CounterStore` | Experimental | a conformance suite defines it |
| `App\Engine\Security\Csrf` | Experimental | `token()`, for forms |
| `App\Engine\Security\RateLimit` | Experimental | |
| `App\Engine\Security\Secret` | Experimental | |
| `App\Engine\Security\SecurityException` | Experimental | |
| `App\Engine\Security\Signer` | Experimental | |
| `App\Engine\Security\UploadPolicy` | Experimental | |
| `App\Engine\Session\*` | Experimental | |
| `App\Engine\Session\SessionId` | Internal | |
| `App\Engine\Session\SessionManager` | Internal | |
| `App\Engine\Session\SessionTableMigration` | Internal | run by `migrate` while `session.store` is `database` |
| `App\Engine\Session\Stores\*` | Internal | selected by name: `SESSION_STORE=file\|database\|memory` |
| `App\Engine\Support\*` | Internal | |
| `App\Engine\System\*` | Experimental | the newest API here; see [System operations](docs/reference/system.md) |
| `App\Engine\System\Command\CommandSlot` | Internal | held by the executor and a `Process` |
| `App\Engine\System\Command\Invocation` | Internal | |
| `App\Engine\System\SystemConfig` | Internal | built by `Bootstrap` from `system.*` |
| `App\Engine\Template\*` | Experimental | |
| `App\Engine\Template\PhpTemplateEngine` | Internal | registered by `Bootstrap` |
| `App\Engine\Template\TemplateRegistry` | Internal | |
| `App\Engine\Template\TemplateSource` | Internal | |
| `App\Engine\Template\TwigTemplateEngine` | Internal | registered by `Bootstrap` |
