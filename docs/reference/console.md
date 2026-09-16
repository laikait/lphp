# CLI

The console boots the same application HTTP does — same container, same
modules, same hooks — and then does what the kernel does for a request: look a
name up in a registry, parse input against what was declared, call a handler
through the container, and turn the result into something the caller
understands. `ConsoleKernel` and `HttpKernel` read as mirror images because
they are.

A command is an ordinary object:

```php
final class SyncCustomers
{
    public function __construct(private readonly CustomerQuery $customers) {}

    public function __invoke(Output $output, string $since, int $limit, bool $dryRun): int
    {
        // ...

        return 0;
    }
}
```

There is **no `Command` base class**, no `$signature` string, no `handle()`
method the framework insists on, and no `$this->argument('since')`. An
architecture test asserts that nothing under `engine/Cli/` is abstract, is an
interface, or has a protected member — because a base class is how a command
stops being an ordinary object. It arrives with `$this->argument()`, then
`$this->output`, then `$this->info()`, and after that a module's command can
only be written one way.

## Commands are declared where routes are

```php
$module->commands(static function (CommandCollector $commands): void {
    $commands->add('customer:sync', SyncCustomers::class)
        ->describe('Pull customer records from the upstream system.')
        ->argument('since', 'Only records changed on or after this date.', required: false, default: 'yesterday')
        ->option('limit', 'Stop after this many records.', shortcut: 'l', default: '25')
        ->flag('dry-run', 'Report what would change without writing anything.', shortcut: 'd');
});
```

Nothing is scanned. A `Commands/` directory is where these classes happen to
live, not how they are found — a file's mere existence should not change what
an application does, and what a module contributes stays readable in one file.

Arguments and options are declared rather than parsed out of a signature
string. `"{user : the id} {--queue=}"` is a small language embedded in a
docblock: invisible to static analysis, checked when somebody runs it. A method
call per argument is longer to write and is checked by the IDE as it is
written, and the same declaration generates the help.

The handler takes the same three forms a route handler does:

```php
$commands->add('customer:sync', SyncCustomers::class);              // invokable class
$commands->add('customer:show', [ShowCustomer::class, 'show']);     // [class, method]
$commands->add('customer:count', static fn (CustomerQuery $c): string => …);  // closure
```

## Input binds the way route parameters do

```bash
php bin/console customer:sync 2026-01-01 --dry-run --limit=5
php bin/console customer:sync 2026-01-01 -dl5      # the same thing
```

Declared input arrives as **typed parameters, matched by name**; everything
with a class type comes from the **container, matched by type**. That is the
console's version of "a route parameter called `request` cannot displace the
Request": a command that declares an argument called `output` still gets a real
`Output`.

A dash is not legal in a PHP parameter name, so `--dry-run` binds to `$dryRun`.
The transformation is mechanical, one-way, and only applies to names that
contain a dash.

Coercion goes through the same `Support\Coercion` routing uses, so `"1"` means
the same thing on the command line as it does in a URL, and `--times=lots` for
an `int $times` is a usage error with the synopsis rather than a `TypeError`.

What the parser accepts, and what it does not:

| | |
|---|---|
| `--flag` `--limit=50` `--limit 50` | declared options |
| `-l 50` `-l50` `-abc` | shortcut, attached value, bundled flags |
| `--` | everything after is positional |
| `--lim` for `--limit` | **not** accepted — see below |

Abbreviation is refused because the abbreviation that is unique today becomes
ambiguous the day somebody adds an option, and a script written against it
breaks at a distance. Nor is a suggested name ever run in place of what was
typed: a typo gets `Did you mean "customer:sync"?` and exit 127, and a command
that deletes something is never reachable by a name its author did not write.

`--limit --dry-run` is refused too. Reading `--dry-run` as the value would hide
a forgotten argument behind a nonsensical limit, and the run would look like it
worked.

## Exit codes mean what a shell expects

| | |
|---|---|
| `0` | it worked |
| `1` | it ran and failed, or something threw |
| `2` | the command line was wrong — missing argument, unknown option |
| `127` | no such command |

The 2/127 split earns its keep: a deployment script that mistypes a command
name and one that forgets an argument are different bugs, and a wrapper that
retries on one should not retry on the other.

A command returns `int` for the code, a `string` to print, or nothing for
success. **A `bool` is refused**: PHP's convention says `true` is success, the
shell's says `0` is, and an exit code that gets it backwards turns a failed job
into a green tick.

Results go to standard output and complaints go to standard error, so
`console route:list | grep customers` carries no warnings and
`console customer:sync 2>errors.log` separates the two.

## The framework's own commands are not special

```
about            Summarise this application: version, modules, routes, connections.
help             List the available commands, or explain one of them.
asset:list       Every published asset directory and the URL prefix it answers on.
auth:access      Every capability, every role, and which routes check them.
auth:hash        Hash a password, for seeding the first account.
cache:clear      Delete the configuration, module, template and application caches.
cache:warm       Build the production boot path: the configuration and discovery caches.
config:cache     Compile config/ and the defaults into one cached file.
config:list      The configuration this process actually resolved to.
log:status       Where records go, and whether they are getting there.
module:list      Discovered modules, in the order they load.
nginx:make       Write the nginx server block for this application to nginx.conf.
queue:failed     The jobs that gave up; retry or discard them.
queue:status     What is waiting on each queue, and what has failed.
queue:work       Run queued jobs until told to stop.
route:list       Every registered route and its owning module.
schedule:list    Every scheduled task, when it next runs, and what is running now.
schedule:run     Run whatever is due this minute. This is what cron calls.
schedule:unlock  Held schedule locks; release them after a machine died mid-run.
security:check   Audit what this deployment actually has switched on.
security:key     Print a new APP_KEY.
session:gc       Delete sessions past their lifetime.
session:table    Print the CREATE TABLE the database session store needs.
template:list    The template search path, highest precedence first.
```

They are registered through the same `CommandCollector` a module uses, under
the module name `engine`, and the kernel has no idea they exist. Delete
`CoreCommands` and the console still works with fewer commands.

Every one of them answers a question that is otherwise expensive to answer.
**None of them generates code**, and an architecture test pins the list.
`nginx:make` writes a file, but it is web server configuration that nothing in
the framework reads. A
`make:something` command writes a file whose shape the framework then quietly
depends on, and the shape is undocumented because the generator *is* the
documentation — that is how a framework stops being a library you call and
becomes a thing you live inside.

Help is generated from the declaration, so there is no second description of
the interface to fall out of date:

```
$ php bin/console customer:sync --help
Usage:
  php bin/console customer:sync [since] [options]

Arguments:
  since  Only records changed on or after this date. (default: yesterday)

Options:
  -l, --limit=<value>  Stop after this many records. (default: 25)
  -d, --dry-run        Report what would change without writing anything.

Declared by module plugins/Example.
```

## Errors on a terminal

`command.matched`, `command.finished` and `command.failed` are the CLI's
lifecycle hooks. There is deliberately **no filter over parsed input**: a
filter carries a value so that a module can change it, and a module silently
rewriting another module's arguments is worse than the flexibility is worth.

An exception's message obeys the same disclosure rule the web does — an
`HttpException` message is written by this framework and survives, anything
else is replaced wholesale outside debug mode. The console was the one place
that did not, and a `DatabaseException` carrying `dsn=…` into a cron log is
exactly what that rule exists to prevent. What the console adds is a way
forward: when a message is withheld it says `Set APP_DEBUG=1 for the full
message and a stack trace`, because the person reading a console error is the
person who can turn debug on.
