# Running in production

For whoever deploys and looks after an application built on the framework: what
has to run, what to set, what to check after every deployment, and what to do
when something is stuck.

Web server configuration and the deny rules are in [Deployment and
security](deployment.md). Every environment variable, with its default, is in
[`.env.example`](../../.env.example).

## What runs

| Process | How | Needed when |
|---|---|---|
| PHP behind a web server | Apache with `.htaccess`, or nginx + PHP-FPM | always |
| `php laika schedule:run` | cron, every minute, on **one** host | any module declares a schedule (`schedule:list` is not empty) |
| `php laika queue:work` | a supervisor, restarting it when it exits | `QUEUE_STORE=file` |

Nothing else is resident. There is no daemon to install and no port besides the
web server's.

## Before the first deployment

1. **PHP 8.2+** with `json`, `mbstring`, `pdo` and your database's PDO driver;
   `fileinfo` if the application accepts uploads. **Turn opcache on** — without
   it a request costs 45–60 ms, almost all of it compiling PHP.
2. **Connect your accounts, if the site has logins.** A fresh installation has
   none: no provider is bound, nobody can log in, and every protected route
   refuses. Bind a `UserProvider` and write a login route — see
   [Users and permissions](../guides/users-and-permissions.md).
3. **Set the environment**, as real environment variables rather than a `.env`
   file:

   | Variable | |
   |---|---|
   | `APP_ENV=production`, `APP_DEBUG=false` | debug shows stack traces and paths to anyone |
   | `APP_KEY` | `php laika security:key --bare`, stored like a password. Changing it invalidates every CSRF token |
   | `APP_TIMEZONE` | set it; do not inherit `php.ini` |
   | `DB_DSN`, `DB_USERNAME`, `DB_PASSWORD` | or `config/database.php` for several connections |
   | `SESSION_STORE`, `CACHE_STORE`, `QUEUE_STORE` | see [More than one host](#more-than-one-host) |
   | `SESSION_ABSOLUTE` | consider a ceiling; the default is none |

4. **Create the tables.** There are no migrations: each module's tables are
   created by whatever that module provides, and the database session store's by
   `php laika session:table`.
5. **Choose where logs go** — nothing is written by default. For daily files
   under `system/Logs`, create `config/logging.php`:

   ```php
   <?php

   return [
       'writers' => ['file'],
       'level' => 'info',
       'file' => ['prefix' => 'app', 'retention_days' => 30],
   ];
   ```

   `stderr` suits containers; `syslog` a host with a log shipper.
6. **Make `system/` writable** by the PHP user, and nothing else in the tree.

## Every deployment

```bash
composer install --no-dev --optimize-autoloader
php laika cache:clear
php laika cache:warm
php laika security:check
```

- `cache:warm` writes the compiled configuration and the module list, so a
  request reads two opcache-held files instead of scanning directories. It
  refuses to run with debug on.
- **Anything that changes modules or `config/` needs `cache:clear` and
  `cache:warm` again** — adding or removing a module, editing a config file.
  Changing an *environment variable* is noticed on its own.
- `security:check` exits 1 on a failure — debug on in production, rate-limit
  counters in memory — so it can fail the deployment. Warnings, such as a
  missing `APP_KEY` or a route that changes data and requires nobody, are
  printed without failing; read them.
- Restart queue workers so they run the new code (`--max-time` makes them
  restart on their own eventually).
- Read [`UPGRADING.md`](../../UPGRADING.md) when the framework itself changed.
- Never `composer dump-autoload --classmap-authoritative`: modules added later
  would not autoload.

After deploying, confirm the web server still never serves source files — run
the curl checks in [Deployment and security](deployment.md).

## Cron

```cron
* * * * *  cd /srv/app && php laika schedule:run >> /dev/null 2>&1
```

`php laika system:cron:install` writes that line into the crontab of the
user it runs as, inside a block marked with this application's name, and leaves
every other line alone. Run it on every deployment of that one host: an unchanged
line is not rewritten. `system:cron:list` shows it and `system:cron:remove` takes
it out.

One line, whatever modules are installed. **One host only**: its locks are files
on that host, so a second host would run every task again. `schedule:run` exits 1
if any task failed, which a cron monitor can alert on; each task's output goes to
the log.

## Queue workers

Run workers under something that restarts them — they are meant to exit:

```ini
# /etc/systemd/system/app-worker.service
[Service]
WorkingDirectory=/srv/app
ExecStart=/usr/bin/php laika queue:work --queue=default --max-jobs=1000 --max-time=3600
Restart=always
User=www-data
```

`--max-jobs` and `--max-time` bound memory growth and make a deployment reach
every worker. Any number of workers on one host is safe; the `file` store does
not work across hosts. A worker exits 1 when it gave up on a job.

| Variable | |
|---|---|
| `QUEUE_TRIES` | attempts before a job is moved to the failed list (default 3) |
| `QUEUE_TIMEOUT` | seconds a job may hold its reservation; a crashed worker's job runs again after this |

## Housekeeping

Schedule these from a module rather than adding cron lines:

```php
$schedules->command('session:gc')->hourly();
$schedules->command('cache:clear', '--expired')->dailyAt('04:00');
```

`session:gc` matters for the `file` and `database` session stores; expired
sessions are already refused, this reclaims the space.

## What is in system/

| Directory | Contents | Back up? |
|---|---|---|
| `system/Logs` | log files | as your log policy says |
| `system/Sessions` | live sessions — **each file is a credential** | no; losing them logs people out |
| `system/Queue` | jobs waiting, and `failed/` | yes, if pending work matters |
| `system/Cache` | compiled configuration, module list, cached data | no; `cache:warm` rebuilds it |
| `system/Security` | rate-limit counters | no |
| `system/Schedule` | locks held by running tasks | no |
| `system/Runtime` | tool caches | no |

All of it is refused by the web server, and none of it belongs in version control.

## More than one host

Three stores keep their state in files on the host, and become wrong the moment a
second host serves the same site:

| Setting | Change to | Otherwise |
|---|---|---|
| `SESSION_STORE=file` | `database` | a user reaching the other host is logged out |
| `security.counters` `file` | nothing yet — no shared counter store is built | each rate limit applies per host |
| `CACHE_STORE=file` | nothing yet — no shared cache store is built | each host caches, and invalidates, on its own |

Plus: `schedule:run` on exactly one host, and the `file` queue store on one host.

## Watching it

- **`X-Request-Id`** is on every response. A user who quotes it lets you find
  every log record for that request; `correlation_id` follows the work into the
  queue.
- **`php laika about`** — version, boot path (cached or not), modules,
  connections, what is switched on.
- **`php laika log:status --write`** — whether records actually arrive, and
  any writer that stopped after an error.
- **`SLOW_QUERY_MS=250`** logs every statement slower than that, without values.
- **`APP_PROFILE=true`**, briefly, writes where each request's time went to the
  `profile` log channel. Switch it off again.

See [Observability](../reference/observability.md).

## When something is stuck

| Symptom | Do |
|---|---|
| A scheduled task never runs since a crash | `schedule:list` shows it as running; `schedule:unlock --id=<id>` |
| Jobs piling up | `queue:status`; is a worker running for that queue? |
| Jobs failing | `queue:failed` shows why; fix, then `queue:failed --retry-all` |
| A config change has no effect | `cache:clear`, then `cache:warm` |
| A new module is missing | `cache:clear`, then `cache:warm`; check its directory's case on Linux |
| Every request is a 500 after deploying | `APP_DEBUG=1 php laika about` on the host shows the boot error — never set debug on the web server |

More in [Troubleshooting](../troubleshooting.md).
