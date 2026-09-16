# Project layout

```
index.php              front controller, three statements
server                 dev router for php -S (denied by the web server)
bin/console            CLI entry point
.env.example           every environment variable, with its assumed value
config/                this installation's decisions; absent until there is one
                       (config/plugins/Billing.php configures plugins/Billing)
engine/                the framework
  bootstrap.php        builds the application for either context
  Bootstrap/ Core/ Container/ Http/ Routing/ Dispatch/
  Module/ Hook/ Filter/ Model/ Schema/ Data/ Database/
  Asset/ Template/ Support/ Config/ Error/ Logging/ Cli/
  Cache/ Queue/ Scheduler/ Security/ Observability/
assets/                the application's own css, js and images
templates/default/     the active template: views/ and assets/
  views/layout.twig    the layout every default page extends
  views/home.twig      the front page until a module claims /
  views/errors/        the 404 and generic error pages
modules/
  shared/              cross-module capability, registers first; owns /
    Model/User.php     a shared domain model
    Auth/              what a user IS here: the provider and the login routes
    Schema/            the pagination contract every list endpoint shares
    Data/              a shared repository
  plugins/<Name>/      a plugin module; the directory appears with the first one
  gateways/<Name>/     a gateway module; likewise
system/                cache, logs, sessions, queued work (not web-readable)
  Cache/               compiled configuration, the module list, cached data
  Queue/               jobs waiting for a worker, and the ones that failed
  Schedule/            one file per schedule lock, while it is held
  Security/            rate-limit counters, one file per key
  Sessions/            one file per session, named by hash rather than by id
  Logs/                where the file writer puts records
tests/
  Benchmark/           composer bench; denied to the web server with the rest of tests/
  Fixtures/Showcase/   plugins/Example and gateways/Example: every subsystem in
                       one application, booted by the feature tests
```

Directories are created when they are used, never in advance.

## Autoloading

| Directory | Namespace |
|---|---|
| `engine/` | `App\Engine\` |
| `modules/shared/` | `App\Modules\Shared\` |
| `modules/plugins/` | `App\Modules\Plugins\` |
| `modules/gateways/` | `App\Modules\Gateways\` |

A new module autoloads with no `composer.json` change and no custom autoloader:
`modules/plugins/Billing/Api/Invoices.php` declares
`App\Modules\Plugins\Billing\Api\Invoices` and simply works.

Two consequences:

- **Directory casing under `modules/` is load-bearing on Linux.** Windows will
  not catch a mismatch between a directory name and its namespace segment. The
  Linux CI job is the enforcement mechanism; do not skip it.
- **Never use `composer dump-autoload --classmap-authoritative` in production.**
  It disables the PSR-4 fallback and breaks any module added after the dump.
  `--optimize` alone is fine and recommended.
