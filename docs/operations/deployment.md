# Deployment and security

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
curl -i http://localhost/framework/templates/
```

The bare directory names are the exception, on purpose: a path such as
`/templates` or `/config` with nothing after it is handed to the application, so
it may be a route. It must be answered by the framework — its 404 page, or your
route — and **not** by a 403 or a redirect to `/templates/`:

```bash
curl -i http://localhost/framework/templates
```

`.htaccess` does this with three rules that share one list of names: route the
bare name to `index.php`, refuse anything after `name/`, and switch off Apache's
`DirectorySlash` redirect for exactly those names. An architecture test keeps the
three lists identical and in that order.

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
    <DirectoryMatch "/srv/app/(engine|modules|templates|config|system|tests|bin|vendor)">
        Require all denied
    </DirectoryMatch>
</VirtualHost>
```

The `DirectoryMatch` refuses each directory **itself**, before `.htaccess` runs,
so behind this virtual host a route named `/templates`, `/config` or any other
name on the list is a 403. Leave it out and rely on `.htaccess` if an application
needs such a route; keep it if none does.

### nginx

Generate the server block rather than writing one:

```bash
php bin/console nginx:make --server-name=app.example.com --root=/srv/app --php=unix:/run/php/php8.3-fpm.sock
sudo cp nginx.conf /etc/nginx/conf.d/app.conf
sudo nginx -t && sudo systemctl reload nginx
```

It writes `nginx.conf` in the application root, which `.htaccess` and
`composer serve` both refuse to serve, and will not replace an existing one
without `--force`. `--root` defaults to the directory the command runs in, so on
the server itself it can be left out; `--server-name` defaults to `_`, any host,
and `--listen` to `80`.

The block refuses the same directories and metadata as `.htaccess` — an
architecture test compares the two lists name for name — lets the bare directory
names through to `index.php`, and runs no PHP file except the front controller.
Run the same `curl` checks against it; `nginx -t` only proves the syntax.

One difference from Apache: `/assets/core/` is served straight from `assets/`
without passing the deny rules, so keep nothing but assets in that directory.

## More than one web server

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

## The production boot path

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
