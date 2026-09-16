# Troubleshooting

Symptoms, what causes them, and the fix. Start with the two commands that
answer most questions:

```bash
php bin/console about          # version, boot path, modules, connections
APP_DEBUG=1 php bin/console …  # the full message and trace, on your terminal only
```

Outside debug mode the framework withholds error messages it did not write,
from browsers, API clients and the console alike. Debug on a terminal is safe;
debug on a public web server is not.

## Installing and starting

**Every page under Apache is a 404, but `composer serve` works.**
`mod_rewrite` is not loaded, or `AllowOverride` is `None` for the directory, so
`.htaccess` is ignored. `grep -E 'rewrite_module|AllowOverride' httpd.conf`.
Without it, the deny rules are ignored too — see
[Deployment and security](operations/deployment.md).

**Links and styles point at the wrong directory.** The base path comes from
`SCRIPT_NAME`. Behind a proxy that rewrites paths, set `http.base_path` in
`config/http.php`.

**A route under an application directory — `/templates`, `/config/app`,
`/modules/list` — is a 403 or a 301.** Every path under those directories is
routed to the application. A 403 means an older `.htaccess`, or a virtual host
whose `DirectoryMatch` denies the directory — see
[Deployment and security](operations/deployment.md). A 301 to the same path with
a slash added means the `DirectorySlash Off` block is missing from `.htaccess`,
and the path is a real directory.

**It is slow — 50 ms for a simple page.** Opcache is off. It is off in XAMPP by
default.

**Every request is a 500 after a change.**
Boot checks run before any request: an unknown or circular module dependency, a
route requiring an undeclared capability, a schedule naming a command that does
not exist, a malformed `.env` line or environment value. Run
`APP_DEBUG=1 php bin/console about` to read the message; it names the module,
route or variable.

## Modules

**A new module does not appear in `module:list`.**

- A module cache from `cache:warm` exists and debug is off. Run
  `php bin/console cache:clear`.
- The file is not `modules/Plugins/<Name>/module.php` exactly, or does not
  `return` a closure.
- It is listed in `modules.disabled` — `module:list` shows disabled modules
  beneath the table.

**`Class "App\Modules\Plugins\…" not found`, on Linux only.** The directory's
case does not match the namespace. Windows and macOS ignore case; Linux does not.

**"Nothing declares the capability …", naming a route.** A route has
`meta(['can' => …])` with a capability no module declared — usually a typo.
Declare it with `$module->access()`, or fix the name.

**"…() is not static, so [… ::class, '…'] cannot be used as a listener".** `[Class::class, 'method']`
is a static call. Make the method static, or register an instance listener from
`onBoot()` — see [Extending other modules](guides/extending-other-modules.md#listen-with-an-object-that-has-dependencies).

**A filter throws about a null value, in debug mode only.** In debug mode a filter listener that returns
nothing for a non-null value throws, naming the listener. It forgot `return`.

**My `/` route is ignored.** It is named `home`, which the shared module already
uses — route names are unique, so boot fails. Give it another name.

## Requests

**A form post is refused with 403.**

- The form has no `_token` field, or it does not match the `XSRF-TOKEN` cookie.
- The browser is blocking the cookie.
- The page was rendered on the browser's **first** visit: in 0.1.0 the printed
  token and the cookie set with it differ then. Reloading the form fixes it.
- It is a cross-origin request: the `Origin` header names another site.

**A form or upload is refused with 413.** Either the body is larger than
`MAX_REQUEST_BYTES`, or it was larger than php.ini's `post_max_size` and PHP
discarded it before the application saw it — the message says which. Raise the
limit that refused it (and `upload_max_filesize` for files).

**A JSON endpoint answers 415.** The request has no `Content-Type:
application/json` and the handler calls `requirePayload()`.

**API requests with a bearer token get 401 under Apache, and work under
`composer serve`.** Apache is dropping the `Authorization` header. Keep the
shipped `.htaccess` rules, or add `CGIPassAuth On` to the virtual host.

**401 or 403?** 401: nobody is logged in. 403: someone is, and lacks the
capability — `php bin/console auth:access` shows who has what.

**429 Too Many Requests in development.** Rate-limit counts are files under
`system/Security` and outlive the process. Delete that directory.

**An API client gets an HTML error page.** It did not send
`Accept: application/json`.

**A 406.** The handler called `negotiate()` and nothing it offers matches the
`Accept` header.

## Templates and assets

**The wrong template renders.** Directories are searched in order — the active
template, its override of the module, the module — and in each, `.twig` before
`.php`. `php bin/console template:list` prints the order.

**A template "is not found" after switching `APP_TEMPLATE`.** A template does not
fall back to `default`; copy the pages it needs, including `layout.twig`,
`home.twig` and `errors/`.

**My error page is not shown.** Debug mode always shows the diagnostic page.
Turn `APP_DEBUG` off to see yours. An error template that itself throws also
falls back to the built-in page.

**A module's stylesheet is a 404.** It must be under the module's `assets/`
directory, with an allowed extension, and be linked through
`asset()->plugin('<Name>', 'css/x.css')`. `php bin/console asset:list` shows
what is published. HTML files are never served as assets.

**The browser keeps an old stylesheet.** The URL must come from `asset()`, which
adds a content hash. A hand-written `/assets/...` URL has no version.

## Data

**Everything I saved is gone on the next request.** No database is configured, so
repositories use memory that lasts one request. Set `DB_DSN`.

**"no such table".** Nothing creates tables; there are no migrations. Create them
yourself — see [Storing data](guides/storing-data.md#create-the-tables).

**A model property is never filled.** Columns match constructor **parameter
names** exactly, including case.

**`related()` throws.** Relations are loaded explicitly with
`keysFor()` and `link()` before `related()` is called — there is no lazy loading.

**A queue worker sees stale data.** Models are identity-mapped per process. Call
`ModelManager::flush()` at the start of a job that must read fresh rows.

## Configuration

**A change in `config/` has no effect.** A configuration cache exists —
`php bin/console config:list --sources` says where values came from. Run
`cache:clear`.

**A configuration key is "of the wrong type".** Typed configuration reads do not
convert: write `30`, not `'30'`, in a config file. The message names the key.

**An environment variable stops the boot.** Booleans must be
`true/false/yes/no/on/off/1/0`, numbers must be numbers. The message names the
variable.

**`.env` is ignored.** A real environment variable of the same name wins, always.

## Background work

**Jobs are never processed.** `QUEUE_STORE=file` needs `queue:work` running. With
the default `sync`, jobs run immediately inside the request.

**A job runs twice.** A worker died, or the job ran longer than `QUEUE_TIMEOUT`,
so its reservation expired and another worker took it. Make jobs safe to repeat,
and raise the timeout for slow ones.

**A scheduled task stopped running.** A process running it was killed, and its
lock is still held: `schedule:list` shows it as running; `schedule:unlock --id=<id>`.

**Every scheduled task runs twice.** `schedule:run` is in cron on two hosts.

**A command's output from a schedule is nowhere.** It goes to the log, and nothing
is logged until a writer is configured — `php bin/console log:status`.

## Sessions and logins

**Users are logged out at random.** Several hosts with `SESSION_STORE=file`. Use
`database`.

**Logged out after exactly two hours.** `SESSION_IDLE` defaults to 7200 seconds.

**Two users see each other's data.** A singleton service takes `Session` in its
constructor and keeps the first request's session. Ask for `Session` as a handler
or method parameter instead.

**A session value comes back as an array, not the object stored.** Session data
is JSON. Store an id, and load the object.

## Logs

**Nothing is logged.** No writer is configured by default. See
[Running in production](operations/running.md#before-the-first-deployment).

**Logging stopped.** A writer that fails — a full disk, a permission — is retired
for the rest of the process. `php bin/console log:status` says which and why.

## Tests

**"Cannot override final method PHPUnit\Framework\TestCase::output()".** A test
helper is named after a final PHPUnit method — `output()`, `matches()`. Rename it.

**Framework tests fail after adding a module.** `DefaultPagesSliceTest` describes
a fresh install. See [Testing](guides/testing.md#traps).

**A PHP warning fails a test.** PHPUnit is configured to fail on warnings,
notices and deprecations. Fix the warning.
