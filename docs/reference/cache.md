# Cache

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

## Stores, and the two that are not here

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

## What is kept apart from what is missing

A store that answers `null` for both cannot hold a `null`, and the consequence
is not theoretical: `remember()` around a lookup that legitimately answers "no
such customer" would query on every single request, silently, while appearing to
work. So a miss is a missing `CacheEntry` and a stored `null` is an entry whose
value is `null`; `has()` and `get() !== null` are different questions; and
`remember()` caches a computed `null`. Every store is held to that by the
conformance test.

## Keys and namespaces

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

## What the framework caches with it

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
`cache:warm` (see [The production boot path](../operations/deployment.md#the-production-boot-path)), because
they hold plain data that changes only at deploy time — which is what opcache is
better at than anything here could be — and because configuration is read
before a `Cache` can exist at all. A cache subsystem configured by the
configuration it caches is a bootstrap problem, not a design.

## Invalidation is the application's job

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

## Clearing

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
