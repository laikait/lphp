# Background work

How to move work out of the request — sending an email, calling a slow service,
recalculating totals — and how to run work on a timetable. Both are declared in
your module; neither needs anything installed to start with.

Reference: [Queue and worker](../reference/queue.md) and
[Scheduler](../reference/scheduler.md). Running workers and cron in production is
in [Running in production](../operations/running.md).

## Write a job

A job is a class in your module's `Jobs/` directory that implements `Job` and has
a `handle()` method:

```php
final class AcknowledgeMessage implements Job
{
    public function __construct(private readonly int $messageId) {}

    public function handle(Logger $log): void
    {
        $log->info('Acknowledging a message', ['id' => $this->messageId]);
    }
}
```

Two rules make jobs safe to run later, in another process:

- **The constructor takes data** — ids, strings, numbers. That is what gets
  written to the queue. Pass the id, not the model: the row may change before the
  job runs, and reading it fresh is almost always what you meant.
- **`handle()` takes services.** They are injected when the job runs, from
  whichever process runs it. A repository or a connection is never written into
  a queue.

Nothing registers a job. The class name is enough.

## Dispatch it

```php
public function __construct(private readonly Queue $queue) {}

$this->queue->push(new AcknowledgeMessage($id));
$this->queue->later(3600, new ChaseInvoice($id));              // in an hour
$this->queue->push(new RecalculateBilling(), queue: 'billing');
```

## Where it runs

`QUEUE_STORE` decides, and the dispatching line never changes:

| `QUEUE_STORE` | The job runs |
|---|---|
| `sync` (default) | immediately, inside `push()`. An exception reaches the caller |
| `file` | later, in `php bin/console queue:work`, which must be running |
| `memory` | never beyond this process; for tests and one-off scripts |

Start with `sync`: everything works, just not in the background. Switch to `file`
and start a worker when the request should stop waiting:

```bash
QUEUE_STORE=file
php bin/console queue:work --queue=default --max-jobs=500 --max-time=3600
php bin/console queue:status
```

`file` works for one machine and any number of workers on it. For several
machines, a database queue store is needed and is not built yet.

## When a job fails

A job that throws is retried after a growing delay — 5s, 10s, 20s, up to ten
minutes — until `QUEUE_TRIES` attempts are used, then moved to the failed list
with the exception:

```bash
php bin/console queue:failed                 # what gave up, and why
php bin/console queue:failed --retry=<id>    # try again, attempts reset
php bin/console queue:failed --retry-all
php bin/console queue:failed --forget=<id>
```

Design jobs so running one twice is harmless. A worker killed mid-job leaves
the job reserved until `QUEUE_TIMEOUT` passes, and then it runs again.

To hear about failures in a worker, listen for the hook:

```php
$module->hook('job.failed', [QueueAlerts::class, 'onFailed']);
```

The listener receives the exception, the `QueuedJob` envelope, and whether the
job will be retried. Under `sync` there is no worker and no `job.failed`: the
exception reaches the code that called `push()`. `job.queued` fires on every
push, and `job.started` and `job.finished` wherever the job actually runs.

## Run work on a timetable

Schedules are declared in `module.php`, next to the code they run:

```php
$module->schedules(static function (ScheduleCollector $schedules): void {
    // A command: the same thing an operator runs by hand.
    $schedules->command('customer:sync', '--limit=100')
        ->describe('Pull anything the upstream system changed overnight.')
        ->dailyAt('02:00')
        ->withoutOverlapping(1800);

    // A job: pushed onto the queue; where it runs is the queue's business.
    $schedules->job(PurgeSpam::class)
        ->describe('Delete messages marked as spam.')
        ->dailyAt('03:00');

    // A closure, with its parameters injected.
    $schedules->call('customer:refresh-counts', static function (CustomerQuery $customers): void {
        $customers->forgetTotal();
    })->everyThirtyMinutes()->allowOverlapping();
});
```

The server needs **one** cron line, once, whatever the modules declare:

```cron
* * * * *  cd /var/www/app && php bin/console schedule:run >> /dev/null 2>&1
```

Frequencies are cron expressions underneath: `everyFiveMinutes()`,
`hourlyAt(20)`, `dailyAt('02:30')`, `weeklyOn(1, '09:00')`, `monthlyOn(1)`, or
`cron('15,45 9-17 * * 1-5')`. Nothing runs more often than once a minute.

Things worth knowing:

- **A scheduled job is built with no arguments**, because a schedule has nothing
  to give it. `PurgeSpam` above has no constructor.
- **Overlap protection is on.** A task still running when its next turn comes is
  skipped. `withoutOverlapping($seconds)` sets how long a crashed run's lock lasts —
  a little longer than the task's longest run.
- **Long work belongs in a job.** A slow scheduled *command* holds up every task
  after it in the same minute.
- **Times are UTC** unless `SCHEDULER_TIMEZONE`, `APP_TIMEZONE` or `->timezone()`
  says otherwise. Leave anything that must run exactly once a day on UTC: local
  clocks repeat an hour every autumn.
- **Mistakes stop the application from starting** — an unknown command, a job
  class that is not a job, a bad expression. You find out on deploy, not at 3am.

```bash
php bin/console schedule:list                     # every task, and when it next runs
php bin/console schedule:run --id=<id> --force    # run one now, lock still honoured
```

## Test it

Under `sync` a pushed job runs inside the test, so its effects are visible
immediately. To check *what* was queued, listen to `job.queued` — and pass
`['queue' => ['store' => 'memory']]` to the application if the job should not
run at all:

```php
$queued = [];
add_hook('job.queued', static function (mixed $envelope, Job $job) use (&$queued): void {
    $queued[] = $job;
});
```

See [Testing](testing.md).
