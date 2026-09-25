# Running in production

For whoever deploys the application and looks after it afterwards:

1. what has to be running,
2. what to do before the first deployment,
3. what to run on every deployment,
4. cron and queue workers,
5. what lives in `system/`, and what to back up,
6. running on more than one machine,
7. how to watch it, and what to do when something is stuck.

Setting up the web server is in [Deployment and security](deployment.md). Every
environment variable, with its default, is listed in
[`.env.example`](../../.env.example).

## What runs

| Process | How to run it | Needed when |
|---|---|---|
| PHP behind a web server | Apache with `.htaccess`, or nginx with PHP-FPM | always |
| `php laika schedule:run` | from cron, every minute, on **one** machine | any module has a schedule (`php laika schedule:list` is not empty) |
| `php laika queue:work` | under something that restarts it | `QUEUE_STORE` is `file` or `database` |

Nothing else runs in the background. There is no daemon to install and no port
to open besides the web server's.

## Before the first deployment

1. **PHP 8.2 or newer**, with the `json`, `mbstring` and `pdo` extensions, plus
   the PDO driver for your database, and `fileinfo` if the application accepts
   uploads.

   **Turn opcache on.** Without it every request spends 45–60 ms compiling PHP.

2. **Connect your user accounts, if the site has logins.** A fresh installation
   has none: nobody can log in, and every protected page refuses everybody. See
   [Users and permissions](../guides/users-and-permissions.md).

3. **Set the environment.** Use real environment variables, not a `.env` file, so
   that secrets are not sitting in the project folder:

   | Variable | Why |
   |---|---|
   | `APP_ENV=production`, `APP_DEBUG=false` | debug shows stack traces, paths and settings to anyone who triggers an error |
   | `APP_KEY` | get one with `php laika security:key --bare`, and keep it like a password. Changing it makes every open form's CSRF token invalid |
   | `APP_TIMEZONE` | set it; do not rely on what `php.ini` happens to say |
   | `MEMORY_LIMIT` | PHP's `memory_limit`, e.g. `256M`, for the same reason; queue workers stop at 80% of it |
   | `DB_DSN`, `DB_USERNAME`, `DB_PASSWORD` | your database; or `config/database.php` for several |
   | `SESSION_STORE`, `CACHE_STORE`, `QUEUE_STORE` | see [More than one host](#more-than-one-host) |
   | `SESSION_ABSOLUTE` | a maximum session length, in seconds; there is none by default |

4. **Create the database tables:**

   ```bash
   php laika migrate --pretend --connection=<name>   # show the SQL, run nothing
   php laika migrate --connection=<name>
   ```

   `--connection` lets you use an account that is allowed to create tables. The
   account the application itself uses should not be allowed to.

   Run `migrate` on every deployment. It does nothing when nothing is pending,
   and only one deployment can run it at a time. Sessions, the cache, the queue
   and the log get their tables here too, when they are set to `database`.

5. **Decide where log records go.** Nothing is written by default. For daily
   files under `system/Logs`, create `config/logging.php`:

   ```php
   <?php

   return [
       'writers' => ['file'],
       'level' => 'info',
       'file' => ['prefix' => 'app', 'retention_days' => 30],
   ];
   ```

   | Writer | Suits |
   |---|---|
   | `file` | one machine; daily files, deleted after `retention_days` |
   | `stderr` | containers, where the platform collects output |
   | `syslog` | a machine that already ships its system log somewhere |
   | `database` | several machines sharing a database — always **alongside** one of the others, since a log in the database cannot record the database failing |

   `database` needs `php laika migrate` once, for its table.

6. **Make `system/` writable** by the user PHP runs as, and nothing else in the
   project.

## Every deployment

```bash
composer install --no-dev --optimize-autoloader
php laika cache:clear
php laika cache:warm
php laika migrate
php laika security:check
```

- **`cache:warm`** prepares the configuration and module list, so a request reads
  two ready-made files instead of searching folders. It refuses to run with debug
  on.
- **Always `cache:clear` before `cache:warm`.** Adding or removing a module, or
  editing anything in `config/`, is **not** noticed on its own. Changing an
  environment variable is.
- **`security:check`** exits with 1 on a real problem — debug on in production,
  rate-limit counters in memory — so it can stop a deployment. Warnings, such as
  a missing `APP_KEY` or a route that changes data and requires nobody, are
  printed without stopping it. Read them.
- **Restart the queue workers**, so they run the new code. (`--max-time` makes
  them restart by themselves eventually, but not immediately.)
- **Read [`UPGRADING.md`](../../UPGRADING.md)** when the framework itself changed.
- **Never run `composer dump-autoload --classmap-authoritative`.** Modules added
  afterwards would not load.

Then run the `curl` checks in [Deployment and security](deployment.md) once more,
to confirm the web server still serves no source files.

## Cron

Cron runs the scheduler once a minute; the scheduler decides what is due:

```cron
* * * * *  cd /srv/app && php laika schedule:run >> /dev/null 2>&1
```

Instead of editing the crontab by hand:

```bash
php laika system:cron:install   # add that line for the current user
php laika system:cron:list      # show what this application installed
php laika system:cron:remove    # take it out again
```

`system:cron:install` writes inside a block marked with this application's name
and leaves every other line alone, so it is safe to run on every deployment: an
unchanged line is not rewritten.

**One line, one machine**, however many modules declare schedules. The locks that
stop a task running twice are files on that machine, so a second machine would
run everything again. `schedule:run` exits with 1 if a task failed, which a cron
monitor can alert on; each task's output goes to the log.

## Queue workers

A worker is meant to exit, so run it under something that starts it again — here,
systemd:

```ini
# /etc/systemd/system/app-worker.service
[Service]
WorkingDirectory=/srv/app
ExecStart=/usr/bin/php laika queue:work --queue=default --max-jobs=1000 --max-time=3600
Restart=always
User=www-data
```

- `--max-jobs` and `--max-time` make the worker stop regularly, which keeps
  memory in check and makes sure a deployment's new code reaches every worker.
  It also stops, between jobs, at 80% of `MEMORY_LIMIT` (or `--memory=`), so it
  is restarted cleanly rather than killed mid-job.
- With `QUEUE_STORE=file`, any number of workers on **one** machine is fine.
  With `database`, workers on any number of machines share the same queue.
- A worker exits with 1 when it gave up on a job.

| Variable | Means |
|---|---|
| `QUEUE_TRIES` | how many attempts before a job is moved to the failed list (3 by default) |
| `QUEUE_TIMEOUT` | how many seconds a job may be held; after that, a crashed worker's job is run again |

## Housekeeping

Declare these in a module rather than adding more cron lines:

```php
$schedules->command('session:gc')->hourly();
$schedules->command('cache:clear', '--expired')->dailyAt('04:00');
```

- **`session:gc`** deletes sessions past their lifetime, for the `file` and
  `database` session stores. Expired sessions are already refused; this reclaims
  the space.
- **`cache:clear --expired`** removes only entries that have expired, and leaves
  the rest in place.

## What is in `system/`

| Folder | Holds | Back up? |
|---|---|---|
| `system/Logs` | log files | as your log policy says |
| `system/Sessions` | live sessions — **each file lets someone in** | no; losing them just logs people out |
| `system/Queue` | jobs waiting, and `failed/` | yes, if unfinished work matters |
| `system/Cache` | prepared configuration, module list, cached values | no; `cache:warm` rebuilds it |
| `system/Security` | rate-limit counters | no |
| `system/Schedule` | locks held by running tasks | no |
| `system/Runtime` | tool caches | no |

None of it is reachable from the web, and none of it belongs in git.

## More than one host

Five things keep their state in files on one machine, and become wrong as soon
as a second machine serves the same site:

| Setting | Change to | Otherwise |
|---|---|---|
| `SESSION_STORE=file` | `database` | a visitor sent to the other machine is logged out |
| `security.counters` `file` | nothing yet — no shared counter store is built | each rate limit counts per machine |
| `CACHE_STORE=file` | `database` | each machine caches, and clears, on its own |
| `QUEUE_STORE=file` | `database` | each machine's workers see only that machine's jobs |
| `logging.writers` `file` | add `database`, or ship the file with `syslog` | each machine's log stays on that machine |

`php laika migrate` creates the tables for whichever of these you set to
`database`. And, as above: `schedule:run` on exactly one machine.

## Watching it

- **`X-Request-Id`** is on every response. When a user quotes it, you can find
  every log record for that request. The same id follows the work into the queue
  as `correlation_id`.
- **`php laika about`** — version, whether the caches are in use, modules,
  connections, what is switched on.
- **`php laika log:status --write`** — sends a real record through the real
  writers, and reports any writer that has stopped after an error.
- **`SLOW_QUERY_MS=250`** logs every database statement slower than 250 ms. The
  statement is logged; the values in it never are.
- **`APP_PROFILE=true`**, for a short while, records where each request's time
  went, to the `profile` log channel. Switch it off again.

See [Observability](../reference/observability.md).

## When something is stuck

| Symptom | What to do |
|---|---|
| A scheduled task has not run since a crash | `php laika schedule:list` shows it as running; release it with `schedule:unlock --id=<id>` |
| Jobs are piling up | `php laika queue:status`; check a worker is running for that queue |
| Jobs are failing | `php laika queue:failed` shows why; fix the cause, then `queue:failed --retry-all` |
| A configuration change does nothing | `php laika cache:clear`, then `cache:warm` |
| A new module is missing | `cache:clear`, then `cache:warm`; on Linux, check the folder's upper and lower case |
| Every request is a 500 after deploying | on the host, `APP_DEBUG=1 php laika about` shows the boot error. Never switch debug on for the web server |

More in [Troubleshooting](../troubleshooting.md).
