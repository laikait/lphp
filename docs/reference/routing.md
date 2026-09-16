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

## Paths in any language

A path may be written in any script, and so may its parameters:

```php
$routes->get('/পণ্য/{slug}', ProductPage::class)
    ->where('slug', '[\p{L}\p{M}\p{N}-]+')
    ->name('products.show');

url('products.show', ['slug' => 'ঢাকা-শহর']);
// /%E0%A6%AA%E0%A6%A3%E0%A7%8D%E0%A6%AF/%E0%A6%A2%E0%A6%BE…
```

- **The request path is percent-decoded, then normalised to NFC**, and so is
  every route path as declared. The same word can arrive as different bytes —
  `é` as one character or as `e` plus a combining accent, Bengali `য়` as one
  character or as `য` plus a nukta — and both spellings reach the same route. A
  handler receives the NFC form. This is why `ext-intl` is required.
- **Constraints are matched as UTF-8.** `\p{L}` is a letter in any script and
  `\p{N}` a digit in any script. Include `\p{M}` for scripts written with vowel
  signs and other combining marks — Bengali, Hindi, Arabic, Thai — or `ঢাকা`
  fails a letters-only pattern at its `া`. A segment that is not valid UTF-8
  fails every constraint; an unconstrained parameter still receives it as it
  arrived.
- **`url()` percent-encodes both parameters and fixed segments**, but only bytes
  outside ASCII in a fixed segment, so `/v1:batch` is written exactly as declared.
- An encoded slash, `%2F`, is decoded before matching and separates segments
  like `/` does, so a parameter can never contain one.

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
