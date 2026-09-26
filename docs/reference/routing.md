# Routing

A *route* maps a URL to a handler. Modules declare them; nothing is scanned.

```php
$module->routes(static function (RouteCollector $routes): void {
    $routes->get('/customers', ListCustomers::class)->name('customers.index');
    $routes->get('/customers/{id}', [CustomerApi::class, 'show'])
           ->where('id', '\d+')
           ->name('customers.show');
    $routes->post('/customers', [CustomerApi::class, 'store'])
           ->meta(['auth' => true, 'can' => 'customer.create']);
});
```

| Method | Declares |
|---|---|
| `get` · `post` · `put` · `patch` · `delete` | one route |
| `any($path, $handler)` | the same handler for every method |
| `group($prefix, $routes, $name, $meta)` | a prefix, a name prefix and shared meta |
| `->name(…)` | the name `url()` uses. Names must be unique |
| `->where($param, $regex)` · `->whereMany([...])` | what a parameter may contain |
| `->meta([...])` | `auth`, `can`, `csrf`, `rate_limit`, and anything of your own |

`php laika route:list` prints every route, its module and its access.

## Handlers

Three forms. No base class, no controllers folder:

```php
$routes->get('/customers', ListCustomers::class);              // __invoke
$routes->get('/customers/{id}', [CustomerApi::class, 'show']); // method
$routes->get('/ping', static fn (): Response => new Response('pong'));
```

**Route parameters bind by name; the `Request` binds by type.** So a route
parameter called `request` cannot displace the real request.

A parameter declared `int`, `float` or `bool` is converted only where the
conversion is unambiguous. Anything else is a clean 400, never a `TypeError`
and a 500.

What you return becomes the response:

| Return | Becomes |
|---|---|
| `Response` | itself |
| `string` | an HTML response |
| `array` or `JsonSerializable` | JSON |
| `null` | 204 No Content |
| anything else | an explicit failure, rather than a guess |

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
  `\p{N}` a digit in any script. **Include `\p{M}`** for scripts written with
  vowel signs and other combining marks — Bengali, Hindi, Arabic, Thai — or
  `ঢাকা` fails a letters-only pattern at its `া`. A segment that is not valid
  UTF-8 fails every constraint; an unconstrained parameter still receives it as
  it arrived.
- **`url()` percent-encodes both parameters and fixed segments**, but in a fixed
  segment only the bytes outside ASCII, so `/v1:batch` is written exactly as
  declared.
- An encoded slash, `%2F`, is decoded before matching and separates segments the
  way `/` does, so a parameter can never contain one.

## There is no middleware

Anywhere. Cross-cutting behaviour is a lifecycle hook that reads route metadata —
which is exactly how the framework's own `auth`, `can`, `csrf` and `rate_limit`
keys work (see [Security](security.md) and
[Authentication and authorization](auth.md)).

Your application adds its own the same way:

```php
$routes->post('/invoices/run', $handler)->meta(['maintenance' => 'blocked']);

$hooks->add('dispatch.before', static function (Route $route) use ($settings): void {
    if ($route->metaValue('maintenance') === 'blocked' && $settings->bool('billing.frozen')) {
        throw new HttpException(503);
    }
});
```

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| Every route but `/` is a 404 | The web server is not sending URLs to `index.php` | See [Deployment](../operations/deployment.md) |
| A non-English path 404s when constrained | The pattern has no `\p{M}` | Use `[\p{L}\p{M}\p{N}-]+` |
| The application refuses to start over a route name | Names must be unique, and `home` is taken by the shared module | Rename yours |
| A parameter arrives as a string | It is unconstrained and untyped | Type the handler parameter |
| A 400 instead of your handler | A parameter could not be converted to the declared type | Loosen the type, or constrain the route |
| The boot names a capability | `meta(['can' => …])` names one nobody declared | Declare it with `$module->access()` |

## Why it works this way

Matching is two-tiered: an O(1) hash lookup for static paths, then a segment
trie for parametric ones, compiled lazily on the first match.

The trie is preferred over combined regexes for three reasons. It is what route
caching wants — nested arrays that `var_export()` and come back through
`require`. Per-segment constraints stay cheap. And a large PCRE alternation over
hundreds of routes is a real backtracking risk, while walking segments is linear
in path depth however many routes there are.

A literal segment always beats a parameter at the same depth, so static routes
win deterministically, with no ordering rules to remember.
