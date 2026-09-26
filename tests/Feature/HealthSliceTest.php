<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Engine\Core\HttpKernel;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Tests\Support\TestCase;

/**
 * GET /health, as the shipped Shared module answers it.
 */
final class HealthSliceTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function health(array $config = []): Response
    {
        return $this->handle($this->shippedApplication($config)->boot());
    }

    /** @return array<string, mixed> */
    private static function body(Response $response): array
    {
        /** @var array<string, mixed> */
        return \json_decode($response->body(), true, 16, \JSON_THROW_ON_ERROR);
    }

    /** A connection that cannot open: a SQLite file in a directory that does not exist. */
    private const BROKEN_DATABASE = ['connections' => ['default' => ['dsn' => 'sqlite:/no/such/directory/health.sqlite']]];

    public function test_a_healthy_instance_answers_200(): void
    {
        $response = $this->health();
        $body = self::body($response);

        self::assertSame(200, $response->status());
        self::assertStringStartsWith('application/json', (string) $response->header('Content-Type'));
        self::assertSame('ok', $body['status']);
        self::assertSame(
            ['database' => ['status' => 'skipped'], 'cache' => ['status' => 'ok'], 'queue' => ['status' => 'skipped'], 'disk' => ['status' => 'ok']],
            $body['checks'],
            'no database is configured and the queue is sync, so neither is checked',
        );
    }

    public function test_the_answer_is_never_cached(): void
    {
        self::assertSame('no-store', $this->health()->header('Cache-Control'));
    }

    public function test_a_failing_database_answers_503(): void
    {
        $response = $this->health(['database' => self::BROKEN_DATABASE]);
        $body = self::body($response);

        self::assertSame(503, $response->status());
        self::assertSame('fail', $body['status']);
        self::assertSame(['status' => 'fail'], $body['checks']['database'] ?? null);
    }

    public function test_a_failure_names_nothing_outside_debug_mode(): void
    {
        $response = $this->health(['app' => ['debug' => false], 'database' => self::BROKEN_DATABASE]);

        self::assertArrayNotHasKey('details', self::body($response));
        self::assertStringNotContainsString('no/such/directory', $response->body());
        self::assertStringNotContainsString('sqlite', $response->body());
    }

    public function test_debug_mode_says_why_a_check_failed(): void
    {
        $body = self::body($this->health(['app' => ['debug' => true], 'database' => self::BROKEN_DATABASE]));

        self::assertIsArray($body['details'] ?? null);
        self::assertArrayHasKey('database', $body['details']);
    }

    public function test_a_queue_that_holds_jobs_reports_its_backlog_without_failing(): void
    {
        $response = $this->health(['queue' => ['store' => 'memory']]);
        $body = self::body($response);

        self::assertSame(200, $response->status());
        self::assertSame('ok', $body['checks']['queue']['status'] ?? null);
        self::assertIsArray($body['checks']['queue']['pending'] ?? null);
    }

    public function test_a_module_can_replace_it(): void
    {
        $application = $this->shippedApplication(['modules' => ['paths' => ['modules/Shared', 'tests/Fixtures/Modules/Health']]]);
        $response = $this->handle($application->boot());

        self::assertSame(['status' => 'custom'], self::body($response));
    }

    private function handle(Application $application): Response
    {
        $kernel = $application->container()->get(HttpKernel::class);
        self::assertInstanceOf(HttpKernel::class, $kernel);

        return $kernel->handle(Request::create('GET', '/health'));
    }
}
