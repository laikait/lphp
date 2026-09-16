# App Framework

A module-first PHP framework for heavy backend applications — ERP, billing,
hosting control panels, SaaS backends, administration platforms, API-heavy
systems.

It is deliberately **not** an MVC framework, and deliberately not a Laravel,
Symfony or CodeIgniter clone. Modules are the primary application boundary,
hooks and filters are the primary extension mechanism, and database access is
explicit rather than ORM-driven.

> **Status: 0.1.0, unreleased.** Every phase of the specification is built except
> the demo application (Phase 29), which is deferred. Nothing is marked Stable
> yet — see [API stability](docs/contributing/releases.md), [What is not built](docs/what-is-not-built.md)
> and [Implementation status](docs/contributing/development.md#implementation-status).

## Requirements

- PHP 8.2 or newer, with `ext-intl`, `ext-json`, `ext-mbstring` and `ext-pdo`.
  XAMPP ships intl switched off: enable `extension=intl` in `php\php.ini`.
- Composer 2
- A PDO driver for whichever database you use. `pdo_sqlite` is enough to run
  the test suite, which includes real database integration tests.
- `ext-fileinfo` if you accept uploads — `UploadPolicy` uses it to check a
  file's contents against its name, and says so rather than passing silently.

`phpstan.neon` analyses across 8.2–8.5, which reports syntax newer than 8.2
on every run whatever PHP you have, and an architecture test fails if that
range and `composer.json` ever stop agreeing. CI runs the full gate on 8.2–8.5
and, separately, installs `--no-dev` on 8.2 to lint, boot and serve a request.

## Quick start

```bash
composer install
composer check          # coding standard + static analysis + tests
composer serve          # http://127.0.0.1:8080
php bin/console         # the command list; or: composer console
```

Under XAMPP the application answers at `http://localhost/framework/` with no
configuration: the base path is derived from `SCRIPT_NAME`, so the same code
runs unchanged in a subdirectory, at a domain root, and under `php -S`.

A fresh installation ships one module, `modules/Shared`, and a default template.
It answers two pages, both rendered by Twig through the default layout:

```bash
curl -i http://127.0.0.1:8080/               # the default home page
curl -i http://127.0.0.1:8080/no/such/page   # the default 404 page
curl -i -H 'Accept: application/json' \
        http://127.0.0.1:8080/no/such/page   # the same 404, as an error document
```

Replace the home page by declaring a `/` route in a module of your own (see
[The default pages](docs/reference/templates.md#the-default-pages)), and look at what is wired:

```bash
php bin/console module:list
php bin/console route:list
```

There is no demo application in `modules/`. The plugin and gateway the
documentation's examples call `Example` exist as a test fixture, under
`tests/Fixtures/Showcase/`, where the feature tests boot them through every
subsystem; they are a reference to read, not something an installation carries.

## Architecture

```
index.php
   -> engine/bootstrap.php        builds the container, picks an execution context
   -> Application::boot()         discover -> load -> register -> boot -> ready
   -> HttpKernel::handle()        Request -> Response, pure
        -> Router                 static hash map, then segment trie
        -> Dispatcher             resolve handler, inject, convert the result
        -> Hooks / Filters        the extension points
```

The same kernel serves browser requests and REST. The console shares the same
bootstrap with a different execution context.

## Documentation

Everything else is in [`docs/`](docs/README.md), by what you are doing:

| You want to | Start with |
|---|---|
| learn the framework | [Getting started](docs/getting-started.md) — a module from nothing, in half an hour |
| build something | the [guides](docs/README.md#guides): pages and forms, JSON APIs, storing data, background work, users and permissions, extending modules, testing |
| look up how a part works | the [reference](docs/README.md#reference), one page per subsystem |
| deploy and run it | [Running in production](docs/operations/running.md), [Deployment and security](docs/operations/deployment.md), [Troubleshooting](docs/troubleshooting.md) |
| change the framework | [Contributing](docs/contributing/README.md) |

Before deploying anything: the shipped `shared` module contains **demo accounts**
that must be replaced — see [Users and permissions](docs/guides/users-and-permissions.md).

## License

MIT. See [LICENSE](LICENSE).
