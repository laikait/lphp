# Modules

A module owns a business capability and declares itself in one file:

```php
<?php // modules/Plugins/Customer/module.php

return static function (ModuleContext $module): void {
    $module->name('Customers')->version('1.0.0');

    $module->config(['page_size' => 25]);

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(CustomerCatalog::class);
    });

    $module->routes(static function (RouteCollector $routes): void {
        $routes->get('/customers', ListCustomers::class)->name('customers.index');

        $routes->group('/api/v1', static function (RouteCollector $routes): void {
            $routes->get('/customers/{id}', [CustomerApi::class, 'show'])
                ->where('id', '\d+')
                ->name('customers.show');
        }, name: 'api.v1.', meta: ['api' => true]);
    });

    $module->hook('customer.created', [CustomerHooks::class, 'onCreated'], priority: 20);
    $module->filter('customer.name', [CustomerFilters::class, 'normalise']);

    $module->onBoot(static function (CustomerCatalog $catalog): void {
        // Every module has registered by now. Dependencies are injected.
    });
};
```

There is nothing to extend and nothing to implement. `ModuleContext` is the
whole API a module author learns.

**Everything above is recorded, not executed.** When the closure returns,
nothing has been bound, routed or hooked. That is what makes ordering
deterministic rather than dependent on the order the filesystem returned
directories.

## Lifecycle

| Stage | What happens | What is illegal |
|---|---|---|
| **Discover** | The configured roots are scanned for `module.php` — or the discovery cache is read. No module code runs. **A request for `/assets/...` stops here.** | — |
| **Load** | Each closure runs and records its declarations. | Resolving services, firing hooks, I/O |
| **Resolve** | Dependencies are checked across every module at once and the registration order is fixed. Disabled modules were already skipped at Load. | — (no module code runs) |
| **Register** | Declarations are replayed across all modules **by category**: config → services → routes → hooks → filters. | Reading from the container (impossible) |
| **Boot** | `onBoot` callbacks run in module order with dependencies injected. | Declaring anything new |
| **Ready** | `app.booted`, then `app.ready`. | — |

Replaying **by category rather than per module** is the important detail: all
config is merged before any service factory is defined, and every service is
bound before any route is registered. That removes the ordering bugs service
providers are known for.

Module order is `shared` → `plugins/*` → `gateways/*`, and within a kind by
directory name — adjusted only where a dependency forces it (see below). Never
filesystem order. `shared` always registers first, which is what makes it
genuinely shared.

## Dependencies between modules

```php
return static function (ModuleContext $module): void {
    $module->name('Payment')->version('1.3.0');

    $module->requires('plugins/Billing', '^1.2')
           ->optionally('plugins/Crm', '^2.0');
};
```

Declared in the module's own file, for the reason everything else is: installing
a module brings its requirements with it, and a reviewer sees them beside the
routes that rely on them. Modules are named **by id**, never by bare name — a
plugin and a gateway may share a directory name.

**Four refusals, all at boot**, each naming both modules:

| | |
|---|---|
| **missing** | required and not installed — with a "did you mean" when it is plausibly a typo |
| **disabled** | installed, but listed in `modules.disabled` — a different fix from "missing", so a different message |
| **version conflict** | installed, and its `version()` does not fit the constraint |
| **circular** | no order exists; the circle is printed: `plugins/A -> plugins/B -> plugins/A` |

A web client sees a generic 500; an operator at the console sees the whole
message, because the framework wrote every word of it. A dependency problem found
at runtime is found by whichever request first touches the missing piece; found
at boot, it is found by whoever deployed.

**The order changes only where a dependency forces it.** Resolution is a stable
topological sort: at each step it takes, of the modules whose dependencies are
all placed, the one that came first in kind-then-name order. An application that
declares nothing registers exactly as before, and one declaration moves exactly
one module — the one that has to wait. Within each registration category a
module is registered after everything it requires, which is visible from inside
a module: two listeners at the same priority run in that order.

**Kind order is never broken**, and that is a fifth refusal rather than a hope. A
plugin depending on a gateway, or `shared` depending on anything, would either
reorder across kinds — breaking "every module may rely on `shared` without
saying so" for modules that never mentioned it — or be unsatisfiable. Refusing it
means the sort only ever moves modules *within* their own kind, so `shared`
registers first by construction.

**Optional means "works without it", not "any version will do".** An optional
dependency that is present and enabled is held to its constraint and orders
registration exactly like a required one; only its absence is forgiven. The
showcase gateway uses one honestly: it listens to `customer.created`, which only
`plugins/Example` fires. Without that plugin the listener is never called — but a
`plugins/Example` 1.0 that changed the event's payload should stop the
application rather than surprise the listener.

To act on whether an optional partner is there, listen for its hooks: they
simply never fire without it, and need no check. When that is not enough, inject
`ModuleRegistry` in an `onBoot` callback and ask `isEnabled()`.

## Versions and constraints

`version()` is **exactly `MAJOR.MINOR.PATCH`**, checked where it is written. It
was decorative until modules could depend on each other, and a version that
cannot be compared is a check that cannot be made. No `v` prefix and no
pre-release suffix — their ordering rules are the part of semver everybody gets
subtly wrong, and a module under development is `0.x`, which the caret already
treats as unstable.

Constraints are **a subset of Composer's syntax that means exactly what Composer
means**, and the rest is refused:

| | |
|---|---|
| `*` | any version |
| `1.2.3` | exactly that |
| `^1.2` / `^0.3` | `>=1.2.0 <2.0.0` / `>=0.3.0 <0.4.0` |
| `~1.2` / `~1.2.3` | `>=1.2.0 <2.0.0` / `>=1.2.3 <1.3.0` |
| `>=1.2 <2.0` | both (space or comma) |
| `^1.0 \|\| ^2.0` | either |

**A bare partial version like `1.2` is refused**, with both spellings offered.
Composer reads it as exactly `1.2.0`; the person who typed it almost always meant
"1.2-ish"; and the disagreement stays invisible until `1.2.1` is installed and
the application will not boot.

Why modules need this when Composer exists: modules under `modules/` are not
Composer packages. They are directories in one repository, and nothing else is
going to check that `plugins/Payment` still fits the `plugins/Billing` beside it.

## Disabling a module

```php
// config/modules.php
return ['disabled' => ['gateways/Stripe']];
```

A disabled module is **installed but never runs** — not its `module.php`, not
its boot callbacks, not its listeners, and its assets are not published. It stays
known to the registry so "disabled" and "missing" can be told apart, and
`module:list` shows it beneath the table. Anything that *requires* it refuses to
boot; anything that uses it *optionally* carries on without it.

```
  ID                KIND      NAME             VERSION  ...  REQUIRES
  shared            shared    Shared           0.1.0    ...  -
  gateways/Example  gateways  Example Gateway  0.1.0    ...  plugins/Example? ^0.1 (absent)

Disabled (installed, switched off in modules.disabled): plugins/Example
```

Two refusals of its own: an id that is not installed, because a typo would leave
the module running while the configuration says it is off; and `shared`, because
every other module may rely on it without declaring so.

**Nothing about resolution is cached.** The discovery cache still holds only what
discovery found — ids and paths, disabled modules included — and an architecture
test pins its shape. Disabling is configuration applied after the cache is read,
so switching a module off never needs the cache cleared, and a cached dependency
graph would be stale the first time somebody edited a `module.php`. Resolving a
few dozen modules in memory costs microseconds.

**A module that uses another module's classes must declare it**, and an
architecture test reads the code to check. Without the declaration it still
works — until the other module is disabled or upgraded, when it fails with a
class-not-found on whichever request first touches the import, instead of
refusing to boot with both modules named.

## Why this is not a service provider

A service provider is a class you subclass, whose `register()` and `boot()`
receive the whole container, and which becomes a dumping ground. All three are
broken here:

- **Nothing to subclass.** A module is a closure in a file.
- **Registration cannot read.** `ServiceRegistrar` exposes `bind`, `singleton`,
  `instance` and `factory`, and no read method at all. Service location during
  registration is impossible *by construction*, not by convention.
- **Boot cannot reach the container either.** `onBoot` callbacks are invoked
  through the container, so dependencies arrive as parameters. There is no
  `$app` handle to reach for.
