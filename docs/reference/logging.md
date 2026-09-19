# Logging

```php
final class ChargeCard
{
    public function __construct(private readonly Logger $log) {}

    public function __invoke(Order $order): void
    {
        $this->log->info('Card charged', ['order' => $order->id, 'amount' => $order->total]);
    }
}
```

A `Logger` is injected like anything else, and it never learns where records
go — that is a deployment decision, and it changes without the module changing.
With no writers configured the call costs one integer comparison and goes
nowhere.

## Independent from error rendering, checkably

Nothing in `engine/Error/` knows this layer exists. The error handler announces
on `error.reported`; `ErrorLog` is an ordinary listener on that hook,
registered exactly the way a module would register one. **Delete it and errors
stop being logged; nothing else changes** — there is a test that does precisely
that and asserts the 500 still renders.

Two architecture tests hold the line: `engine/Error/` may not mention a logger,
and only `ErrorLog` in `engine/Logging/` may mention an error. A hook has no
compile-time direction, which is why it is the right joint here.

The level comes from the status, not the exception class:

| | |
|---|---|
| 5xx, or not an `HttpException` | `error` |
| 429 | `warning` |
| other 4xx | `notice` |

A 404 is a visitor typing a URL. At error level, a scanner sweeping for
`/wp-admin` pages somebody at three in the morning.

**The log is where a withheld message belongs.** A response must not repeat
`dsn=secret-hunter2`; a log file exists so somebody can find out what actually
happened. The two rules are opposites on purpose.

## Logging never breaks a request

This is the specification's hardest requirement here, and most of `LogManager`
follows from it.

A writer that throws is caught, **retired** — taken out of the rotation for the
rest of the process — and the reason is kept.

Retiring matters as much as catching: a full disk does not un-fill itself, so a
writer that failed once fails on every record, and a request that logs forty
times would otherwise spend forty exceptions finding that out. Keeping the
reason matters because the failure mode of "catch everything" is an application
that has silently not been logging for three weeks, which is worse than the
exception was.

```bash
php laika log:status            # what is attached, and what has stopped
php laika log:status --write    # and does a record actually arrive
```

Reentrancy is refused for the same reason — a writer that logs would recurse
until the stack ran out. And a writer may not catch its own failures: an
architecture test forbids `catch` under `engine/Logging/Writers/`, because a
writer that swallows its own errors looks safer and is worse. The manager could
no longer tell it had stopped working, and `log:status` would report a healthy
writer that writes nothing.

## Levels

The eight RFC 5424 severities, which are also the eight PSR-3 levels. The
specification's list also contains `log`, which is not a severity — it is the
name of the generic method that takes one, and `Logger::log()` is it. A ninth
case with no place in the ordering would make "is this record severe enough to
write" unanswerable.

The backing value is the RFC 5424 code, so comparing severity is comparing
integers. It is **not** what `syslog()` wants: on Windows there is no syslog, so
PHP collapses the eight onto the event log's three record types and `LOG_EMERG`
is 1, not 0. `Level::priority()` is the translation, and a test pins it — a
mismatch would file every record under the wrong severity on the one platform
where nothing would look broken.

## Context is data, not string interpolation

There is no `{placeholder}` splicing. A message is a message and context is
data; splicing one into the other produces a line that is harder to grep and a
context that has been said twice.

Context is normalised before any writer sees it, because a log line is written
at the worst possible moment and must not be the thing that fails next. A
closure, a PDO handle, a model with a circular reference, `NAN`, a resource, ten
thousand rows — all of them either break `json_encode` or write a megabyte into
the log. So values are reduced first: scalars survive, throwables become class,
message and position, objects become their class name unless they can say more
for themselves, and depth, string length and item count are capped.

A small, exact, configurable list of **key names** is replaced with
`[redacted]`: `password`, `token`, `authorization`, `api_key` and a dozen more.

This is deliberately not the same thing as filtering a message, which this
framework refuses to do on the grounds that guessing which substrings are secret
is wrong eventually. A key is not a substring — it is structured data, matched
exactly, against a list somebody wrote down. It catches the case that actually
happens, a request payload logged whole with `password` still in it, and claims
nothing about the rest. **A log file is still sensitive**; an architecture test
asserts `system/Logs` is refused by the web server.

## Destinations

```php
'logging' => [
    'writers' => ['file'],       // file, stderr, syslog, database
    'level'   => 'info',
    'file'    => ['prefix' => 'app', 'retention_days' => 30],
],
```

**Nothing is attached by default.** A framework that starts writing files to a
directory nobody asked about is a framework that fills a disk on somebody
else's machine, and the first thing a deployment does is decide where its logs
go. An unknown writer name or an unparseable level is ignored rather than
fatal — a typo in a deployment's configuration must not stop an application
from starting, and `log:status` reports what is actually attached.

`file` writes one file per day under `system/Logs`. Daily rather than by size,
because the two questions anybody asks of a log are "what happened just now"
and "what happened on the day the invoices went wrong", and a date in the
filename answers the second without reading the first. Retention deletes, so it
is bounded hard — only that directory, only that writer's own naming, and only
when a positive number of days is set. The default is 0, keep everything:
deleting an audit trail because a default said so is a worse failure than a
large directory.

### The database writer

`database` writes each record as a row, for machines that share a database and
people who would rather query a log than grep one:

```php
'logging' => [
    'writers'  => ['file', 'database'],
    'database' => ['connection' => '', 'table' => 'logs', 'retention_days' => 30],
],
```

- **Its table comes from `php laika migrate`**, as a framework migration, like
  the session, cache and queue tables. It is only made while `writers` names
  `database`. Until it exists, the writer retires on its first record, and
  `log:status` gives the command to run.
- **Name another writer alongside it.** A log kept in the database cannot record
  the database failing: when the database goes, this writer retires, and only
  the other writer is left to say why.
- **It writes through a connection of its own** on MySQL, PostgreSQL and SQL
  Server. A shared connection would put each record inside whatever transaction
  the application had open, and a rollback would take the record of what went
  wrong with it. Writing a record is also never itself reported as a slow query.
  On SQLite, which allows one writer at a time, it shares the application's
  connection instead: a second one would wait behind the first's transaction.
- **Rows** hold `logged_at` (UTC, with microseconds), `level` (the RFC 5424
  code, so "error or worse" is `level <= 3`), `level_name`, `channel`, `message`
  and `context` (JSON, or null when there is none).
- **Retention** is `retention_days`, 0 by default, which keeps everything, as for
  files. When it is set, older rows are deleted once per process, before the
  first record.
- `LOG_CONNECTION` names the connection; empty means the default one.

**A remote writer is not built.** It needs an HTTP client that does not exist
yet. `LogWriter` is three methods — `describe()`, `accepts()`, `write()` — so it
is a small class in an application that wants one.

## PSR-3

`psr/log` is deliberately not a dependency, and `Logger` deliberately does not
implement `LoggerInterface`. The eight method signatures match PSR-3 exactly;
what differs is `log()`, which takes a `Level` enum where PSR-3 takes a plain
string. The enum is worth more here than the interface is.

An application that must hand a PSR-3 logger to a vendor SDK writes the adapter
once:

```php
final class Psr3Logger extends AbstractLogger
{
    public function __construct(private readonly Logger $log) {}

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->log->log(Level::fromName((string) $level, Level::Info), (string) $message, $context);
    }
}
```

That is twelve lines in an application that needs it, rather than a flattened
API in every application that does not.
