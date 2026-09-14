<?php

declare(strict_types=1);

namespace App\Engine\Cli;

use App\Engine\Cli\Commands\AboutCommand;
use App\Engine\Cli\Commands\AssetListCommand;
use App\Engine\Cli\Commands\CacheClearCommand;
use App\Engine\Cli\Commands\ConfigCacheCommand;
use App\Engine\Cli\Commands\ConfigListCommand;
use App\Engine\Cli\Commands\HelpCommand;
use App\Engine\Cli\Commands\LogStatusCommand;
use App\Engine\Cli\Commands\ModuleListCommand;
use App\Engine\Cli\Commands\QueueFailedCommand;
use App\Engine\Cli\Commands\QueueStatusCommand;
use App\Engine\Cli\Commands\QueueWorkCommand;
use App\Engine\Cli\Commands\RouteListCommand;
use App\Engine\Cli\Commands\ScheduleListCommand;
use App\Engine\Cli\Commands\ScheduleRunCommand;
use App\Engine\Cli\Commands\ScheduleUnlockCommand;
use App\Engine\Cli\Commands\TemplateListCommand;

/**
 * The commands the framework itself provides.
 *
 * Registered through the same collector a module uses, with the module name
 * "engine" so that `module:list` and a duplicate-name error both tell the truth
 * about where a command came from. The kernel has no idea these are special,
 * because they are not: if this file were deleted the console would still work
 * and would simply have fewer commands.
 *
 * Every one of them answers a question that is otherwise expensive to answer --
 * what loaded, what routes exist, where templates are searched. None of them
 * generates code. A make:something command writes a file whose shape the
 * framework then quietly depends on, and scaffolding is how a framework stops
 * being a library you call and starts being a thing you live inside.
 */
final class CoreCommands
{
    public const MODULE = 'engine';

    public static function register(CommandRegistry $registry): void
    {
        $commands = new CommandCollector($registry, self::MODULE);

        $commands->add('about', AboutCommand::class)
            ->describe('Summarise this application: version, modules, routes, connections.');

        $commands->add('help', HelpCommand::class)
            ->describe('List the available commands, or explain one of them.')
            ->argument('command', 'The command to explain.', required: false);

        $commands->add('module:list', ModuleListCommand::class)
            ->describe('List discovered modules, in the order they load.')
            ->note('The order is load order, which is what decides whose hook runs first at equal priority.');

        $commands->add('route:list', RouteListCommand::class)
            ->describe('List every registered route and its owning module.')
            ->option('module', 'Only routes belonging to this module, e.g. plugins/Example.', shortcut: 'm')
            ->flag('names', 'Only routes that have a name.', shortcut: 'n');

        $commands->add('asset:list', AssetListCommand::class)
            ->describe('List every published asset directory and the URL prefix it answers on.');

        $commands->add('template:list', TemplateListCommand::class)
            ->describe('List the template search path, highest precedence first.');

        $commands->add('log:status', LogStatusCommand::class)
            ->describe('Show where log records go, and whether they are getting there.')
            ->flag('write', 'Send a real record through the real writers.', shortcut: 'w')
            ->option('channel', 'The channel to write the test record to.', default: 'app')
            ->note('Logging fails quietly on purpose, so this is where it admits to having failed.');

        $commands->add('config:list', ConfigListCommand::class)
            ->describe('Show the configuration this process actually resolved to.')
            ->option('prefix', 'Only keys starting with this, e.g. logging or plugins/Example.', shortcut: 'p')
            ->flag('sources', 'Also report which files, .env and cache were involved.', shortcut: 's')
            ->note('A few key names print as [hidden]. There is deliberately no flag to reveal them.');

        $commands->add('config:cache', ConfigCacheCommand::class)
            ->describe('Compile config/ and the defaults into one cached file.')
            ->flag('clear', 'Delete the cached file instead of building it.')
            ->note('The cache ignores itself when an environment variable it read has changed.');

        $commands->add('queue:work', QueueWorkCommand::class)
            ->describe('Run queued jobs until told to stop.')
            ->option('queue', 'Which queue to drain.', shortcut: 'q')
            ->flag('once', 'Take one job and stop.')
            ->flag('drain', 'Work until the queue is empty, then stop.')
            ->option('max-jobs', 'Stop after this many jobs.', default: '0')
            ->option('max-time', 'Stop after this many seconds.', default: '0')
            ->option('tries', 'Attempts before a job is recorded as failed.')
            ->option('timeout', 'How long a job may hold its reservation, in seconds.')
            ->option('sleep', 'Seconds to wait when the queue is empty.')
            ->note('Bounded runs are the point: let it exit and have the supervisor start a fresh one.');

        $commands->add('queue:status', QueueStatusCommand::class)
            ->describe('Show what is waiting on each queue, and what has failed.')
            ->note('A queue nobody is draining looks exactly like an empty one from inside the application.');

        $commands->add('queue:failed', QueueFailedCommand::class)
            ->describe('List the jobs that gave up, and retry or discard them.')
            ->option('retry', 'Queue this job again, with its attempts reset.')
            ->option('forget', 'Discard this failed job.')
            ->flag('retry-all', 'Queue every failed job again.');

        $commands->add('schedule:list', ScheduleListCommand::class)
            ->describe('List every scheduled task, when it next runs, and whether it is running now.')
            ->option('module', 'Only schedules belonging to this module, e.g. plugins/Example.', shortcut: 'm')
            ->flag('due', 'Only what is due this minute.')
            ->note('One cron line drives all of these. If it is missing, every "Next" below is fiction.');

        $commands->add('schedule:run', ScheduleRunCommand::class)
            ->describe('Run whatever is due this minute. This is what cron calls.')
            ->option('id', 'Run just this schedule.')
            ->flag('force', 'Run it even though it is not due. Still takes the lock.')
            ->flag('dry-run', 'Print what would run and run nothing.', shortcut: 'd')
            ->note('Exits 1 if anything failed, so the cron line itself can be monitored.');

        $commands->add('schedule:unlock', ScheduleUnlockCommand::class)
            ->describe('Show held schedule locks, and release them after a machine died mid-run.')
            ->option('id', 'Release this schedule\'s lock.')
            ->flag('all', 'Release every held lock.')
            ->note('Locks expire on their own. Releasing one whose task is still running starts a second copy.');

        $commands->add('cache:clear', CacheClearCommand::class)
            ->describe('Delete the configuration, module, template and application caches.')
            ->flag('expired', 'Only remove entries that have expired, leaving the rest warm.')
            ->note('None of them invalidates itself on a file edit, which is why clearing them is a deployment step.');
    }
}
