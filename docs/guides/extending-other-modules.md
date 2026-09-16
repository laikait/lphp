# Extending other modules

How modules change each other's behaviour without editing each other: reacting
to events, adjusting values, adding rules that apply across many routes,
depending on another module safely, and overriding what another module renders.

Reference: [Hooks and filters](../reference/hooks-and-filters.md), including the
[table of every extension point the framework fires](../reference/hooks-and-filters.md#lifecycle-extension-points),
and [Modules](../reference/modules.md).

## Hooks and filters in one paragraph

A **hook** says something happened — `customer.created` — and ignores what its
listeners return. A **filter** passes a value through its listeners and uses
what comes back — `invoice.total`. Hooks are named `<subject>.<what happened>`;
filters are named after the value they carry. Listeners run by priority, lowest
first (default 10), and equal priorities in module order, so the order is always
the same.

## Listen from module.php

```php
$module->hook('customer.created', [CustomerHooks::class, 'onCreated'], priority: 20);
$module->filter('invoice.total', [LoyaltyDiscount::class, 'apply'], priority: 30);
```

```php
final class LoyaltyDiscount
{
    public static function apply(Money $total, Invoice $invoice): Money
    {
        return $invoice->customerIsLoyal() ? $total->percentOff(5) : $total;
    }
}
```

A listener declared as `[Class::class, 'method']` is a **static** call, and is
refused at boot if the method is not static. A filter receives the value first
and then whatever extra arguments the code applying it passed, and **must return
the value** — in debug mode, returning `null` for a non-null value throws and
names your listener.

## Listen with an object that has dependencies

Register from `onBoot()`, where dependencies are injected:

```php
$module->onBoot(static function (Auditor $auditor, HookEngine $hooks): void {
    $hooks->add('customer.created', [$auditor, 'record'], 10, 'plugins/Audit');
});
```

The last argument is your module's id, which is what `module:list` and the
profiler report the listener under.

## Announce your own events

In a class, inject the engines:

```php
public function __construct(private readonly HookEngine $hooks, private readonly FilterEngine $filters) {}

$this->hooks->do('invoice.paid', $invoice);
$total = $this->filters->apply('invoice.total', $total, $invoice);
```

In `module.php` and in templates, where nothing is injected, the helpers do the
same: `do_hook()`, `apply_filter()`, `add_hook()`, `add_filter()`. Pass domain
objects rather than arrays, so listeners get types instead of guessing at keys.

Once another module listens to your hook, its name and arguments are a contract.
Changing them is a breaking change to your module — raise its major version.

## A rule for many routes

There is no middleware. A cross-cutting rule is a listener that reads route
metadata. Mark the routes:

```php
$routes->post('/contact', [ContactForm::class, 'send'])
    ->meta(['maintenance' => 'blocked']);
```

and enforce it once:

```php
$module->onBoot(static function (HookEngine $hooks, Config $config): void {
    $hooks->add('dispatch.before', static function (Route $route) use ($config): void {
        if ($route->metaValue('maintenance') === 'blocked' && $config->bool('plugins/Desk.maintenance')) {
            throw new HttpException(503, 'The contact form is closed for maintenance.');
        }
    }, 10, 'plugins/Desk');
});
```

Throwing an `HttpException` is how a listener refuses a request. The framework's
own `auth`, `can`, `csrf` and `rate_limit` keys work exactly this way.

Group metadata applies to every route in the group, so
`$routes->group('/billing', …, meta: ['maintenance' => 'blocked'])` closes a whole
section.

## Change responses

To add headers to responses from particular routes, filter `dispatch.response`,
which receives the response, the route and the request:

```php
$module->filter('dispatch.response', [ApiFilters::class, 'stampVersion'], priority: 20);
```

```php
public static function stampVersion(Response $response, Route $route): Response
{
    $version = $route->metadata()['version'] ?? null;

    return \is_string($version) ? $response->withHeader('X-Api-Version', $version) : $response;
}
```

To touch **every** response, including 404s, filter `response.instance` instead.
Responses are immutable: `withHeader()` returns a new one, which is what the
filter must return.

Your listeners on either do not run for `/assets/...` requests, which are
answered before any module loads.

## Other useful points

| To | Use |
|---|---|
| react to every handled error — metrics, an error tracker | hook `error.reported` (`Throwable`, `ErrorContext`, `?Request`) |
| do something once all modules are ready | `onBoot()` in your module, or hook `app.ready` |
| hear about logins and failed logins | hooks `auth.login`, `auth.failed`, `auth.logout` |
| refuse access to a particular record | filter `authorization.decision` — see [Users and permissions](users-and-permissions.md#rules-about-a-particular-record) |
| observe jobs and schedules | hooks `job.*`, `schedule.*` — see [Background work](background-work.md) |

## Depend on another module

If your module uses another module's classes, hooks or services, say so:

```php
$module->requires('plugins/Billing', '^1.2');        // refuse to boot without it
$module->optionally('plugins/Crm', '^2.0');          // work without it; check the version if present
```

The application refuses to start — naming both modules — when a requirement is
missing, disabled, the wrong version, or circular. Registration order follows
the declarations, so your listeners run after the module you depend on has
registered.

Nothing stops a module from importing another module's classes *without*
declaring it; it works until that module is disabled or upgraded, and then fails
on whichever request touches the import. Declare every module whose classes you
use — `shared` included, since its API has a version too.
`ArchitectureTest::test_a_module_declares_every_module_whose_classes_it_uses`
checks this for the showcase modules, and is a pattern to copy for your own.

For an optional module, listening to its hooks needs no check: they never fire
without it. When you do need to know, inject `ModuleRegistry` in `onBoot()` and
ask `isEnabled('plugins/Crm')`.

A plugin cannot depend on a gateway, and `shared` cannot depend on anything:
kind order — shared, plugins, gateways — is never broken.

## Replace what another module renders or answers

- **Its templates**: copy into `templates/<active>/views/plugin.<Name>/`. See
  [Pages and forms](pages-and-forms.md#change-a-page-you-did-not-write).
- **A route**: the router keeps the last route declared for a method and path,
  and modules register in order — shared, then plugins by directory name, then
  gateways, adjusted only where `requires()` forces it. A later module declaring the same path answers it. Use a different
  route name; names are unique.
- **A service binding**: a later module binding the same interface replaces it,
  which is how an application swaps the `UserProvider`.
- **Its configuration**: `config/plugins/<Name>.php` overrides the module's
  defaults key by key. See [Configuration](../reference/configuration.md).

Relying on registration order to replace another plugin's route or service is
fragile: rename a directory and the winner changes. Declare `requires()` on the
module you replace, so your module always registers after it.

## Switch a module off

```php
// config/modules.php
return ['disabled' => ['gateways/Stripe']];
```

A disabled module does not run at all. Modules that require it refuse to boot;
modules that use it optionally carry on.
