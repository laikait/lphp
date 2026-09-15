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
> yet — see [API stability](#api-stability), [What is not built](#what-is-not-built)
> and [Implementation status](#implementation-status).

## Requirements

- PHP 8.2 or newer, with `ext-json`, `ext-mbstring` and `ext-pdo`
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

A fresh installation ships one module, `modules/shared`, and a default template.
It answers two pages, both rendered by Twig through the default layout:

```bash
curl -i http://127.0.0.1:8080/               # the default home page
curl -i http://127.0.0.1:8080/no/such/page   # the default 404 page
curl -i -H 'Accept: application/json' \
        http://127.0.0.1:8080/no/such/page   # the same 404, as an error document
```

Replace the home page by declaring a `/` route in a module of your own (see
[The default pages](#the-default-pages)), and look at what is wired:

```bash
php bin/console module:list
php bin/console route:list
```

There is no demo application in `modules/`. The plugin and gateway the examples
in this document call `Example` exist as a test fixture, under
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

### Modules

A module owns a business capability and declares itself in one file:

```php
<?php // modules/plugins/Customer/module.php

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

#### Lifecycle

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

#### Dependencies between modules

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

#### Versions and constraints

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

#### Disabling a module

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

#### Why this is not a service provider

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

### Hooks and filters

```php
add_hook('customer.created', $callback, priority: 20);
do_hook('customer.created', $customer);

$total = apply_filter('invoice.total', $total, $invoice);
```

A **hook** announces that something happened; return values are ignored. A
**filter** transforms a value; the callback must return one.

Three behaviours differ deliberately from WordPress:

1. **Snapshot iteration.** A listener registered while a hook is running does
   not join that run. Determinism beats the trick.
2. **Recursion is capped** at 64 levels, so two modules filtering each other
   produce a readable error instead of a stack overflow.
3. **Debug-mode null guard.** A filter returning `null` for a non-null value
   throws and names the callback. In production the value is used as-is.

Equal priorities fire in registration order, and registration order is fixed by
module order, so ordering is a total order with no ties.

**Array callbacks are static calls**, as they are everywhere in PHP. To listen
with an instance method, register from `onBoot`, where the instance can be
injected:

```php
$module->onBoot(static function (Auditor $auditor, HookEngine $hooks): void {
    $hooks->add('customer.created', [$auditor, 'record'], 10, 'plugins/Audit');
});
```

Registering a non-static method as `[Class::class, 'method']` is rejected at
registration time with a message pointing here, rather than fatalling weeks
later when the hook first fires.

### Helpers are not facades

The framework bans facades and provides ten global functions. Those are only
contradictory if "reachable globally" and "facade" mean the same thing.

A facade is a class with `__callStatic` resolving **arbitrary** services from a
global container, producing call sites no static analyser can type, and growing
one class per service. What exists here is a **closed set**, each member with a
concrete typed signature, covering only the subsystems the specification says
authors reach globally:

```
add_hook    do_hook      remove_hook    has_hook
add_filter  apply_filter remove_filter  has_filter
asset       template
```

`asset()` and `template()` return their manager rather than doing the work,
because each API is several verbs and more global functions would be worse than
one. The return type is a single concrete class either way, so the call site
stays exactly as analysable as an injected one.

The set is now **complete**: the specification mandates no global beyond these.

What decides membership is a rule, not a number: a subsystem is here when the
specification says authors reach it globally, because those are the places with
no constructor to inject into. A count would only ever be one commit from being
the next number. Three rules keep that honest, all enforced by tests in
`tests/Architecture`:

1. `Support\Extensions` exposes exactly `hooks`, `filters`, `assets` and
   `templates`, and the test asserts the **names**.
2. No file under `engine/` may call a global helper, `helpers.php` excepted.
   Engine code takes its collaborators by constructor injection.
3. Nothing in `Extensions` may take a string. A lookup by name is a service
   locator, whatever the class is called.

The helpers exist for `module.php` files, templates and one-off extension code
— places with no constructor to inject into. Module *classes* should prefer
injection, as the ones in `modules/shared/` and the showcase do.

### Routing

Matching is two-tiered: an O(1) hash lookup for static paths, then a segment
trie for parametric ones, compiled lazily on first match.

The trie is preferred over combined regexes because it is what route caching
wants (nested arrays that `var_export()` and come back through `require`),
because per-segment constraints stay cheap, and because a large PCRE
alternation over hundreds of routes is a real backtracking risk while segment
walking is linear in path depth regardless of route count.

A literal segment always beats a parameter at the same depth, so static routes
win deterministically with no ordering rules to remember.

**There is no middleware**, anywhere. Cross-cutting behaviour is a lifecycle
hook reading route metadata — which is exactly how the framework's own `auth`,
`can`, `csrf` and `rate_limit` keys work (see [Security](#security) and
[Authentication and authorization](#authentication-and-authorization)). An
application adds its own the same way:

```php
$routes->post('/invoices/run', $handler)->meta(['maintenance' => 'blocked']);

$hooks->add('dispatch.before', static function (Route $route) use ($settings): void {
    if ($route->metaValue('maintenance') === 'blocked' && $settings->bool('billing.frozen')) {
        throw new HttpException(503);
    }
});
```

### Handlers

Three forms, no base class, no controllers directory:

```php
$routes->get('/customers', ListCustomers::class);              // __invoke
$routes->get('/customers/{id}', [CustomerApi::class, 'show']); // method
$routes->get('/ping', static fn (): Response => new Response('pong'));
```

Route parameters bind to handler arguments **by name**; the `Request` binds **by
type**, so a route parameter called `request` cannot displace it. A declared
`int`/`float`/`bool` is coerced only when the conversion is unambiguous —
anything else is a clean 400, never a `TypeError` 500.

Return values become responses: `Response` passes through, `string` becomes
HTML, `array`/`JsonSerializable` becomes JSON, `null` becomes 204, and anything
else is an explicit failure rather than a guess.

### Models

A model is domain state and the behaviour that guards it. It is **not** an
active record: there is no `save()`, no `delete()`, no static `find()`, no query
builder and no lazy relationship property.

```php
final class Customer extends Model
{
    public function __construct(
        private ?int $id,
        private string $name,
        private string $email,
        private ?int $ownerId = null,
        private bool $active = true,
    ) {}

    public function identity(): ?int { return $this->id; }

    public function deactivate(): void { $this->active = false; }
}
```

Ordinary typed properties, a constructor that can refuse bad state, domain
methods instead of setters. What the engine adds is the part persistence cannot
infer:

**Change tracking.** `markClean()` snapshots the declared properties; `changes()`
reports what has diverged. A repository writes the changed columns rather than
every column, and it gets that from `deactivate()` without a single setter —
tracking reads the properties instead of intercepting the writes. A model that
has never been clean reports all of its attributes, so `changes()` serves an
insert and an update alike.

**Explicit relations.** A `Relation` is a declaration and nothing more. Loading
is written out where it can be seen:

```php
$relations->declare(Customer::class,
    Relation::one('owner', User::class, localKey: 'ownerId', foreignKey: 'id'));

$customers = $catalog->all();                                      // one query
$owners = $users->findAll($relations->keysFor($customers, 'owner')); // one more
$relations->link($customers, 'owner', $owners);                    // none
```

`$customer->related('owner')` returns what was attached and **throws** if
nothing was. There is no lazy loader to trigger by accident, so a loop over ten
thousand customers cannot quietly become ten thousand queries. A relation that
was loaded and found nothing is attached as `null` or an empty collection, so
"empty" and "never loaded" stay distinguishable.

**Identity.** `ModelManager` hydrates rows by matching them to the model's
constructor **by parameter name**, converting only where the conversion is
unambiguous — which matters because a driver may return every column as a
string. Within one unit of work the same row hydrated twice is the same object,
so a change made through one reference is visible through the other.

> The identity map is memory. A long-running worker must call
> `ModelManager::flush()` between units of work, or state from one job is
> visible to the next.

**Read models.** Domain models are deliberately not serialisable — a domain
entity is not an API representation. A list endpoint projects instead:

```php
final class CustomerListRecord extends ReadModel
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
    ) {}
}
```

Three columns, no tracking, no relations. The fields in the payload are the ones
someone chose to put there.

Business models never live in `engine/Model/`, which holds infrastructure only.
They belong to the module that owns the capability, or to `modules/shared/` when
genuinely more than one module needs them. Both rules are enforced by tests.

### Schemas

A schema describes the shape of structured data — a request body, a response
representation, a configuration block, an API contract. It is not tied to a
model and not tied to a table.

```php
final class CustomerSchema
{
    public static function input(): Schema
    {
        return Schema::of('customer.input',
            Field::string('name')->length(1, 120),
            Field::string('email')->check(
                'an email address',
                static fn (mixed $v): bool => \is_string($v)
                    && \filter_var($v, \FILTER_VALIDATE_EMAIL) !== false,
            ),
        );
    }

    public static function resource(): Schema
    {
        return self::input()->with(Field::int('id'))->named('customer.resource');
    }
}
```

Typed field constructors, not `'required|string|max:255'`. A rule string is
shorter to type and worse in every other way: nothing checks it, an editor
cannot complete it, a typo is found by a user, and it needs a parser for a
second language inside the first. Here a mistake is a PHP error — and a
constraint that cannot mean anything, like a `length()` on an integer, is
refused **while the schema is built** rather than on the first request that
happens to exercise the field.

**This is not a form-request object.** It is not a base class anyone extends, it
is not resolved out of a handler signature, and it throws no status codes. The
schema layer cannot reference `Http`, `Routing`, `Dispatch`, `Model`, `Container`
or `Module` at all — enforced by an architecture test, so it cannot grow into one
later. A handler calls a schema where it chooses and decides itself what a
failure means.

Three operations:

| | |
|---|---|
| `validate()` | collects everything wrong, throws nothing |
| `deserialize()` | data from outside — converts, applies defaults, **drops unknown keys**. A failure is the sender's fault |
| `serialize()` | data going outside — same shaping, but a failure means the application broke its own promise, which is a bug and is reported as one |

Validation does not stop at the first problem, and every error carries a path,
so nested data reports `address.postcode` and `contacts.2.email` rather than
"validation failed":

```json
{"error": {"status": 400,
  "fields": {"name": ["is required"], "email": ["must be an email address"]},
  "expected": {"name": {"type": "string", "min": 1, "max": 120}, …}}}
```

The `expected` block is `Schema::describe()` — the contract as plain data,
derived from the same declaration that enforced it, so documentation cannot
drift from behaviour.

`deserialize()` returns an **array**, not an object. That is what keeps a schema
from becoming a model factory:

```php
$attributes = CustomerSchema::input()->deserialize($request->json());
$customer = $models->hydrate(Customer::class, $attributes);
```

Neither side knows about the other, and dropping unknown keys means a client
cannot set a field you did not declare.

Fields are immutable values, so one declared once can be reused across schemas.
**Required is the default** — a field you declared and forgot to mark fails
loudly rather than going quietly missing. `null` and absent are different
questions: `nullable()` governs one, `optional()` and `default()` the other.

The constraint set is closed on purpose: `range()`, `length()`, `size()`,
`pattern()`, `oneOf()`, and `check()` for everything else. There is no `email`,
`url`, `uuid` or `date` rule, because that list never ends and every entry is an
opinion someone disagrees with — `check('an email address', …)` is one line and
the engine keeps no view on what a valid postcode looks like in your country.

### Repositories and queries

`DataSource` is the whole seam between the data layer and storage — five
methods, no connection, no statement, no dialect:

```php
fetch(Query): iterable   count(Query): int
insert(collection, key, row)   update(...)   delete(...)
```

`ArraySource` implements it in memory, and it is not a toy: a repository tested
against it runs the real repository, the real query and the real hydration at
full speed, with no database to install and nothing to clean up between tests.
`SqlSource` is the PDO-backed implementation (see
[Running the data layer on it](#running-the-data-layer-on-it)), and nothing above
this interface changes between the two.

**A repository is written, never generated.** The base class publishes *nothing*
but its constructor — an architecture test enforces it — so every public method
on a repository is one somebody named after something the application does:

```php
final class CustomerRepository extends Repository
{
    protected function model(): string      { return Customer::class; }
    protected function collection(): string { return 'customers'; }

    public function findByEmail(string $email): ?Customer
    {
        return $this->query()->whereIs('email', $email)->first();
    }

    public function register(array $attributes): Customer
    {
        return $this->persist(new Customer(null, $attributes['name'], $attributes['email']));
    }
}
```

`query()`, `persist()`, `remove()` and `hydrate()` are `final protected` — the
plumbing, not the API. There is no inherited `find()`, `findAll()`, `save()` or
`delete()`, because a base with all of those is a table gateway with a longer
name: it says nothing about the domain and grows a method per column.

An update writes **only what changed** — that is what the model layer's change
tracking is for. `persist()` returns what is now stored, because a new model has
no identity until it is written; reading the row back beats every model exposing
a writable identity for the engine's benefit.

**A query is built lazily and immutably.** Nothing is read until a terminal
method is called, so a repository can hold a base query and hand out narrowed
copies without one caller changing what the next one sees.

```php
$query->whereIs('active', true)
      ->where('balance', Operator::Gt, 0)
      ->orderBy('name')
      ->limit(50);
```

**How much hydration is the caller's choice**, and that is the point:

| | |
|---|---|
| `rows()` `firstRow()` | plain arrays, nothing built |
| `column()` `value()` | one field — and it selects only that field |
| `count()` `exists()` | no rows read at all |
| `into()` `firstInto()` `pageInto()` | read models, reading only the columns they declare |
| `get()` `first()` `stream()` | full domain models, hydrated and identity-mapped |
| `page()` `chunk()` | batches, with the totals a pager needs |

A list screen that builds ten thousand domain objects to show three columns is
the most common way a fast query becomes a slow page, so the cheap options are
first-class rather than an optimisation to find later. `pageInto()` derives its
column list from the read model's own constructor, so selecting the right
columns cannot drift from the fields being built.

Two things a query deliberately does not have:

- **No OR.** Criteria combine with AND. A general boolean tree is the point at
  which a query builder becomes a query language, with its own precedence rules
  and its own bugs. A read that genuinely needs one is a named repository method
  over SQL the database layer runs directly.
- **No join.** A join is a relational idea and a five-method interface cannot
  honour it for every kind of source. Data from two places is loaded in two
  queries and linked explicitly — which the model layer already does, and which
  cannot degrade into N+1 the way a lazy association can.

Transactions, connections and nested-transaction strategy belong to the database
layer — `Connection::transaction()`, below. A `transaction()` on `DataSource`
would make every source pretend to have one, including the array in a test.

#### Bulk writes

A billing run that marks four thousand invoices overdue should be one statement,
not four thousand loads, change sets and single-row updates. Row-at-a-time is the
right default for domain work, because rules run per object; it is the wrong
tool for a set. So a repository has three more pieces of `final protected`
plumbing, and — like the rest — names its own public methods around them:

```php
public function import(array $rows): int
{
    return $this->insertMany($rows);                  // as few INSERTs as the driver allows
}

public function deactivateOwnedBy(int $ownerId): int
{
    return $this->updateWhere($this->query()->whereIs('ownerId', $ownerId), ['active' => false]);
}

public function purgeInactive(): int
{
    return $this->deleteWhere($this->query()->whereIs('active', false));
}
```

They live on a second interface, `BulkWrites`, rather than as three more methods
on `DataSource`. `DataSource` is five methods on purpose — it is the seam a
non-relational store can implement — and a source that cannot write a set is
still a perfectly good source. Ask one for a bulk write and the repository says
so by name instead of looping quietly. Both shipped sources implement it, and a
conformance suite runs every behaviour below against both.

What is refused, and why:

| | |
|---|---|
| a query with an order, a limit, an offset or a column list | a bulk write touches every row the criteria match and nothing else about the query applies. `UPDATE ... LIMIT` means different things on different databases |
| a query with **no criteria** | "change every row" should be written on purpose — `whereNotNull('id')` — not arrived at by a filter somebody forgot |
| rows naming different columns | a multi-row `INSERT` has one column list, and filling the gap with `NULL` would override the column's default |

Three consequences worth knowing:

- **No identities come back** from `insertMany()`. Returning them portably means
  one statement per row again, which is the cost being avoided.
- **Loaded models are forgotten** after `updateWhere()` and `deleteWhere()`. The
  database changed underneath objects the identity map would otherwise keep
  handing out.
- **No transaction is opened.** A large insert is split to stay under the driver's
  placeholder limit (999 on SQLite, 2000 on SQL Server, 65535 on MySQL and
  PostgreSQL), and whether a failure in the third statement should undo the first
  two is the caller's decision — which is where the specification puts it:

```php
$connection->transaction(fn () => $customers->import($rows));
```

### The database

`ConnectionManager` holds the configured connections; `Connection` is one of
them. Heavy backends rarely have one database — a reporting replica, a legacy
system being migrated from, a separate ledger — so each is a name:

```php
$connections->connection('reports')->select('SELECT ...', [$id]);
```

**Connecting is lazy.** Building a `Connection` opens nothing; the PDO handle is
created on first use. A request that never reads the database never opens a
socket, which is what makes it reasonable to declare every database an
application *might* touch.

**Nothing here knows what a model is.** A `Connection` is for a report, a
migration or a one-off script as much as for a repository — enforced by an
architecture test. The data layer sits on top of it rather than being the only
way in.

```php
$connection->select('SELECT * FROM invoices WHERE customer_id = ?', [$id]);
$connection->selectOne(...);  $connection->scalar(...);  $connection->cursor(...);
$connection->execute(...);    $connection->insert(...);
```

Every method takes SQL and a **separate** list of bindings, and prepared
statements are real rather than emulated (`ATTR_EMULATE_PREPARES => false`) —
which matters twice: emulation interpolates values into the statement string,
and it hands every column back as a string. A failing statement reports the SQL
and the *number* of bound values, never the values themselves; a bound value is
the one thing in a query likely to be a password.

**Transactions are the application's boundary**, not each repository method's:

```php
$connection->transaction(function (Connection $db) use ($invoice): void {
    $invoices->store($invoice);
    $entries->post($invoice);
    $payments->record($invoice);
});
```

PDO has no nested transactions — a second `beginTransaction()` throws or is
ignored, and the first `commit()` writes everything. `begin()` uses **savepoints**
beyond the first level, so nesting means what it looks like. The caveat worth
knowing, and it is inherent to nested transactions everywhere: rolling back to a
savepoint undoes the inner work and leaves the outer transaction open, so if the
calling code swallows the exception, the outer transaction still commits with
the inner work gone. Catch deliberately or not at all.

### Running the data layer on it

`SqlSource` implements `DataSource`, so every repository, query, read model,
relation and page from the previous section runs against a database **unchanged**.
Switching is one factory in a module:

```php
$services->singleton(DataSource::class, static function (Container $c): DataSource {
    $connections = $c->get(ConnectionManager::class);

    return $connections->isConfigured()
        ? new SqlSource($connections->connection())
        : new ArraySource();
});
```

That is the only place in the application that knows which kind of storage it
has. The framework does not bind `DataSource` itself — which source an
application reads through is its decision, not the framework's, and that is
architecture-tested too.

`SqlSource::fetch()` returns a **cursor**, so `Query::stream()` and
`Query::chunk()` genuinely hold one row at a time rather than a table.

### The injection boundary is one file wide

No database accepts a parameter where a column goes, so table and column names
are the one part of a statement built by interpolation. All of that lives in
`Grammar`, and every identifier passes one check first:

```
/^[A-Za-z_][A-Za-z0-9_]*$/
```

Anything else — a quote, a space, a dot, a comment, a semicolon, a function
call — is refused with an exception naming it. Names reach the Grammar from
repository declarations rather than from requests; the check is what keeps that
true on the day somebody passes a sort column straight from a query string. An
architecture test asserts that no other file in `engine/Database/` builds SQL at
all.

Limits and offsets are written in rather than bound: they are typed `int` in PHP
by the time they arrive, so there is nothing to inject, and binding them is the
one thing several drivers get wrong.

### Testing against a database

The database tests are real integration tests against SQLite in memory — real
PDO, real prepared statements, real savepoints — costing about a millisecond
each and needing nothing installed. `tests/Feature/SqlSourceTest.php` runs the
same repository against `ArraySource` and `SqlSource` and asserts the answers
are identical, which is the `DataSource` claim stated as a test rather than as a
paragraph.

### Assets

`asset()` builds public URLs; nothing an application writes mentions a
directory, a hash or a manifest.

```php
asset()->core('js/app.js');                    // /assets/core/js/app.js?v=9c81f4a2
asset()->template('css/app.css');              // the active template
asset()->template('admin', 'css/admin.css');   // a named one
asset()->plugin('Example', 'js/example.js');   // /assets/plugin/Example/js/example.js?v=...
asset()->gateway('Stripe', 'js/stripe.js');
```

There are four namespaces and no fifth. A URL names a namespace and a path
inside it, and the set of namespaces is finite, enumerable and decided at boot —
which together are what "assets must never expose physical application
directories" means in practice.

The unnamed template namespace is the **active** template's `assets/`, not a
shared `templates/assets/` as the specification's mapping table draws it: the
stylesheet a page asks for with `asset()->template('css/theme.css')` has to
change when `APP_TEMPLATE` does, or switching templates would keep the old
look.

| URL prefix | Directory |
|---|---|
| `/assets/core/` | `assets/` |
| `/assets/template/` | `templates/<active>/assets/` |
| `/assets/template/admin/` | `templates/admin/assets/` |
| `/assets/plugin/Example/` | `modules/plugins/Example/assets/` |
| `/assets/gateway/Stripe/` | `modules/gateways/Stripe/assets/` |

**A module is published because it has an `assets/` directory**, not because it
asked to be. There is nothing about assets in any `module.php`. That is a
deliberate asymmetry with everything else a module declares: the URL space is
`/assets/plugin/<name>/` for every plugin, so a declaration could only ever say
"yes" or be wrong. `php bin/console asset:list` shows what ended up published.

The shared module is excluded. Its id is just `shared`, with no name of its own,
so no URL could address it; assets belonging to the application as a whole are
the application's own, under `assets/`.

#### Why PHP serves them at all

Because `.htaccess` denies `modules/` outright — it has to, since `module.php`
and every repository lives there. A plugin's `assets/` directory is therefore
unreachable by the web server *by design*, and the asset server is what makes
those files reachable without unlocking the directory holding the source. With a
plugin called `Example` installed that ships `assets/js/example.js`, you can
prove both halves at once:

```bash
curl -i http://localhost/framework/modules/plugins/Example/assets/js/example.js  # 403
curl -i http://localhost/framework/assets/plugin/Example/js/example.js           # 200
```

The application's own `assets/` directory is different: the web server can serve
it directly, and letting it is faster and fully supported. The URL scheme is the
same either way.

#### What is checked before a file is delivered

Every asset path becomes a file in one place, `AssetResolver`, and an
architecture test keeps it that way. The checks run cheapest-first, so hostile
input never reaches the expensive ones:

1. **Syntax.** Every segment must match `[A-Za-z0-9_][A-Za-z0-9._-]*`. That makes
   `..` unrepresentable rather than merely rejected, and keeps `.env`,
   `.htaccess` and `.git` out without naming them. Null bytes, backslashes,
   absolute paths and drive letters are refused here too.
2. **Extension**, against an allow list. `MimeTypes` *is* the list: there is no
   octet-stream fallback, so `.php`, `.phtml`, `.env`, `.ini`, `.sh` and
   everything else nobody enumerated are simply not assets. `app.js.php` is
   judged by its last extension, like every other file.
3. **Existence.**
4. **Containment**, with `realpath()` on both sides. This is the symlink check:
   a link inside `assets/` pointing at `/etc/passwd` passes every step above and
   fails here. Step 1 stops a path from *saying* anything about the outside;
   step 4 stops the filesystem from *meaning* it.

Every refusal is a 404, including the ones that were really "you tried to
traverse" — distinguishing them would confirm to whoever is probing which
attempt got closer. With `app.debug` on, the reason is in the body, because the
person reading it then is the developer who made the typo. No refusal ever names
an absolute path.

HTML is not on the served list. Serving author-supplied HTML from the
application's own origin is stored XSS with extra steps; content that needs to
be a page belongs behind a route. SVG *is* served, because it has to be, and it
goes out under a `Content-Security-Policy` with `default-src 'none'` and
`sandbox`, so that opening one directly in a tab cannot run anything. Everything
gets `X-Content-Type-Options: nosniff`, and the type comes from the extension
rather than from sniffing the content — what a file looks like is whatever
whoever uploaded it made it look like.

#### Versions and caching

`?v=` is a content hash by default. The obvious alternative is modification
time, and it is wrong in exactly the case that matters: deploy tools preserve
timestamps (`rsync -a`, `tar -p`, a checkout of unchanged files), so a changed
file can arrive with an unchanged mtime and every browser keeps the old copy. A
content hash cannot be wrong about whether the content changed. Set
`assets.versioning` to `modified` or `none` if you would rather not pay for it.

A manifest always wins where one exists, because a bundler that renamed
`app.js` to `app.9c81f4a2.js` has already solved this. `assets/manifest.json`
maps logical names to built ones, in either shape:

```json
{ "js/app.js": "js/app.9c81f4a2.js",
  "css/app.css": { "path": "css/app.3f1c.css" } }
```

Application code still writes `asset()->core('js/app.js')`. A manifested URL
carries no `?v=` — the filename already carries the hash.

A URL that carries a version is `public, max-age=31536000, immutable`; one that
does not is `public, max-age=0, must-revalidate` with an ETag. "Immutable" is a
promise about the URL, not the file. `If-None-Match` and `If-Modified-Since` both
produce a 304. Byte ranges are **not** implemented, and the response says
`Accept-Ranges: none` rather than quietly returning the whole file — a video is
not something PHP should be streaming.

#### Resolution and delivery are separate

`AssetManager` resolves and never delivers; it cannot see `Request` or
`Response` at all, and an architecture test enforces it. `AssetServer` delivers.
They share one constant, the `/assets` prefix.

That separation is what the specification asks for, and the reason is different
lifetimes: a URL gets generated in a template, a CLI job or a queued email where
there is no request anywhere, while delivery is one HTTP handler that a web
server or CDN should eventually take over. Pointing `assets.url` at a CDN origin
is a one-line config change that no application code notices.

#### An asset request loads no module

The specification is blunt about it: *"`/assets/...` should not initialize
billing."* It is not. A request under `/assets/` is answered after **discovery
alone** — no `module.php` runs, no service is bound, no `onBoot` fires, no route
is registered. That is possible because publishing was never a declaration:
having an `assets/` directory is the whole of it, and discovery already knows
which modules have one.

You can watch it on a real server. Install a plugin with a route and an asset —
the `Example` one from `tests/Fixtures/Showcase/` will do — make its
`module.php` throw, and its pages fail while its assets do not:

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost/framework/customers.json                     # 500
curl -s -o /dev/null -w "%{http_code}\n" http://localhost/framework/assets/plugin/Example/js/example.js  # 200
```

The decision is made on the raw path, before any filter runs, because the filters
that could change it belong to modules that have not loaded. The engine's own
listeners — the request size limit, the security headers — are attached by the
bootstrap, so they still apply; `app.booted` and every module listener do not.

**The consequence: module code cannot take part in delivering an asset.** Until
Phase 27 `asset.response` was documented as the way to put access control on
assets, with a module filter. That is now refused when the module declares it,
naming the module — because in production the recommended setup has the web
server or a CDN deliver assets without PHP at all, so such a filter was only ever
guaranteed to run in a test. A file that needs a permission check is not a
public asset; serve it from a route, where `meta(['can' => ...])` already
applies. `asset.response` remains for engine-level listeners attached at
bootstrap.

### Templates

```php
template()->render('customer/profile', ['customer' => $customer]);
template()->render('@plugin.Example/invoice', $data);
```

**No extension is written at the call site.** That is not a convenience: it is
what lets a template move from PHP to Twig, or a Twig one be replaced by a PHP
one, without a single caller changing. The manager knows every extension any
registered engine claims and tries them all.

**Rendering produces a string, never a response.** An architecture test keeps
the layer from seeing `Request` or `Response` at all. The handler wraps it:

```php
return (new Response($this->templates->render('layout', $data)))->withContentType('text/html');
```

which is what lets the same template be rendered into an email, a PDF pipeline
or a test assertion.

#### Resolution, and how overriding works

First hit wins, in this order:

| | Directory |
|---|---|
| 1. the active template | `templates/<active>/views/` |
| 2. the override of a namespace | `templates/<active>/views/<namespace>/` |
| 3. the module itself | `modules/plugins/Example/Templates/` |

So a site replaces a plugin's invoice by creating

```
templates/default/views/plugin.Example/invoice.php
```

and the plugin is never edited, asked or told. Its own copy stays as the
fallback, which is what makes it safe for the plugin to keep shipping one.

Only the active template takes part in rule 2. A module must not be able to
override another module by guessing a directory name, or which template wins
would come down to discovery order.

The search is **directory-major, not extension-major**: every extension is
tried in the highest-precedence directory before dropping to the next one. A
theme's `.php` beats a module's `.twig`, and the other loop order would let the
module win by virtue of its file extension.

`php bin/console template:list` prints the whole search path in order, which is
most of the answer to "which file is actually being rendered".

#### Namespaces

A module with a `Templates/` directory gets a namespace, automatically — there
is nothing about templates in any `module.php`, the same bargain as assets.

| Module | Namespace |
|---|---|
| `modules/shared/Templates/` | `@shared/…` |
| `modules/plugins/Example/Templates/` | `@plugin.Example/…` |
| `modules/gateways/Stripe/Templates/` | `@gateway.Stripe/…` |

The dot is not decoration: a Twig namespace cannot contain a slash, and the two
engines have to agree on how a template is named. The shared module *does* get a
namespace here, unlike in the asset layer — a shared partial is an ordinary
thing to want, and unlike a URL there is a name for it.

#### What a PHP template gets

Every key of the data as a local variable, plus `$view` and `$e`. Nothing else:
the file is included from a static closure, so `$this` does not exist inside a
template and the engine's internals cannot be reached from one.

```php
<h1><?= $e($title) ?></h1>
<?php foreach ($customers as $customer): ?>
    <li><?= $e($customer->name()) ?></li>
<?php endforeach ?>
<?= $view->render('partials/pager', ['page' => $page]) ?>
```

`$e` is short on purpose. PHP templates do not escape anything on their own, and
that is the single largest hazard in using them; the framework does not fix it
by inventing a syntax — that road ends at a compiler nobody asked for — it fixes
it by making the correct call short enough that there is no excuse. Escaping is
by context, because it is not one operation:

```php
<?= $e($text) ?>                          <!-- between tags -->
<a title="<?= $e->attr($tip) ?>">         <!-- an attribute -->
<script>const id = <?= $e->js($id) ?>;</script>
<a href="?q=<?= $e->url($term) ?>">
<?= $e->raw($trustedHtml) ?>              <!-- named so it shows in a diff -->
```

Data can never overwrite `$e` or `$view` — a data key called `e` would otherwise
turn every escape call in the application into a call to whatever the handler
passed, which is a security bug with a very long fuse. The shadowed value is
still readable as `$view->get('e')`.

`$view` is deliberately tiny: `render()`, `exists()`, `asset()`, `escaper()`,
`has()`, `get()`, `data()`. It is **not** a handle on the container. A template
that can resolve arbitrary services is a template that can run a query, and then
"what does this page do" stops having an answer you can read in the handler. If
a template needs something, the handler passes it in.

A partial gets exactly the data it is given and never sees its parent's
variables, because a partial that could is a partial whose contract is
"whatever happened to be in scope".

#### Twig by default, PHP second

`twig/twig` is a runtime dependency and the default engine. Bootstrap registers
the Twig engine first and the PHP engine second, and **the order is the
precedence**: where one directory holds both `home.twig` and `home.php`, the
Twig file renders. Directory precedence still comes first — a theme's `.php`
beats a module's `.twig` — so the engine order only decides ties inside one
directory. An architecture test pins both the dependency and the order.

Twig is the default because it escapes everything it prints unless told not
to, which turns a forgotten escape from a hole into a visible `&lt;`. PHP
templates stay because a module that already ships `.php` views should keep
working, and because they need nothing compiled or cached before they run.

Nothing outside `TwigTemplateEngine.php` mentions Twig, and an architecture test
says so: the manager talks to engines through `TemplateEngine`, so replacing
Twig later is one class and one line in Bootstrap.

Twig's loader mirrors the registry — the same directories, the same order,
module namespaces as Twig namespaces — so `{% extends "layout.twig" %}` and
`{% include "@plugin.Example/row.twig" %}` resolve exactly where the manager
would have resolved them, override rule included. If the two disagreed, a
template found by one would be missing to the other.

#### Layouts

A Twig page uses Twig's own inheritance; the default template's pages do:

```twig
{% extends "layout.twig" %}
{% block title %}Welcome{% endblock %}
{% block content %}<h1>Your application is running</h1>{% endblock %}
```

The PHP engine has no `@extends`, `@section` or `@yield`, and will not grow them —
that is the line between a template engine and a reimplementation of Blade. A
PHP page renders to a string and hands it to the layout, and because the default
`layout.twig` prints a `content` value when no block replaces it, that layout
works for PHP pages too:

```php
$content = $this->templates->render('@plugin.Example/customers', ['customers' => $rows]);
$html    = $this->templates->render('layout', ['title' => 'Customers', 'content' => $content]);
```

`content` is printed unescaped, because it is markup another template already
escaped. Every other value in the layout is escaped by Twig.

#### The default pages

```
templates/default/views/layout.twig        shared by every page below
templates/default/views/home.twig          GET /, declared by modules/shared
templates/default/views/errors/404.twig    any path nothing answers
templates/default/views/errors/error.twig  every other error status
```

The front page is an ordinary route in the shared module — there is no global
routes file for it to live in — rendering `home` with the request's `home` URL.
It says nothing about the machine or the version, because a default page is
public by definition.

It is meant to be replaced, and replacing it needs no edit to the framework:

- **Declare `/` in your own module.** Every other module registers after
  shared, and the router keeps the last route declared for a method and path,
  so yours answers. Give it a name other than `home`; route names are unique
  and that one is taken.
- **Or edit `home.twig`**, or switch `APP_TEMPLATE` to a template of your own
  that ships one.

The 404 page is what any unmatched path gets from a browser; a client that
asks for JSON gets the error document instead, and debug mode shows the
built-in diagnostic page so a trace is never hidden behind a pretty one.

#### Templates are not web-readable

`templates/` is denied by `.htaccess` alongside `engine/` and `modules/`, for
exactly the same reason: a PHP view the web server can reach is a PHP file it
will execute, and a Twig view it can reach is source it will hand out. The
active template's assets stay reachable as `/assets/template/…` through the asset
manager, which is the only way in.

### REST

The examples in this section are the showcase plugin's customer endpoints
(`tests/Fixtures/Showcase/`); a fresh installation has none of these routes.

REST is not a subsystem here. There is no `engine/Rest/`, no API kernel, no
`ApiController`, no Resource class and no Transformer — an architecture test
asserts the directories do not exist. A REST endpoint is a handler that returns
JSON, reached through the same router and the same dispatcher as a web page,
and everything below is a convention layered on top rather than machinery
underneath.

```php
final class CustomerApi
{
    public function show(int $id): JsonResponse
    {
        $customer = $this->customers->find($id) ?? throw HttpException::notFound();

        return ApiResponse::item(CustomerSchema::resource()->serialize([...]));
    }
}
```

#### One route, two representations

```bash
curl /customers                                 # the page
curl -H 'Accept: application/json' /customers   # the payload
curl /customers.json                            # the payload, for clients that
                                                # cannot set Accept
```

A browser request and a REST request are the same request arriving with
different Accept headers, so answering both is a branch rather than an
architecture:

```php
if ($request->negotiate(['text/html', 'application/json']) === 'application/json') {
    return ($this->json)($request);
}
```

Offers go in the server's order of preference, and that order breaks a tie the
client did not break. Anything the endpoint cannot produce is a **406** naming
what it could have returned, rather than JSON the client has no way to read.

**Negotiation is a call, not middleware.** A route knows what it can produce;
the framework does not. There is no configuration for it because the offers are
a fact about the endpoint, written next to the endpoint.

#### Quality values are honoured

```
Accept: application/json;q=0.9, text/html;q=0.8
```

A client that sends this wants JSON. Any check of the form "does the header
contain text/html" hands it a web page, and an API quietly serves markup to a
program. `MediaType` parses the header properly — quality, specificity,
parameters, `q=0` as an explicit refusal, `+json` suffixes — and `Negotiator`
ranks the offers against it.

The one rule worth spelling out: when a client names types and includes neither
HTML nor JSON, error bodies come back as JSON. It is not a browser — a browser
would have said `text/html` — so the machine-readable body is the more useful
of the two wrong answers.

#### One error shape

```json
{
  "error": {
    "status": 400,
    "title": "Bad Request",
    "message": "The customer could not be created.",
    "fields": { "name": ["is required"], "email": ["is required"] },
    "expected": { "name": { "type": "string", "required": true } }
  }
}
```

`status`, `title` and `message` are always present, always first, and never
change meaning; `with()` refuses to overwrite them. Anything else is an addition
under its own key, so a client that ignores unknown keys keeps working.

Every error in the application is this shape, because every error goes through
`ErrorDocument`: a handler's validation failure, a 404 from the router, a 415
from a content-type check, a 500 from an uncaught exception. Before it existed
those were built by different pieces of code that agreed by coincidence, and an
architecture test now fails if a second one appears.

```php
return ErrorDocument::validation(
    fields: $result->messages(),
    message: 'The customer could not be created.',
    expected: $schema->describe()['fields'],
)->toResponse();
```

Returned rather than thrown, because the handler has more to say than a status
line. Throwing hands the error to the error handler, which knows the status and
nothing about which fields were wrong.

Outside debug mode an exception's message is replaced wholesale with the status
text rather than filtered — filtering means guessing which substrings are
secret, and that guess is wrong eventually. `HttpException` messages are written
by the framework and survive.

It is deliberately **not** RFC 9457 `problem+json`: that format nests the useful
part next to `type` and `instance` URIs most APIs never populate meaningfully,
and needs a content type tools handle worse.

#### Statuses that mean what they say

| | |
|---|---|
| `ApiResponse::item()` | `{"data": …}` |
| `ApiResponse::collection($rows, $page->meta())` | `{"data": […], "meta": …}` |
| `ApiResponse::created($data, $url)` | 201 with `Location` |
| `ApiResponse::noContent()` | 204, no body and no content type |
| `ApiResponse::accepted()` | 202, for work taken but not done |

Five static factories, and nothing imposes them — the
dispatcher still accepts a plain array, so an application that wants a different
envelope writes one. What they remove is the fifteenth hand-written
`['data' => …]`, not the choice.

`ApiResponse` does **not** know what a `Page` is. `page()` would be the obvious
convenience and would put the data layer inside the HTTP layer; `$page->meta()`
at the call site costs eleven characters and keeps transport ignorant of
storage. Pagination *links* are built by the handler for the same reason —
building one needs the router and the route's own name.

415 and 400 are different failures and are reported differently:

```php
$request->requirePayload();   // 415 if the body is not JSON
```

Without that line a POST whose `Content-Type` is `text/plain` reaches the schema
as an empty payload, and the client is told its fields are missing — which sends
whoever is debugging it to look at the fields instead of at the header. A vendor
type like `application/vnd.example.v2+json` satisfies a JSON requirement;
refusing it would be pedantry rather than safety.

#### Versioning is a prefix, not a subsystem

```php
$routes->group('/api/v1', …, name: 'api.v1.', meta: ['api' => true, 'version' => 'v1']);

$routes->group('/api/v0', …, name: 'api.v0.', meta: [
    'version'    => 'v0',
    'deprecated' => '2026-01-01',
    'sunset'     => '2027-01-01',
]);
```

There is no version negotiator and no version resolver. A URL prefix already
answers "which version" unambiguously, and a second mechanism could only let the
two disagree. What the metadata adds is somewhere for conventions to read from:
the shared module turns it into `X-Api-Version`, `Deprecation` (RFC 9745) and
`Sunset` (RFC 8594) headers, so a client learns an endpoint is going away from
the response rather than from a changelog it never read.

That module filter listens on **`dispatch.response`**, the one point in the
lifecycle where the finished `Response` and the `Route` that produced it both
exist. It is how this framework does cross-cutting response behaviour without
middleware: a filter reads `Route::metadata()` and decorates accordingly, and
adding a second one is a line in `module.php` rather than a place in a pipeline
everything has to pass through.

### CLI

The console boots the same application HTTP does — same container, same
modules, same hooks — and then does what the kernel does for a request: look a
name up in a registry, parse input against what was declared, call a handler
through the container, and turn the result into something the caller
understands. `ConsoleKernel` and `HttpKernel` read as mirror images because
they are.

A command is an ordinary object:

```php
final class SyncCustomers
{
    public function __construct(private readonly CustomerQuery $customers) {}

    public function __invoke(Output $output, string $since, int $limit, bool $dryRun): int
    {
        // ...

        return 0;
    }
}
```

There is **no `Command` base class**, no `$signature` string, no `handle()`
method the framework insists on, and no `$this->argument('since')`. An
architecture test asserts that nothing under `engine/Cli/` is abstract, is an
interface, or has a protected member — because a base class is how a command
stops being an ordinary object. It arrives with `$this->argument()`, then
`$this->output`, then `$this->info()`, and after that a module's command can
only be written one way.

#### Commands are declared where routes are

```php
$module->commands(static function (CommandCollector $commands): void {
    $commands->add('customer:sync', SyncCustomers::class)
        ->describe('Pull customer records from the upstream system.')
        ->argument('since', 'Only records changed on or after this date.', required: false, default: 'yesterday')
        ->option('limit', 'Stop after this many records.', shortcut: 'l', default: '25')
        ->flag('dry-run', 'Report what would change without writing anything.', shortcut: 'd');
});
```

Nothing is scanned. A `Commands/` directory is where these classes happen to
live, not how they are found — a file's mere existence should not change what
an application does, and what a module contributes stays readable in one file.

Arguments and options are declared rather than parsed out of a signature
string. `"{user : the id} {--queue=}"` is a small language embedded in a
docblock: invisible to static analysis, checked when somebody runs it. A method
call per argument is longer to write and is checked by the IDE as it is
written, and the same declaration generates the help.

The handler takes the same three forms a route handler does:

```php
$commands->add('customer:sync', SyncCustomers::class);              // invokable class
$commands->add('customer:show', [ShowCustomer::class, 'show']);     // [class, method]
$commands->add('customer:count', static fn (CustomerQuery $c): string => …);  // closure
```

#### Input binds the way route parameters do

```bash
php bin/console customer:sync 2026-01-01 --dry-run --limit=5
php bin/console customer:sync 2026-01-01 -dl5      # the same thing
```

Declared input arrives as **typed parameters, matched by name**; everything
with a class type comes from the **container, matched by type**. That is the
console's version of "a route parameter called `request` cannot displace the
Request": a command that declares an argument called `output` still gets a real
`Output`.

A dash is not legal in a PHP parameter name, so `--dry-run` binds to `$dryRun`.
The transformation is mechanical, one-way, and only applies to names that
contain a dash.

Coercion goes through the same `Support\Coercion` routing uses, so `"1"` means
the same thing on the command line as it does in a URL, and `--times=lots` for
an `int $times` is a usage error with the synopsis rather than a `TypeError`.

What the parser accepts, and what it does not:

| | |
|---|---|
| `--flag` `--limit=50` `--limit 50` | declared options |
| `-l 50` `-l50` `-abc` | shortcut, attached value, bundled flags |
| `--` | everything after is positional |
| `--lim` for `--limit` | **not** accepted — see below |

Abbreviation is refused because the abbreviation that is unique today becomes
ambiguous the day somebody adds an option, and a script written against it
breaks at a distance. Nor is a suggested name ever run in place of what was
typed: a typo gets `Did you mean "customer:sync"?` and exit 127, and a command
that deletes something is never reachable by a name its author did not write.

`--limit --dry-run` is refused too. Reading `--dry-run` as the value would hide
a forgotten argument behind a nonsensical limit, and the run would look like it
worked.

#### Exit codes mean what a shell expects

| | |
|---|---|
| `0` | it worked |
| `1` | it ran and failed, or something threw |
| `2` | the command line was wrong — missing argument, unknown option |
| `127` | no such command |

The 2/127 split earns its keep: a deployment script that mistypes a command
name and one that forgets an argument are different bugs, and a wrapper that
retries on one should not retry on the other.

A command returns `int` for the code, a `string` to print, or nothing for
success. **A `bool` is refused**: PHP's convention says `true` is success, the
shell's says `0` is, and an exit code that gets it backwards turns a failed job
into a green tick.

Results go to standard output and complaints go to standard error, so
`console route:list | grep customers` carries no warnings and
`console customer:sync 2>errors.log` separates the two.

#### The framework's own commands are not special

```
about            Summarise this application: version, modules, routes, connections.
help             List the available commands, or explain one of them.
asset:list       Every published asset directory and the URL prefix it answers on.
auth:access      Every capability, every role, and which routes check them.
auth:hash        Hash a password, for seeding the first account.
cache:clear      Delete the configuration, module, template and application caches.
cache:warm       Build the production boot path: the configuration and discovery caches.
config:cache     Compile config/ and the defaults into one cached file.
config:list      The configuration this process actually resolved to.
log:status       Where records go, and whether they are getting there.
module:list      Discovered modules, in the order they load.
queue:failed     The jobs that gave up; retry or discard them.
queue:status     What is waiting on each queue, and what has failed.
queue:work       Run queued jobs until told to stop.
route:list       Every registered route and its owning module.
schedule:list    Every scheduled task, when it next runs, and what is running now.
schedule:run     Run whatever is due this minute. This is what cron calls.
schedule:unlock  Held schedule locks; release them after a machine died mid-run.
security:check   Audit what this deployment actually has switched on.
security:key     Print a new APP_KEY.
session:gc       Delete sessions past their lifetime.
session:table    Print the CREATE TABLE the database session store needs.
template:list    The template search path, highest precedence first.
```

They are registered through the same `CommandCollector` a module uses, under
the module name `engine`, and the kernel has no idea they exist. Delete
`CoreCommands` and the console still works with fewer commands.

Every one of them answers a question that is otherwise expensive to answer.
**None of them generates code**, and an architecture test pins the list. A
`make:something` command writes a file whose shape the framework then quietly
depends on, and the shape is undocumented because the generator *is* the
documentation — that is how a framework stops being a library you call and
becomes a thing you live inside.

Help is generated from the declaration, so there is no second description of
the interface to fall out of date:

```
$ php bin/console customer:sync --help
Usage:
  php bin/console customer:sync [since] [options]

Arguments:
  since  Only records changed on or after this date. (default: yesterday)

Options:
  -l, --limit=<value>  Stop after this many records. (default: 25)
  -d, --dry-run        Report what would change without writing anything.

Declared by module plugins/Example.
```

#### Errors on a terminal

`command.matched`, `command.finished` and `command.failed` are the CLI's
lifecycle hooks. There is deliberately **no filter over parsed input**: a
filter carries a value so that a module can change it, and a module silently
rewriting another module's arguments is worse than the flexibility is worth.

An exception's message obeys the same disclosure rule the web does — an
`HttpException` message is written by this framework and survives, anything
else is replaced wholesale outside debug mode. The console was the one place
that did not, and a `DatabaseException` carrying `dsn=…` into a cron log is
exactly what that rule exists to prevent. What the console adds is a way
forward: when a message is withheld it says `Set APP_DEBUG=1 for the full
message and a stack trace`, because the person reading a console error is the
person who can turn debug on.

### Errors

Everything that goes wrong arrives in one place and leaves as one document: a
handler's validation failure, a route that did not match, a PHP warning, an
uncaught exception, and a fatal that killed the process. What differs is the
rendering, and the rendering is chosen by **who is reading**.

```
Browser   the application's own error template, or a built-in page
Api       the JSON error document
Console   two lines, and in debug a trace
```

Production versus development is a **separate axis**. The specification lists
it alongside the three above, but it answers a different question: those decide
*to whom*, production/development decides *how much*. Keeping them apart is
what makes "this message is fine on a terminal and not in a response"
expressible at all — with one axis it is not.

#### What may be said, and to whom

| | Browser | Api | Console |
|---|---|---|---|
| `HttpException` message | shown | shown | shown |
| any other framework message | withheld | withheld | **shown** |
| an application exception's message | withheld | withheld | withheld |
| class, file, line, trace | debug only | debug only | debug only |

The middle row is the one worth explaining. `Command "customer:sync" is already
registered by plugins/Example` is exactly what an operator needs and exactly
what an anonymous client should not have: it is an inventory of the
application. An operator already has the source, the configuration and the
directory listing, so withholding it from them protects nobody and costs them
an afternoon.

Which messages are the framework's own is a property of the exception, not a
check against a class list:

```php
return (new self(\sprintf('Could not open "%s": %s', $name, $previous->getMessage())))
    ->withheld();
```

`FrameworkException` messages disclose by default, because the framework wrote
them. A factory that quotes something it did not write — nearly always
`$previous->getMessage()` — calls `withheld()`, because PDO quotes the DSN back
on a connection failure and a DSN is one keystroke from a password. An
architecture test fails if a factory interpolates another exception's message
without withholding it, which is the tripwire for the one mistake here that
costs something real.

Outside debug a withheld message is replaced **wholesale** with the status
text, never filtered. Filtering means guessing which substrings are secret, and
that guess is wrong eventually.

#### The application's own error page

```
templates/default/views/errors/404.twig     a lost visitor
templates/default/views/errors/error.twig   everything else
```

A template named for the status wins; `errors/error` catches the rest; with
neither, the framework's built-in page renders. A branded 404 costs one file
rather than a subsystem, and it extends the ordinary layout because it is an
ordinary template. Both are handed `error` (the `ErrorDocument`) and `home` —
the front page as the failing request addresses it, so "Back to the home page"
still leads home under Apache in a subdirectory. Neither repeats the requested
path back, so the address cannot put markup on the page.

Two rules keep it from making things worse. **A template that throws falls back
to the built-in page** instead of propagating — a typo in `errors/500` is
discovered the day the first 500 happens, and at that point the visitor needs a
page far more than the framework needs to be right about which one. And **the
built-in page is used whenever there are diagnostics to show**, which means in
debug mode: an error template is written for a visitor, and if it won, the day
somebody added a branded 500 page would be the day stack traces stopped
appearing. The consequence is that error templates are previewed with debug
off, which is the right way round.

The built-in page has no `<link>`, no `<script>` and no asset URL, and an
architecture test keeps it that way. It has to render when the thing that broke
is the asset pipeline.

#### Logging is a listener, not a dependency

```php
$module->hook('error.reported', [Telemetry::class, 'record']);
```

Every handled error fires `error.reported` with the throwable itself — not a
formatted string, so a listener that wants the previous exception or the trace
does not have to parse them back out of a sentence — plus the `ErrorContext`
and the request. That is the seam `Logging\ErrorLog` attaches to (see
[Logging](#logging)), and it is why there is no logger interface here to
implement.

An architecture test asserts that nothing under `engine/Error/` calls
`error_log()`, `syslog()` or `fopen()`. The moment a file handle appears there,
rendering and recording are one thing again, and changing either means touching
both.

#### Taking over from PHP

`register()` installs four things:

- **`display_errors` off** in production. This is the single most
  security-relevant line in the phase. Left on, PHP writes a warning — with the
  absolute path of the file that raised it — straight into the response body,
  above the doctype, before any framework code runs and with no way to
  intercept it afterwards. "Never expose filesystem paths" is not achievable
  without it. `error_reporting` stays at `E_ALL` either way, because the handler
  still needs to see everything.
- **errors become `ErrorException`**, so they can be caught like anything else,
  while a suppressed notice stays suppressed.
- **an exception handler**, so an uncaught throwable renders instead of
  printing a trace.
- **a shutdown handler**, so a fatal produces a 500 rather than a blank 200.

That last one needs memory it will not have. `RESERVED_MEMORY` is 256KB held
from registration and released at the top of the shutdown handler, and the size
is measured rather than guessed: at 32KB the handler ran out of memory a second
time and produced **nothing at all** — building the `ErrorException` captures a
backtrace, and the document and page are strings. 64KB was enough for
production; 256KB leaves room for a debug trace on a deep stack.

The handler is also told which request is in flight:

```php
$this->container->get(ErrorHandler::class)->serving($request);
```

The one piece of mutable state in the class, and it earns its place — PHP's
exception and shutdown handlers take no arguments, so when a fatal happens
between the kernel returning and the last byte being written, this is the only
way to know whether a browser or a program is waiting. Without it an API client
receives an HTML page.

### Logging

```php
final class ChargeCard
{
    public function __construct(private readonly Logger $log) {}

    public function __invoke(Order $order): void
    {
        $this->log->info('Card charged', ['order' => $order->id, 'amount' => $order->total]);
    }
}
```

A `Logger` is injected like anything else, and it never learns where records
go — that is a deployment decision, and it changes without the module changing.
With no writers configured the call costs one integer comparison and goes
nowhere.

#### Independent from error rendering, checkably

Nothing in `engine/Error/` knows this layer exists. The error handler announces
on `error.reported`; `ErrorLog` is an ordinary listener on that hook,
registered exactly the way a module would register one. **Delete it and errors
stop being logged; nothing else changes** — there is a test that does precisely
that and asserts the 500 still renders.

Two architecture tests hold the line: `engine/Error/` may not mention a logger,
and only `ErrorLog` in `engine/Logging/` may mention an error. A hook has no
compile-time direction, which is why it is the right joint here.

The level comes from the status, not the exception class:

| | |
|---|---|
| 5xx, or not an `HttpException` | `error` |
| 429 | `warning` |
| other 4xx | `notice` |

A 404 is a visitor typing a URL. At error level, a scanner sweeping for
`/wp-admin` pages somebody at three in the morning.

**The log is where a withheld message belongs.** A response must not repeat
`dsn=secret-hunter2`; a log file exists so somebody can find out what actually
happened. The two rules are opposites on purpose.

#### Logging never breaks a request

This is the specification's hardest requirement here, and most of `LogManager`
follows from it.

A writer that throws is caught, **retired** — taken out of the rotation for the
rest of the process — and the reason is kept.

Retiring matters as much as catching: a full disk does not un-fill itself, so a
writer that failed once fails on every record, and a request that logs forty
times would otherwise spend forty exceptions finding that out. Keeping the
reason matters because the failure mode of "catch everything" is an application
that has silently not been logging for three weeks, which is worse than the
exception was.

```bash
php bin/console log:status            # what is attached, and what has stopped
php bin/console log:status --write    # and does a record actually arrive
```

Reentrancy is refused for the same reason — a writer that logs would recurse
until the stack ran out. And a writer may not catch its own failures: an
architecture test forbids `catch` under `engine/Logging/Writers/`, because a
writer that swallows its own errors looks safer and is worse. The manager could
no longer tell it had stopped working, and `log:status` would report a healthy
writer that writes nothing.

#### Levels

The eight RFC 5424 severities, which are also the eight PSR-3 levels. The
specification's list also contains `log`, which is not a severity — it is the
name of the generic method that takes one, and `Logger::log()` is it. A ninth
case with no place in the ordering would make "is this record severe enough to
write" unanswerable.

The backing value is the RFC 5424 code, so comparing severity is comparing
integers. It is **not** what `syslog()` wants: on Windows there is no syslog, so
PHP collapses the eight onto the event log's three record types and `LOG_EMERG`
is 1, not 0. `Level::priority()` is the translation, and a test pins it — a
mismatch would file every record under the wrong severity on the one platform
where nothing would look broken.

#### Context is data, not string interpolation

There is no `{placeholder}` splicing. A message is a message and context is
data; splicing one into the other produces a line that is harder to grep and a
context that has been said twice.

Context is normalised before any writer sees it, because a log line is written
at the worst possible moment and must not be the thing that fails next. A
closure, a PDO handle, a model with a circular reference, `NAN`, a resource, ten
thousand rows — all of them either break `json_encode` or write a megabyte into
the log. So values are reduced first: scalars survive, throwables become class,
message and position, objects become their class name unless they can say more
for themselves, and depth, string length and item count are capped.

A small, exact, configurable list of **key names** is replaced with
`[redacted]`: `password`, `token`, `authorization`, `api_key` and a dozen more.

This is deliberately not the same thing as filtering a message, which this
framework refuses to do on the grounds that guessing which substrings are secret
is wrong eventually. A key is not a substring — it is structured data, matched
exactly, against a list somebody wrote down. It catches the case that actually
happens, a request payload logged whole with `password` still in it, and claims
nothing about the rest. **A log file is still sensitive**; an architecture test
asserts `system/Logs` is refused by the web server.

#### Destinations

```php
'logging' => [
    'writers' => ['file'],       // file, stderr, syslog
    'level'   => 'info',
    'file'    => ['prefix' => 'app', 'retention_days' => 30],
],
```

**Nothing is attached by default.** A framework that starts writing files to a
directory nobody asked about is a framework that fills a disk on somebody
else's machine, and the first thing a deployment does is decide where its logs
go. An unknown writer name or an unparseable level is ignored rather than
fatal — a typo in a deployment's configuration must not stop an application
from starting, and `log:status` reports what is actually attached.

`file` writes one file per day under `system/Logs`. Daily rather than by size,
because the two questions anybody asks of a log are "what happened just now"
and "what happened on the day the invoices went wrong", and a date in the
filename answers the second without reading the first. Retention deletes, so it
is bounded hard — only that directory, only that writer's own naming, and only
when a positive number of days is set. The default is 0, keep everything:
deleting an audit trail because a default said so is a worse failure than a
large directory.

**Database and remote writers are not built**, which is the honest reading of
"database logging should be optional". A database writer needs a schema and a
migration runner that do not exist yet; a remote one needs an HTTP client that
does not exist yet. Building either now would mean inventing both. `LogWriter`
is three methods — `describe()`, `accepts()`, `write()` — so either is a small
class in an application that wants one, and `system/Logs` is not where it has to
go.

#### PSR-3

`psr/log` is deliberately not a dependency, and `Logger` deliberately does not
implement `LoggerInterface`. The eight method signatures match PSR-3 exactly;
what differs is `log()`, which takes a `Level` enum where PSR-3 takes a plain
string. The enum is worth more here than the interface is.

An application that must hand a PSR-3 logger to a vendor SDK writes the adapter
once:

```php
final class Psr3Logger extends AbstractLogger
{
    public function __construct(private readonly Logger $log) {}

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->log->log(Level::fromName((string) $level, Level::Info), (string) $message, $context);
    }
}
```

That is twelve lines in an application that needs it, rather than a flattened
API in every application that does not.

### Configuration

```php
final class SendInvoice
{
    public function __construct(private readonly Config $config) {}

    public function __invoke(): void
    {
        $from = $this->config->string('billing.sender', 'billing@example.com');
        $size = $this->config->int('plugins/Example.page_size', 25);
    }
}
```

Four sources, in this order, each overriding the one before it:

| | |
|---|---|
| `Bootstrap::defaults()` | every key the framework reads, with a working value |
| `config/*.php` | what this installation decided |
| whatever `Bootstrap::create()` is handed | an embedding application, or a test |
| a module's `config()` declaration | **defaults only** — see below |

The environment is not a fifth layer. Config files read it themselves, so the
precedence is visible in the file somebody has open rather than hidden in a
merge order in another directory:

```php
// config/database.php
return [
    'default' => Env::string('DB_CONNECTION', 'default'),
    'connections' => [
        'default' => [
            'dsn' => Env::string('DB_DSN', 'sqlite::memory:'),
            'username' => Env::string('DB_USERNAME'),
            'password' => Env::string('DB_PASSWORD'),
        ],
    ],
];
```

PHP files rather than YAML, JSON or INI: the file is read by PHP, opcache
already caches it, a typo is a parse error on the line it happened on, and an
editor can complete the constants it references. A format that needs a parser
buys a parser.

**The filename is the namespace.** `config/database.php` lands under
`database`, so the file somebody opens to change a connection is the one named
after it. Nothing has to be registered and there is no lookup table between the
two to maintain.

**Nothing in `config/` is required.** An application with no such directory runs
on the defaults and the environment.

#### Configuring a module

A subdirectory joins with a slash, and a module's id *is* a path:

```php
// config/plugins/Example.php — the module at modules/plugins/Example
return ['page_size' => 10];
```

```php
// modules/plugins/Example/module.php
$module->config(['page_size' => 25]);
```

The file wins, and that direction is the point. A module's `config()` is
**defaults** — values written by whoever wrote the module — and the file is the
**decision**, made by whoever runs the installation. Modules register long after
`config/` has been read, so merging their values the ordinary way would have
every module quietly overwrite whatever the application had configured for it.
The symptom of getting this backwards is a config file that appears to do
nothing at all, so `Config::defaults()` is a separate method from `merge()` and
a test holds each direction.

Only the keys a file names are overridden; the rest of a module's defaults are
untouched, so the file never has to be kept in step with the module's.

#### Typed retrieval does not coerce

```php
$config->string('app.timezone', 'UTC');   // ?string
$config->int('logging.file.retention_days', 0);
$config->bool('app.debug');
$config->float('billing.rate');
$config->array('database.connections');
$config->strings('logging.writers');       // list<string>
```

A missing key takes the default. A key that is present but of the wrong type
throws, naming the key, the type wanted and what was there instead.

That is deliberate and it is the opposite of defensive. `'retention_days' =>
'30'` in a file, absorbed by a fallback, silently becomes `0` — which means keep
every log forever, and nobody finds out for a year. It is a mistake in a file
somebody has open right now, and the cheapest moment to mention it is right now.

The environment is the one place a setting legitimately arrives as text, and
that is exactly where the parsing lives.

#### The environment is read in one place

`Env` is the only class in the framework that reads it — an architecture test
enforces that, and a second forbids `putenv()` anywhere, because it is not
thread-safe and this is a ZTS build.

```php
Env::string('APP_ENV', 'production');
Env::bool('APP_DEBUG', false);   // true/false, yes/no, on/off, 1/0
Env::int('LOG_RETENTION_DAYS', 30);
Env::list('TRUSTED_PROXIES');    // comma separated
```

`"false"` is a non-empty string and therefore `true` to PHP, which is the single
most expensive gotcha in this area; here it is `false`. An empty variable counts
as absent, because `APP_ENV=` is a variable somebody meant to fill in. A boolean
that reads `maybe`, or a number that reads `30 days`, stops the boot and says
which variable it was rather than guessing.

Every variable the engine reads is listed in [`.env.example`](.env.example), and
a test fails if one is added without being documented there.

#### .env fills gaps, it does not override

A `.env` file is loaded if there is one, for machines with no real environment
to speak of — a laptop, a CI container. **A real environment variable always
wins**, which is the opposite of the usual "last loader wins" and is what makes
loading it unconditionally safe: a `.env` left behind on a server cannot
override what the deployment set. Values go into `$_ENV` only.

There is no variable interpolation. `${OTHER}` stays the six characters it looks
like — interpolation turns a flat list of settings into a small programming
language, and the first question it raises, whether it sees the real environment
or the file, has no good answer. A line that is not an assignment stops the boot
rather than being skipped: a setting that silently fails to apply is worse than
a boot that stops.

#### Cached configuration

```bash
php bin/console config:cache          # compile config/ and the defaults into one file
php bin/console config:cache --clear  # or cache:clear, which clears every cache
php bin/console config:list --sources # what resolved, and where it came from
php bin/console cache:warm            # this cache and the module discovery cache, in one step
```

The cache is one `var_export`ed array in `system/Cache/config.php`, which
opcache already holds. Once it exists it is used — there is no setting to switch
it on, because that setting would have to be read out of the configuration this
is building.

**It carries the environment it was built from.** The well-known failure of
cached configuration is that config files read environment variables, the cache
freezes those values, and changing a variable afterwards then does nothing at
all — silently, with no error and no clue. Because every read goes through `Env`,
`Env` can record what it was asked and what it answered, and that record goes
into the file. On load the variables are compared against the environment as it
is now, and one difference makes the cache stale and it is ignored:

```bash
php bin/console config:cache                      # built with APP_DEBUG unset
APP_DEBUG=1 php bin/console config:list -p app    # app.debug true: the cache noticed
```

Editing a config file does **not** invalidate it. Noticing that would mean
stat-ing every file on every request, which is the work the cache exists to
avoid, and a deployment that changes configuration is a deployment — it runs
`cache:clear`. The environment is different: it changes without any file
changing, and checking it costs a few dozen string comparisons.

Anything that is not plain data — a closure, an object — is refused when the
cache is written, by name, rather than being written happily and failing as a
fatal error inside a generated file on the way back in. A test asserts the
framework's own shipped configuration can always be cached, so nobody discovers
otherwise while running `config:cache` on a production machine.

#### config/ is not web-readable

It holds the database credentials. Both `.htaccess` and the `php -S` router deny
the directory, and both deny `.env*` by name — a `.env` is not a `.php` file, so
without that the built-in server would hand one over as plain text on a port a
colleague on the same network can reach. An architecture test compares the two
deny lists and fails when they drift apart, which is how `config/` came to be on
both.

`config:list` prints `[hidden]` for a handful of key names — `password`,
`token`, `dsn` and a few more. There is deliberately no flag to reveal them: a
flag like that exists to be used, and where it gets used is a terminal somebody
is sharing their screen from.

### Cache

```php
final class CustomerQuery
{
    public function __construct(private readonly Cache $cache) {}

    public function total(): int
    {
        return $this->cache->remember('customers.total', fn(): int => $this->query()->count(), 300);
    }
}
```

Injected, always. There is no `Cache::get()`, no `cache()` helper and no static
method anywhere in `engine/Cache/` — the specification bans a static cache API
by name, next to `DB::table()` and `User::find()`, and an architecture test
holds the line. What the ban buys is ordinary and worth having: a class that
caches says so in its constructor, and its test hands it a store that keeps
nothing.

| | |
|---|---|
| `get($key, $default = null)` | the value, or the default |
| `has($key)` | including a stored `null` |
| `set($key, $value, ?int $ttl = null)` | seconds, or the configured default |
| `delete($key)` · `clear()` | one key, or this namespace |
| `remember($key, fn() => …, ?int $ttl)` | compute once |
| `refresh($key, fn() => …)` | compute again and replace |
| `namespace($name)` | another `Cache` over the same store |

#### Stores, and the two that are not here

`CacheStore` is five methods — `describe`, `get`, `put`, `forget`, `flush` — and
not one of them mentions a file, a socket or a serialisation format. Three
implement it:

| | |
|---|---|
| `array` | memory, for one process. The default. |
| `file` | one file per entry under `system/Cache/data`. |
| `null` | keeps nothing, and everything still works. |

**APCu, Redis and Memcached are not built.** None of the three extensions is
installed on the machine this was developed on, so every line of them would be
unverified — and the one operation that genuinely differs between backends is
clearing a namespace without clearing somebody else's keys, which is exactly the
part that cannot be written blind. What is shipped instead is the thing that
makes adding one safe: `tests/Unit/Cache/StoreConformanceTest` runs the same
twenty-odd assertions against every store, and an architecture test fails if a
store exists that it does not run against. A Redis store is five methods and one
line in a data provider.

Memory is the default rather than files for the same reason the log has no
writers by default: a framework that starts writing files into a directory
nobody asked about fills a disk on somebody else's machine. It is a real cache
even so — a page that resolves the same template from six partials pays for one
search — and crossing requests is what `CACHE_STORE=file` opts into.

#### What is kept apart from what is missing

A store that answers `null` for both cannot hold a `null`, and the consequence
is not theoretical: `remember()` around a lookup that legitimately answers "no
such customer" would query on every single request, silently, while appearing to
work. So a miss is a missing `CacheEntry` and a stored `null` is an entry whose
value is `null`; `has()` and `get() !== null` are different questions; and
`remember()` caches a computed `null`. Every store is held to that by the
conformance test.

#### Keys and namespaces

A key is up to 128 characters of letters, digits, dot, dash, colon and
underscore. Keys are **checked rather than escaped**, because a key becomes a
filename in one store and part of a wire protocol in another, and one set of
rules both can keep beats two escaping schemes that disagree. A value that
cannot leave this process — a closure, a resource — is refused by `Cache`
rather than by a store, so memory and files fail the same way.

```php
$billing = $cache->namespace('billing');   // keys become "billing:..."
$billing->clear();                         // and nothing outside billing goes
```

In the file store a namespace is a directory, so clearing one is removing it
rather than reading every entry to find out whose it is. Namespaces compose, and
`namespace()` returns a new `Cache` rather than mutating the one it was called
on, so handing a module its own costs nothing.

#### What the framework caches with it

Everything that caches is **handed a namespace at bootstrap**; nothing reaches
for one.

- **`assets`** — version tokens. Content hashing reads the whole file, and a
  page referencing five assets hashes five files on every request. Cached, that
  is once per deployment. **Not in debug**: a hash that outlived the file it
  describes would mean editing a stylesheet and the browser keeping the old one,
  which is the exact failure content hashing exists to prevent.
- **`templates`** — resolved names. The search is directories times extensions
  of `is_file`, and a page with a layout and six partials does it seven times.
  A remembered resolution is stat-ed before it is trusted, so a cache that
  outlived the layout degrades into a slow lookup rather than a missing
  template. Misses are never written — a missing template is an exception, and
  caching one would turn "add the file" into "add the file and clear the cache".

Two caches deliberately do **not** go through this layer. The module discovery
list and the compiled configuration keep their own `var_export` files, built by
`cache:warm` (see [The production boot path](#the-production-boot-path)), because
they hold plain data that changes only at deploy time — which is what opcache is
better at than anything here could be — and because configuration is read
before a `Cache` can exist at all. A cache subsystem configured by the
configuration it caches is a bootstrap problem, not a design.

#### Invalidation is the application's job

```php
$module->onBoot(static function (CustomerQuery $customers, HookEngine $hooks): void {
    $hooks->add('customer.created', $customers->forgetTotal(...), 5, 'plugins/Example');
});
```

A TTL is a backstop, not a plan. The thing that knows a cached count is wrong is
the event saying a customer was created, and a cache whose only invalidation is
time is a cache that is usually wrong for a while. Hooks are already the
framework's announcement mechanism, so invalidation needs no machinery of its
own — and it is declared in `onBoot`, where the dependency can be injected.

#### Clearing

```bash
php bin/console cache:clear             # config, modules, templates and the application cache
php bin/console cache:clear --expired   # only entries whose TTL has passed; the rest stay warm
php bin/console cache:warm              # then rebuild the production boot path
```

`cache:clear` asks the store rather than deleting files, which is the version of
this that stays correct when the store is not files. `--expired` is a job for a
nightly schedule rather than a deployment: everything else here has no TTL to
have passed.

Reading an expired entry deletes it on the way past, so anything still being
asked for tidies up on its own.

### Queue and worker

```php
// modules/plugins/Example/Jobs/WelcomeCustomer.php
final class WelcomeCustomer implements Job
{
    public function __construct(private readonly int $customerId) {}

    public function handle(CustomerQuery $customers, Logger $log): void
    {
        ...
    }
}
```

```php
$this->queue->push(new WelcomeCustomer($customer->identity()));
$this->queue->later(3600, new ChaseInvoice($id), queue: 'billing');
```

A job is an ordinary class in a module's `Jobs/` directory. **Nothing registers
it** — the class name is the registration, and a worker in another process finds
it through the same autoloader everything else uses.

Look at where the two kinds of thing go. **The constructor takes data**, and
that is what gets written to the queue. **`handle()` takes collaborators**, and
they are injected from the container of whatever process runs the job. An id
rather than the model it names is deliberate: the customer may be edited between
dispatching and running, and reading it fresh is almost always what was meant.
Serialising a repository into a queue file and hoping its connection still works
an hour later is the failure this shape avoids by construction.

`Job` declares no methods, which is deliberate rather than lazy. An interface
method fixes its signature for every implementation, and `handle()`'s signature
is exactly the part that has to differ. A job with no `handle()` is refused when
it is **dispatched** instead — loudly, at the line that made the mistake.

#### The same line, with or without a worker

| | |
|---|---|
| `sync` | runs the job where it was dispatched. **The default.** |
| `file` | one file per job under `system/Queue`; needs `queue:work`. |
| `memory` | one process; for tests and one-off batch commands. |

The default is sync because the alternatives fail quietly on a machine nobody
has set up yet: memory drops the job when the response is sent, and a file queue
holds it for a worker that may not exist. Running it inline is neither. An
application works out of the box, and deferring the work is a configuration
change plus a process — not an edit to the line that dispatches it.

**Under sync, an exception reaches the caller.** There is nothing to retry from
— the caller is still on the stack — and swallowing it would be pretending to be
a queue. A job that throws breaks the request that dispatched it, exactly as the
code would have if it had never been deferred.

**Database and Redis stores are not built**, for the same reason the cache has
no Redis store: neither extension is installed here, and the one part of a
database queue that is genuinely hard — claiming a job atomically so that two
workers never get the same one — is dialect-specific and cannot be written
blind. What is shipped instead is the thing that makes adding one safe:
`tests/Unit/Queue/StoreConformanceTest` runs the same twenty assertions against
every store, and an architecture test fails if a store exists that it does not
run against.

#### How the file store is safe

The whole design is one system call. Moving a job from `pending/` to
`reserved/` is a `rename()`, which either succeeds or fails as a single
operation on NTFS and on every POSIX filesystem — so the loser of a race gets
`false` rather than a second copy of the job. No lock files, nothing to leak
when a worker is killed.

```
system/Queue/
  <queue>/pending/<due>-<id>.job     waiting; the name sorts by due time
  <queue>/reserved/<id>.job          claimed, with its reservation inside
  failed/<id>.job                    gave up; kept until somebody looks
```

The due time is in the filename so a directory listing is already in the order a
worker wants. Ten thousand pending jobs is fine; ten million is where this
should be a database, and `QueueStore` is the seam for that. **One machine, any
number of workers** — two machines sharing this over NFS would be trusting a
network filesystem's rename semantics, which is a bet worth not making.

#### Retry, backoff, failure

```bash
php bin/console queue:work --queue=billing --max-jobs=100 --max-time=300
php bin/console queue:status
php bin/console queue:failed --retry=<id> | --retry-all | --forget=<id>
```

A job that throws goes back on the queue with a delay; when its attempts are
gone it moves to the failed list with the error that killed it. Nothing inspects
the exception to decide — a worker that tried to tell a transient failure from a
permanent one would be guessing on the application's behalf, and an application
that knows the difference says so by catching its own exception.

Backoff is exponential and capped: 5s, 10s, 20s, up to ten minutes. The cap
matters as much as the growth, because without one the fifteenth attempt is nine
hours out, which for an invoice reminder is indistinguishable from never.
`queue.backoff.jitter` is worth raising above zero for anything that talks to a
shared service — a hundred jobs that failed together otherwise retry together
and knock the recovering service over again.

Retrying a failed job **resets its attempts**. Somebody has looked and decided
the reason is gone; a retry that immediately exhausted the attempts it had
already spent would answer a question nobody asked.

#### What a crashed worker costs

**One retry, not one job.** A reservation expires: once `reservedUntil` has
passed, the job is available again, so a worker killed mid-job leaves work that
comes back on its own. That is also why there is no signal handling here — pcntl
does not exist on Windows, a guarded call to it would be code nobody in this
project can run, and correctness does not need it.

And the attempt count is incremented when a job is **reserved**, not when it
finishes, so a job that kills whatever picks it up still runs out of tries
instead of cycling forever. Counting on success is the obvious way round and the
wrong one.

**Timeout is a reservation, not an interruption.** Stopping a job in the middle
of a socket read needs `pcntl_alarm`, which is not portable here; what the
timeout does is bound how long a job may hold its claim, so a hung process
delays work rather than stopping it. `set_time_limit()` is asked as well, which
covers the runaway-loop case where it is supported. Between them that is what
this framework can honestly enforce, and saying so beats a `timeout` setting
that quietly does nothing.

#### Bounded runs are the point

`--max-jobs` and `--max-time` exist so a worker exits and something starts
another. A worker that runs for a month is running last month's deployment, with
a month of memory growth and a database handle it opened on Tuesday. Let it
finish; let systemd, supervisor or a container restart policy start a fresh one.

`queue:work` exits 1 when it gave up on a job, so a cron line that drains a queue
can be alerted on without parsing its output.

#### Announcements, not entanglement

`job.queued`, `job.started`, `job.finished` and `job.failed` — the last carrying
the exception, the envelope and whether it will be retried. That is how logging
hears about a failed job without the queue knowing a logger exists, and how an
application adds metrics without touching the worker. The same hooks fire under
sync, so nothing an application observes changes when a queue is switched on.

Invalidation, alerting and metrics all attach here. The queue layer itself
cannot see `Request` or `Response` at all, and a test enforces it: a worker runs
where no browser is waiting.

**Queued payloads are not web-readable.** A job file is a serialised object that
something later unserialises, so a directory anybody could write to is a
directory that could hand a worker an object of its choosing. `system/` is
denied by `.htaccess` and by the development router, and an architecture test
checks both.

### Scheduler

```php
// modules/plugins/Example/module.php
$module->schedules(static function (ScheduleCollector $schedules): void {
    $schedules->command('invoice:send-reminders')->dailyAt('02:00');
    $schedules->job(RecalculateBilling::class)->monthlyOn(1, '03:00')->onQueue('billing');
    $schedules->call('customer:cleanup', $purge(...))->weeklyOn(0, '04:00');
});
```

**The machine gets one cron line, and it never changes:**

```cron
* * * * *  cd /var/www/app && php bin/console schedule:run >> /dev/null 2>&1
```

That trade is the whole point. A crontab is edited over ssh by whoever has
shell access; it is not in version control, not installed by a deployment, and
invisible to everyone who wrote the code. Moving the decision into `module.php`
makes a schedule reviewable in a diff, testable in CI, installed with the module
that needs it, and **gone when that module is removed**. What stays on the
machine is one line that says "ask the application".

`schedule:run` exits as soon as it has run what is due. It is not a daemon and
does not want supervising — nothing in `engine/Scheduler` sleeps, loops on the
clock, or starts a process, and an architecture test keeps it that way.

#### Three things to schedule, no new machinery

| | |
|---|---|
| `command('name', '--flag')` | dispatched through the console, in this process |
| `job(Class::class)` | pushed onto the queue |
| `call('id', $closure)` | called through the container, parameters injected |

A **command** is the shape the specification's own examples take, and the reason
is that a command is already how a person runs that work by hand. Scheduling one
means the thing that runs at night and the thing an operator runs while
debugging are the same code path, which is not true of a scheduler with its own
task type.

A **job** is the queue integration, and it is one line because the queue already
answers the hard part: the scheduler pushes and returns, and where the work
actually happens is the queue's configuration. Under the default sync store it
runs inline and nothing is lost. A long task should be a job for exactly this
reason — a scheduled *command* that takes ten minutes is ten minutes during
which the process holding up every other schedule is that one.

A scheduled job is built with no arguments, because a schedule has nothing to
tell it. A job whose constructor requires something is refused **at the line
that scheduled it**, not at three in the morning.

**There is no shell-command target.** Shelling out means locating a PHP binary,
quoting a command line for two operating systems, and losing the container, the
configuration and the log this process already built.

#### Everything is a cron expression

```php
->everyFiveMinutes()   // */5 * * * *
->hourlyAt(20)         // 20 * * * *
->dailyAt('02:30')     // 30 2 * * *
->weeklyOn(1, '09:00') // 0 9 * * 1
->monthlyOn(1)         // 0 0 1 * *
->cron('15,45 9-17 * * 1-5')
```

The fluent methods set an expression and do nothing else — there is no second
scheduling engine measuring elapsed time. That matters more than the saved code.
An elapsed-time scheduler has to remember when each task last ran, which is
durable state that can be lost, restored stale, or disagree between two
machines. A cron expression is a pure function of the clock, so two processes
asking "is this due" in the same minute always agree, and **a machine that was
switched off simply missed that minute** rather than firing a backlog when it
comes back.

Cron is also the notation operations already reads, so an expression pasted out
of a real crontab means here what it meant there — including the rule that
surprises everyone once: **with both day fields restricted they are ORed**, so
`0 0 13 * FRI` is "the 13th, and also every Friday", not "Friday the 13th".

The honest cost: nothing finer than a minute, because the process that asks is
started once a minute. A task that must run every ten seconds needs a resident
process, which this framework does not have and will not pretend to.

#### Timezones, said once

`scheduler.timezone`, falling back to `app.timezone`, and `->timezone()` per
task — because an ERP that bills in two countries has month ends in two places.
Over a daylight-saving boundary a wall clock repeats an hour and skips another,
so **a task that must run exactly once and never twice belongs on UTC**, which
is what it gets unless something says otherwise.

#### Not twice

Overlap protection is **on by default**. A task holds a lock keyed by its id
while it runs, so one that overruns its own interval is skipped rather than
started beside itself. The cost of a skip is one missed run; the cost of an
overlap is two processes writing the same invoices, so the safe way round is the
default. A task that genuinely may overlap says `->allowOverlapping()`, where
the decision sits next to the task it affects and shows up in `schedule:list`.

The lock is one system call: creating a file with `x` mode opens it `O_EXCL`,
which either creates the file or fails, atomically, on NTFS and on every POSIX
filesystem. The same argument the file queue store makes with `rename()`.

**A lock expires**, because a process killed outright releases nothing and a
lock without an expiry would stop its task permanently and silently. A crash
costs a bounded number of skipped runs. Set `->withoutOverlapping($seconds)` a
little above the task's longest run, not far above.

```bash
php bin/console schedule:list                       # what runs, when, and what is running now
php bin/console schedule:run                        # what cron calls
php bin/console schedule:run --id=<id> --force      # run one now; still takes the lock
php bin/console schedule:unlock --id=<id>           # after a machine died mid-run
```

`--force` ignores the clock and nothing else. "Run this now" is a decision about
timing; "this may run beside a copy of itself" is a decision about safety, and
conflating them would let somebody debugging a task at their desk start a second
copy of the one already running.

**There is no lock that does not lock.** `CacheStore` has a null store and
`QueueStore` has a sync store, because "do not cache" and "run it here" are
things people legitimately want. The equivalent here has exactly one behaviour —
two copies of a task at once — and shipping it would make the most dangerous
configuration the easiest to reach. An architecture test refuses one.

#### Where the output goes

`schedule.started`, `schedule.finished` (**every** outcome, carrying a
`ScheduleResult`) and `schedule.failed` (carrying the exception).
`Logging\ScheduleLog` is an ordinary listener on the second one and is meant to
be deletable; `engine/Scheduler` contains no reference to a logger, and a test
enforces it.

That listener earns its place more plainly than most. Work that runs at three in
the morning has no operator and no response — if it is not written down, nothing
happened as far as anybody can tell. The ordinary failure of cron is that a
command's output goes to a `MAILTO` nobody set, so **the scheduler captures what
each task printed** and hands it to the log with everything else. A task that
stopped working six weeks ago should not look identical to one with nothing to
do.

Levels say what happened, so a log filtered to warnings and above still shows
the right things: failed is an error, skipped is a notice, ran is info. Skipped
is deliberately its own outcome — it is neither a success nor a failure, and
collapsing it into either is how a schedule that has silently stopped keeping up
looks fine on a dashboard.

**One failing task does not stop the run**, and `schedule:run` exits 1 when
anything failed, so the cron line itself can be monitored without parsing a log.

**Checked when somebody is watching.** An unknown command name, a misspelt
option, a class that is not a job, a malformed expression, two schedules sharing
an id — all of them stop the application from starting. It is the single most
valuable property a scheduler can have: the code runs when nobody is looking, so
the checking has to happen when somebody is.

### Security

Security is attached by the framework at bootstrap, not by a module that could
forget. Three listeners on hooks the kernel already fires:

| | |
|---|---|
| `request.received` | the body size limit, before anything reads a body |
| `dispatch.before` | CSRF and the rate limit, with the route in hand |
| `response.instance` | the CSRF cookie and the security headers |

**This is what the ban on middleware looks like in practice**, and it is not a
workaround. A middleware stack is a pipeline every request walks whether or not
each layer has anything to say, ordered by a list somebody maintains, and the
usual failure is a layer that silently stopped running because it was registered
in the wrong place. Here the seams are named events, ordering is a priority
number, and **a listener refuses by throwing an `HttpException`** — which the
kernel already turns into a response, because that is how 404 and 405 work.

The cost, stated plainly: a listener cannot wrap the handler, so there is no
"do this after the response, in the same closure". The response filter covers
the other half, and nothing here has needed more.

```bash
php bin/console security:check      # audit this deployment; exits 1 on a problem
php bin/console security:key        # print a new APP_KEY
```

`security:check` is the command this section is really about. A security setting
is invisible when it is working and invisible when it is not, so "is HSTS on",
"is there a key", "is anything web-readable that should not be" get checked once
during setup and never again. It reports what the running process **actually
resolved to** rather than what a config file says, and exits non-zero, so it can
be a deployment step rather than something somebody remembers.

#### CSRF is opt-out

```php
$routes->group('/api/v1', ..., meta: ['csrf' => false, 'rate_limit' => '60/1m']);
```

Every `POST`, `PUT`, `PATCH` and `DELETE` is checked unless its route opts out.
**The direction is the whole decision.** Opt-in means the route somebody adds in
a hurry is unprotected, and that is reliably the one that matters.

Opting out is for an API authenticated by a bearer token, which has no ambient
credential to abuse — the token has to be put on the request by whoever makes
it, and another site cannot do that. Note what does *not* imply it: an API
authenticated by a **session cookie** needs CSRF exactly as much as a form does,
which is why `'api' => true` grants nothing and the exemption is written out by
hand, one group at a time. `security:check` lists every route that took it.

Two independent checks, either of which fails the request. A **token** in a
cookie that must be echoed back in `_token` or `X-CSRF-TOKEN` — same-origin
policy stops another site reading the cookie, so it cannot echo it — and the
**`Origin` header**, which browsers send on unsafe cross-origin requests and
script cannot forge. Origin is checked only when present; absent means "cannot
tell", because proxies and privacy tools strip it and treating that as an attack
produces false rejections. `Referer` is not checked at all, for the same reason
more so.

`check()` returns **a reason, not a bool**. "CSRF token mismatch" is one of the
least useful errors a framework produces: the form is missing a field, cookies
are being dropped, the page is older than the cookie, or the request really is
cross-site — four completely different fixes behind one message.

An existing valid token is reused rather than rotated, because two tabs open on
the same site is ordinary browsing and rotating would show the second one a
security error for it.

**What this does not do.** It does not survive XSS: script on your own page can
read the cookie like your own page can. It is the layer underneath SameSite
cookies, not a replacement for them. And a token is bound to a browser rather
than to a login. Sessions close half of that: regenerating the session id
**rotates the token**, so a token cannot outlive the identity it was issued
under, and logging in regenerates it. Binding a token to one particular session,
so that one user's token cannot be presented by another, is **not built** — see
[What is not built](#what-is-not-built).

#### APP_KEY, and what an application without one still gets

```bash
APP_KEY=$(php bin/console security:key --bare)
```

With a key, CSRF tokens are signed, so a sibling subdomain — or anyone able to
set a cookie over plain HTTP — cannot plant a matching cookie and field. Without
one, plain double-submit still works and still refuses cross-site requests; what
is lost is that one guarantee. **The framework says which mode it is in** rather
than implying the stronger one, and an application boots either way.

`security:key` **prints and does not write**. Every other framework's equivalent
edits `.env`, and that convenience is exactly wrong here: silently replacing a
live key invalidates every token and session the application has issued. A
command that cannot do that by accident is worth one copy and paste.

`Signer` is the only place an HMAC is computed, and an architecture test keeps it
that way — a second implementation is a second chance to compare the result with
`===`, which returns as soon as two bytes differ and so leaks how many leading
bytes were right. Signatures are bound to a **purpose**, so a CSRF token does not
verify as a signed URL.

#### Secrets do not leak when somebody is debugging

```php
$key = new Secret($raw);

echo $key;                      // [redacted]
var_dump($key);                 // [redacted]
json_encode(['key' => $key]);   // {"key":"[redacted]"}
serialize($key);                // throws
$key->reveal();                 // the actual bytes
```

The problem is not storage; it is the moment after. A key in a plain string is
one `var_dump($config)` from a screenshot in a ticket, one `"bad key: $key"` from
a log aggregator, one `json_encode($settings)` from a debug endpoint. None of
those is a decision anybody made.

**`reveal()` is the only way out, and that is the design** — every place that
needs the value says so in one conspicuous word, so `grep -rn 'reveal()'` is a
complete list of where secrets are used. An architecture test refuses a second
accessor.

Serialising throws, because a secret inside a queued job or a cached value is a
secret written somewhere it was never meant to be, usually because a closure
captured it.

#### Rate limiting

```php
meta(['rate_limit' => '60/1m'])   // also 5/15m, 1000/1h, 10/1d
```

**The key is the application's decision.** Per IP protects against one machine;
per username protects one account from every machine; per token protects a
quota. Those are different threats, and a framework that picked one would be
wrong for the other two. What the framework supplies is the place to put the
answer and a sensible default scoping — route plus client, so a limit on the
login form does not also stop the same office reading the catalogue.

Counts live in `CounterStore`, **not in the cache**, and the distinction is not
pedantry. A cache is allowed to forget — that is its contract — and a store that
may drop an entry is a limiter that may forget how many login attempts have been
made. More decisively, a counter must be incremented **atomically**, and
`get`/`set` cannot do that: two requests arriving together both read 5, both
write 6, and the limit is off by exactly as much as the traffic it exists to
stop. The file store holds a `flock()` across the read and the write, which is
the one place in this framework that needed a lock rather than a single atomic
syscall.

**Memory counters are no limit at all** — a web request is a process that ends —
and `security:check` calls that configuration a failure rather than a warning.

The window is **fixed, not sliding**, and the README says so because the
consequence is real: a client can spend its whole allowance at the end of one
window and the whole of the next at the start. A sliding log stores every
timestamp and a sliding counter needs two windows read atomically; for "five
login attempts" the boundary burst is not what matters, and claiming a precision
this does not have would be worse.

A refused attempt is **still counted**, or a client that keeps hammering would
start fresh the instant the window ends — rewarding the behaviour the limit
exists to discourage. `RateLimit::headers()` returns `RateLimit-*` and, only on a
refusal, `Retry-After`: sending it on an allowed request has been known to make
well-behaved clients wait.

#### Request size, and the failure that looks like a bug

Bodies over `security.max_request_bytes` are refused with 413 before anything
reads them — unbounded bodies are how one client exhausts a server's memory.

The second check is the one worth having. **When an upload exceeds
`post_max_size`, PHP does not fail**: it hands the script an empty `$_POST` and
an empty `$_FILES` with `Content-Length` still describing what was sent. The
handler reports "name is required", the user swears the field was filled in, and
the cause is an ini setting nobody has looked at. The symptom is
indistinguishable from an application bug, so the framework detects the shape —
a form body, a length over the ini limit, and no fields at all — and says what
really happened.

#### Uploads

```php
$problems = UploadPolicy::images()->check($request->file('avatar'));

if ($problems === []) {
    $path = UploadPolicy::images()->store($request->file('avatar'), $directory);
}
```

**Nothing is automatic.** A framework cannot know that this endpoint takes
avatars and that one takes CSVs, so a global policy is either wrong for one of
them or not a policy. `check()` returns **every** problem rather than the first,
because a user who fixes the size and is then told about the type has uploaded
twice for one answer the server already had.

An **allowlist, never a blocklist** — a blocklist has to enumerate `.php`,
`.phtml`, `.phar`, `.htaccess` and whatever the next server module adds.
**Exactly one extension**, because `avatar.php.jpg` is a `.jpg` to an extension
check and a script to an Apache with an old `AddHandler` line. **Contents
checked against the name** with `finfo`, since the client's `Content-Type` is
whatever the client said. The **stored name is generated**, never the client's:
every rule for making an attacker-controlled name safe is a rule that can be got
subtly wrong.

Path stripping happens one layer down, in `UploadedFile::clientName()`, and
`UploadPolicy` deliberately does not repeat it — a check that can never fire is
worse than none, because it reads as though this class were what stands between
`../../etc/passwd` and the filesystem.

#### Headers, and the two that are off on purpose

`X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`,
`Referrer-Policy: strict-origin-when-cross-origin` and
`Cross-Origin-Opener-Policy: same-origin` go on every response, error pages
included — the ones most likely to be reached by somebody probing, and the ones a
per-route mechanism would miss. **An existing header is never overwritten**: a
handler that set its own policy has thought about it harder than a default can,
and the asset server's deliberately strict CSP would otherwise be loosened.

**Content-Security-Policy is off by default**, and that is a refusal rather than
an oversight. A useful policy names this application's own script and style
sources; a generic one is either so loose it permits what it exists to stop or so
strict it breaks the first page with an inline handler — and the one that gets
switched off in a hurry is worse than the one never claimed. `security:check`
says out loud that there is not one.

**HSTS is off by default and only ever sent over HTTPS.** It is the one header
here that cannot be taken back: a browser that has seen it refuses plain HTTP for
the whole `max-age`, so sending it from a development machine breaks every other
project on that hostname.

#### What was already true

Four of the specification's twelve concerns were structural before this phase and
are now enforced rather than merely intended:

| | |
|---|---|
| SQL injection | the grammar is the only thing that builds a statement |
| Path traversal | `AssetResolver` is the only thing that turns a path into a file |
| XSS | `Escaper` escapes by context; Twig autoescapes |
| Directory access | `.htaccess` and the dev router deny the same list, compared by a test |

That last one gained a member this phase: the development router itself. It is a
PHP file whose name does not end in `.php`, so a real web server will not execute
it and hands over the source instead — which is a map of the deny list to anyone
who asks for it. Both lists now name it.

### Sessions

```php
$routes->get('/visits', static function (Session $session): array {
    $visits = (int) $session->get('visits', 0) + 1;
    $session->set('visits', $visits);

    return ['visits' => $visits];
});
```

Type-hint `Session` and you get this request's. **There is no `$_SESSION`, no
`session_start()` and no global to reach for**, and an architecture test refuses
all of them anywhere in the project — the specification asks for this directly,
and the reasons are worth writing down. PHP's session machinery keeps the data
in a superglobal, the id in engine state and the policy in ini settings, so a
session cannot be constructed in a test, cannot be swapped for a fake, and
behaves differently depending on what the SAPI did before the script ran. It
also **holds a lock on the session for the whole request**, which is why one
slow endpoint blocks every other request from the same browser.

```bash
php bin/console session:gc       # delete what is past its lifetime
php bin/console session:table    # print the CREATE TABLE for the database store
```

#### Nothing is read until something asks

A request that never mentions the session does no storage read, no storage write
and sets no cookie. That is not an optimisation. It is what keeps a JSON API
from handing session cookies to clients that will never send them back — and it
fixes a bug people usually live with, because **flash data ages only on requests
that touch the session**. A background poll no longer eats the message meant for
the next page the user opens.

#### The request writes what it changed, not what it read

A browser makes several requests at once — a page and three fetches — and all of
them carry the same session. PHP's answer is to lock it for the whole request.
The usual alternative is to read at the start, write the whole array at the end,
and quietly lose whichever request finished first.

Here `Session` records a **change log**, and `SessionStore::commit()` takes a
closure that the store applies to whatever is stored *now*, under its own lock:

```php
public function commit(string $id, \Closure $apply): ?SessionRecord;
```

Two concurrent requests, one setting `cart` and one setting `locale`, both
survive. Two requests writing the *same* key still resolve to the last writer,
which is inherent — there is no answer to "both of us set it" that a session
layer can pick for you. The lock covers a read-modify-write of one record, so it
is measured in microseconds rather than in the length of a request.

`clear()` is the one operation that is not per-key: it discards whatever is
stored rather than unsetting the keys this request happened to read. Since
`invalidate()` is built on it, the alternative would be a logout that leaves
data behind.

#### Regeneration, and the grace window

```php
$session->regenerate();   // same data, new id -- call this on login
$session->invalidate();   // no data, new id -- call this on logout
```

Changing the id is the defence against **session fixation**: an attacker who
planted an id in the victim's browser holds one that stopped meaning anything
the moment the user's privileges changed.

The naive version — write the new id, delete the old — breaks a page that logs
in with three fetches already in flight. Each arrives holding an id that no
longer exists, gets a brand new empty session, and **the user is logged out by
the act of logging in**. So `regenerate()` leaves the old record as a *pointer*
with no payload in it, which a request inside `session.grace` seconds follows
exactly once. Once, not until it stops pointing: a chain would let one stale id
walk through every regeneration a session ever had. `invalidate()` leaves no
pointer at all, because a logout should not be followable.

Regenerating also **rotates the CSRF token**, through the `session.regenerated`
hook rather than a dependency — the security layer still does not know that
sessions exist, and an architecture test keeps it that way. A token issued to
the anonymous page that showed the login form must not stay valid against the
session the login created.

#### Two clocks

| | |
|---|---|
| `session.idle` | 2 hours. The one users feel: "it logged me out while I was at lunch" |
| `session.absolute` | off by default. A ceiling regardless of activity |

Without the second, a session that a background request keeps warm never expires
at all. It is off by default because it is the setting most often switched on
after an incident rather than before one, and a framework that forced a value
would have to pick one that is wrong for both a kiosk and an internal tool.
`security:check` says out loud that there is not one. Regenerating does **not**
reset the absolute clock.

#### Flash data is ordinary data

```php
$session->flash('status', 'Saved.');   // this request and the next
$session->now('status', 'Saved.');     // this request only, stored nowhere
$session->reflash();                   // keep it all for one more
$session->keep('status');              // keep some of it
```

Flashed values are ordinary keys, plus a list of names to delete one request
later, so reading one is just `get('status')`. A separate flash bag with its own
accessors would mean every template showing a message has to know which kind of
value it is holding.

#### Storage

| | |
|---|---|
| `file` | the default. JSON per session, merged under `flock()`. One machine |
| `database` | a shared table. What "distributed sessions" actually means |
| `memory` | tests and the console only |

**A session payload must be JSON-serialisable**, and the memory store encodes
exactly like the others so that "it worked in tests" means something. PHP's
sessions `serialize()`, so an object dropped into one comes back as an object —
usually a stale copy of a row that changed an hour ago, occasionally a class
that no longer exists, which is a fatal error on a page nobody touched. Keep an
id in the session and load the object from it.

**File names are SHA-256 hashes, not ids.** A session id is a live credential:
whoever holds one is the user. A directory listing, a backup manifest or an `ls`
in a support ticket should not hand over an account, so the id appears only
inside the file, which needs read permission to reach.

The database store takes a **row lock** — `SELECT ... FOR UPDATE` where the
driver has it — and **does not create its own table**. A store that issues DDL on
its first request needs permissions in production that nothing should have, and
it uses them at the worst available moment. `session:table` prints the statement
for the configured driver; where it goes is the application's business.

#### Sweeping

Expiry is decided **on read**, by the timestamps, so a sweep that has not run for
a week costs disk space and not correctness. `session:gc` reclaims it:

```php
$schedules->command('session:gc')->hourly();
```

A command rather than PHP's lottery, in which roughly one request in a hundred
pays for scanning the whole directory — the cost lands on a random user, at a
random moment, and most often on the busiest sites. (Worse, Debian-derived
systems switch the lottery off and replace it with a cron job, so the behaviour
of a default installation depends on the distribution.)

#### The cookie

`HttpOnly`, `SameSite=Lax`, `Secure` following the request scheme unless forced,
and it expires when the browser does. **HttpOnly is not configurable**, and an
architecture test refuses to let it become so: this cookie *is* the credential,
and a setting that exists is a setting somebody switches off at four in the
afternoon to make a widget work. Everything else here is a judgement call
somebody may reasonably need to make differently.

`Lax` rather than `Strict` because Strict makes arriving from an email link or a
search result look logged out, and the usual fix for that is to turn the whole
thing off.

**One thing to watch when injecting `Session`:** ask for it where the request is
— a closure route, a handler bound with `bind()`, a method parameter. A
*singleton* service taking `Session` in its constructor would hold the first
request's session for the life of a worker, and the symptom is users seeing each
other's data.

### Authentication and authorization

```php
$routes->post('/invoices/{id}/void', [Invoices::class, 'void'])
       ->meta(['auth' => true, 'can' => 'invoice.void']);
```

```bash
php bin/console auth:access           # every capability, role and guarded route
php bin/console auth:hash 'hunter2'   # a hash, for seeding the first account
```

**The framework does not know what a user is.** There is no `User` model in
`engine/`, no users table it expects and no column it names — there is a
`UserProvider` interface with two lookups, `byId()` and `byLogin()`, plus
`describe()` for the console. An architecture test freezes that list, because a
third lookup is how an interface starts describing a schema it does not own. Everything that knows what a user actually is lives in a module; in
this repository that is `modules/shared/Auth/AccountProvider.php`. Point it at
LDAP and nothing in `engine/` changes.

```php
$services->singleton(UserProvider::class, AccountProvider::class);
```

Until a module binds one, every request is a guest and every protected route
answers 401. That is the correct behaviour for an application with no user
store, and `security:check` says so rather than leaving it to be discovered.

#### Authorization is set membership, and then it can only get smaller

This is the part the specification asked to be designed rather than copied.

| | |
|---|---|
| **Capability** | a name: `invoice.void`. A noun, not a question |
| **Permission** | a capability some module declared, with a sentence saying what it means |
| **Role** | a named set of capabilities, optionally inheriting other roles |
| **Subject** | whatever a decision is about — passed through, never interpreted |

```php
$module->access(static function (AccessCollector $access): void {
    $access->capability('invoice.void', 'Cancel an invoice that has been issued.');
    $access->role('accountant', ['invoice.*'], ['clerk']);
});
```

A check asks whether the identity's flattened grants cover the capability. **No
closure runs.** `invoice.*` covers `invoice.void` and deliberately does not
cover `invoices.void` — prefix matching without the separator is how a new
capability quietly falls inside somebody's old role.

**Why this is not a Gate.** Laravel's Gate is a registry of closures keyed by
ability strings: the closure *is* the decision, it can say yes to anything, and
finding out what the application can express means reading every one of them.
Policies are the same thing discovered by class-name convention. Both make "who
may do what" a question you can only answer by running the code. Here grants are
data, `auth:access` prints all of it, and an architecture test fails if
`Authorizer` ever gains a method that takes a callback.

#### The one thing a set cannot answer

"May this user edit *this* invoice" is a fact about data, not about the user. So
a module may narrow a decision:

```php
$module->filter('authorization.decision', static function (
    bool $allowed, Capability $capability, Identity $identity, mixed $subject,
): bool {
    return $subject instanceof Invoice && $subject->ownerId !== $identity->id ? false : $allowed;
});
```

**A filter may refuse and may not grant.** That asymmetry is the whole design.
Granting stays declarative and greppable; refusing stays contextual, and lives
in the module that owns the data. A filter that could also grant would be a Gate
with extra steps — the same "some closure somewhere says yes" that makes an
access model unauditable. The chain is not even run for a decision it could not
change, and anything other than an explicit `true` is a refusal, so a listener
that returns nothing fails closed.

#### A route asking for a capability nobody declared will not boot

```
Nothing declares the capability "invoice.viod", required by route POST /invoices/{id}/void.
```

Without this, that route refuses **everybody** — including the administrator
holding every role — and looks like a routing fault or a broken login, because
the one thing it never says is that the capability does not exist. The check
runs after every module has registered, since the module that enforces a
capability may well register after the one that routes to it.

#### Requiring a login is opt-in, and CSRF is not

The two defaults point in opposite directions and the reasoning is not
inconsistent. CSRF protects a route's side effects and costs a correct client
nothing, so defaulting it on is free. A login defaults every page to private,
including the home page — so it gets switched off wholesale on the first day,
and a default everybody disables protects nothing while looking like it does.

What replaces it is visibility rather than hope:

```
METHOD  PATH       NAME         MODULE  ACCESS     HANDLER
GET     /users     users.index  shared  user.list  Closure
POST    /login     auth.login   shared  public     ...
```

`route:list` has an ACCESS column, `auth:access` lists which routes check what,
and **`security:check` warns about routes that change something and require
nobody**. Some of those are meant to be open — a login form has to be — and the
point is that you can see them.

`can` implies `auth`: a capability check on a guest is a login prompt, and
writing both would be a chance to write only one.

#### 401 and 403 are different answers

**401 means "say who you are"; 403 means "I know who you are and the answer is
still no."** Retrying a 401 with credentials may work; retrying a 403 with the
same ones never will, and a client that cannot tell them apart retries for ever.
Every 401 carries `WWW-Authenticate: Bearer`, which RFC 9110 requires. Basic is
deliberately not offered — it makes browsers show a dialog the application
cannot style, cancel or explain.

#### Logging in

```php
$identity = $auth->attempt($username, new Secret($password));
```

**Every failure is the same failure.** No such account, wrong password,
suspended — one answer, and the caller must not explain which. "No account with
that email" is a way to find out which addresses are registered, and the usual
next step is to try that address on other sites. A missing account still costs a
password hash, because a wrong username answering in microseconds and a wrong
password answering in fifty milliseconds is the same disclosure by another
route.

**`attempt()` regenerates the session id**, which is the reason
`Session::regenerate()` exists. An attacker who planted a session id in the
victim's browser before the login holds one that stopped meaning anything the
moment it succeeded. The CSRF token rotates with it.

`logout()` invalidates the session rather than forgetting a key: the id changes
and the data goes with it, so a basket, a half-finished form or the previous
user's filters do not survive for whoever sits down at that machine next.

**The session holds an id, never an Identity.** Roles are re-read on every
request, so revoking one takes effect on the next click rather than the next
login, and suspending an account ends its session immediately. The cost is one
provider lookup per authenticated request, which is why `byId()` is the method
worth making fast.

The key holding that id is **reserved**: keys beginning with `_` cannot be
written through `Session::set()`. Otherwise any path that puts a user-supplied
key into the session — a `fill()` over request input is the obvious one — would
be a way to log in as anybody.

#### Bearer tokens, and the header Apache eats

```
Authorization: Bearer <token>
```

Registered only when the provider implements `TokenProvider`, so an application
without tokens does not carry a listener that can never succeed. A token beats a
session when both are present: a token was put on the request deliberately, a
cookie was attached by the browser on its own.

**This is why an API may opt out of CSRF.** A bearer token is not an ambient
credential, so another site cannot make a request that carries it. An API
authenticated by a *session cookie* needs CSRF exactly as much as a form does.

Tokens are looked up by **fingerprint, not by value** — `TokenAuthenticator::
fingerprint()` is SHA-256, because a table of usable tokens is a password table
that skipped the last thirty years. A fast hash is right here and a slow one
would be wrong: 32 random bytes are not guessable offline, and bcrypt on every
API request would make a read endpoint slower than the query behind it.

**Apache receives `Authorization` and does not pass it on.** It is absent from
`$_SERVER` entirely unless `CGIPassAuth` is set or a rewrite copies it, so a
bearer token the client definitely sent is invisible to PHP. The failure is
silent and environment-specific: token auth passes every test, works under
`php -S`, and answers 401 to everything once deployed. The framework recovers it
from `getallheaders()` and from `REDIRECT_HTTP_AUTHORIZATION`, and `.htaccess`
sets the latter — both, because `getallheaders()` is absent under FastCGI and
the rewrite is absent from an nginx install.

#### Passwords

`PASSWORD_DEFAULT`, never a named algorithm: naming bcrypt pins the application
to whatever was current when somebody typed it. A hash records which algorithm
made it, so old and new coexist without a migration, and `needsRehash()` fires
the `auth.rehash` hook at the one moment an application holds the plaintext —
somebody logging in. Writing the new hash is the application's job, because only
the provider knows where hashes live.

The plaintext arrives as a `Secret`. Not so it cannot be read, but so that
reading it says `reveal()` at the call site — and an architecture test keeps
`Auth\Password` the only place in the project that calls `password_hash()`.

### Performance

The specification's instruction is that performance "must not be based only on
theoretical architecture", so this phase started by measuring the framework as it
stood, and changed only what the measurements pointed at. Most of what a heavy
backend needs was already there — lazy connections, a lazily compiled route trie,
memoised reflection, read models — and the phase is mostly about the gaps
measuring found and the numbers that justify leaving the rest alone.

#### What measuring found

| Found | Done about it |
|---|---|
| An asset request booted every module — loaded every `module.php`, registered every route, ran every `onBoot` | It now stops after discovery. [An asset request loads no module](#an-asset-request-loads-no-module) |
| The listener that tells authentication about each request **built** the auth manager to do it — and with it the user provider, the shared repository and its models, on every request including a stylesheet | It records the request and hands it over when a manager exists or is built. A public page constructs nothing |
| Reading configuration and scanning module directories were the largest remaining costs of a boot | `cache:warm` replaces both with one opcache-held file each. [The production boot path](#the-production-boot-path) |
| Registration asked the filesystem, per module, per request, whether `assets/` and `Templates/` exist | Discovery asks once and records the answer; the cache carries it; an architecture test stops anything after discovery from asking again |

#### The numbers

From `composer bench` with opcache on. This machine is Windows with a
thread-safe PHP, where a filesystem stat costs tens of microseconds — so treat
the scan figures as pessimistic, and the relationships as the point:

| | |
|---|---|
| bootstrap + boot every module | 1.7 ms |
| bootstrap + serve an asset | **1.65 ms**, against 2.13 ms booting every module first |
| read configuration from `config/` → from the cache | 249 µs → **13 µs** |
| scan module directories → read the discovery cache | 487 µs → **4.4 µs** |
| compile a 500-route table | 0.87 ms, once per process, lazily |
| match a route among 500 | 0.8 µs static, 2.8 µs with a constrained parameter |
| resolve 50 modules' dependencies | 141 µs |
| insert 100 rows one at a time → `insertMany()` (SQLite) | 1.00 ms → **0.35 ms** |
| hydrate 50 rows into domain models → into read models (SQLite) | 411 µs → **168 µs** |
| fire a hook with 10 listeners / apply a filter with 10 | 2.0 µs / 2.6 µs |

Without opcache — XAMPP's default — a request costs 45–60 ms, nearly all of it
PHP compiling around 170 files, and none of the above is visible behind that. The
first production setting is `opcache.enable=1`; everything in this section comes
after it.

#### What is deliberately not cached

The specification lists routes and dependency resolution among the things to
cache. Measuring says not yet, and the reasons are in the numbers:

- **Routes.** A route is declared inside `module.php`, next to the hooks and
  services that must be registered on every boot regardless, so the declaration
  runs anyway. What a cache could skip is compiling the table — 0.87 ms for 500
  routes, once per process, never for a request that does not route — at the
  price of an invalidation story and of refusing closure handlers, which no cache
  file can hold.
- **Dependency resolution.** 141 µs for fifty modules, and it depends on what every
  `module.php` declares: a cached graph is wrong the first time somebody edits
  one.

Both are benchmarked, so the day either stops being true is a number rather
than a feeling, and `cache:warm` says out loud that it does not cache them.

#### Benchmarks, and what "track regressions" can honestly mean

```bash
composer bench                                      # every subject in the specification, section 50
composer bench -- --filter="Route resolution"
composer bench -- --save=system/Runtime/bench/before.json
composer bench -- --compare=system/Runtime/bench/before.json --threshold=20 --fail
```

A timing is a fact about the machine that produced it. Comparing a laptop with a
CI runner is noise, so is comparing opcache on with opcache off, and a comparison
across either is **refused** rather than reported. The useful comparison is one
machine before and after a change, which is what `--save` and `--compare` are.

What must not regress on *any* machine is asserted exactly, in the test suite,
where it fails the build: an asset request running no module code, a public page
constructing no authentication layer, a warmed boot reading its caches, nothing
after discovery probing a module directory, and every one of the specification's
eleven subjects having a benchmark. CI also runs the benchmarks on one PHP version
and prints them — informationally, because a shared runner's timings move by a
third between runs, and a threshold there would fail builds at random.

Benchmarks live in `tests/Benchmark/`, which ships with nothing: the web server
denies `tests/`, and a production install has no dev dependencies.

#### The model layer under load

§40 asks for mechanisms rather than promises. Each one is a method, and none of
them is new in this phase except the last:

| The specification asks for | Where it is |
|---|---|
| Column selection | `select()`, and `into()` / `pageInto()`, which read only the columns a read model declares |
| Pagination | `page()` / `pageInto()` — a count ignoring limit and offset, then the slice |
| Chunking | `chunk($size, $callback)` |
| Streaming | `stream()`; over SQL, `fetch()` is a cursor, so one row is held at a time |
| Read models | `ReadModel`, built by `into()` without hydration or identity mapping |
| Explicit relations | `RelationManager` — declared, never lazy; loaded in a second query and linked |
| Batch queries | `whereIn()`, which is what relation linking runs on — N+1 cannot happen by accident |
| Bulk operations | `insertMany()`, `updateWhere()`, `deleteWhere()` — [Bulk writes](#bulk-writes) |

And the things it says to avoid are avoided by shape rather than by discipline:
there is no lazy association to fire a query from a template, no automatic
relationship loading, and the cheap reads are listed first.

**Transactions (§41)** were already what the specification describes:
`Connection::transaction()` with the application choosing the boundary, nested
calls becoming savepoints, and no repository method opening one of its own. An
invoice, its lines, its accounting entries and its payment are one transaction
because the calling code says so.

#### Development and production

| | Development (`APP_DEBUG=true`) | Production (`APP_DEBUG=false` + `cache:warm`) |
|---|---|---|
| Errors | detailed | safe |
| Module discovery | scanned, always — a debug process never reads the cache | one cached file |
| Configuration | read from `config/` unless a cache exists | one cached file, fingerprinted against the environment |
| Asset version tokens | recomputed | cached (with `CACHE_STORE=file` across requests) |
| Asset delivery | PHP | the web server for `assets/`, PHP for module assets, or a CDN via `assets.url` |
| Routes, dependencies | compiled / resolved per process | the same — see above |

### Observability

The specification's §52 lists what a heavy backend eventually needs to be
diagnosed — request id, correlation id, execution, query, module, hook and filter
timing, memory, error context — and then gives the instruction that shaped all
of this: *"Do not build a giant debug dashboard initially. First build reliable
instrumentation APIs."* So there is no dashboard, no page and no store. What is
observed leaves through two channels every deployment already has: **the log**,
and **response headers**. An architecture test holds `engine/Observability/` to
that — nothing in it opens a file, prints or sets a header on its own.

It comes in two halves, deliberately different.

#### Always on: which unit of work is this?

Every request, console command and job runs inside a **trace**. A trace has an
id of its own and a **correlation id** naming the thing that started the chain:

```
POST /invoices/run          request   id 6aa97a..e1   correlation 6aa97a..e1
  └ queues InvoiceRun
      job (a worker, later)  job       id 6aa97b..07   correlation 6aa97a..e1
        └ queues SendInvoice
            job              job       id 6aa97b..3c   correlation 6aa97a..e1
```

The correlation travels inside the queued job's envelope, so *"what did that
click cause"* is one search of the log, across the queue boundary where a request
id alone stops. Nobody passes an id along by hand.

What that buys, with nothing configured:

- **Every response carries `X-Request-Id`** — assets, 404s and error pages
  included. It is what a user quotes to support.
- **Every log record carries `trace`, `request_id` and `correlation_id`.** The log
  manager adds them underneath the record's own context, so a worker logging
  about some other job can still say which one it means.
- **Error records say what the request was** — method and path — beside the id
  the client was given. Not the query string and not the body: that is where a
  token is, and an error log is read by more people than the request was.

Ids are 24 hex characters, sortable by time. **An id from outside is ignored**
unless `observability.trust_incoming_ids` is on — which is right behind a gateway
that stamps `X-Request-Id` and `X-Correlation-Id`, so its log and this one agree,
and wrong otherwise, because a client should not choose the id its own requests
are logged under. A trusted id is still checked against a pattern before it is
used, because a newline in it would let a client write a log line of its own.

```php
public function __construct(private readonly Tracer $tracer) {}

$this->tracer->current()->id;                 // this request, command or job
$this->tracer->current()->correlationId;      // the chain it belongs to
$this->tracer->current()->elapsedMilliseconds();
$this->tracer->current()->memoryGrowth();     // bytes, since the unit of work began
```

#### Off unless asked for: where did the time go?

```bash
APP_PROFILE=true
```

The **profiler** times every hook listener, every filter listener, every module
stage — discover, resolve and register as totals; load and boot per module,
because those run a module's own code — and every database statement. At the end
of each request, command and job it writes one record to the `profile` log
channel:

```
INFO [profile] GET /customers.json 200 in 17.53 ms {"request_id":"6aa979e8...",
  "elapsed_ms":17.547,"memory_growth_kb":1284.6,"memory_peak_mb":6,
  "categories":{"module":{"count":9,"ms":10.523},"filter":{"count":5,"ms":0.277},...},
  "slowest":{"module":[{"name":"register","detail":null,"ms":4.397},
                       {"name":"boot","detail":"plugins/Example","ms":1.72},...],
             "hook":[{"name":"request.received","detail":"engine App\\Engine\\Security\\Guard::onRequest",...}]}}
```

With `APP_DEBUG` on as well, the same numbers go out as a `Server-Timing`
header, which a browser's developer tools already draw as a waterfall:

```
Server-Timing: app;dur=19.03, module;dur=10.52;desc="9", filter;dur=0.28;desc="5", hook;dur=0.03;desc="5"
```

Only in debug, because it is an exact description of where a request spends its
time — not something to hand every client of a production system. The log gets it
either way.

Things worth knowing about the numbers:

- **They are aggregated, not a list of events.** A page fires thousands of filter
  listeners; each measurement is folded into a count, a total and a maximum under
  its name, and the number of names kept per category is capped. A worker running
  for hours holds the same amount. The count is also the answer to N+1: *the same
  statement fifty times* is one line with `"count":50`.
- **They are inclusive.** A hook listener that applies a filter counts the filter's
  time as its own, and the filter counts it too. Categories answer "how long was
  spent inside hooks" and "inside queries" separately; they do not add up to the
  request.
- **Queries are named by their SQL, never their values.** The connection's
  observation seam does not pass the bindings at all — a test checks the observer
  receives exactly three arguments — because a bound value is where a password or
  a card number is, and a profile is exactly the output that gets pasted into a
  ticket.
- **Listeners are named for what they are**: `Guard::onRequest` for a method,
  `closure at Bootstrap.php:577` for a closure.

Application code can time its own work under its own names:

```php
$profiler->measure('billing', 'invoice run', fn () => $run->execute());
```

With profiling off that is a function call and nothing else.

#### How "off" costs nothing

The subsystems being measured do not know the profiler exists. `HookEngine`,
`FilterEngine`, `ModuleManager` and `Connection` each expose one `observe()` seam
— a closure told what ran and for how long — and the profiler is the only thing
that attaches to them, from the bootstrap. With profiling off nothing is attached,
so a hook firing pays for a null check rather than a clock. The benchmark suite
shows it: a hook with ten listeners costs 2.0 µs unobserved, as it did before the
seam existed, and 15.6 µs profiled. That difference is why it is off by default.

Two architecture tests keep this true: **no subsystem references the profiler**
(add a check for "is profiling on" inside `HookEngine` and the build fails), and
**every `observe()` seam is connected**, so a subsystem cannot quietly drop out of
every profile.

#### Slow queries, profiling or not

```bash
SLOW_QUERY_MS=250
```

Any statement slower than this is a warning on the `database` channel, with its
SQL and its duration and never its values. It is the one timing worth paying for
on every statement in production: a query that took four seconds is a fact nobody
should have to reproduce to learn about.

| Key | Default | |
|---|---|---|
| `observability.profile` | `false` (`APP_PROFILE`) | Attach the profiler. Leave it off; switch it on to find out where a slow page goes. |
| `observability.slow_query_ms` | `0` (`SLOW_QUERY_MS`) | Warn about statements slower than this. `0` is off. |
| `observability.trust_incoming_ids` | `false` | Use `X-Request-Id` / `X-Correlation-Id` from the request. Only behind something that sets them. |

`php bin/console about` says which of these is on.

### Lifecycle extension points

Hooks are named `<subject>.<what-happened>`; filters are named after the value
they carry.

| Hooks | Filters |
|---|---|
| `app.booted`, `app.ready` | `request.instance` |
| `module.registered`, `module.booted` | `router.path` |
| `request.received` | `route.match` |
| `route.matched` | `dispatch.handler` |
| `dispatch.before`, `dispatch.after` | `dispatch.parameters` |
| `request.failed` | `dispatch.result` |
| `response.sent` | `response.instance` |
| `app.terminating` | `error.response` |
| `command.matched` | `asset.response` (engine listeners only — see [Assets](#an-asset-request-loads-no-module)) |
| `command.finished` | `dispatch.response` |
| `command.failed` |  |
| `error.reported` |  |
| `job.queued`, `job.started` |  |
| `job.finished`, `job.failed` |  |
| `schedule.started` |  |
| `schedule.finished`, `schedule.failed` |  |
| `session.started` |  |
| `session.regenerated` |  |
| `auth.identified`, `auth.login` |  |
| `auth.logout`, `auth.failed` |  |
| `auth.rehash` | `authorization.decision` |

## Project layout

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

## Deployment and security

The layout puts `index.php` in the same directory as `engine/`, `modules/` and
`vendor/`. **A front controller does not protect files the web server can reach
on its own**, so `.htaccess` is load-bearing — and it does nothing at all if
`AllowOverride` is `None`.

Verify after any deployment; each of these must return **403**, not 200:

```bash
curl -i http://localhost/framework/engine/Core/Application.php
curl -i http://localhost/framework/modules/shared/module.php
curl -i http://localhost/framework/templates/default/views/home.twig
curl -i http://localhost/framework/composer.json
curl -i http://localhost/framework/vendor/autoload.php
```

And these, which check that the asset layer did not become a second way in.
The first two must be refused — **403** or **404**, depending on whether Apache or
the asset server says no first — and the last must be **200**:

```bash
curl -i http://localhost/framework/assets/core/../composer.json
curl -i http://localhost/framework/assets/core/../index.php
curl -i http://localhost/framework/assets/core/css/app.css
```

With a plugin installed, repeat the traversal against it —
`/assets/plugin/<Name>/module.php` and `/assets/plugin/<Name>/../module.php`
must be 404 too.

If routes 404 under Apache, check these two directives:

```bash
grep -E 'rewrite_module|AllowOverride' /path/to/httpd.conf
```

**For production, prefer a virtual host** whose `DocumentRoot` contains only the
front controller, so a server misconfiguration cannot expose source at all:

```apache
<VirtualHost *:80>
    ServerName app.example.com
    DocumentRoot /srv/app
    <Directory /srv/app>
        AllowOverride All
        Require all granted
    </Directory>
    <DirectoryMatch "/srv/app/(engine|modules|templates|system|tests|bin|vendor)">
        Require all denied
    </DirectoryMatch>
</VirtualHost>
```

```nginx
server {
    server_name app.example.com;
    root /srv/app;

    location ~ ^/(engine|modules|templates|system|tests|bin|vendor)/ { deny all; }
    location ~ \.(json|lock|neon|dist|md|env)$ { deny all; }

    # The application's own assets can be served directly; /assets/core/<path>
    # is <root>/assets/<path>. Every other asset namespace lives inside the
    # denied modules/ tree and has to go through the front controller.
    location ^~ /assets/core/ {
        alias /srv/app/assets/;
        access_log off;
    }

    location / { try_files $uri /index.php$is_args$args; }

    location ~ ^/index\.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    }
}
```

### More than one web server

Three things are per-machine by default and become wrong the moment a second
machine serves the same site, all for the same reason: a file on one host is not
a file on the other.

| | |
|---|---|
| `session.store` | `file` → `database`, or a user lands on the other host and is logged out |
| `security.counters` | `file` counts per host, so a limit of 60 becomes 60 per machine |
| `cache.store` | `file` means each host warms and invalidates its own |

Sticky sessions push the first one around rather than solving it, and lose every
session on a node when it restarts. `session:table` prints the table the shared
store needs.

The scheduler is the opposite problem: its lock is a file, so **`schedule:run`
belongs on exactly one host**. Running it on three gives three copies of every
task.

### The production boot path

```bash
composer install --no-dev --optimize-autoloader
php bin/console cache:clear
php bin/console cache:warm
```

`cache:warm` writes two files: `system/Cache/config.php`, the whole resolved
configuration, and `system/Cache/modules.php`, what discovery found — each module's
location, and whether it has an `assets/` and a `Templates/` directory. A boot
that has both reads one opcache-held array for each and walks no directory.

**There is no setting.** The file existing is the switch, as it always was for
the configuration cache. Until Phase 27 the module cache was a `modules.cache`
setting, and the first request to find it missing wrote it — which is how a cache
comes to be built on a laptop halfway through adding a module. Only `cache:warm`
writes it now, and an architecture test keeps it that way.

**A debug process never reads the module cache.** That is the specification's
development mode — "uncached module discovery" — and it means a developer who
warmed the cache once to try it cannot lose an afternoon to a module that does
not exist. `cache:warm` refuses to run with debug on, and refuses again if the
configuration on disk turns it on, rather than caching a debug configuration for
deployment.

**Two kinds of staleness are noticed, and the rest are not.** The configuration
cache ignores itself when an environment variable it read has changed. The
module cache ignores itself when it was built under different roots —
`modules.paths` changed, or the application now lives in another directory.
Both checks cost nothing, because the answers are already in hand. Adding a
module, removing one or editing a config file is **not** noticed: detecting that
means stat-ing the very directories the cache exists to avoid, and mtime is
unreliable on Windows and network shares. That is what `cache:clear` in the
deployment is for.

`php bin/console about` says which path a process took:

```
Boot path    config cached, modules cached
Boot path    config read from config/, modules scanned (debug never reads the cache)
```

## Configuring a connection

A single connection needs no file at all, which is what a container image
wants:

| Variable | |
|---|---|
| `DB_DSN` | the PDO DSN, e.g. `mysql:host=localhost;dbname=erp;charset=utf8mb4` |
| `DB_USERNAME` `DB_PASSWORD` | credentials, if the driver needs them |
| `DB_CONNECTION` | what to call it, and which one is the default (default: `default`) |

More than one connection, or anything with options, goes in
`config/database.php` -- where the environment is still reachable, because a
config file may call `Env` itself:

```php
// config/database.php
return [
    'default' => 'main',
    'connections' => [
        'main' => [
            'dsn' => Env::string('DB_DSN', 'mysql:host=db;dbname=erp'),
            'username' => Env::string('DB_USERNAME'),
            'password' => Env::string('DB_PASSWORD'),
        ],
        'reports' => ['dsn' => 'pgsql:host=replica;dbname=warehouse', 'username' => 'reader'],
    ],
];
```

An embedding application can still pass connections to `Bootstrap::create()`
directly; those outrank the file.

With nothing configured the application boots exactly as before and opens
nothing; the shared module's `DataSource` factory falls back to `ArraySource`.

## Configuring assets

| Key | Default | |
|---|---|---|
| `assets.url` | `null` | URL prefix for every generated asset URL. `null` means "whatever prefix the application is mounted under", which is right for both a subdirectory install and the built-in server. Set it to a CDN origin to move delivery off this process entirely. |
| `assets.versioning` | `content` | `content`, `modified` or `none`. See [Versions and caching](#versions-and-caching). |
| `assets.manifests` | `true` | Whether `manifest.json` in a published directory is consulted. |
| `assets.max_age` | `31536000` | Seconds on the immutable `Cache-Control`, for versioned URLs only. |

One more knob is not in this table: `app.debug` decides whether a missing asset
throws or quietly produces an unversioned URL.

Asset URLs are built once, when the application is built, from the execution
context - route URLs are derived per request. Under every SAPI this framework
targets those are the same `$_SERVER`, so they always agree. A resident worker
serving many requests per process would be the case where they could not, and
that is a reason to revisit this rather than a bug today.

## Configuring templates

| Key | Default | |
|---|---|---|
| `templates.active` | `default` (or `APP_TEMPLATE`) | The active template. One name, two directories: `templates/<active>/views/` and `templates/<active>/assets/`. It is checked against `[A-Za-z0-9][A-Za-z0-9_-]*` before it becomes a path. |
| `templates.cache` | `false` | Twig's compilation cache, under `system/Cache/templates`. PHP templates never need it - opcache already has them. |

## Development

```bash
composer check      # everything below
composer cs         # coding standard, dry run
composer cs:fix     # apply it
composer stan       # PHPStan level 8
composer test       # PHPUnit
composer bench      # benchmarks; not part of the gate -- see Performance
```

There is no coverage gate, because neither Xdebug nor PCOV is assumed to be
present. The gate is `composer check`: clean coding standard, clean level 8,
all tests green. There is deliberately **no PHPStan baseline** — a baseline
created at the start of a project becomes permanent debt.

`tests/Architecture` holds executable architecture rules: the facade ban, the
frozen helper set, the engine/module layering, the HTTP/routing separation, the
model/schema/data/database separations, the repository base publishing no API,
statement construction living only in `Grammar`, the absence of a command base
class, the `.htaccess` deny rules, only discovery probing module directories, only
`cache:warm` writing the discovery cache, every specified subject having a
benchmark, no subsystem referencing the profiler, and observability keeping
nothing of its own. These are the invariants that erode quietly, so
each one is a test rather than a paragraph nobody re-reads.

`tests/Architecture/DocumentationTest.php` does the same for the documentation's
statements of fact: every engine class has a level in `STABILITY.md` and no
module uses an Internal one, the lifecycle table matches what the engine fires,
every framework command is mentioned, every link lands somewhere, and the
version, the changelog and `composer.json` agree.

## Implementation status

| Phase | Area | State |
|---|---|---|
| 0 | Repository foundation | done |
| 1 | Bootstrap / Kernel | done |
| 2 | HTTP Request / Response | done |
| 3 | Dependency injection | done |
| 4 | Module system | done |
| 5 | Hooks | done |
| 6 | Filters | done |
| 7 | Routing | done |
| 8 | Dispatcher | done |
| 9 | Model infrastructure | done |
| 10 | Schema system | done |
| 11 | Data / Repository / Query | done |
| 12 | Database | done |
| 13 | Asset manager | done |
| 14 | Template system | done |
| 15 | REST API | done |
| 16 | CLI | done |
| 17 | Error handling | done |
| 18 | Logging | done |
| 19 | Configuration | done |
| 20 | Cache | done |
| 21 | Queue / Worker | done |
| 22 | Scheduler | done |
| 23 | Security | done |
| 24 | Session | done |
| 25 | Authentication / Authorization | done |
| 26 | Module dependency system | done |
| 27 | Performance architecture | done |
| 28 | Observability | done |
| 29 | Demo application | **deferred** by the project owner; the showcase fixture covers most of §55 in the meantime |
| 30 | Documentation / release | done |

The specification's §56 workflow ends each phase with "commit the phase". That
step is the project owner's, and nothing here has been committed on their
behalf.

## API stability

Every public class and every public surface — hook names, configuration keys,
console commands, the error document, asset URLs — is marked **Stable**,
**Experimental**, **Internal** or **Deprecated** in
[`STABILITY.md`](STABILITY.md), as specification §53 asks. The short version:

- **Nothing is Stable before 1.0.0.** The specification says not to promise
  stability too early, and a framework no independent application has used yet
  is early. What is expected to become Stable is marked *1.0 candidate*.
- **Experimental** is what modules and applications are written against: the
  `module.php` contract, the ten helpers, handlers' types, stores' interfaces. It
  changes only in a minor release, and never without an entry in
  [`UPGRADING.md`](UPGRADING.md).
- **Internal** is wiring — kernels, registries behind collectors, resolvers, the
  framework's own commands, concrete stores selected by name — and changes
  whenever it needs to.
- **Deprecated** is empty. A deprecation is announced by `@deprecated`, the
  changelog, the upgrade notes and `STABILITY.md` — never by
  `E_USER_DEPRECATED`, which the error handler would turn into an exception.
- **Persisted formats are the exception to "Internal means no notice".** A queued
  job and a session record outlive the deployment that wrote them, so a release
  must read what the previous one wrote.

`tests/Architecture/DocumentationTest.php` keeps the file honest: every engine
class resolves to exactly one level, no row is stale, nothing is Stable in 0.x,
and **no module in `modules/` or in the showcase may use an Internal class** —
the rule that makes the classification mean something.

## Versioning and releases

**Semantic versioning, with 0.x read the way SemVer allows.** Before 1.0.0, a
minor release (`0.1` → `0.2`) may change Experimental APIs, with upgrade notes; a
patch release (`0.1.0` → `0.1.1`) may not. From 1.0.0, Stable APIs change only in
a major release, after a deprecation in a minor one.

**The version is written in one place**, `Application::VERSION`, which `about`
and `help` print. `composer.json` deliberately has no `version` field: Composer
takes a package's version from its git tag, and a second copy is a copy that
disagrees. A module's own `version()` is independent of the framework's — it is
what other modules' constraints are checked against — and `modules/shared`
changes version only when its own contract does.

**A release, step by step:**

1. `composer check` green locally, and CI green on both jobs: the gate on
   8.2–8.5 and the 8.2 runtime job, which installs `--no-dev`, lints, boots and
   serves the default pages.
2. The checks in [Deployment and security](#deployment-and-security) through a
   real web server, not only the test suite — every security defect this project
   has found late was found that way.
3. `composer bench -- --compare=<the previous release's saved run>` on the same
   machine. Informational: a slower number is a question to answer in the
   changelog, not a gate.
4. `CHANGELOG.md`: rename `[Unreleased]` to `[X.Y.Z] - YYYY-MM-DD` and start a new
   empty `[Unreleased]`. Every **Changed** or **Removed** entry has its section in
   `UPGRADING.md`, and `STABILITY.md` reflects anything promoted or deprecated.
5. Set `Application::VERSION` to `X.Y.Z`. `DocumentationTest` fails if it and the
   changelog disagree.
6. Commit, then an annotated tag: `git tag -a vX.Y.Z -m "X.Y.Z"`, and push the tag.

Nothing in this list is automated, on purpose: a release is the one moment
somebody should be reading what changed, and a script that tags on green is a
script that releases whatever happened to pass.

## What is not built

Deliberate omissions, each with the reason, so that nobody mistakes one for an
oversight — or builds one without reading why it was left out.

### Deferred

| Not built | Why |
|---|---|
| **The demo application** (Phase 29, §55: `Customer`, `Billing`, `DemoGateway`) | Deferred by the project owner. `tests/Fixtures/Showcase/` exercises nearly all of §55 through every subsystem, but it is a test fixture, not an application a reader can install, and a second plugin *depending on* the first — the part that would make module dependencies visible — does not exist |
| **Migrations and seeders** (§23: `modules/<kind>/<Name>/Database/Migrations/`) | No runner, and no module has a `Database/` directory. Pointing `DB_DSN` at a database means creating the tables yourself. A runner is an ordering problem across modules as much as a SQL one, and it should be designed against the demo application's real schema rather than an imagined one |
| **Database and remote log writers** | A database writer needs migrations; a remote one needs an HTTP client. `LogWriter` is three methods |
| **APCu, Redis and Memcached cache stores; database and Redis queue stores** | None of the extensions is installed where this was developed, so every line would be unverified, and the hard parts — clearing a namespace, claiming a job atomically — cannot be written blind. Each store type has a conformance suite, which is what makes adding one safe |
| **The asset and template hooks the specification lists as future** (§42: `asset.registered`, `asset.resolved`, `asset.served`; filters `asset.url`, `asset.version`, `asset.mime`, `asset.cache_control`, `template.path`) | Nothing needs them yet, and an asset request now loads no module, so a module could not listen to most of them anyway. `module.loaded` and `api.response` from the same list exist as `module.registered` / `module.booted` and `dispatch.response` |
| **CSRF tokens bound to one session** | Tokens are bound to a browser and rotate when the session id does, including at login. Binding them to a session is a change to how tokens are signed and verified, and has not been made |
| **Byte ranges for assets** | Responses say `Accept-Ranges: none`. Large media is the web server's or a CDN's job, not PHP's |
| **Route caching and dependency-resolution caching** (§38) | Measured and declined: 0.87 ms to compile 500 routes once per process, 141 µs to resolve 50 modules. See [What is deliberately not cached](#what-is-deliberately-not-cached) |

### Declined by design

| Not built | Why |
|---|---|
| Facades, service providers, middleware, gates and policies, form requests, route-model binding, an Eloquent, Blade or Artisan clone, `make:*` generators | §54. Each has an architecture test |
| A debug dashboard | §52: instrumentation first. Observability writes to the log and headers only, and a test holds it there |
| A catch-all route parameter (`{path:.*}`) | It breaks the trie's determinism, and nothing has needed it |
| `OR` and joins in `Query` | A boolean tree turns a query builder into a query language; a join cannot be honoured by every `DataSource`. Both belong in a named repository method over SQL |
| `ModelQuery` (the §14 list) | A query object with nothing to query. `Data\Query` is the query API §21 specifies |
| PSR-3 `LoggerInterface` on `Logger` | `log()` takes a `Level` enum; a twelve-line adapter is in [Logging](#logging) |
| RFC 9457 `problem+json` | See [One error shape](#one-error-shape) |
| A Content-Security-Policy by default; HSTS by default | A generic CSP is too loose or breaks the first page; HSTS cannot be taken back. Both are reported by `security:check` |
| Sub-minute schedules, signal handling in the worker, a resident HTTP worker | No resident process and no `pcntl` on Windows; bounded worker runs and reservation expiry give correctness without them |
| HTTP Basic authentication | It makes browsers show a dialog the application cannot style, cancel or explain |
| A runtime deprecation notice | See [API stability](#api-stability) |

### Where this departs from the specification

- **Twig is required and is the default engine.** §25 and invariant 14 say Twig
  must remain optional. The project owner decided otherwise, so the default
  template can ship Twig pages. What survives of the invariant: the manager speaks
  only to `TemplateEngine`, nothing outside `TwigTemplateEngine` mentions Twig,
  and PHP templates render through the same manager.
- **The unnamed template asset namespace is the active template's**, not a shared
  `templates/assets/`. See [Assets](#assets).
- **No commits per phase** (§56 step 16) — that is the project owner's step.

## License

MIT. See [LICENSE](LICENSE).
