# Assets

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
| `/assets/core/` | `public/assets/` |
| `/assets/template/` | `templates/<active>/assets/` |
| `/assets/template/admin/` | `templates/admin/assets/` |
| `/assets/plugin/Example/` | `modules/Plugins/Example/assets/` |
| `/assets/gateway/Stripe/` | `modules/Gateways/Stripe/assets/` |

**A module is published because it has an `assets/` directory**, not because it
asked to be. There is nothing about assets in any `module.php`. That is a
deliberate asymmetry with everything else a module declares: the URL space is
`/assets/plugin/<name>/` for every plugin, so a declaration could only ever say
"yes" or be wrong. `php bin/console asset:list` shows what ended up published.

The shared module is excluded. Its id is just `shared`, with no name of its own,
so no URL could address it; assets belonging to the application as a whole are
the application's own, under `public/assets/`.

## Why PHP serves them at all

Because the web server serves only `public/`, and `modules/` is outside it —
where `module.php` and every repository live. A plugin's `assets/` directory is
therefore unreachable by the web server *by design*, and the asset server is what
makes those files reachable without unlocking the directory holding the source. With a plugin called `Example`
installed that ships `assets/js/example.js`, you can prove both halves at once:

```bash
curl -i http://localhost/framework/modules/Plugins/Example/assets/js/example.js  # 404, from the application
curl -i http://localhost/framework/assets/plugin/Example/js/example.js           # 200
```

The application's own `public/assets/` directory is different: the web server can
serve it directly, and letting it is faster and fully supported. The URL scheme is
the same either way.

## What is checked before a file is delivered

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

## Versions and caching

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

## Resolution and delivery are separate

`AssetManager` resolves and never delivers; it cannot see `Request` or
`Response` at all, and an architecture test enforces it. `AssetServer` delivers.
They share one constant, the `/assets` prefix.

That separation is what the specification asks for, and the reason is different
lifetimes: a URL gets generated in a template, a CLI job or a queued email where
there is no request anywhere, while delivery is one HTTP handler that a web
server or CDN should eventually take over. Pointing `assets.url` at a CDN origin
is a one-line config change that no application code notices.

## An asset request loads no module

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
