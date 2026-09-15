# Plan — Heavy Backend PHP Framework

Where the project is and what is left. For the rules of engagement and the
current state of the working tree, see [`HANDOFF.md`](HANDOFF.md). For the
framework's actual documentation, see [`README.md`](README.md).

**The specification is `C:\Users\nuren\Downloads\new-framework.md`** — outside
this repository, 60 sections, 30 phases. Read the relevant section before
implementing a phase; do not work from a summary of it, including this one.

**§56: one phase per go-ahead.** Nothing below may be started without the user
saying so. As of Phase 30 every phase is done except 29, which the owner
deferred; there is no "next phase" until they say what it is.

---

## Done

| Phase | Area | Lives in |
|---|---|---|
| 0 | Repository foundation | root config files, `tests/` |
| 1 | Bootstrap / Kernel | `engine/Bootstrap/`, `engine/Core/` |
| 2 | HTTP Request / Response | `engine/Http/` |
| 3 | Dependency injection | `engine/Container/` |
| 4 | Module system | `engine/Module/` |
| 5–6 | Hooks and filters | `engine/Hook/`, `engine/Filter/`, `engine/Support/` |
| 7 | Routing | `engine/Routing/` |
| 8 | Dispatcher | `engine/Dispatch/` |
| 9 | Model infrastructure | `engine/Model/` |
| 10 | Schema system | `engine/Schema/` |
| 11 | Data / Repository / Query | `engine/Data/` |
| 12 | Database | `engine/Database/` |
| 13 | Asset manager | `engine/Asset/` |
| 14 | Template manager | `engine/Template/` |
| 15 | REST API | `engine/Http/ApiResponse.php`, shared module filters |
| 16 | CLI | `engine/Cli/` |
| 17 | Error handling | `engine/Error/` |
| 18 | Logging | `engine/Logging/` |
| 19 | Configuration | `engine/Config/` |
| 20 | Cache | `engine/Cache/` |
| 21 | Queue / Worker | `engine/Queue/` |
| 22 | Scheduler | `engine/Scheduler/` |
| 23 | Security | `engine/Security/` |
| 24 | Session | `engine/Session/` |
| 25 | Authentication / Authorization | `engine/Auth/`, `modules/shared/Auth/` |
| 26 | Module dependency system | `engine/Module/` (`DependencyResolver`, `Version`, `VersionConstraint`) |
| 27 | Performance architecture | `ModuleDiscovery`, `CacheWarmCommand`, `Data\BulkWrites`, `tests/Benchmark/` |
| 28 | Observability | `engine/Observability/` (`Tracer`, `Profiler`, `Report`) |
| — | Default pages; demo modules out of `modules/` | `templates/default/views/*.twig`, `modules/shared` `/` route, `tests/Fixtures/Showcase/` |
| 30 | Documentation / Release | `STABILITY.md`, `CHANGELOG.md`, `UPGRADING.md`, README sections, `tests/Architecture/DocumentationTest.php` |

**Phase 29 (Demo Application) is deferred** by the project owner ("I will make
demo app later"). It is the only phase not built.

Gate: `composer check` — **2460 tests, 27957 assertions, 3 skipped**, PHPStan
level 8 with no baseline.

---

## Between 28 and 30 — default pages · **done**

Asked for directly by the owner, before Phase 30:

1. **The Example plugin and gateway left `modules/`** and became the *showcase*
   fixture in `tests/Fixtures/Showcase/` (namespace `App\Tests\Fixtures\Showcase\…`),
   with `config/plugins/Example.php` and the theme override of their profile.
   `TestCase::application()` boots real `modules/shared` plus the showcase and
   hands Bootstrap `plugins/Example.page_size = 10` (what the config file did);
   `shippedApplication()` boots exactly what ships; `fixtureApplication()` is
   unchanged. `modules/plugins/` and `modules/gateways/` no longer exist —
   discovery reads an absent root as empty.
2. **Twig is required and the default engine; PHP second.** `twig/twig` moved to
   `require`. `TemplateManager` precedence is now engine registration order (Twig
   first) instead of longest-extension-first; directory precedence still wins.
   `TwigTemplateEngine::isAvailable()` and `TemplateException::twigIsNotInstalled()`
   removed. **This departs from spec §25 and invariant 14** ("Twig is optional") —
   recorded as an owner decision in README, CHANGELOG and UPGRADING.
3. **Default pages**: `layout.twig` (blocks, or a `content` string so PHP pages
   can use it), `home.twig` served by a `/` route in `modules/shared` named
   `home`, `errors/404.twig` and `errors/error.twig`. `ErrorPage` now hands
   templates `home` from the request, so "back home" survives a subdirectory.
   The PHP theme files were deleted.
4. Architecture tests: Twig in `require` (not `require-dev`) and registered ahead
   of PHP; the dependency-declaration rule now also reads showcase namespaces.
   `DefaultPagesSliceTest` (11) boots the shipped application. CI's 8.1 runtime
   job requests `/` and a 404 instead of the removed `/ping` and `/customers.json`.

---

## Phase 26 — Module Dependency System · **done**

Spec §37. Kept below as the plan it was; what was actually decided:

1. **Sort, not just validate** — a stable topological sort that takes the
   earliest ready module in kind-then-name order, so the order only changes where
   a dependency forces it.
2. **Constraints** — a Composer-meaning subset (`*`, exact, `^`, `~`, comparisons,
   `||`). Bare partial versions (`1.2`) are refused. Versions are strict
   `MAJOR.MINOR.PATCH`.
3. **Disabled** — `modules.disabled` config; a disabled module's `module.php`
   never runs; unknown ids and `shared` are refused.
4. **Optional** — present optional dependencies are version-checked and order
   registration; only absence is forgiven.
5. **A fifth refusal the spec did not list** — a dependency against kind (plugin →
   gateway, or shared → anything). This makes "shared registers first" hold by
   construction, which retired the main risk below.
6. Nothing about resolution is cached; the discovery cache shape is pinned.

### Original plan

### What the spec asks for

Modules declare `dependencies`, `optional dependencies` and `version
constraints`. The manager must detect **missing**, **circular**, **version
conflict** and **disabled** dependencies. Loading order must stay deterministic.

### What it builds on

- `ModuleContext` already has four declaration registrars (`services`, `routes`,
  `commands`, `schedules`, `access`) with an established shape — a fifth,
  `requires()`, fits the same pattern.
- `ModuleDefinition` / `ModuleRegistry` already carry id, kind and path.
- Order today is **kind rank** (Shared 0, Plugin 1, Gateway 2) then directory
  name ascending, and `ModuleManager::register()` replays declarations **by
  category** — config, services, routes, commands, schedules, access. That
  category replay is what makes cross-module registration work at all; a
  dependency sort must not break it.
- `AccessRegistry::assertConsistent()` is the closest existing model for the
  cycle detection and the "check once every module has spoken" timing.
- `$module->version('0.1.0')` already exists and is currently decorative. This
  phase is what gives it meaning.

### Decisions to make

1. **Sort, or validate and keep the existing order?** Dependency order and
   kind-then-name order can disagree. The safe reading of "deterministic" is a
   stable topological sort that falls back to the current comparator for ties —
   so the order only changes where a dependency actually requires it.
2. **What a version constraint looks like.** Composer-style (`^1.2`) is what
   people expect; implementing a full parser is not in scope. A small
   caret/tilde/exact subset is probably right, and should say so rather than
   pretending to be Composer.
3. **What "disabled" means.** There is no enable/disable mechanism yet. Either
   this phase introduces one (config: `modules.disabled`) or the check is only
   about a dependency that is not installed. The former is more useful and is a
   small addition to discovery.
4. **Optional dependencies** change ordering but not validity — a module that
   optionally depends on another must still register after it when it is
   present.
5. Failures are `ModuleException` at **boot**, naming both modules — same
   posture as the undeclared-capability check in Phase 25.

### Deliverables

- `requires()` / `optionally()` on `ModuleContext`
- resolution in `ModuleManager` between discover and register
- `module:list` showing the graph, and the resolved order
- unit tests over fixture modules covering all four failure modes
- architecture rules, each verified by planting a regression
- README section

### Risks

- A topological sort that quietly reorders `shared` would break the "shared
  registers first, so everything can rely on it" guarantee. Pin it with a test.
- The module discovery cache (`modules.cache`) stores a definition list; if
  resolution results are cached too, a stale graph is a new class of bug. The
  cache has no automatic invalidation by design — be careful here.

---

## Phase 27 — Performance Architecture · **done**

Spec §38–41, §50, §51. Measured first (in-process, opcache on, and cold through
Apache); what was actually decided:

1. **Lazy asset path (§39).** A request under `/assets/` runs discovery only — no
   `module.php`, no service, no `onBoot`. Verified live by making a plugin's
   `module.php` throw: pages 500, its assets 200. **This reverses a Phase 13
   documented feature**: a module filter on `asset.response` is now refused at
   declaration, because it could only ever run in a test (production web servers
   deliver assets without PHP). Engine listeners still apply.
2. **Measured defect fixed**: the `request.received` auth listener built
   `AuthManager` — and the user provider, shared repository and models — on every
   request. It now records the request and hands it over on construction.
3. **Production boot path = `cache:warm`**, not a setting. `modules.cache` is
   **removed**; the discovery cache is read when the file exists and `app.debug`
   is off (spec §51: "uncached module discovery" in development). The cache now
   records its absolute roots and is ignored if they differ — this is what stops
   a test suite pointed at fixtures from reading a real warmed cache. Only
   `cache:warm` writes it (architecture test).
4. **Discovery records `assets`/`templates` presence**, so registration probes no
   module directory; `ModuleDiscovery` is now its own class and the only one
   allowed to probe (architecture test). The pinned cache shape gained exactly
   those two fields.
5. **Not cached, with numbers**: routes (0.87 ms per 500, declared by
   `module.php` which runs anyway, closures uncacheable) and dependency
   resolution (141 µs for 50 modules). Decision 1 of the original plan answered
   "no route cache"; reversible if the benchmark says otherwise.
6. **Benchmarks (§50)** in `tests/Benchmark/`, `composer bench`, all eleven
   subjects (pinned by an architecture test). `--save`/`--compare` on one machine;
   comparisons across PHP/opcache/OS are refused. CI runs them informationally on
   8.4. What must not regress is asserted exactly in `PerformanceSliceTest`.
7. **§40 bulk operations** were the one gap: `BulkWrites` interface (separate, so
   `DataSource` stays five methods), on both sources, with `Repository`
   `insertMany`/`updateWhere`/`deleteWhere` that flush the identity map. Queries
   carrying order/limit/offset/columns, or no criteria, are refused. No implicit
   transaction (§41). Sixth conformance suite.
8. **§41** confirmed, not rebuilt.
9. **One production switch?** Decided against a new `production` posture:
   production is `APP_DEBUG=false` plus the `cache:warm` deployment step, and
   `about` prints the boot path a process took.

Opcache finding worth remembering: a file modified within
`opcache.file_update_protection` (2 s) is not cached, so a benchmark that writes
a cache and reads it immediately measures compilation — the first run reported
the config cache twice as slow as `config/`.

### Original plan

One input from Phase 26: dependency resolution is deliberately **not** cached
(microseconds for a few dozen modules, and a cached graph goes stale). If
measurement says otherwise, the spec lists "dependency resolution" among the
things to cache — but invalidation is the whole problem, so measure first.

Spec §38–41, §50, §51.

### What the spec asks for

Caching for module discovery, module metadata, routes, configuration, templates,
assets and dependency resolution. Avoid repeated filesystem scans, reflection,
discovery, configuration parsing and route compilation. **Production mode should
have a minimal boot path.** Plus lazy loading (§39): `/assets/...` must not
initialise billing. Plus the model performance rules (§40) and explicit
transaction scope (§41).

### What already exists

Most of the caching does. This phase is largely about **measuring**, **closing
gaps** and **the production boot path**, not about building from nothing:

- config cache (`config:cache`), module discovery cache (`modules.cache`),
  template compilation cache, asset manifests — all built
- the router's trie is compiled **lazily on first `match()`** and was designed
  to be `var_export`-able; **route caching itself is not written**
- the container memoises `ReflectionParameter` lists per class
- `Application::terminate()` calls `fastcgi_finish_request()` before
  `app.terminating`
- laziness is already the house pattern: the session, the auth manager and the
  data source all do nothing until asked
- `Connection::transaction()` with savepoint-based nesting exists (§41 is
  largely satisfied — confirm rather than rebuild)

### Decisions to make

1. **Route caching** is the obvious remaining one, and the trie was built for
   it. The hard part is invalidation, which the module cache deliberately does
   not solve — decide whether route cache clearing is `cache:clear` only, and
   say so loudly.
2. **Benchmarks (§50).** The original plan set targets — boot < 2 ms cache-warm,
   route match < 50 µs at 500 routes, zero filesystem stats per cached request —
   and noted that **local numbers are meaningless** on this machine (ZTS, no
   opcache). Decide where benchmarks run and how regressions are tracked; a
   benchmark nobody runs is worse than none.
3. **Production mode.** Today `app.env` and `app.debug` are separate switches.
   Whether there should be one `production` posture that turns on every cache is
   a real decision — one switch is easier to get right, several are easier to
   debug.

### Risk

This is the phase most likely to produce speculative machinery. The spec says
performance must be *considered* before the framework is large; it does not ask
for a profiler. Measure first, then close the gap that measurement found.

---

## Phase 28 — Observability · **done**

What was decided:

1. **Two halves.** `Tracer` is always on (ids, elapsed, memory; one random id
   per unit of work). `Profiler` is off by default (`APP_PROFILE`) and when off is
   **not attached**, rather than attached-and-checking.
2. **Traces**: http (Application::serve, covering boot), console
   (Application::runConsole, covering boot), job (JobRunner, ended in `finally`),
   process (lazy fallback). A new request closes anything left open.
3. **Correlation crosses the queue** in `QueuedJob::$correlationId` (nullable;
   older envelopes read fine). Retries keep it.
4. **Incoming ids ignored** unless `observability.trust_incoming_ids`; even then
   pattern-checked (log injection).
5. **Seams, not imports**: `observe()` on HookEngine, FilterEngine, ModuleManager,
   Connection/ConnectionManager. Only engine/Observability attaches (architecture
   test); every seam must be connected (architecture test).
6. **Query seam never passes bindings** (unit test counts the arguments).
7. **Output is the log and headers only** — `X-Request-Id` always,
   `X-Correlation-Id` when different, `Server-Timing` only with profiling AND
   debug; `profile` channel record per request/command/job. Architecture test:
   engine/Observability opens no file and prints nothing (no dashboard).
8. **Aggregated, capped, inclusive, trace-scoped** profiling; nested job scopes
   merge into the parent.
9. **Slow-query warning** (`SLOW_QUERY_MS`) works with profiling off; shares the
   connection seam via `Report::watchQueries()`.
10. **Log enrichment** via `LogManager::enrich()`; a record's own context wins; a
    throwing enricher is detached and recorded.
11. Cost measured: hook with 10 listeners 2.0 µs unobserved (unchanged), 15.6 µs
    profiled.

Live verification found listener names printed as `Closure#154`; fixed to
`Class::method` / `closure at file:line`.

### Original plan

Spec §52.

Inputs from Phase 27: `tests/Benchmark/` measures from outside; observability
measures from inside a running request, and the two should not grow into one
thing. An asset request no longer fires `app.booted` or any module listener, so
request timing that hangs off module hooks will not see asset requests — attach
engine-level listeners in `Bootstrap` for anything that must.

Request id, correlation id, execution timing, query timing, module timing, hook
timing, filter timing, memory usage, error context.

**The spec's own instruction is the design constraint: "Do not build a giant
debug dashboard initially. First build reliable instrumentation APIs."**

### What it builds on

- `engine/Logging/` with channels and context, and a `Logging\Context` that
  already coerces values safely
- hooks already fire around every seam (`request.received`, `dispatch.before`,
  `dispatch.after`, `response.sent`, `command.*`, `job.*`, `schedule.*`,
  `auth.*`) — timing is a listener, not a new mechanism
- `HookEngine::listeners()` already reports module, priority, callback and handle

### Decisions to make

1. **Where a request id lives.** A header (`X-Request-Id`, honoured if a proxy
   sent one), log context, and an accessor. Correlation id is the same thing
   crossing a queue boundary — `QueuedJob` would need to carry it.
2. **How timing is collected without being a cost.** A collector that is off by
   default and that listeners feed, rather than instrumentation compiled into
   every subsystem.
3. Query timing means `Connection` needs a seam. Check whether it already has
   one before adding another.

---

## Phase 29 — Demo Application · **deferred by the owner**

When it is picked up: the Example modules it would have extended are now the
showcase fixture, not `modules/` content. A demo built for §55 goes in
`modules/` as `Customer`, `Billing`, `DemoGateway`; its routes must not use the
name `home`, and any `/` it declares replaces the shared default page. It is also
the natural place to design migrations (listed as not built).

Spec §55, §43–45.

The spec asks for `modules/plugins/Customer/`, `modules/plugins/Billing/` and
`modules/gateways/DemoGateway/`, demonstrating HTTP, REST, CLI, model, shared
model, schema, repository, query, hooks, filters and templates together.

### What exists

`modules/plugins/Example/`, `modules/gateways/Example/` and `modules/shared/`
already demonstrate nearly all of it, and the `VerticalSliceTest` proves the
chain end to end. What is missing is a **second real plugin that depends on the
first** — which is what makes Phase 26 visible, and why this phase is best done
after it.

Billing is the right choice: it needs Customer, it has money (there is already a
`money` template helper in shared), it justifies a transaction spanning several
writes (§41), and it gives the capability model something to protect that is
worth protecting.

---

## Phase 30 — Documentation / Release · **done**

What was decided:

1. **§53 marking lives in `STABILITY.md`**, not in 270 docblocks: namespace rows
   with class overrides, most specific wins. **Nothing is Stable before 1.0.0**
   (tested); Experimental changes only in a minor with an UPGRADING entry;
   Internal changes freely — except **persisted formats** (queued job envelope,
   session record, counters), which a release must still read.
2. **The rule that gives it teeth**: no class in `modules/` or the showcase may use
   an Internal class. It immediately found `AccountProvider` using
   `TokenAuthenticator::fingerprint()`, which the README documents as public, so
   that class is Experimental.
3. **Deprecation** = `@deprecated` + STABILITY row + CHANGELOG + UPGRADING. Never
   `E_USER_DEPRECATED`: the error handler turns reported errors into exceptions.
   No runtime mechanism built — nothing is deprecated.
4. **Versioning**: SemVer, 0.x minors may break Experimental; `Application::VERSION`
   is the only copy (no `version` in composer.json — the tag is the version).
   Release steps are manual on purpose (README *Versioning and releases*).
5. **`CHANGELOG.md`** (Keep a Changelog; `[Unreleased]` = the 0.1.0 contents) and
   **`UPGRADING.md`** (from the last commit, "Phase 23-25", to now — it becomes the
   0.1.0 notes).
6. **README drift pass** over all ~3,800 lines. Fixed: status line (said 0–23),
   "helpers reserved, not implemented", migrations blamed on the CLI phase, "the
   database phase adds a PDO source", the `templates/assets/` row, a Model example
   assigning an undeclared property, a logging example with undefined variables,
   "six" ApiResponse factories (five), the command list (six missing),
   `UserProvider` "two methods" (three), "three caches", CSRF binding "belongs
   with authentication" (authentication did not do it), "the demo modules".
7. **What is not built** is a README section: deferred (demo, migrations, stores,
   §42 future hooks, session-bound CSRF, byte ranges, route/dependency caches),
   declined by design (§54 and friends), and the three departures from the spec.
8. **`DocumentationTest`** (9 tests): classification complete and not stale;
   nothing Stable in 0.x; Deprecated rows name replacement and removal; no module
   uses Internal; lifecycle table == engine hooks/filters; every framework command
   in the README; every link in the four documents resolves; VERSION, CHANGELOG
   and composer.json agree. Ten plants, all caught.

---

## Standing notes for any phase

- **Read the spec section first.** Every phase so far has had at least one
  requirement that a summary would have lost.
- **Live verification finds what tests do not.** Apache serving the dev router's
  source, a forged CSRF pair being accepted unkeyed, and Apache eating the
  `Authorization` header were all found with `curl.exe`, not with PHPUnit.
- **Every new architecture rule gets a planted regression** to prove it bites.
- **No `final readonly class`, no `never` in arrow functions** — the framework
  supports PHP 8.1.
- **Write PHP with the Write/Edit tools, not heredocs** — see `HANDOFF.md` §6.
