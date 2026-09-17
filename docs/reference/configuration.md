# Configuration

```php
final class SendInvoice
{
    public function __construct(private readonly Config $config) {}

    public function __invoke(): void
    {
        $from = $this->config->string('billing.sender', 'billing@example.com');
        $size = $this->config->int('plugins/Example.page_size', 25);
    }
}
```

Four sources, in this order, each overriding the one before it:

| | |
|---|---|
| `Bootstrap::defaults()` | every key the framework reads, with a working value |
| `config/*.php` | what this installation decided |
| whatever `Bootstrap::create()` is handed | an embedding application, or a test |
| a module's `config()` declaration | **defaults only** — see below |

The environment is not a fifth layer. Config files read it themselves, so the
precedence is visible in the file somebody has open rather than hidden in a
merge order in another directory:

```php
// config/database.php
return [
    'default' => Env::string('DB_CONNECTION', 'default'),
    'connections' => [
        'default' => [
            'dsn' => Env::string('DB_DSN', 'sqlite::memory:'),
            'username' => Env::string('DB_USERNAME'),
            'password' => Env::string('DB_PASSWORD'),
        ],
    ],
];
```

PHP files rather than YAML, JSON or INI: the file is read by PHP, opcache
already caches it, a typo is a parse error on the line it happened on, and an
editor can complete the constants it references. A format that needs a parser
buys a parser.

**The filename is the namespace.** `config/database.php` lands under
`database`, so the file somebody opens to change a connection is the one named
after it. Nothing has to be registered and there is no lookup table between the
two to maintain.

**Nothing in `config/` is required.** An application with no such directory runs
on the defaults and the environment.

## Configuring a module

A subdirectory joins with a slash, and a module's id *is* a path:

```php
// config/plugins/Example.php — the module at modules/Plugins/Example
return ['page_size' => 10];
```

```php
// modules/Plugins/Example/module.php
$module->config(['page_size' => 25]);
```

The file wins, and that direction is the point. A module's `config()` is
**defaults** — values written by whoever wrote the module — and the file is the
**decision**, made by whoever runs the installation. Modules register long after
`config/` has been read, so merging their values the ordinary way would have
every module quietly overwrite whatever the application had configured for it.
The symptom of getting this backwards is a config file that appears to do
nothing at all, so `Config::defaults()` is a separate method from `merge()` and
a test holds each direction.

Only the keys a file names are overridden; the rest of a module's defaults are
untouched, so the file never has to be kept in step with the module's.

## Typed retrieval does not coerce

```php
$config->string('app.timezone', 'UTC');   // ?string
$config->int('logging.file.retention_days', 0);
$config->bool('app.debug');
$config->float('billing.rate');
$config->array('database.connections');
$config->strings('logging.writers');       // list<string>
```

A missing key takes the default. A key that is present but of the wrong type
throws, naming the key, the type wanted and what was there instead.

That is deliberate and it is the opposite of defensive. `'retention_days' =>
'30'` in a file, absorbed by a fallback, silently becomes `0` — which means keep
every log forever, and nobody finds out for a year. It is a mistake in a file
somebody has open right now, and the cheapest moment to mention it is right now.

The environment is the one place a setting legitimately arrives as text, and
that is exactly where the parsing lives.

## The environment is read in one place

`Env` is the only class in the framework that reads it — an architecture test
enforces that, and a second forbids `putenv()` anywhere, because it is not
thread-safe and this is a ZTS build.

```php
Env::string('APP_ENV', 'production');
Env::bool('APP_DEBUG', false);   // true/false, yes/no, on/off, 1/0
Env::int('LOG_RETENTION_DAYS', 30);
Env::list('TRUSTED_PROXIES');    // comma separated
```

`"false"` is a non-empty string and therefore `true` to PHP, which is the single
most expensive gotcha in this area; here it is `false`. An empty variable counts
as absent, because `APP_ENV=` is a variable somebody meant to fill in. A boolean
that reads `maybe`, or a number that reads `30 days`, stops the boot and says
which variable it was rather than guessing.

Every variable the engine reads is listed in [`.env.example`](../../.env.example), and
a test fails if one is added without being documented there.

## .env fills gaps, it does not override

A `.env` file is loaded if there is one, for machines with no real environment
to speak of — a laptop, a CI container. **A real environment variable always
wins**, which is the opposite of the usual "last loader wins" and is what makes
loading it unconditionally safe: a `.env` left behind on a server cannot
override what the deployment set. Values go into `$_ENV` only.

There is no variable interpolation. `${OTHER}` stays the six characters it looks
like — interpolation turns a flat list of settings into a small programming
language, and the first question it raises, whether it sees the real environment
or the file, has no good answer. A line that is not an assignment stops the boot
rather than being skipped: a setting that silently fails to apply is worse than
a boot that stops.

## Cached configuration

```bash
php bin/console config:cache          # compile config/ and the defaults into one file
php bin/console config:cache --clear  # or cache:clear, which clears every cache
php bin/console config:list --sources # what resolved, and where it came from
php bin/console cache:warm            # this cache and the module discovery cache, in one step
```

The cache is one `var_export`ed array in `system/Cache/config.php`, which
opcache already holds. Once it exists it is used — there is no setting to switch
it on, because that setting would have to be read out of the configuration this
is building.

**It carries the environment it was built from.** The well-known failure of
cached configuration is that config files read environment variables, the cache
freezes those values, and changing a variable afterwards then does nothing at
all — silently, with no error and no clue. Because every read goes through `Env`,
`Env` can record what it was asked and what it answered, and that record goes
into the file. On load the variables are compared against the environment as it
is now, and one difference makes the cache stale and it is ignored:

```bash
php bin/console config:cache                      # built with APP_DEBUG unset
APP_DEBUG=1 php bin/console config:list -p app    # app.debug true: the cache noticed
```

Editing a config file does **not** invalidate it. Noticing that would mean
stat-ing every file on every request, which is the work the cache exists to
avoid, and a deployment that changes configuration is a deployment — it runs
`cache:clear`. The environment is different: it changes without any file
changing, and checking it costs a few dozen string comparisons.

Anything that is not plain data — a closure, an object — is refused when the
cache is written, by name, rather than being written happily and failing as a
fatal error inside a generated file on the way back in. A test asserts the
framework's own shipped configuration can always be cached, so nobody discovers
otherwise while running `config:cache` on a production machine.

## config/ is not web-readable

It holds the database credentials, and it is outside `public/`, the only
directory any web server serves — Apache, nginx and the `php -S` router alike.
So is `.env`, which matters more than it looks: a `.env` is not a `.php` file,
so a server that could reach one would hand it over as plain text.

`config:list` prints `[hidden]` for a handful of key names — `password`,
`token`, `dsn` and a few more. There is deliberately no flag to reveal them: a
flag like that exists to be used, and where it gets used is a terminal somebody
is sharing their screen from.
