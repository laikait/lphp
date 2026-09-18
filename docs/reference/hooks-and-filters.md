# Hooks and filters

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

## Helpers are not facades

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
injection, as the ones in `modules/Shared/` and the showcase do.

## Lifecycle extension points

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
| `command.matched` | `asset.response` (engine listeners only — see [Assets](assets.md#an-asset-request-loads-no-module)) |
| `command.finished` | `dispatch.response` |
| `command.failed` |  |
| `error.reported` |  |
| `job.queued`, `job.started` |  |
| `job.finished`, `job.failed` |  |
| `schedule.started` |  |
| `schedule.finished`, `schedule.failed` |  |
| `session.started` | `system.command.max_output` (narrow only) |
| `session.regenerated` |  |
| `auth.identified`, `auth.login` |  |
| `auth.logout`, `auth.failed` |  |
| `auth.rehash` | `authorization.decision` |
| `system.audit` | `system.command.timeout` (narrow only) |
| `mcp.request.received`, `mcp.request.failed` | `mcp.tool.input` (before validation) |
| `mcp.response.created`, `mcp.access.denied` | `mcp.tool.output` |
| `mcp.tool.before`, `mcp.tool.after`, `mcp.tool.failed` | `mcp.tool.description` |
| `mcp.resource.read`, `mcp.resource.failed` | `mcp.resource.output` |
| `mcp.prompt.loaded`, `mcp.prompt.failed` | `mcp.prompt.output` |
| `database.query.failed` |  |
| `database.transaction.committed`, `database.transaction.rolled_back` |  |
| `database.transaction.retrying` |  |

The `database.*` hooks report what has already happened. A listener cannot undo
a commit or replace a database failure, and nothing it throws causes a
transaction to be retried. See
[Database](database.md#watching-statements-and-transactions) for their
arguments.

The `mcp.*` extension points never run ahead of MCP's security checks. A
capability the caller may not use reaches no listener, `mcp.tool.input` runs
before the arguments are validated against the tool's schema, and an output
filter must return the same kind of value it was given. A listener can refuse
by throwing an MCP exception, but no listener can grant access.
