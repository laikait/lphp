> **Archived.** This is the original plan-mode plan, written before any code
> existed, and it covers **Phases 0-8 only**. It is kept because its reasoning
> is still the reasoning behind the container, the router, the module contract
> and the hook/filter engines -- the sections on *why* a decision was taken are
> the best record of it.
>
> It is **not** a description of the current codebase. Phases 0-25 are done, and
> several things here were superseded on contact with reality:
>
> - `server.php` is now the extensionless `server`
> - `composer.json` requires `^8.1`, not `^8.2`, and the package is `laikait/lphp`
> - `ci.yml` is now `.github/workflows/pcc.yml`, with two jobs
> - "git init but no commits" was overtaken by the user's own commits
> - the deferred list at the end has almost entirely been built since
>
> For the current state see [`HANDOFF.md`](../../HANDOFF.md); for what comes
> next see [`PLAN.md`](../../PLAN.md).

---

# Phases 0–8 — Heavy Backend PHP Framework

## Context

`C:\xampp\htdocs\framework` is completely empty. `new-framework.md` specifies a 30-phase, module-first PHP framework for heavy backend applications (ERP, billing, hosting, SaaS), explicitly *not* a Laravel clone, and §56 forbids implementing it in one pass.

This plan implements **Phases 0–8 only**: repository foundation, bootstrap/kernel, HTTP, DI container, module system, hooks, filters, routing, dispatcher. The outcome is a runnable vertical slice — a real HTTP request served through a route registered by a module, passing through a hook and a filter — proving the architecture before Model/Schema/Data design (Phases 9+) is committed to.

**Environment (verified):** PHP 8.3.33 ZTS, Composer 2.8.3, git 2.55. No `intl`, `opcache`, `xdebug`, `pcov` — so **code coverage cannot be a quality gate**; the gate is `composer check`.

**User decisions (fixed):** root namespace `App\` · greenfield, ignore sibling `laika-*` dirs · `git init` but **no commits** · spec-faithful root `index.php` + hardened `.htaccess` · narrow route-param coercion · all three hook/filter semantics (snapshot, depth cap, null guard).

## Two framing decisions

**A. Build order ≠ phase order.** Phase numbers are a *specification* ordering. Building 1→8 literally means writing a Kernel that dispatches to a Router that doesn't exist. Dependency-correct order: **0 → 3 → 2 → 5/6 → 7 → 8 → 4 → 1**. See §7.

**B. Create only what is used.** Invariant 10 forbids empty directories "for appearance". This pass does **not** create `engine/{Model,Schema,Data,Database,Asset,Template,Logging,Cache,Queue,Scheduler,Security}/`, nor `templates/`, nor `assets/`. It does **not** define the `asset()` / `template()` helpers — a helper with no backing subsystem is a lie. They are reserved in `README.md` and in the architecture test's allow-list, nothing more.

---

## 1. composer.json

```json
{
    "name": "app/framework",
    "description": "Module-first, hook/filter driven backend PHP framework.",
    "type": "project",
    "license": "MIT",
    "require": {
        "php": "^8.2",
        "ext-json": "*",
        "ext-mbstring": "*",
        "psr/container": "^2.0"
    },
    "require-dev": {
        "friendsofphp/php-cs-fixer": "^3.75",
        "phpstan/phpstan": "^2.1",
        "phpunit/phpunit": "^11.5"
    },
    "autoload": {
        "psr-4": {
            "App\\Engine\\": "engine/",
            "App\\Modules\\Shared\\": "modules/shared/",
            "App\\Modules\\Plugins\\": "modules/plugins/",
            "App\\Modules\\Gateways\\": "modules/gateways/"
        },
        "files": ["engine/Support/helpers.php"]
    },
    "autoload-dev": { "psr-4": { "App\\Tests\\": "tests/" } },
    "config": {
        "sort-packages": true,
        "optimize-autoloader": true,
        "platform": { "php": "8.2.0" }
    },
    "scripts": {
        "test": "phpunit",
        "stan": "phpstan analyse --memory-limit=512M",
        "cs": "php-cs-fixer fix --dry-run --diff",
        "cs:fix": "php-cs-fixer fix",
        "check": ["@cs", "@stan", "@test"],
        "serve": "php -S 127.0.0.1:8080 server.php",
        "console": "php bin/console"
    }
}
```

**Why these choices:**
- **Three module PSR-4 roots kill the need for a custom module autoloader.** `App\Modules\Plugins\` → `modules/plugins/` means a user creates `modules/plugins/Billing/Api/Invoices.php` and it autoloads with **zero** `composer.json` edits. `App\` itself stays unmapped — a vendor prefix, not a directory.
- Consequence: directory casing under `modules/` is load-bearing on Linux. Windows will never catch a mistake — **the Linux CI job is the enforcement mechanism**, which is what justifies `ci.yml` in Phase 0.
- Consequence: document in README that production may use `composer dump-autoload --optimize` but **never `--classmap-authoritative`**, which disables the PSR-4 fallback and breaks modules added after the dump.
- **`psr/container` is the only external dependency** — two interfaces, zero implementation, real interop. `psr/log` deliberately excluded (logging is Phase 18).
- **PHPUnit ^11.5, not 12** — PHPUnit 12 needs PHP ≥8.3, which would make the declared `^8.2` a lie.
- **PHPStan** (level 8, no plugin needed) over Psalm; **PHP-CS-Fixer** over CodeSniffer because it *fixes* and can auto-insert `declare(strict_types=1)`, a Phase 0 requirement.
- Commit `composer.lock` (`type: project`).

---

## 2. File layout

### Root
`index.php` (4 lines) · `server.php` (dev router for `php -S`) · `.htaccess` (rewrite **and** deny) · `.gitignore` · `phpunit.xml` (suites `unit`/`feature`, no coverage section) · `phpstan.neon` (level 8) · `.php-cs-fixer.dist.php` (`@PER-CS2.0` + `declare_strict_types` + `native_function_invocation`) · `.editorconfig` · `README.md` · `LICENSE` (MIT) · `.github/workflows/ci.yml` (Linux 8.2/8.3/8.4) · `bin/console` · `system/.gitignore`

### engine/

| Dir | Files |
|---|---|
| `Core/` | `Application.php`, `ExecutionContext.php`, `ExecutionMode.php` (enum `Http\|Cli`), `HttpKernel.php` |
| `Bootstrap/` | `bootstrap.php` (returns booted-but-not-run `Application`), `Bootstrap.php` |
| `Container/` | `Container.php`, `ServiceRegistrar.php`, `ContainerException.php`, `EntryNotFoundException.php` |
| `Http/` | `Request.php`, `Response.php`, `JsonResponse.php`, `RedirectResponse.php`, `StreamResponse.php`, `Cookie.php`, `UploadedFile.php`, `Headers.php`, `HttpException.php` |
| `Routing/` | `Router.php`, `RouteCollector.php`, `Route.php`, `RouteMatch.php`, `MatchStatus.php` (enum), `RoutingException.php` |
| `Dispatch/` | `Dispatcher.php`, `HandlerResolver.php` |
| `Module/` | `ModuleManager.php`, `ModuleRegistry.php`, `ModuleDefinition.php`, `ModuleContext.php`, `ModuleKind.php`, `ModuleStage.php`, `ModuleException.php` |
| `Hook/` | `HookEngine.php`, `HookException.php` |
| `Filter/` | `FilterEngine.php`, `FilterException.php` |
| `Support/` | `CallbackChain.php`, `Callback.php`, `Extensions.php`, `helpers.php`, `Path.php` |
| `Config/` | `Config.php` — **seam only**, no loaders |
| `Error/` | `ErrorHandler.php`, `FrameworkException.php` — **seam only** |
| `Cli/` | `ConsoleKernel.php`, `Output.php` |

### modules/ (the demo that proves the slice)
```
modules/shared/module.php
modules/shared/Filters/ResponseFilters.php
modules/plugins/Example/{module.php, Data/CustomerCatalog.php,
    Api/ListCustomers.php, Api/CustomerApi.php,
    Hooks/CustomerHooks.php, Filters/CustomerFilters.php}
modules/gateways/Example/{module.php, Hooks/AuditHooks.php}
```
All three kinds, because the spec's own tree has a plugin **and** a gateway both named `Example` — which is exactly why module ids must be kind-qualified.

### tests/
`Unit/` mirrors `engine/` (Container, Http, Hook, Filter, Routing, Dispatch, Module, Config, Error, Support, Bootstrap) · `Architecture/ArchitectureTest.php` · `Feature/{HttpKernelTest, VerticalSliceTest, ConsoleTest}.php` · `Support/TestCase.php` · `Fixtures/Modules/{Shared, Plugins/Alpha, Plugins/Beta, Gateways/Zeta}/module.php`.

Fixtures are discovered by pointing `modules.paths` at `tests/Fixtures/Modules/*` — which means **module roots must be configurable**, good design independently.

---

## 3. Public API

### index.php — exactly the spec's shape
```php
<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/engine/Bootstrap/bootstrap.php';
exit($app->run());
```

### engine/Bootstrap/bootstrap.php — one bootstrap, two contexts (invariant 5)
```php
$context = \PHP_SAPI === 'cli'
    ? ExecutionContext::cli($_SERVER['argv'] ?? [], $_SERVER)
    : ExecutionContext::http($_SERVER);

return Bootstrap::create(\dirname(__DIR__, 2), $context);
```

### HttpKernel — pure: never echoes, never exits, never touches superglobals
```php
public function handle(Request $request): Response
{
    try {
        $request = $this->filters->apply('request.instance', $request);
        $this->hooks->do('request.received', $request);

        $path  = $this->filters->apply('router.path', $request->path(), $request);
        $match = $this->filters->apply('route.match', $this->router->match($request->method(), $path), $request);

        $response = match ($match->status) {
            MatchStatus::Matched          => $this->dispatcher->dispatch($request, $match),
            MatchStatus::MethodNotAllowed => throw HttpException::methodNotAllowed($match->allowedMethods),
            MatchStatus::NotFound         => throw HttpException::notFound($path),
        };
    } catch (\Throwable $e) {
        $this->hooks->do('request.failed', $e, $request);
        $response = $this->filters->apply('error.response', $this->errors->toResponse($e, $request), $e, $request);
    }

    return $this->filters->apply('response.instance', $response, $request);
}
```
`Application::run()` is the only place touching the outside world: `Request::fromGlobals()` → `handle()` → `send()` → `response.sent` hook → `terminate()`. `terminate()` calls `fastcgi_finish_request()` when available *before* firing `app.terminating`, so post-response work never delays the client.

### Container — eight methods, nothing more
```php
bind(string $id, \Closure|string|null $factory = null, bool $shared = false): void
singleton(string $id, \Closure|string|null $factory = null): void
instance(string $id, object $instance): void
get(string $id): mixed            // PSR-11
has(string $id): bool             // PSR-11
make(string $id, array $parameters = []): mixed
call(callable|array|string $callable, array $parameters = []): mixed
resolved(string $id): bool
```
No `alias()` (`bind(I::class, C::class)` *is* the alias), no `extend()`, `tag()`, `contextual()`, `resolving()` — Phases 0–8 don't need them, so they don't get written. `make()` carries `@template T of object` + `class-string<T>` + conditional return for real PHPStan typing.

### module.php — the most important API in the framework
```php
return static function (ModuleContext $module): void {
    $module->name('Example Customers')->version('1.0.0');

    $module->config(['currency' => 'USD', 'page_size' => 25]);

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(CustomerCatalog::class);
        $services->bind(CustomerApi::class);
    });

    $module->routes(static function (RouteCollector $routes): void {
        $routes->get('/customers', ListCustomers::class)->name('customers.index');

        $routes->group('/api/v1', static function (RouteCollector $routes): void {
            $routes->get('/customers/{id}', [CustomerApi::class, 'show'])
                   ->where('id', '\d+')->name('customers.show');
            $routes->post('/customers', [CustomerApi::class, 'store'])
                   ->name('customers.store')->meta(['auth' => true]);
        }, name: 'api.v1.', meta: ['api' => true]);
    });

    $module->hook('customer.created', [CustomerHooks::class, 'onCreated'], priority: 20);
    $module->filter('invoice.total', [CustomerFilters::class, 'applyLoyaltyDiscount'], priority: 30);

    $module->onBoot(static function (CustomerCatalog $catalog, FilterEngine $filters): void {
        // Every module is registered by now. Dependencies are INJECTED, not service-located.
    });
};
```

`ModuleContext` full surface: `name/version/description` · `services(\Closure)` · `routes(\Closure)` · `config(array)` · `hook()` · `filter()` · `onBoot(\Closure)` · `id()` · `kind()` · `path()` · `stage()`.

### Handlers — three forms, no controller base class
```php
// invokable class, constructor injection
final class ListCustomers {
    public function __construct(private readonly CustomerCatalog $catalog) {}
    public function __invoke(Request $request): JsonResponse { ... }
}
// [class, method] — route params arrive as NAMED, type-coerced arguments
public function show(int $id): JsonResponse { ... }
// closure
$routes->get('/ping', static fn (): Response => new Response('pong'));
```

Return normalisation (after the `dispatch.result` filter): `Response`→itself · `string`→200 html · `array`/`JsonSerializable`→`JsonResponse` · `null`→204 · anything else→`DispatchException` (explicit failure, never a guess).

### Global helpers — exactly eight, `function_exists`-guarded
`add_hook` `do_hook` `remove_hook` `has_hook` `add_filter` `apply_filter` `remove_filter` `has_filter`

---

## 4. Key design decisions

### 4.1 Why module.php returns a closure taking ModuleContext
- **Pure array** (`return ['routes' => [...]]`) — rejected. The moment routes need callables the array holds closures: untyped, unvalidatable, no IDE completion, no stage enforcement, and *not cacheable anyway*, so the supposed benefit evaporates. Errors surface as `TypeError` deep inside the manager instead of at the declaration site.
- **`return new ExampleModule()` implementing `ModuleInterface`** — rejected. This **is** a Laravel Service Provider with the serial numbers filed off; explicitly forbidden by §54.
- **Closure** — chosen. One argument is the whole API; nothing to extend or implement; **stage legality is enforceable by construction** (every method asserts `ModuleStage` and throws with the module id at the declaration site); declarations are *recorded, not executed*, which is what makes ordering deterministic rather than filesystem-dependent. The caching objection dissolves: discovery never runs the closure, it only walks for `module.php`, and the cacheable artefact is the definition list (`id`, `kind`, `path`) — plain scalars.

### 4.2 Module identity
`id = kind-prefix + '/' + directory` → `shared`, `plugins/Example`, `gateways/Example`. Forced by the spec's own tree having two modules named `Example`. `modules/shared` is the one non-nested root — a single module, not a container.

### 4.3 How modules register without becoming Service Providers
A Service Provider is "a class you subclass, whose `register()`/`boot()` receive the whole container, which becomes a dumping ground". All three are broken:
- **Nothing to subclass** — a closure.
- **Register never sees the container.** It sees `ServiceRegistrar`: `bind/singleton/instance/factory` and **no read method at all**. `Container::get()` during registration is impossible *by construction*, not convention — this structurally enforces invariant 7.
- **Boot never sees the container either.** `onBoot()` closures run through `Container::call()`, so parameters are injected. There is no `$app` handle to reach for, so service location can't creep in.

### 4.4 Lifecycle ordering
Deterministic total order: `ModuleKind` rank (Shared 0, Plugin 1, Gateway 2), then directory name ascending, case-sensitive. Never filesystem order. `shared` always registers first — that is what makes it genuinely shared.

| Stage | Legal | Illegal (throws `ModuleException`) |
|---|---|---|
| **Discover** | FS scan of configured roots for `module.php`; `realpath` containment; build definitions; optional cache r/w | Nothing user-authored runs |
| **Load** | Metadata, `services/routes/config/hook/filter/onBoot` — all **recorded** | Resolving services, emitting hooks, any I/O |
| **Register** | Replay across all modules **by category: config → services → routes → hooks → filters**. Emits `module.registered` | Container reads (impossible); `do_hook` (nothing listening yet) |
| **Boot** | `onBoot` with injected deps, in module order. Cross-module work safe. Emits `module.booted` | Registering new routes/services (context frozen) |
| **Ready** | `app.booted` then `app.ready` | — |

Replaying **by category rather than per-module** is the crucial detail: all config merges before any service factory is defined, and all services bind before any route registers. That removes an entire class of ordering bugs Service Providers famously have.

### 4.5 Helpers vs. the facade ban
The spec bans facades *and* mandates `add_hook()` as a global. These conflict only if "global" and "facade" are the same thing. A facade is a class with `__callStatic` resolving **arbitrary** services, producing statically-untypeable call sites, growing **one class per service**. Ours is a **closed set of eight free functions**, each with a **concrete typed signature**, each bound to **one of exactly two subsystems**, covering **only** the extension mechanism the spec calls first-class.

Wiring: `final class Extensions` with `init(HookEngine, FilterEngine)`, `hooks()`, `filters()`, `reset()`; accessors throw `LogicException` if used before bootstrap. Three rules, **enforced by `tests/Architecture/ArchitectureTest.php`**:
1. `Extensions` may **never** gain a third subsystem accessor — the moment it returns an arbitrary service it *is* a service locator. Frozen by test.
2. **No file under `engine/` may call a global helper** (except `helpers.php`). Engine code takes the engines by constructor injection. Enforced by a `token_get_all()` scan.
3. The permitted global function list is a `const` array in the test — adding a ninth is a deliberate reviewed act, not a drive-by commit. (This matters: `asset()`/`template()` in Phases 13–14 take it to four subsystems.)

### 4.6 Container specifics
**Circular detection:** a `array<string,true> $building` stack, `unset` in `finally`. O(1), and the message carries the full chain: `App\A -> App\B -> App\C -> App\A`. No max-depth heuristic.

**Autowiring, per parameter, in order:** (1) name in `$parameters` override — **top-level only**, never propagated into nested resolutions; (2) no type hint → default, `[]` if variadic, else throw; (3) builtin scalar → default, `null` if nullable, else throw with an *actionable* message naming the class, the param, and the exact `$services->singleton(...)` line to write; (4) class/interface → recurse, then default/nullable, else rethrow with chain appended; (5) **variadic class type → resolve zero arguments**, never guess a collection; (6) **union → try members in declaration order but only those explicitly bound**; if none bound → default → nullable → `ambiguousUnion()` (autowiring a union by "first instantiable class" is exactly the magic the spec forbids); (7) intersection → never autowired.

**Performance:** `ReflectionParameter` lists memoised per class. Reflection is the dominant container cost; this removes it for all repeat resolutions.

### 4.7 Router — static hash map, then segment trie
```php
$key = $method . ' ' . $path;              // tier 1: O(1), the common case
if (isset($this->static[$key])) { ... }
// tier 2: trie bucketed by method, walked segment by segment
// node = ['literals' => [seg => node], 'param' => ['name','pattern','node'], 'route' => ?Route]
```
Compiled **lazily on first `match()`**, so a CLI run never pays trie construction.

Trie over FastRoute-style chunked regexes because: it is **what route caching wants** (nested plain arrays → `var_export()` → `require` → opcache-friendly); per-segment constraints stay cheap (one `preg_match` on one short segment, and only when declared); **no pathological backtracking** (big PCRE alternations over hundreds of routes are a real production risk — segment walking is linear in path depth, independent of route count); and it's debuggable by printing an array.

Precedence: **literal beats parameter** at every node. Method miss probes other methods to distinguish 404 from 405 + `Allow:`. `HEAD` falls back to the `GET` trie, body truncated at send. `OPTIONS` auto-answers unless a route declares it. Patterns: `{param}` and trailing `{param?}` — **no catch-all `{path:.*}`** in this pass; it breaks trie determinism and nothing here needs it.

**No middleware.** Not per-route, not global, not anywhere — invariant 6 taken at face value. Cross-cutting behaviour is a lifecycle hook reading `Route::metadata()`: `meta(['auth' => true])` + a `dispatch.before` hook is the auth story, composing without a pipeline abstraction.

### 4.8 Dispatcher and the canonical lifecycle names
```php
$this->hooks->do('route.matched', $route, $request);
$handler    = $this->filters->apply('dispatch.handler', $route->handler(), $route, $request);
$parameters = $this->filters->apply('dispatch.parameters', $match->params, $route, $request);
$this->hooks->do('dispatch.before', $route, $request);
$result   = $this->container->call($callable, $this->resolver->arguments($callable, $parameters, $request));
$result   = $this->filters->apply('dispatch.result', $result, $route, $request);
$response = $this->toResponse($result, $request);
$this->hooks->do('dispatch.after', $response, $route, $request);
```
Convention: **hooks are `<subject>.<event-that-happened>`; filters are named after the value they carry.**

**Hooks (10)** — `app.booted` · `app.ready` · `module.registered` · `module.booted` · `request.received` · `route.matched` · `dispatch.before` · `dispatch.after` · `request.failed` · `response.sent` · `app.terminating`

**Filters (8)** — `request.instance` · `router.path` · `route.match` · `dispatch.handler` · `dispatch.parameters` · `dispatch.result` · `response.instance` · `error.response`

Every one is consumed by the slice or the demo modules. None speculative.

**Route param binding (confirmed):** matched to handler args **by name**, coerced **only** to declared `int`/`float`/`bool`/`string` and **only** on unambiguous conversion (`ctype_digit`, `is_numeric`, `'1'|'0'|'true'|'false'`). Anything else → `HttpException::badRequest()` → clean 400, **never a `TypeError` 500**. `Request` is injected **by type, never by name**, so a route param literally called `request` cannot hijack it.

### 4.9 Hook/filter engine internals
Both wrap `Support/CallbackChain`: `array<string, array<int $priority, list<Callback>>>` plus a monotonic global sequence. Sort key `(priority asc, sequence asc)` — deterministic and stable, so equal priorities fire in registration order, and registration order is fixed by module order (§4.4). Sorted list memoised per name, invalidated on add/remove.

**Confirmed semantics:** (1) **snapshot iteration** — `do()`/`apply()` iterate a snapshot taken at entry; adding a listener to the currently-running hook does not affect that run; (2) **recursion cap 64** → `HookException`; (3) **debug-only null guard** — a filter returning `null` when the input was not `null` throws and names the callback + module when `app.debug`; in production the null is used as-is.

`acceptedArgs` kept as optional `?int` purely so `add_filter('x', 'strtoupper', acceptedArgs: 1)` doesn't blow up — userland callables ignore extra args, PHP internals throw.

`listeners(string $name): array` returns `['module' =>, 'priority' =>, 'callback' =>, 'handle' =>]` — the spec's debugging-info requirement, and the data `module:list` prints.

Sharing `CallbackChain` couples ~70 lines of ordering mechanics while the two public APIs stay completely distinct (`do()`/`apply()`, `void`/`mixed`).

### 4.10 CLI — the honest minimum
Phase 16 owns the real CLI. Phase 1 requires only that "a CLI bootstrap path exists". `bin/console` is five lines requiring the same `bootstrap.php`. `ConsoleKernel::handle()` supports **three hard-coded commands, no registry, no `Command` base class, no argument parser**: `about` (default), `module:list` (proves Phase 4), `route:list` (proves Phase 7). Unknown → usage, exit 1. ~80 lines, and genuinely the fastest way to debug Phases 4 and 7. The Phase 16 seam: the dispatch `match` becomes a registry lookup and `ModuleContext` gains `command()` — which **does not exist yet**.

### 4.11 The config seam (Phase 19 comes later)
The seam is the **read API**, not a loader: `Config` with `get()` (dot notation), `has()`, `set()`, `merge(string $namespace, array $values)`. Four methods, **zero loaders, zero file I/O, no `config/` directory**. `Bootstrap` constructs it from inline defaults plus env overrides for a **fixed enumerated key set**: `app.debug`, `app.env`, `http.base_path`, `http.trusted_proxies`, `http.auto_options`, `modules.paths`, `modules.cache`.

**The bound:** if a Phase 0–8 feature needs a key outside this list, that is a signal to stop and question the feature, not to expand the config system. Phase 19 replaces only the *source*; these four methods stay identical.

### 4.12 The error seam (Phase 17 comes later)
`ErrorHandler` with `register()`, `toResponse(\Throwable, ?Request): Response`, `renderCli(\Throwable): string`. In this pass `toResponse()` does exactly three things: honours `HttpException::status()` and its headers (so 404/405/400 come out right, with `Allow`); returns `JsonResponse` when `$request?->expectsJson()`; includes class/message/file/line/trace **only** when `app.debug` — otherwise a generic page with no leakage. `register()` converts `E_*` to `ErrorException` respecting `error_reporting()`, and installs a shutdown function catching `E_ERROR|E_PARSE|E_CORE_ERROR|E_COMPILE_ERROR` so a fatal yields a 500, not a blank page. **No logger interface is defined now.**

### 4.13 Base-path derivation — the Apache-subdirectory problem
The highest-risk, most environment-specific logic in the slice. `Request::path()` must yield `/customers` for all of: `http://localhost/framework/customers` (Apache + rewrite, base `/framework`), `/framework/index.php/customers` (no rewrite), and `http://127.0.0.1:8080/customers` (`php -S`, base `""`).

```php
$scriptName = (string) ($server['SCRIPT_NAME'] ?? '');          // /framework/index.php
$uri        = \strtok((string) ($server['REQUEST_URI'] ?? '/'), '?') ?: '/';

if ($scriptName !== '' && \str_starts_with($uri, $scriptName)) {
    $base = $scriptName;                                        // no-rewrite form
} else {
    $dir  = \rtrim(\str_replace('\\', '/', \dirname($scriptName)), '/');
    $base = ($dir !== '' && ($uri === $dir || \str_starts_with($uri, $dir . '/'))) ? $dir : '';
}

$path = '/' . \ltrim(\rawurldecode(\substr($uri, \strlen($base))), '/');
```
`http.base_path` config overrides for exotic proxy setups. Gets its own five tests.

---

## 5. Tests — ~180, gate is `composer check`

No coverage gate (no xdebug/pcov). Per-file focus:

| File | ~n | Key assertions |
|---|---|---|
| `Unit/Container/ContainerTest` | 24 | singleton identity; nested autowire 3 deep; **circular A→B→C→A message contains the full chain**; unresolvable scalar names class+param; variadic class → zero args; union with one bound member resolves, with none throws `ambiguousUnion`; intersection throws; `make()` overrides top-level only; `call()` across all four callable forms |
| `Unit/Container/ServiceRegistrarTest` | 4 | delegates writes; **has no readable method** (asserted via reflection) |
| `Unit/Http/RequestTest` | 22 | the three base-path scenarios above; root `/framework/` → `/`; percent-decoding; JSON body, invalid JSON → `null` not fatal; headers from `HTTP_*` + `CONTENT_TYPE`; multi-file normalisation; `ip()` ignores `X-Forwarded-For` without trusted proxies and honours it with |
| `Unit/Http/ResponseTest` | 18 | withers clone and leave the original untouched; **`\r\n` stripped from header values**; Json flags; `StreamResponse` captured via `ob_start`; double `send()` is a no-op; `Cookie` SameSite/HttpOnly/Secure |
| `Unit/Hook/HookEngineTest` | 15 | priority order; **equal priority → registration order**; remove by handle/callable/closure identity; `didCount`; `listeners()` reports module; **snapshot semantics**; recursion cap throws |
| `Unit/Filter/FilterEngineTest` | 14 | chains through 3 callbacks; extra args unchanged; `acceptedArgs` limits args for `strtoupper`; **debug null-guard throws and names the callback**; non-debug uses the null |
| `Unit/Routing/RouterTest` | 24 | **literal beats param at the same depth**; 405 with sorted `allowedMethods`; `HEAD`→`GET`; nested groups compose prefixes *and* names; URL generation includes base path; duplicate route name throws at registration; 500-route trie sanity |
| `Unit/Dispatch/*` | 24 | every handler form; coercion incl. non-numeric for `int` → 400; **`Request` injected by type even when a param is named `request`**; the three dispatch filters each swap their value; hook order recorded in a spy array |
| `Unit/Module/*` | 22 | discovery across three roots; ids kind-qualified; **order shared → plugins/Alpha → plugins/Beta → gateways/Zeta**; non-closure entry throws; **register replays by category**; `onBoot` params injected; `routes()` during Boot throws with the module id in the message; cache write→read identical |
| `Unit/{Config,Error,Support,Bootstrap}/*` | 30 | dot notation; `merge` namespaces without clobbering siblings; 405 keeps `Allow`; **non-debug body contains no file/line/class**; each helper delegates to the right engine; `Extensions::reset()` then a helper throws; core singletons bound and shared; `index.php` matches the mandated shape |
| `Architecture/ArchitectureTest` | 7 | **no `engine/` file except `helpers.php` calls a global helper**; no `__callStatic`; no `*Facade`; `engine/` never references `App\Modules\`; `Extensions` exposes exactly four methods; the global function set is exactly the eight names; `.htaccess` exists and contains the deny rule |
| `Feature/HttpKernelTest` | 10 | 200 JSON; 404; 405 + `Allow: GET, POST`; `HEAD` empty body same headers; auto-`OPTIONS`; throwing handler → 500 and `request.failed` fired; debug vs non-debug bodies; kernel never echoes |
| `Feature/ConsoleTest` | 5 | three commands; unknown → exit 1; no-arg defaults to `about` |
| `Feature/VerticalSliceTest` | 5 | **the proof, below** |

### The end-to-end proof
```php
public function test_module_registered_route_dispatches_through_hooks_and_filters(): void
{
    $app = $this->application(basePath: '/framework');   // real Bootstrap, fixture module roots

    $seen = [];
    add_hook('customer.created', function (array $c) use (&$seen): void { $seen[] = $c['id']; }, priority: 5);
    add_filter('example.customers.list', static fn (array $rows): array => \array_slice($rows, 0, 1));

    $response = $app->boot()->container()->get(HttpKernel::class)->handle(
        Request::create('GET', '/framework/customers', ['server' => ['SCRIPT_NAME' => '/framework/index.php']])
    );

    self::assertSame(200, $response->status());
    self::assertCount(1, $response->data()['data']);                       // the filter truncated the list
    self::assertSame('plugins/Example',                                     // route came from a module,
        $app->container()->get(Router::class)->route('customers.index')?->module());  // not a global file
    self::assertNotNull($response->header('X-Engine'));                     // shared module's response filter

    $created = $app->container()->get(HttpKernel::class)->handle(
        Request::create('POST', '/framework/api/v1/customers', [
            'server'  => ['SCRIPT_NAME' => '/framework/index.php'],
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => '{"name":"Ada"}',
        ])
    );

    self::assertSame(201, $created->status());
    self::assertNotEmpty($seen);                          // module-owned hook fired
    self::assertNotEmpty(AuditHooks::$records);           // a *gateway* module observed it
}
```
One test exercises: Bootstrap → Application → Container → discover/load/register/boot → Request with an Apache base path → Router (static + parametric) → Dispatcher → constructor-injected handler → filter transforming a value → hook fired by one module and observed by another → shared-module response filter → `JsonResponse`.

---

## 6. Verification (Windows PowerShell)

> Windows PowerShell 5.1's `Invoke-WebRequest` **throws** on 4xx/5xx (`-SkipHttpErrorCheck` is PS7 only). Use `curl.exe`, which ships with Windows 11.

```powershell
cd C:\xampp\htdocs\framework
git init ; composer install ; composer validate --strict

composer run check                  # cs + stan(level 8) + all tests, must be green

php bin/console about
php bin/console module:list
php bin/console route:list
php bin/console nope ; $LASTEXITCODE            # must be 1

Start-Process php -ArgumentList '-S','127.0.0.1:8080','server.php' -WorkingDirectory (Get-Location)
curl.exe -i http://127.0.0.1:8080/customers
curl.exe -i http://127.0.0.1:8080/api/v1/customers/7
curl.exe -i -X POST -H "Content-Type: application/json" -d '{\"name\":\"Ada\"}' http://127.0.0.1:8080/api/v1/customers
curl.exe -i http://127.0.0.1:8080/nope                    # 404
curl.exe -i -X DELETE http://127.0.0.1:8080/customers     # 405 + Allow: GET
curl.exe -i -I http://127.0.0.1:8080/customers            # HEAD, no body

curl.exe -i http://localhost/framework/customers          # Apache, base path /framework
curl.exe -i http://localhost/framework/api/v1/customers/7

# security — these MUST be 403, not 200
curl.exe -i http://localhost/framework/engine/Core/Application.php
curl.exe -i http://localhost/framework/modules/plugins/Example/module.php
curl.exe -i http://localhost/framework/composer.json
curl.exe -i http://localhost/framework/vendor/autoload.php
```

If Apache 404s on `/framework/customers`, the cause is one of two things in `httpd.conf`:
```powershell
Select-String C:\xampp\apache\conf\httpd.conf -Pattern 'rewrite_module|AllowOverride'
```

`.htaccess` (rewriting **and** denial in one file):
```apache
Options -Indexes
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /framework/
    RewriteRule ^(engine|modules|system|tests|bin|vendor)(/|$) - [F,L]
    RewriteRule ^(composer\.(json|lock)|phpunit\.xml|phpstan\.neon|\.php-cs-fixer.*)$ - [F,L]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L]
</IfModule>
```

CI cannot run locally (no `act`, no Node). The honest local gate is `composer check`; `ci.yml`'s real job is catching the `modules/` casing trap on Linux.

---

## 7. Implementation order — nine groups, each ending `composer check` green

| # | Group | Gate |
|---|---|---|
| **A** | Phase 0 scaffolding: `composer.json`, `.gitignore`, `phpunit.xml`, `phpstan.neon`, `.php-cs-fixer.dist.php`, `.editorconfig`, `LICENSE`, README skeleton, `ci.yml`, `tests/Support/TestCase.php`, one smoke test. `git init`. | `composer install`; check green with 1 test |
| **B** | Container + seams (Phase 3 + Config/Error) | 42 tests; container standalone, zero framework deps |
| **C** | HTTP (Phase 2) + `Support/Path.php` | 46 tests; **zero routing references**, grep-verified by the architecture test |
| **D** | Hooks + Filters + helpers + `Extensions` (Phases 5, 6) | 38 tests + first architecture tests |
| **E** | Routing (Phase 7) | 24 tests incl. 500-route trie |
| **F** | Dispatcher (Phase 8) | 24 tests; hooks/filters via spies, no kernel yet |
| **G** | Modules (Phase 4) + fixtures | 22 tests; the `module.php` contract proven before any web server |
| **H** | Bootstrap + kernel (Phase 1), `index.php`, `server.php`, `.htaccess` | `Feature/HttpKernelTest`; first real request over `php -S` and Apache |
| **I** | CLI + demo modules + full README | End-to-end test; all §6 curl checks |

B, C and D are mutually independent. E needs only C's `Request`. F needs B+E. G needs B+D+E. H needs everything.

---

## 8. Risks

1. **Root `index.php` is the largest security risk in the design** — accepted as a fixed decision. Mitigations: the `.htaccess` above; the §6 curl checks as a deployment checklist; an architecture test asserting `.htaccess` exists and still contains the deny rule; and README documenting an Apache vhost + Nginx server block as the recommended production posture.
2. **Stale module cache.** `modules.cache` defaults **off**; the cached artefact is a `var_export`'d definition array with **no automatic invalidation** (mtime is unreliable on Windows and network shares, and stat-ing every module dir to validate defeats the point). Clearing is "delete `system/Cache/modules.php`" until Phase 16 adds `module:cache:clear`. Document prominently — this is the kind of bug that eats an afternoon.
3. **PHPStan level 8 will fight container/hook/filter code** (`get(): mixed` is intrinsically untypeable). Use `@template`/`class-string<T>`/conditional returns where possible, accept `mixed` where PSR-11 mandates it, and **do not create a baseline** — a baseline at Phase 0 becomes permanent debt. If level 8 is genuinely hostile in Group B, scope an `ignoreErrors` entry to `engine/Container/` with a comment, never globally.
4. **Two kernels, no `Kernel` interface.** Invariant 5 says REST and web share the kernel; it is silent on CLI. `HttpKernel` (`Request→Response`) and `ConsoleKernel` (`ExecutionContext→int`) differ fundamentally, so a common interface would be a fake abstraction. Ten-line change if you later want one.
5. **`modules/` casing is a latent cross-platform trap.** `modules/plugins/` maps to `App\Modules\Plugins\`; every module dir beneath must match its namespace segment exactly. Windows never complains. Do not skip the Linux CI job.
6. **Local performance numbers are meaningless** — no opcache, ZTS build. Set targets now (boot <2 ms cache-warm, route match <50 µs at 500 routes, zero FS stats per cached request) and measure in Phase 27 on NTS + opcache. The choices that matter (reflection cache, lazy trie compile, lazy resolution, `fastcgi_finish_request`) are correct regardless.
7. **Register-stage isolation is structural, not airtight** — a module could smuggle a container reference via a captured variable. Acceptable: the goal is to make the right path the easy path, not to build a sandbox. Do **not** add enforcement machinery.
8. **`bin/console` is extensionless**, so on Windows it must be `php bin/console`; the shebang does nothing. No `console.bat` (speculative). PHPStan may skip extensionless files, so keep it at five lines with all logic in `ConsoleKernel`.

## Deferred to later phases (explicitly not built now)

`engine/{Model,Schema,Data,Database,Asset,Template,Logging,Cache,Queue,Scheduler,Security}/` · `templates/` · `assets/` · the `asset()` and `template()` helpers · config file loaders · a logger · a command registry or `Command` base class · module dependency resolution · route caching (the trie is *designed* for it; the cache is not written) · middleware (never).
