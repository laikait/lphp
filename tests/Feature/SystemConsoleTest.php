<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Cli\CommandDispatcher;
use App\Engine\Cli\CommandRegistry;
use App\Engine\Cli\ConsoleKernel;
use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Core\ExecutionContext;
use App\Engine\Error\ErrorHandler;
use App\Engine\Hook\HookEngine;
use App\Engine\System\Cron\CronManager;
use App\Engine\System\Cron\ScheduleRunJob;
use App\Tests\Fixtures\System\MemoryCronTable;
use App\Tests\Support\TestCase;

/**
 * The system:* commands through the real console kernel.
 *
 * The cron commands run against an in-memory crontab bound in place of the
 * real one: a test run must never edit the crontab of whoever runs it.
 */
final class SystemConsoleTest extends TestCase
{
    private MemoryCronTable $crontab;

    private Application $app;

    protected function setUp(): void
    {
        $this->crontab = new MemoryCronTable("MAILTO=\"\"\n");
        $this->app = $this->shippedApplication()->boot();
        $this->app->container()->instance(CronManager::class, new CronManager($this->crontab, 'console-test'));
    }

    /** @return array{int, string} */
    private function console(string ...$arguments): array
    {
        $container = $this->app->container();
        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        $kernel = new ConsoleKernel(
            $container->get(CommandRegistry::class),
            $container->get(CommandDispatcher::class),
            $container->get(HookEngine::class),
            $container->get(ErrorHandler::class),
            new Output($stream),
        );

        $status = $kernel->handle(ExecutionContext::cli(\array_values(['laika', ...$arguments])));

        \rewind($stream);
        $output = (string) \stream_get_contents($stream);
        \fclose($stream);

        return [$status, $output];
    }

    public function test_system_info_describes_this_machine(): void
    {
        [$status, $output] = $this->console('system:info');

        self::assertSame(0, $status);
        self::assertStringContainsString(\PHP_VERSION, $output);
        self::assertStringContainsString(\php_uname('m'), $output);
    }

    /** The console asks for no identity, and still cannot get past the application's policy. */
    public function test_restarting_a_service_the_configuration_does_not_allow_is_refused(): void
    {
        [$status, $output] = $this->console('system:service:restart', 'ssh');

        self::assertSame(1, $status);
        self::assertStringContainsString('Nothing permits "restart ssh.service"', $output);
    }

    public function test_a_name_that_is_not_a_service_is_refused(): void
    {
        [$status, $output] = $this->console('system:service:status', 'poweroff.target');

        self::assertSame(1, $status);
        self::assertStringContainsString('The service name is invalid', $output);
    }

    public function test_the_schedule_run_line_is_installed_listed_and_removed(): void
    {
        [$status, $output] = $this->console('system:cron:list');
        self::assertSame(0, $status);
        self::assertStringContainsString('no jobs in the crontab', $output);

        [$status, $output] = $this->console('system:cron:install');
        self::assertSame(0, $status);
        self::assertStringContainsString('Installed the schedule:run line', $output);
        self::assertStringContainsString("'laika' 'schedule:run'", $output);

        [, $output] = $this->console('system:cron:install');
        self::assertStringContainsString('already installed', $output);

        [$status, $output] = $this->console('system:cron:list');
        self::assertSame(0, $status);
        self::assertStringContainsString(ScheduleRunJob::ID, $output);

        [$status, $output] = $this->console('system:cron:remove');
        self::assertSame(0, $status);
        self::assertStringContainsString('Removed ' . ScheduleRunJob::ID, $output);

        [$status] = $this->console('system:cron:remove');
        self::assertSame(1, $status, 'removing what is not there says so');

        self::assertSame("MAILTO=\"\"\n", $this->crontab->contents, 'the rest of the crontab was touched');
    }

    public function test_remove_all_takes_only_this_applications_jobs(): void
    {
        $this->console('system:cron:install');

        [$status, $output] = $this->console('system:cron:remove', '--all');

        self::assertSame(0, $status);
        self::assertStringContainsString('Removed 1 job(s)', $output);
        self::assertSame("MAILTO=\"\"\n", $this->crontab->contents);
    }

    /** The plan's warning, pinned: no command runs whatever it is given. */
    public function test_there_is_no_arbitrary_execution_command(): void
    {
        foreach (['system:exec', 'system:shell', 'system:run'] as $name) {
            [$status, $output] = $this->console($name, 'id');

            self::assertNotSame(0, $status, $name . ' exists');
            self::assertStringContainsString('help', $output);
        }
    }
}
