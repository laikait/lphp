# Handoff — Heavy Backend PHP Framework

Everything a fresh session needs to continue this project.

| | |
|---|---|
| `HANDOFF.md` | this file — state of play and rules of engagement. Read first |
| [`PLAN.md`](PLAN.md) | what is done, and the decisions of Phases 26–30 |
| [`README.md`](README.md) | the framework's actual documentation |
| [`STABILITY.md`](STABILITY.md) · [`CHANGELOG.md`](CHANGELOG.md) · [`UPGRADING.md`](UPGRADING.md) | §53 marking, releases, migration notes (Phase 30) |
| [`docs/plans/phases-0-8-original.md`](docs/plans/phases-0-8-original.md) | the original plan-mode plan, archived |

**Last updated:** after Phase 30 (Documentation / Release), and the default-pages
work the owner asked for just before it. Phase 29 is deferred by the owner. The
quality gate is green.

---

## 1. What this is

A module-first, hook/filter-driven backend PHP framework for **ERP, billing,
hosting control panels, SaaS and API-heavy backends**. Explicitly **not a
Laravel clone**.

- Project root: `C:\xampp\htdocs\framework`
- Root namespace `App\` — `App\Engine\` → `engine/`,
  `App\Modules\{Shared,Plugins,Gateways}\` → `modules/{shared,plugins,gateways}/`.
  Only `modules/shared` ships; the Example plugin and gateway are the showcase
  fixture, `App\Tests\Fixtures\Showcase\…` in `tests/Fixtures/Showcase/`
- Served by XAMPP Apache at `http://localhost/framework/`
- Git user is "Laika IT"; 4 commits exist, all made by the user. **Claude has
  made no commits and should not without being asked.**

### The specification

**`C:\Users\nuren\Downloads\new-framework.md`** — 60 sections, 30 phases.
It lives **outside** the project on purpose. A new session must read the
relevant phase section before implementing it; do not work from memory of it.

Useful anchors in that file:
- §54 — the ban list (see below)
- §56 — one phase at a time
- §57 / §~line 2430 — the phase table
- §58 — definition of done

---

## 2. Hard constraints — do not violate these

These came from the user and from the specification. They are not preferences.

1. **Only work in `C:\xampp\htdocs\framework`.** Sibling directories
   `laika-framework/`, `laika/`, `laika-container/` are to be ignored entirely.
2. **One phase per go-ahead (§56).** Never implement several phases in one pass.
   The user authorises each phase with "go next", "go ahead", "start Phase N" or
   similar. After finishing a phase, report and **stop**.
3. **§54 bans**, all currently honoured and enforced by architecture tests:
   - no facades
   - no Eloquent / Blade / Artisan clones
   - no Service Providers
   - no middleware anywhere
   - no Gates / Policies
   - no FormRequest clones
   - no global `Controllers/` or `Models/` directories
   - no automatic repository generation, no `make:*` scaffolding
4. **Spec-faithful root `index.php` + hardened `.htaccess`.** There is no
   `public/` directory; that was a deliberate decision with documented
   mitigations.
5. **Create only what is used.** No empty directories, no speculative helpers,
   no abstraction without a caller.
6. **No commits** unless the user asks.

---

## 3. Current state

### Phases

| Phase | Area | State |
|---|---|---|
| 0–8 | Foundation, Bootstrap/Kernel, HTTP, DI, Modules, Hooks, Filters, Routing, Dispatcher | done |
| 9–12 | Model, Schema, Data/Repository/Query, Database | done |
| 13–16 | Asset manager, Templates, REST, CLI | done |
| 17–20 | Errors, Logging, Configuration, Cache | done |
| 21–23 | Queue/Worker, Scheduler, Security | done |
| 24 | Session | done |
| 25 | Authentication / Authorization | done |
| 26 | Module Dependency System | done |
| 27 | Performance Architecture | done |
| 28 | Observability | done |
| — | Default Twig pages; Example modules moved to the showcase fixture | done (owner request) |
| **29** | **Demo application** | **deferred by the owner** — "I will make demo app later" |
| 30 | Documentation/Release | done |

There is no queued next phase. Wait for the user to say what comes next.

### The quality gate

```bash
composer check      # = cs + stan + test.  This must be green before reporting a phase done.
```

Current numbers: **2460 tests, 27957 assertions, 3 skipped**, PHPStan level 8
with **no baseline**, PHP-CS-Fixer clean over 419 files.

`composer bench` runs the benchmarks (`tests/Benchmark/`). They are **not** part
of the gate. Locally they need `php -d zend_extension=opcache -d
opcache.enable_cli=1 tests/Benchmark/run.php` — XAMPP loads no opcache, and
without it every number is dominated by compilation.

The 3 skips are Windows-only (this account cannot create symlinks); they run on
Linux CI.

### Git state

4 commits by the user; the latest is "Phase 23-25 done and under review".
**Phases 26, 27, 28, the default pages and Phase 30 are uncommitted** in the
working tree. `UPGRADING.md` lists what changed since that commit. Notable:

- The dev router is the extensionless `server` (renamed by the user from
  `server.php`); that rename and the old `system/Security/*.count` files are
  already settled in the user's last commit.
- `.gitignore:37` lists `composer.lock`, but the lock **is** tracked (correct for
  `type: project`). The ignore line is stale and does nothing.

---

## 4. How work is delivered here

This is the working method the user has come to expect. Every phase gets **all**
of it:

1. **Read the spec section** for that phase first.
2. **Implementation** in `engine/<Subsystem>/`, wired in
   `engine/Bootstrap/Bootstrap.php`, with config defaults in the same file.
3. **Heavy docblocks that explain *why*.** Every non-obvious decision is written
   down where the code is, including what was rejected and what the cost is.
   This is the house style and it is load-bearing — match it.
4. **Tests**: unit tests per class, one `tests/Feature/<Phase>SliceTest.php`
   proving it end to end through a real `Application`.
5. **Architecture rules** in `tests/Architecture/ArchitectureTest.php`, and
   **every new rule is verified by planting a deliberate regression and watching
   it fail**, then reverting. A rule that has never failed is not a rule.
6. **Conformance suite** for any pluggable subsystem (one data-provider-driven
   test class per store type, plus an architecture rule asserting every shipped
   store appears in it). Five exist: Cache stores, Queue stores, Scheduler
   locks, Security counters, Session stores.
7. **README section** — the README is the framework's documentation and gets a
   substantial section per phase, written in the same explanatory voice.
8. **Live verification** through Apache with `curl.exe`, not just tests. This has
   repeatedly found real defects that tests did not.
9. **Report** and stop.

### Pinned-surface tests that will fail when you add things

These are deliberate tripwires. When they fail, update them — do not weaken them:

- `BootstrapTest::test_the_defaults_cover_exactly_the_documented_keys` — config keys
- `ArchitectureTest::test_no_command_writes_source_code` — the command list
- `ArchitectureTest::test_the_console_layer_holds_infrastructure_only` — files in `engine/Cli/`
- `ArchitectureTest::test_every_environment_variable_is_documented` — `.env.example`
- `ArchitectureTest::test_the_minimum_php_version_is_the_one_that_is_analysed`
- `ArchitectureTest::test_a_module_declares_every_module_whose_classes_it_uses` — cross-module `use` needs `requires()`
- `ArchitectureTest::test_the_discovery_cache_carries_no_dependency_data` — the cache's field list
- `ArchitectureTest::test_no_business_repository_lives_in_the_engine` — files in `engine/Data/`
- `ArchitectureTest::test_every_specified_subject_has_a_benchmark` — spec §50's list, verbatim
- `RepositoryTest::test_the_plumbing_cannot_be_overridden` — `Repository`'s final protected methods
- `ArchitectureTest::test_every_observation_seam_is_connected` — the list of classes with `observe()`
- `DocumentationTest::test_every_engine_class_has_one_stability_level` — a new namespace under `engine/` needs a `STABILITY.md` row
- `DocumentationTest::test_no_module_uses_an_internal_class` — a module (or the showcase) reaching for an Internal class
- `DocumentationTest::test_the_lifecycle_table_lists_exactly_what_the_engine_fires` — a new hook/filter needs a README row
- `DocumentationTest::test_every_framework_command_is_documented` — a new core command needs a README mention
- `DocumentationTest::test_the_version_and_the_changelog_agree` — bumping `Application::VERSION` needs a CHANGELOG section
- `TestCase::application()` boots **shared + the showcase**; use `shippedApplication()` for what actually ships

---

## 5. Architecture decisions that must not be undone

A new session will be tempted to "fix" some of these. They are deliberate.

### Cross-cutting behaviour is hooks, never middleware

There is no pipeline. `Security\Guard`, `SessionManager` and `AuthGuard` all
attach as listeners on hooks the kernel already fires
(`request.received`, `dispatch.before`, `response.instance`). A listener refuses
by **throwing an `HttpException`**, which the kernel already renders — that is
how 404 and 405 work. The stated cost: a listener cannot wrap the handler.

Bootstrap ordering of the `dispatch.before` listeners: security guard at
priority 5 (rate limit, then CSRF), auth guard at 8.

### Defaults point in deliberately different directions

| | direction | why |
|---|---|---|
| CSRF | **opt-out** (`meta(['csrf' => false])`) | the route added in a hurry is the one that matters |
| Rate limit | opt-in | a limit needs a number only the app knows |
| Login required | **opt-in** (`meta(['auth' => true])`) | opt-out makes the home page private, so it gets disabled wholesale |

The compensating control for the last one: `route:list` has an ACCESS column,
`auth:access` lists guarded routes, and `security:check` **warns about routes
that change something and require nobody**.

### Authorization is set membership that can only shrink

Roles grant capabilities (data, declared by modules). A check is set membership —
**no closure runs**. A module may narrow a decision through the
`authorization.decision` filter and **may not widen it**. That asymmetry is what
makes this not a Gate, and `ArchitectureTest::test_there_is_no_gate_and_no_policy`
freezes `Authorizer`'s method list to enforce it.

A route asking for an undeclared capability **fails at boot**.

### Storage stores take a closure, not a value

`SessionStore::commit(string $id, \Closure $apply)` — the store applies the
closure to whatever is stored *now*, under its own lock. This is what makes
concurrent requests both survive without locking the session for the whole
request the way PHP does. Do not "simplify" it to read/write.

### Other load-bearing decisions

- **No `$_SESSION` / `session_start()` anywhere** — enforced across `engine/` and
  `modules/` by an architecture test.
- **Session payloads are JSON**, and the memory store encodes too, so "it worked
  in tests" means something.
- **The framework does not know what a user is.** `UserProvider` has exactly
  three methods and an architecture test freezes it there. The real provider
  lives in `modules/shared/Auth/AccountProvider.php`.
- **Session keys beginning with `_` are reserved** and cannot be written through
  `Session::set()` — the logged-in account id lives in one.
- `Signer` is the only HMAC; `Auth\Password` is the only password hasher;
  `SessionId` is the only session-id generator. Each is enforced by a test.
- **No NullLock for the scheduler** — a lock that locks nothing looks like it
  works. (By contrast `Auth\Providers\EmptyProvider` *is* shipped, because a
  provider with no users is not pretending anything.)
- Demo credentials: `ada` / `grace`, both password `secret`; ada is
  `administrator`. API token `ada-token-do-not-use`.

---

## 6. Environment — things that will bite

### Windows / XAMPP

- PHP 8.3.33 ZTS. `curl.exe` (not `curl`) for live testing; PowerShell 5.1's
  `Invoke-WebRequest` throws on 4xx/5xx.
- `fileinfo` is now enabled in php.ini (the user did this).
- The account cannot create symlinks → 3 tests skip locally, pass on CI.
- Apache serves the project at `/framework/`. The dev router is the
  extensionless file `server` (renamed by the user from `server.php`) and is
  denied by both `.htaccess` and its own deny list.

### The heredoc escape trap — this one wastes the most time

Writing PHP through a Bash heredoc into Python **corrupts backslash escapes**:
`\a` → BEL (0x07), `\b` → backspace (0x08), `\f` → form feed (0x0c), `\t` → tab,
and `\N`/`\U`/`\E` raise SyntaxErrors. This silently produced broken files
several times.

**Rules:**
- Use the **Write/Edit tools** for PHP content, not heredocs.
- If you must use Python, avoid backslashes in search/replace strings, or build
  them numerically (`bytes([92])`).
- After any scripted edit, check:
  `grep -cP '[\x00-\x08\x0b\x0c\x0e-\x1f]' <file>` — must be 0.
- Also check for stray non-ASCII: `grep -nP '[^\x00-\x7F]' <file>`.

### PHPUnit gotcha

`PHPUnit\Framework\Assert::matches()` is **final**. A data provider named
`matches()` causes a fatal. Name providers something else.

---

## 7. PHP version / CI arrangement

`composer.json` declares `"php": "^8.1"` (the user's decision) but
`config.platform.php` is `8.2.0`, because **PHPUnit 11 and php-cs-fixer's
Symfony 7 components require 8.2**. That is a fact about the tools, not the
framework.

What keeps the 8.1 claim honest:

- `phpstan.neon` has `phpVersion: {min: 80100, max: 80500}` — 8.2-only syntax
  (readonly classes, `never` in arrow functions) is an error on **every** run.
- An architecture test fails if `composer.json` and that range drift apart.
- `.github/workflows/pcc.yml` has two jobs: **Gate** (8.2–8.5, full
  `composer check`) and **Runtime** (8.1, `--no-dev`, lints every shipped file,
  boots the console, serves a request).

**Consequence for new code: no `final readonly class`, no `never` return type in
arrow functions.** Use `final class` with `public readonly` promoted properties.

---

## 8. Open items for the user to decide

1. **PHP 8.1 is EOL** (security support ended December 2025). Shipping a
   framework that has just completed its security and auth phases on an EOL
   runtime is worth a deliberate decision. Raising to `^8.2` would let
   `platform.php` be removed entirely.
2. The stale `composer.lock` line in `.gitignore` (see §3).
3. **The spec lives outside the repo.** Consider copying
   `new-framework.md` into the project (e.g. `docs/specification.md`) so it
   travels with the code — the user's call, since keeping it outside was
   deliberate.
4. **Opcache in production.** Without it a request costs 45–60 ms here, nearly
   all compilation, and every Phase 27 saving is invisible behind that. XAMPP
   ships it disabled. Nothing in the framework can switch it on.

---

5. **Twig is required** (owner decision), which departs from spec §25 and
   invariant 14. Recorded in README *What is not built*; reversible by moving the
   package back to `require-dev` and shipping PHP default pages again.

## 9. What comes next

Nothing is queued. **Phase 29 (Demo Application) is deferred by the owner**; do
not start it, or anything else, without a fresh instruction. Releasing 0.1.0
follows README *Versioning and releases* and needs the owner's commit and tag.

### Phase 30 and default-pages decisions that must not be undone

- **Nothing is Stable before 1.0.0** — a test refuses a Stable row in 0.x.
- **No module or showcase code may use an Internal class.** Fix the module or
  reclassify with a reason; never exempt a file.
- **Never deprecate with `E_USER_DEPRECATED`** — the error handler turns it into
  an exception and breaks the request.
- **`Application::VERSION` is the only version**; composer.json has no `version`.
- **Persisted formats** (queued job envelope, session record, counters) must stay
  readable by the next release, Internal or not.
- **Engine registration order is template precedence**: Twig, then PHP. Pinned.
- **The shared module owns `/` under the name `home`.** A module replaces the page
  by declaring `/` under another name; the router keeps the last route declared.
- **Error templates get `home` from the request** (`ErrorPage::data()`), so links
  survive a subdirectory install. The 404 page never echoes the path.

### Phase 28 decisions that must not be undone

- **The profiler is attached, never checked.** Subsystems expose `observe()` and
  must not reference `Observability\Profiler`/`Report` (architecture test).
- **The query observer never receives bindings.**
- **Observability writes nowhere but the log and response headers** — no
  dashboard, no store, no endpoint (architecture test).
- **Incoming request/correlation ids are ignored by default**; trusted ids are
  still pattern-checked.
- **`Server-Timing` only when profiling AND debug.**
- **Job traces end in `finally`** so a failing sync job cannot leave its id on
  the rest of a request's log.

### Phase 27 decisions that must not be undone

- **An asset request runs discovery only** (`Application::prepareFor()` →
  `ModuleManager::prepareAssets()`). No `module.php`, no `app.booted`, no module
  listener. A module filter on `asset.response` is refused at declaration — this
  deliberately reversed a Phase 13 README example.
- **The `request.received` auth listener must not resolve `AuthManager`.** It
  records the request in a small holder; the manager's factory hands it over.
  `PerformanceSliceTest` fails if a public page constructs the manager.
- **There is no `modules.cache` setting.** The discovery cache is used when the
  file exists, `app.debug` is off, and its recorded roots match. **Only
  `cache:warm` writes it** — never a request.
- **Only `ModuleDiscovery` probes module directories.** Registration reads
  `ModuleDefinition::$hasAssets` / `$hasTemplates`. Architecture test.
- **Routes and dependency resolution are not cached** — measured and decided;
  see README "What is deliberately not cached".
- **`BulkWrites` is a separate interface.** `DataSource` stays five methods.
  Bulk queries are criteria-only and non-empty; no implicit transaction.
- **Timings never fail the build.** Structural facts do (`PerformanceSliceTest`).
  The benchmark comparison refuses across PHP version, opcache, ZTS or OS.

### Phase 26 decisions that must not be undone

- **Lifecycle is now Discover → Load → Resolve → Register → Boot.**
  `register()` calls `resolve()` if nobody did.
- **Kind order is enforced, not hoped for.** A dependency against kind (plugin →
  gateway, shared → anything) is refused, so the stable topological sort only
  moves modules within their kind and `shared` registers first by construction.
- **Optional dependencies are version-checked and order registration** when
  present; only absence is forgiven. They can therefore close a cycle.
- **`version()` is strict `MAJOR.MINOR.PATCH`**; a bare partial constraint (`1.2`)
  is refused rather than given Composer's surprising "exactly 1.2.0" meaning.
- **A disabled module's `module.php` never runs.** `ModuleRegistry::definitions()`
  / `ids()` / `count()` are *enabled* modules; `installed()` includes disabled
  ones and is what the discovery cache stores.
- **Resolution is not cached**, and the discovery cache's shape is pinned by an
  architecture test.
- **A module that references another module's namespace must declare it** — an
  architecture test reads `modules/` to check. Adding a cross-module `use` means
  adding `requires()`.

---

## 10. Quick orientation commands

```bash
composer check                    # the gate: cs + stan + test
php bin/console about             # what is wired up
php bin/console module:list
php bin/console route:list        # includes the ACCESS column
php bin/console auth:access       # capabilities, roles, guarded routes
php bin/console security:check -v # audit; exits 1 on a problem
php bin/console cache:warm        # production boot path; refuses with APP_DEBUG on
php bin/console cache:clear       # always run cache:clear afterwards when testing locally
php bin/console schedule:list
php bin/console session:gc

# live, through Apache
curl.exe -i http://localhost/framework/
curl.exe -s -H "Authorization: Bearer ada-token-do-not-use" http://localhost/framework/me
curl.exe -i http://localhost/framework/engine/Core/Application.php   # must be 403
```
