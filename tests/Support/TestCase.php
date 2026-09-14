<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Engine\Bootstrap\Bootstrap;
use App\Engine\Core\Application;
use App\Engine\Core\ExecutionContext;
use App\Engine\Support\Extensions;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base test case.
 *
 * Grows as the framework does; right now its only job is to expose the project
 * root so tests can reason about real files on disk.
 */
abstract class TestCase extends PHPUnitTestCase
{
    protected function basePath(string $relative = ''): string
    {
        $base = \dirname(__DIR__, 2);

        return $relative === '' ? $base : $base . \DIRECTORY_SEPARATOR . $relative;
    }

    /**
     * Build a real application over the real Bootstrap.
     *
     * Nothing is mocked: the container, the module manager, the router and both
     * engines are the production ones. Only two things are forced -- the module
     * roots, so a test can point at fixtures, and error handling, which stays
     * off so the handler does not take set_error_handler() away from PHPUnit.
     *
     * @param array<string, mixed> $config
     */
    protected function application(array $config = []): Application
    {
        Extensions::reset();

        $config['app']['handle_errors'] = false;

        return Bootstrap::create(
            $this->basePath(),
            ExecutionContext::http(['REQUEST_TIME_FLOAT' => \microtime(true)]),
            $config,
        );
    }

    /**
     * An application wired to the fixture modules rather than the real ones.
     *
     * @param array<string, mixed> $config
     */
    protected function fixtureApplication(array $config = []): Application
    {
        $config['modules']['paths'] = [
            'shared' => 'tests/Fixtures/Modules/Shared',
            'plugins' => 'tests/Fixtures/Modules/Plugins',
            'gateways' => 'tests/Fixtures/Modules/Gateways',
        ];

        return $this->application($config);
    }

    protected function tearDown(): void
    {
        // Extensions is the one piece of static framework state. Clearing it
        // keeps one test's listeners out of the next test's run, and proves the
        // helpers are wired rather than ambient.
        Extensions::reset();

        parent::tearDown();
    }
}
