# Default settings

Every environment variable the framework reads, and every configuration key with
the value it has when nothing sets it. How settings are loaded is explained in
[Configuration](configuration.md); this page is the list.

## Who wins

The framework's defaults read the environment variables. A file in `config/`
comes after them, and **replaces a key it names**:

```
.env              APP_DEBUG=true
config/app.php    return ['debug' => false];
result            app.debug is false: the file named the key, so APP_DEBUG is not used for it
```

To keep a key under the environment's control, read it in the file:

```php
// config/app.php
return ['debug' => Env::bool('APP_DEBUG', false)];
```

Keys the file does not name keep their defaults, environment variables included.
A real environment variable beats the same name in `.env`.

## Environment variables

| Variable | Config key | Default | What it does |
|---|---|---|---|
| `APP_ENV` | `app.env` | production | Free-form name of the environment; yours to branch on. |
| `APP_DEBUG` | `app.debug` | false | Stack traces and messages in responses, and the Whoops page. Never true in production. |
| `APP_EDITOR` | `app.editor` | unset | Editor the debug page links file paths to: phpstorm, vscode, sublime, … |
| `APP_TIMEZONE` | `app.timezone` | UTC | Timezone for every date. An unknown name stops the boot. |
| `MEMORY_LIMIT` | `app.memory_limit` | unset (php.ini) | PHP memory_limit: 256M, 1G, -1. Workers stop at 80% of it. |
| `MAX_EXECUTION_TIME` | `app.max_execution_time` | unset (php.ini) | Seconds a web request may run; 0 = no limit. Not the console. |
| `APP_KEY` | `security.key` | unset | The one secret: signs CSRF tokens. php laika security:key. |
| `CSRF_ENABLED` | `security.csrf.enabled` | true | CSRF checks on unsafe methods. |
| `MAX_REQUEST_BYTES` | `security.max_request_bytes` | 8388608 (8 MiB) | Larger request bodies get 413. |
| `LOCALIZATION_COUNTRY_HEADER` | `localization.country_header` | unset | CDN country header, e.g. CF-IPCountry; trusted proxies only. |
| `LOCALIZATION_MAXMIND_DATABASE` | `localization.maxmind_database` | unset | Local GeoLite2/GeoIP2 Country or City .mmdb file. |
| `DB_DSN` | `database.connections` | unset | One database with no config file, e.g. mysql:host=…;dbname=…. |
| `DB_USERNAME` | `database.connections` | unset | User for DB_DSN. |
| `DB_PASSWORD` | `database.connections` | unset | Password for DB_DSN. |
| `DB_CONNECTION` | `database.default` | default | Name of the default connection. |
| `CACHE_STORE` | `cache.store` | array | array, file, database or null. |
| `CACHE_CONNECTION` | `cache.connection` | empty (default connection) | Connection for the database cache store. |
| `CACHE_TTL` | `cache.ttl` | 3600 | Seconds an entry lives when the caller does not say. |
| `QUEUE_STORE` | `queue.store` | sync | sync (no worker), file, database or memory. |
| `QUEUE_CONNECTION` | `queue.connection` | empty (default connection) | Connection for the database queue store. |
| `QUEUE_NAME` | `queue.queue` | default | Queue a job goes on when it does not say. |
| `QUEUE_TRIES` | `queue.tries` | 3 | Attempts before a job is recorded as failed. |
| `QUEUE_TIMEOUT` | `queue.timeout` | 60 | Seconds a job may run / hold its reservation. |
| `QUEUE_MAX_MEMORY` | `queue.max_memory` | unset (80% of MEMORY_LIMIT) | A worker stops between jobs at this size: 128M. |
| `SESSION_STORE` | `session.store` | file | file, database or memory (tests). |
| `SESSION_CONNECTION` | `session.connection` | empty (default connection) | Connection for the database session store. |
| `SESSION_IDLE` | `session.idle` | 7200 | Seconds of inactivity before a session ends. |
| `SESSION_ABSOLUTE` | `session.absolute` | 0 (none) | Hard maximum session length, in seconds. |
| `SESSION_GRACE` | `session.grace` | 30 | Seconds an old session id keeps working after regeneration. |
| `SESSION_COOKIE` | `session.cookie.name` | session | Session cookie name. |
| `SESSION_COOKIE_LIFETIME` | `session.cookie.lifetime` | 0 (browser session) | Cookie lifetime in seconds. |
| `SESSION_COOKIE_SECURE` | `session.cookie.secure` | unset (follow the request) | Require HTTPS for the session cookie. |
| `SCHEDULER_TIMEZONE` | `scheduler.timezone` | unset (APP_TIMEZONE) | Clock scheduled tasks are read against. |
| `SCHEDULER_LOCK_TTL` | `scheduler.lock_ttl` | 3600 | Seconds a schedule lock survives a crashed process. |
| `LOG_LEVEL` | `logging.level` | unset (debug if APP_DEBUG, else info) | Lowest level written. |
| `LOG_CONNECTION` | `logging.database.connection` | empty (default connection) | Connection for the database log writer. |
| `APP_PROFILE` | `observability.profile` | false | Record where each request's time went. Do not leave on. |
| `SLOW_QUERY_MS` | `observability.slow_query_ms` | 0 (off) | Log statements slower than this many milliseconds. |

Every one of these is also in [`.env.example`](../../.env.example), and an
architecture test fails if the framework reads one that is not listed there.

## Configuration keys

Grouped by file: `app.*` is `config/app.php`, `queue.*` is `config/queue.php`,
and so on. A module's own keys are under its name — `Billing.*` in
`config/Billing.php` — and are listed by the module, not here. The values are
the defaults with no environment variable set.


### `app`

| Key | Default | Environment variable |
|---|---|---|
| `app.env` | `'production'` | `APP_ENV` |
| `app.debug` | `false` | `APP_DEBUG` |
| `app.timezone` | `'UTC'` | `APP_TIMEZONE` |
| `app.memory_limit` | `null` | `MEMORY_LIMIT` |
| `app.max_execution_time` | `null` | `MAX_EXECUTION_TIME` |
| `app.editor` | `null` | `APP_EDITOR` |
| `app.handle_errors` | `true` | — |

### `http`

| Key | Default | Environment variable |
|---|---|---|
| `http.base_path` | `null` | — |
| `http.trusted_proxies` | `[]` | — |

### `database`

| Key | Default | Environment variable |
|---|---|---|
| `database.default` | `'default'` | `DB_CONNECTION` |
| `database.connections` | `[]` | `DB_DSN`, `DB_USERNAME`, `DB_PASSWORD` |

### `assets`

| Key | Default | Environment variable |
|---|---|---|
| `assets.url` | `null` | — |
| `assets.versioning` | `'content'` | — |
| `assets.manifests` | `true` | — |
| `assets.max_age` | `31536000` | — |

### `cache`

| Key | Default | Environment variable |
|---|---|---|
| `cache.store` | `'array'` | `CACHE_STORE` |
| `cache.connection` | `''` | `CACHE_CONNECTION` |
| `cache.table` | `'cache'` | — |
| `cache.ttl` | `3600` | `CACHE_TTL` |
| `cache.namespace` | `''` | — |

### `queue`

| Key | Default | Environment variable |
|---|---|---|
| `queue.store` | `'sync'` | `QUEUE_STORE` |
| `queue.connection` | `''` | `QUEUE_CONNECTION` |
| `queue.table` | `'jobs'` | — |
| `queue.queue` | `'default'` | `QUEUE_NAME` |
| `queue.tries` | `3` | `QUEUE_TRIES` |
| `queue.timeout` | `60` | `QUEUE_TIMEOUT` |
| `queue.max_memory` | `null` | `QUEUE_MAX_MEMORY` |
| `queue.sleep` | `1` | — |
| `queue.backoff.base` | `5` | — |
| `queue.backoff.multiplier` | `2` | — |
| `queue.backoff.cap` | `600` | — |
| `queue.backoff.jitter` | `0.0` | — |

### `security`

| Key | Default | Environment variable |
|---|---|---|
| `security.key` | `unset` | `APP_KEY` |
| `security.csrf.enabled` | `true` | `CSRF_ENABLED` |
| `security.csrf.check_origin` | `true` | — |
| `security.csrf.lifetime` | `7200` | — |
| `security.counters` | `'file'` | — |
| `security.max_request_bytes` | `8388608` | `MAX_REQUEST_BYTES` |
| `security.headers.csp` | `''` | — |
| `security.headers.hsts_days` | `0` | — |
| `security.headers.hsts_subdomains` | `false` | — |
| `security.headers.extra` | `[]` | — |

### `auth`

| Key | Default | Environment variable |
|---|---|---|
| `auth.password.options` | `[]` | — |
| `auth.guest_roles` | `[]` | — |

### `session`

| Key | Default | Environment variable |
|---|---|---|
| `session.store` | `'file'` | `SESSION_STORE` |
| `session.connection` | `''` | `SESSION_CONNECTION` |
| `session.table` | `'sessions'` | — |
| `session.idle` | `7200` | `SESSION_IDLE` |
| `session.absolute` | `0` | `SESSION_ABSOLUTE` |
| `session.grace` | `30` | `SESSION_GRACE` |
| `session.cookie.name` | `'session'` | `SESSION_COOKIE` |
| `session.cookie.lifetime` | `0` | `SESSION_COOKIE_LIFETIME` |
| `session.cookie.path` | `'/'` | — |
| `session.cookie.domain` | `''` | — |
| `session.cookie.same_site` | `'Lax'` | — |
| `session.cookie.secure` | `null` | `SESSION_COOKIE_SECURE` |

### `scheduler`

| Key | Default | Environment variable |
|---|---|---|
| `scheduler.lock` | `'file'` | — |
| `scheduler.lock_ttl` | `3600` | `SCHEDULER_LOCK_TTL` |
| `scheduler.timezone` | `null` | `SCHEDULER_TIMEZONE` |

### `system`

| Key | Default | Environment variable |
|---|---|---|
| `system.enabled` | `true` | — |
| `system.execution.default_timeout` | `60.0` | — |
| `system.execution.max_output` | `1048576` | — |
| `system.execution.max_concurrent` | `16` | — |
| `system.execution.http_timeout` | `10.0` | — |
| `system.shell.enabled` | `false` | — |
| `system.shell.binary` | `'bash'` | — |
| `system.commands.allowed` | `null` | — |
| `system.commands.scripts` | `null` | — |
| `system.services` | `[]` | — |
| `system.filesystem.read` | `[]` | — |
| `system.filesystem.write` | `[]` | — |
| `system.permissions.owners` | `[]` | — |
| `system.permissions.groups` | `[]` | — |
| `system.cron.enabled` | `true` | — |
| `system.cron.owner` | `null` | — |
| `system.audit.enabled` | `true` | — |

### `mcp`

| Key | Default | Environment variable |
|---|---|---|
| `mcp.enabled` | `true` | — |
| `mcp.server.name` | `'lphp'` | — |
| `mcp.server.version` | `null` | — |
| `mcp.transports.stdio` | `true` | — |
| `mcp.transports.http` | `false` | — |
| `mcp.http.path` | `'/mcp'` | — |
| `mcp.allow_guests` | `false` | — |
| `mcp.log.enabled` | `true` | — |

### `logging`

| Key | Default | Environment variable |
|---|---|---|
| `logging.writers` | `[]` | — |
| `logging.level` | `null` | `LOG_LEVEL` |
| `logging.redact` | `[]` | — |
| `logging.file.prefix` | `'app'` | — |
| `logging.file.retention_days` | `0` | — |
| `logging.syslog.identity` | `'app'` | — |
| `logging.database.connection` | `''` | `LOG_CONNECTION` |
| `logging.database.table` | `'logs'` | — |
| `logging.database.retention_days` | `0` | — |

### `observability`

| Key | Default | Environment variable |
|---|---|---|
| `observability.profile` | `false` | `APP_PROFILE` |
| `observability.slow_query_ms` | `0` | `SLOW_QUERY_MS` |
| `observability.trust_incoming_ids` | `false` | — |

### `localization`

| Key | Default | Environment variable |
|---|---|---|
| `localization.country_header` | `null` | `LOCALIZATION_COUNTRY_HEADER` |
| `localization.maxmind_database` | `null` | `LOCALIZATION_MAXMIND_DATABASE` |

### `templates`

| Key | Default | Environment variable |
|---|---|---|
| `templates.cache` | `false` | — |

### `modules`

| Key | Default | Environment variable |
|---|---|---|
| `modules.paths` | `['modules']` | — |
| `modules.disabled` | `[]` | — |
