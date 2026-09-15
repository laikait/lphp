# Changelog

Every release, newest first. The format is
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/spec/v2.0.0.html) as described in the
README's *Versioning and releases*.

Breaking changes are listed under **Changed** or **Removed** and each one has a
matching section in [`UPGRADING.md`](UPGRADING.md). What is public, and how
public, is in [`STABILITY.md`](STABILITY.md).

## [Unreleased]

The first release, 0.1.0, will contain everything below. Every public API in it
is **Experimental**: 0.x minor releases may change it, always with an upgrade
note.

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
- **Templates**, engine-neutral, with Twig and PHP engines, a theme override rule
  and module namespaces. The default template ships a Twig layout, a home page for
  `/` and error pages for 404 and every other status.
- **REST** conventions on the same kernel: content negotiation with quality
  values, one JSON error document, `ApiResponse`, prefix versioning with
  `Deprecation`/`Sunset` headers.
- **Console** with declared arguments and options, typed binding, meaningful exit
  codes and generated help; 23 framework commands, none of which writes code.
- **Errors** rendered by audience (browser, API, console) with disclosure rules,
  and **logging** as a listener, with redaction, retiring writers and file,
  stream and syslog destinations.
- **Configuration** from defaults, `config/*.php` and the environment, with a
  cache that notices environment changes; a **cache** with array, file and null
  stores.
- **Queue and worker** with sync, memory and file stores, retries with capped
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
- **Documentation**: `STABILITY.md`, `UPGRADING.md`, this file, and a README
  section on what is not built.

### Changed

- **PHP 8.2 or newer is required.** Support for 8.1, which reached end of life
  in December 2025, is dropped.

### Decided against the specification

- **Twig is a required dependency and the default engine**, where §25 and
  invariant 14 call it optional. The project owner's decision: the default
  template's pages are Twig. PHP templates still render through the same
  manager, and the manager itself does not depend on Twig.
- **The unnamed template asset namespace is the active template's `assets/`**,
  not a shared `templates/assets/`, so switching templates switches stylesheets.

[Unreleased]: https://github.com/laikait/lphp/commits/main
