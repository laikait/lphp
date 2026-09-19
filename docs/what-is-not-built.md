# What is not built

Deliberate omissions, each with the reason, so that nobody mistakes one for an
oversight — or builds one without reading why it was left out.

## Deferred

| Not built | Why |
|---|---|
| **The demo application** (Phase 29, §55: `Customer`, `Billing`, `DemoGateway`) | Deferred by the project owner. `tests/Fixtures/Showcase/` exercises nearly all of §55 through every subsystem, but it is a test fixture, not an application a reader can install, and a second plugin *depending on* the first — the part that would make module dependencies visible — does not exist |
| **Database and remote log writers** | A database writer can now bring its table as a migration, but nothing has needed one yet, and a log kept in the database that is failing loses the records about the failure. A remote one needs an HTTP client. `LogWriter` is three methods |
| **APCu, Redis and Memcached cache stores; a Redis queue store** | None of the extensions is installed where this was developed, so every line would be unverified, and the hard parts — clearing a namespace, claiming a job atomically — cannot be written blind. Each store type has a conformance suite, which is what makes adding one safe |
| **The asset and template hooks the specification lists as future** (§42: `asset.registered`, `asset.resolved`, `asset.served`; filters `asset.url`, `asset.version`, `asset.mime`, `asset.cache_control`, `template.path`) | Nothing needs them yet, and an asset request now loads no module, so a module could not listen to most of them anyway. `module.loaded` and `api.response` from the same list exist as `module.registered` / `module.booted` and `dispatch.response` |
| **CSRF tokens bound to one session** | Tokens are bound to a browser and rotate when the session id does, including at login. Binding them to a session is a change to how tokens are signed and verified, and has not been made |
| **Byte ranges for assets** | Responses say `Accept-Ranges: none`. Large media is the web server's or a CDN's job, not PHP's |
| **MCP beyond request and response**: server-sent event streams, `Mcp-Session-Id` sessions, `list_changed` and progress notifications, cancellation, resource subscriptions, completions, sampling and elicitation | Every one of these needs a connection that outlives a request, or a server that talks first. Neither fits a PHP process that answers and ends, and no module has needed them. HTTP is stateless and answers GET with 405, which the specification allows. See [MCP](reference/mcp.md#http) |
| **An MCP production manifest** | Measured and declined, like route caching: capabilities are declared in `module.php`, and registering a hundred takes about 0.15 ms. See [MCP](reference/mcp.md#in-production) |
| **Route caching and dependency-resolution caching** (§38) | Measured and declined: 0.87 ms to compile 500 routes once per process, 141 µs to resolve 50 modules. See [What is deliberately not cached](reference/performance.md#what-is-deliberately-not-cached) |

## Declined by design

| Not built | Why |
|---|---|
| Facades, service providers, middleware, gates and policies, form requests, route-model binding, an Eloquent, Blade or Artisan clone, `make:*` generators | §54. Each has an architecture test |
| A debug dashboard | §52: instrumentation first. Observability writes to the log and headers only, and a test holds it there |
| A catch-all route parameter (`{path:.*}`) | It breaks the trie's determinism, and nothing has needed it |
| `OR` and joins in `Query` | A boolean tree turns a query builder into a query language; a join cannot be honoured by every `DataSource`. Both belong in a named repository method over SQL, where `Connection::table()` offers them — see [The query builder](reference/database.md#the-query-builder) |
| `ModelQuery` (the §14 list) | A query object with nothing to query. `Data\Query` is the query API §21 specifies |
| PSR-3 `LoggerInterface` on `Logger` | `log()` takes a `Level` enum; a twelve-line adapter is in [Logging](reference/logging.md) |
| RFC 9457 `problem+json` | See [One error shape](reference/rest.md#one-error-shape) |
| A Content-Security-Policy by default; HSTS by default | A generic CSP is too loose or breaks the first page; HSTS cannot be taken back. Both are reported by `security:check` |
| Sub-minute schedules, signal handling in the worker, a resident HTTP worker | No resident process and no `pcntl` on Windows; bounded worker runs and reservation expiry give correctness without them |
| HTTP Basic authentication | It makes browsers show a dialog the application cannot style, cancel or explain |
| A runtime deprecation notice | See [API stability](contributing/releases.md) |

## Where this departs from the specification

- **Twig is required and is the default engine.** §25 and invariant 14 say Twig
  must remain optional. The project owner decided otherwise, so the default
  template can ship Twig pages. What survives of the invariant: the manager speaks
  only to `TemplateEngine`, nothing outside `TwigTemplateEngine` mentions Twig,
  and PHP templates render through the same manager.
- **The unnamed template asset namespace is the active template's**, not a shared
  `templates/assets/`. See [Assets](reference/assets.md).
- **No commits per phase** (§56 step 16) — that is the project owner's step.
