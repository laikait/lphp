# Deployment and security

**The web server serves `public/` and nothing else.** It holds `index.php`, its
`.htaccess` and the application's own `assets/`. `engine/`, `modules/`,
`config/`, `vendor/`, `.env` and the rest of the project are one level up, where
no URL can reach them — so there is no list of directories or files to deny, and
nothing to forget to add to one. An architecture test keeps `public/` to exactly
those three entries, and `security:check` fails if a second PHP file appears in it.

**For production, point the document root at `public/`:**

```apache
<VirtualHost *:80>
    ServerName app.example.com
    DocumentRoot /srv/app/public
    <Directory /srv/app/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

`AllowOverride All` is what lets `public/.htaccess` route requests to
`index.php`; with `None` every route is a 404 while the home page still works.

**Where the document root cannot be changed** — XAMPP at
`http://localhost/framework/`, or shared hosting — serve the project directory
and its own `.htaccess` forwards every request into `public/`. Every request, by
design: `/composer.json` becomes `public/composer.json`, which does not exist, so
the application answers it. The visitor's URL never shows `/public`, and without
`mod_rewrite` that file refuses everything rather than serving the source.

Verify after any deployment. Each of these must be answered by the application
— its 404 page, or a route of yours — and **never** by the file or a redirect:

```bash
curl -i http://localhost/framework/composer.json
curl -i http://localhost/framework/engine/Core/Application.php
curl -i http://localhost/framework/modules/Shared/module.php
curl -i http://localhost/framework/vendor/autoload.php
curl -i http://localhost/framework/engine
curl -i http://localhost/framework/templates
```

Dotfiles in `public/` are refused with **403**, `/.well-known/` excepted so
certificate authorities can read their challenges:

```bash
curl -i http://localhost/framework/.htaccess
```

A route may use any of those paths — `/templates`, `/config/app` — because none
of them names a file the web server could serve.

And these, which check that the asset layer did not become a second way in.
The first two must be refused — **403** or **404** — and the last must be **200**:

```bash
curl -i --path-as-is http://localhost/framework/assets/core/../composer.json
curl -i --path-as-is http://localhost/framework/assets/core/../index.php
curl -i http://localhost/framework/assets/core/css/app.css
```

With a plugin installed, repeat the traversal against it —
`/assets/plugin/<Name>/module.php` and `/assets/plugin/<Name>/../module.php`
must be 404 too.

If routes 404 under Apache, check these two directives:

```bash
grep -E 'rewrite_module|AllowOverride' /path/to/httpd.conf
```

### nginx

Generate the server block rather than writing one:

```bash
php laika nginx:make --server-name=app.example.com --root=/srv/app --php=unix:/run/php/php8.3-fpm.sock
sudo cp nginx.conf /etc/nginx/conf.d/app.conf
sudo nginx -t && sudo systemctl reload nginx
```

`--root` is the application directory; the block serves its `public/`. The file
is written to the application directory, outside `public/`, and an existing one
is not replaced without `--force`. `--root` defaults to the directory the command
runs in, so on the server itself it can be left out; `--server-name` defaults to
`_`, any host, and `--listen` to `80`.

The block refuses dotfiles, runs no PHP file except the front controller, and
serves `/assets/core/` straight from `public/assets/`. Run the same `curl` checks
against it; `nginx -t` only proves the syntax.

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
php laika cache:clear
php laika cache:warm
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

`php laika about` says which path a process took:

```
Boot path    config cached, modules cached
Boot path    config read from config/, modules scanned (debug never reads the cache)
```
