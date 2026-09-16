# Routing

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
`can`, `csrf` and `rate_limit` keys work (see [Security](security.md) and
[Authentication and authorization](auth.md)). An
application adds its own the same way:

```php
$routes->post('/invoices/run', $handler)->meta(['maintenance' => 'blocked']);

$hooks->add('dispatch.before', static function (Route $route) use ($settings): void {
    if ($route->metaValue('maintenance') === 'blocked' && $settings->bool('billing.frozen')) {
        throw new HttpException(503);
    }
});
```

## Handlers

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
