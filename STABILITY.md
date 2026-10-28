# API stability

What an application or a module may build on, and what it may not. Specification
§53 asks for every API to be marked **Experimental**, **Internal**, **Stable** or
**Deprecated**. This file is that marking.

`tests/Architecture/DocumentationTest.php` holds it to the code: every class
under `engine/` must resolve to exactly one level below, every row must still
match something, a Deprecated row must name its replacement and its removal
version, and no shipped module — nor
the showcase modules in `tests/Fixtures/Showcase/` — may use an Internal class.

## The four levels

| Level | Meaning | May change in |
|---|---|---|
| **Stable** | The contract. Changing it takes a deprecation first and a major version. | a major release only |
| **Experimental** | Public and meant to be used, but new: not yet promised. Nothing has this level today; an addition may start here until it is promoted. | any minor release, always with an entry in [`UPGRADING.md`](UPGRADING.md); never in a patch release |
| **Internal** | Wiring. Public only because PHP has no package visibility. | any release, including a patch, with no notice |
| **Deprecated** | Still works, has a replacement, and names the release that removes it. | removed no earlier than the next major release, after being deprecated in a minor one |

**Everything that was Experimental is Stable as of 3.2.0.** Every class and surface
marked Stable below is a promise: it changes only in a major release, and only
after a deprecation in a minor one. Internal is not promised. A new public API may
be added as Experimental and is promoted by an explicit entry in
[`CHANGELOG.md`](CHANGELOG.md).

**Nothing is Deprecated yet.** When something is, it gets all four of: a
`@deprecated` tag naming the replacement and the removal version, a row here, a
`Deprecated` entry in [`CHANGELOG.md`](CHANGELOG.md), and a note in
`UPGRADING.md`. It does **not** get `trigger_error(E_USER_DEPRECATED)`: the
error handler turns reported PHP errors into exceptions, so a deprecation notice
would break the request it was meant to warn. A runtime signal, where one is
needed, is a log record on a `deprecation` channel.

### What decides the level

- **Stable** — what `module.php`, a handler, a command, a job, a template or
  a store implementation is written against: the types those receive, return,
  implement or catch.
- **Internal** — what only `Bootstrap` constructs or only the engine calls:
  kernels, dispatchers, registries behind a collector, resolvers, the framework's
  own commands, the concrete stores an application selects by *name* in
  configuration rather than by class.

A class that is Internal is not worse code. It is code that must stay free to
change, so that the Stable surface can stay still.

## Surfaces that are not classes

| Surface | Level | Notes |
|---|---|---|
| The `module.php` contract: a closure receiving `ModuleContext` | Stable | |
| The ten global helpers (`add_hook` … `template`) | Stable | the set is closed |
| Hook and filter **names** ([Lifecycle extension points](docs/reference/hooks-and-filters.md#lifecycle-extension-points)) | Stable | a test compares that table with what the engine fires |
| Arguments each hook and filter passes | Stable | adding a trailing argument is not a break; reordering or removing one is |
| Route metadata keys: `auth`, `can`, `csrf`, `rate_limit`, `signed`, `api`, `version`, `deprecated`, `sunset`, `openapi` | Stable | |
| Configuration keys and their defaults (`Bootstrap::defaults()`) | Stable | pinned by `BootstrapTest` |
| Environment variables (`.env.example`) | Stable | a test fails if one is undocumented |
| Console command names, options and exit codes (0, 1, 2, 127) | Stable | scripts and cron lines depend on these |
| The JSON error document: `status`, `title`, `message` first, additions under their own keys | Stable | |
| Response headers: `X-Request-Id`, `X-Correlation-Id`, `X-Api-Version`, `Deprecation`, `Sunset`, `RateLimit-*`, `Retry-After` | Stable | |
| `Server-Timing` and the `profile` log record's shape | Stable | |
| Asset URLs: `/assets/{core,template,module}/…` | Stable | URLs end up in caches and emails |
| Module ids (the folder name under `modules/`; `Shared` is fixed), template namespaces (`@<Name>`) and the default template's names (`layout`, `home`, `errors/404`, `errors/error`) with the data they are given | Stable | |
| Translation files (`lang/<locale>.php`, `lang/countries.php`, a module's `lang/`), key namespaces (`<Module>.`, e.g. `Shared.`, `Billing.`), the `language` cookie and the `local` filter | Stable | |
| Cache files under `system/Cache/` (`config.php`, `modules.php`) | Internal | rebuilt by `cache:warm`; never read across versions |
| **Persisted formats**: a queued job's envelope (`QueuedJob::toArray()`), a session record, a rate-limit counter, `framework.json` (the release manifest an application keeps) | Internal | **with one promise**: a release must read what the previous release wrote, because a deployment does not drain its queue or log everybody out |

## Classes

A row names one class, or a namespace followed by `\*` meaning everything in it
and below it. The most specific row wins: a class beats a namespace, and a longer
namespace beats a shorter one.

| Class or namespace | Level | Notes |
|---|---|---|
| `App\Engine\Asset\*` | Internal | |
| `App\Engine\Asset\AssetException` | Stable | |
| `App\Engine\Asset\AssetKind` | Stable | |
| `App\Engine\Asset\AssetManager` | Stable | `core()`, `template()`, `module()`, `url()` |
| `App\Engine\Asset\AssetReference` | Stable | |
| `App\Engine\Asset\AssetRegistry` | Stable | `register()` for directories that are not modules |
| `App\Engine\Asset\AssetSource` | Stable | |
| `App\Engine\Auth\*` | Internal | |
| `App\Engine\Auth\AccessCollector` | Stable | |
| `App\Engine\Auth\Account` | Stable | |
| `App\Engine\Auth\AuthException` | Stable | |
| `App\Engine\Auth\AuthManager` | Stable | |
| `App\Engine\Auth\Authenticators\TokenAuthenticator` | Stable | `fingerprint()`, which a `TokenProvider` looks tokens up by |
| `App\Engine\Auth\Authorizer` | Stable | its method list is frozen by a test |
| `App\Engine\Auth\Capability` | Stable | |
| `App\Engine\Auth\EmailVerifiable` | Stable | |
| `App\Engine\Auth\EmailVerification` | Stable | |
| `App\Engine\Auth\Identity` | Stable | |
| `App\Engine\Auth\Password` | Stable | |
| `App\Engine\Auth\PasswordReset` | Stable | |
| `App\Engine\Auth\PasswordResettable` | Stable | |
| `App\Engine\Auth\Social\*` | Stable | `Provider` and `SocialAccounts` are the extension points |
| `App\Engine\Auth\TokenProvider` | Stable | |
| `App\Engine\Auth\Totp` | Stable | RFC 6238; checked against its test vectors |
| `App\Engine\Auth\TwoFactor` | Stable | |
| `App\Engine\Auth\TwoFactorAccounts` | Stable | |
| `App\Engine\Auth\TwoFactorEnrolment` | Stable | |
| `App\Engine\Auth\TwoFactorResult` | Stable | |
| `App\Engine\Auth\UserProvider` | Stable | its method list is frozen by a test |
| `App\Engine\Bootstrap\Bootstrap` | Stable | `create()`, `settings()` and `defaults()`, for embedding and tests |
| `App\Engine\Cache\*` | Stable | |
| `App\Engine\Cache\CacheTableMigration` | Internal | run by `migrate` while `cache.store` is `database` |
| `App\Engine\Cache\Stores\*` | Internal | selected by name: `CACHE_STORE=array\|file\|database\|null` |
| `App\Engine\Cli\*` | Internal | |
| `App\Engine\Cli\Command` | Stable | what `CommandCollector::add()` returns |
| `App\Engine\Cli\CommandCollector` | Stable | |
| `App\Engine\Cli\ConsoleException` | Stable | |
| `App\Engine\Cli\Input` | Stable | |
| `App\Engine\Cli\Output` | Stable | |
| `App\Engine\Config\*` | Internal | |
| `App\Engine\Config\Config` | Stable | |
| `App\Engine\Config\ConfigurationException` | Stable | |
| `App\Engine\Config\Env` | Stable | config files call it |
| `App\Engine\Container\*` | Stable | |
| `App\Engine\Core\*` | Stable | `Application` and its execution context, for embedding |
| `App\Engine\Core\HttpKernel` | Internal | |
| `App\Engine\Data\*` | Stable | |
| `App\Engine\Data\Bulk` | Internal | |
| `App\Engine\Data\BulkWrites` | Stable | |
| `App\Engine\Data\Criterion` | Internal | |
| `App\Engine\Data\Cursor` | Internal | what a cursor string holds; the string itself is opaque |
| `App\Engine\Data\Order` | Internal | |
| `App\Engine\Data\Seek` | Internal | built by `cursor()` and `chunk()` |
| `App\Engine\Database\*` | Stable | |
| `App\Engine\Database\ConnectionConfig` | Internal | |
| `App\Engine\Database\Grammar` | Internal | the only place SQL is built; `Grammar::for()` picks the dialect |
| `App\Engine\Database\MySqlGrammar` | Internal | |
| `App\Engine\Database\PostgresGrammar` | Internal | |
| `App\Engine\Database\Query\Condition` | Internal | what the builder records and the grammar writes |
| `App\Engine\Database\Query\QueryState` | Internal | |
| `App\Engine\Database\SqlServerGrammar` | Internal | |
| `App\Engine\Database\SqliteGrammar` | Internal | |
| `App\Engine\Dispatch\*` | Internal | |
| `App\Engine\Dispatch\DispatchException` | Stable | |
| `App\Engine\Error\*` | Stable | |
| `App\Engine\Error\ErrorHandler` | Internal | |
| `App\Engine\Error\ErrorPage` | Internal | an application's page is a template, not a subclass |
| `App\Engine\Feature\*` | Stable | `Features`; see [Feature flags](docs/reference/features.md) |
| `App\Engine\Filter\*` | Stable | |
| `App\Engine\Hook\*` | Stable | |
| `App\Engine\Http\*` | Stable | |
| `App\Engine\Http\Negotiator` | Internal | reached through `Request::negotiate()` |
| `App\Engine\Localization\*` | Stable | |
| `App\Engine\Localization\AcceptLanguage` | Internal | used by `LocaleResolver` |
| `App\Engine\Localization\TranslationLoader` | Internal | used by `Localization` |
| `App\Engine\Logging\*` | Internal | |
| `App\Engine\Logging\Level` | Stable | |
| `App\Engine\Logging\LogRecord` | Stable | what a `LogWriter` receives |
| `App\Engine\Logging\LogTableMigration` | Internal | run by `migrate` while `logging.writers` names `database`; its columns are what a query of the log reads |
| `App\Engine\Logging\LogWriter` | Stable | |
| `App\Engine\Logging\Logger` | Stable | |
| `App\Engine\Logging\LoggingException` | Stable | |
| `App\Engine\Mail\*` | Stable | `Mailer`, `Message`, the transports; a queued `SendMessageJob` (with its `Message`) is a persisted format |
| `App\Engine\Mail\MimeBuilder` | Internal | used by `Mailer` |
| `App\Engine\MCP\*` | Stable | what a tool, resource or prompt implements, returns or throws, and what an `mcp.*` hook receives; see [MCP](docs/reference/mcp.md) |
| `App\Engine\MCP\McpAuthorizer` | Internal | a permission is an auth capability |
| `App\Engine\MCP\McpConfig` | Internal | built by `Bootstrap` from `mcp.*` |
| `App\Engine\MCP\McpRegistry` | Internal | declare with `$module->mcp()`, read with `mcp:list` |
| `App\Engine\MCP\McpServer` | Stable | `handle()`, for tests |
| `App\Engine\MCP\PlainData` | Internal | |
| `App\Engine\MCP\Prompt\PromptProvider` | Internal | |
| `App\Engine\MCP\Protocol\*` | Internal | |
| `App\Engine\MCP\Protocol\Response` | Stable | what `mcp.response.created` passes |
| `App\Engine\MCP\Resource\ResourceReader` | Internal | |
| `App\Engine\MCP\Tool\ToolRunner` | Internal | |
| `App\Engine\MCP\Transport\*` | Internal | selected by `mcp.transports` |
| `App\Engine\Migration\*` | Stable | what a migration or seeder file implements (`Migration`, `Reversible`, `Seeder`) and what a failed run throws |
| `App\Engine\Migration\MigrationFile` | Internal | the name rule is documented; the class is not |
| `App\Engine\Migration\MigrationRepository` | Internal | the tracking table's layout is the runner's |
| `App\Engine\Migration\Migrator` | Internal | run through `migrate`, `migrate:status` and `migrate:rollback` |
| `App\Engine\Migration\SeederFile` | Internal | the name rule is documented; the class is not |
| `App\Engine\Migration\SeedRunner` | Internal | run through `db:seed` |
| `App\Engine\MCP\Validation\*` | Internal | the schema subset is documented; the validator is not |
| `App\Engine\Model\*` | Stable | |
| `App\Engine\Model\Attributes` | Internal | |
| `App\Engine\Module\*` | Internal | |
| `App\Engine\Module\ModuleContext` | Stable | |
| `App\Engine\Module\ModuleException` | Stable | |
| `App\Engine\Module\ModuleKind` | Stable | |
| `App\Engine\Module\ModuleRegistry` | Stable | `isEnabled()` and friends, from `onBoot` |
| `App\Engine\Network\*` | Stable | addresses, CIDR blocks and masks; see [IP addresses](docs/reference/network.md) |
| `App\Engine\Network\Bytes` | Internal | byte arithmetic behind `IpAddress` and `Cidr` |
| `App\Engine\Observability\*` | Stable | |
| `App\Engine\Observability\Report` | Internal | |
| `App\Engine\OpenApi\*` | Stable | `OpenApiGenerator::generate()` and `json()`, and what a meta mistake throws; see [OpenAPI](docs/reference/openapi.md) |
| `App\Engine\OpenApi\OpenApiDocs` | Internal | registered by `openapi.docs`; the bundled Swagger UI version may change in any release |
| `App\Engine\OpenApi\OpenApiEndpoint` | Internal | registered by `openapi.route` |
| `App\Engine\OpenApi\OpenApiRoutes` | Internal | |
| `App\Engine\OpenApi\OpenApiState` | Internal | written by `openapi:docs` and `openapi:auth`; the file's layout is theirs |
| `App\Engine\OpenApi\SchemaConverter` | Internal | the mapping is documented; the class is not |
| `App\Engine\Queue\*` | Internal | |
| `App\Engine\Queue\Job` | Stable | |
| `App\Engine\Queue\Queue` | Stable | |
| `App\Engine\Queue\QueueException` | Stable | |
| `App\Engine\Queue\QueueStore` | Stable | a conformance suite defines it |
| `App\Engine\Queue\QueuedJob` | Stable | a persisted format — see above |
| `App\Engine\Queue\QueueTableMigration` | Internal | run by `migrate` while `queue.store` is `database`; its columns are a persisted format too |
| `App\Engine\Routing\*` | Stable | |
| `App\Engine\Routing\MatchStatus` | Internal | |
| `App\Engine\Routing\RouteMatch` | Internal | |
| `App\Engine\Scheduler\*` | Internal | |
| `App\Engine\Scheduler\Schedule` | Stable | what `ScheduleCollector` returns |
| `App\Engine\Scheduler\ScheduleCollector` | Stable | |
| `App\Engine\Scheduler\ScheduleLock` | Stable | a conformance suite defines it |
| `App\Engine\Scheduler\ScheduleOutcome` | Stable | |
| `App\Engine\Scheduler\ScheduleResult` | Stable | what `schedule.finished` passes |
| `App\Engine\Scheduler\SchedulerException` | Stable | |
| `App\Engine\Schema\*` | Stable | |
| `App\Engine\Security\*` | Internal | |
| `App\Engine\Security\CounterStore` | Stable | a conformance suite defines it |
| `App\Engine\Security\Csrf` | Stable | `token()`, for forms |
| `App\Engine\Security\Encrypter` | Stable | `encrypt()`, `decrypt()`; the `v1.` token format is persisted, so a release must decrypt what the previous one wrote |
| `App\Engine\Security\RateLimit` | Stable | |
| `App\Engine\Security\Secret` | Stable | |
| `App\Engine\Security\SecurityException` | Stable | |
| `App\Engine\Security\SignedUrl` | Stable | `sign()`, `verify()`; `Routing\UrlSigner` for named routes |
| `App\Engine\Security\Signer` | Stable | `hmac()` is for a protocol's own key (S3, webhooks); `sign()` for this application's |
| `App\Engine\Security\UploadPolicy` | Stable | |
| `App\Engine\Security\Webhook` | Stable | |
| `App\Engine\Security\Webhooks` | Stable | `receive()`, `release()`; the formats are configuration |
| `App\Engine\Session\*` | Stable | |
| `App\Engine\Session\SessionId` | Internal | |
| `App\Engine\Session\SessionManager` | Internal | |
| `App\Engine\Session\SessionTableMigration` | Internal | run by `migrate` while `session.store` is `database` |
| `App\Engine\Session\Stores\*` | Internal | selected by name: `SESSION_STORE=file\|database\|memory` |
| `App\Engine\Storage\*` | Stable | `Disk` is defined by its conformance suite; see [Storage](docs/reference/storage.md) |
| `App\Engine\Storage\Contents` | Internal | |
| `App\Engine\Storage\StoragePath` | Internal | the path rule every disk applies |
| `App\Engine\Support\*` | Internal | |
| `App\Engine\Support\QrCode` | Stable | `svg()`, `encode()`, `matrix()` |
| `App\Engine\System\*` | Stable | see [System operations](docs/reference/system.md) |
| `App\Engine\System\Command\CommandSlot` | Internal | held by the executor and a `Process` |
| `App\Engine\System\Command\Invocation` | Internal | |
| `App\Engine\System\SystemConfig` | Internal | built by `Bootstrap` from `system.*` |
| `App\Engine\Template\*` | Stable | |
| `App\Engine\Template\PhpTemplateEngine` | Internal | registered by `Bootstrap` |
| `App\Engine\Template\TemplateRegistry` | Internal | |
| `App\Engine\Template\TemplateSource` | Internal | |
| `App\Engine\Template\TwigTemplateEngine` | Internal | registered by `Bootstrap` |
| `App\Engine\Update\*` | Internal | driven only through `framework:update`, `framework:rollback` and `framework:manifest` |
