<?php

declare(strict_types=1);

namespace App\Modules\Shared\Health;

use App\Engine\Cache\Cache;
use App\Engine\Config\Config;
use App\Engine\Core\Application;
use App\Engine\Database\ConnectionManager;
use App\Engine\Http\JsonResponse;
use App\Engine\Queue\Queue;

/**
 * GET /health: whether this instance can do its job, for a load balancer or an
 * uptime monitor.
 *
 * Each check answers "ok", "fail" or "skipped", and the whole answers 200 when
 * nothing failed and 503 when something did -- the status code is what a load
 * balancer reads. The body names checks and states only: no DSN, no path, no
 * error message, because /health is public. With app.debug on, a failed check
 * says why.
 *
 * A module that wants a different check declares GET /health itself; every
 * module registers after Shared, and the route declared last wins.
 */
final class HealthCheck
{
    /** Below this much free space in system/, logs, sessions and caches start failing. */
    public const MIN_FREE_BYTES = 52428800;

    /** @var array<string, string> check => why it failed, for debug mode */
    private array $details = [];

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Cache $cache,
        private readonly Queue $queue,
        private readonly Application $application,
        private readonly Config $config,
    ) {}

    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->database(),
            'cache' => $this->cache(),
            'queue' => $this->queue(),
            'disk' => $this->disk(),
        ];

        $failed = \in_array('fail', \array_column($checks, 'status'), true);
        $body = ['status' => $failed ? 'fail' : 'ok', 'checks' => $checks];

        if ($this->config->bool('app.debug') && $this->details !== []) {
            $body['details'] = $this->details;
        }

        return new JsonResponse($body, $failed ? 503 : 200, ['Cache-Control' => 'no-store']);
    }

    /** @return array{status: string} */
    private function database(): array
    {
        if (!$this->connections->isConfigured()) {
            return ['status' => 'skipped'];
        }

        return $this->attempt('database', function (): void {
            $this->connections->connection()->scalar('SELECT 1');
        });
    }

    /** @return array{status: string} */
    private function cache(): array
    {
        return $this->attempt('cache', function (): void {
            $key = 'health.' . \bin2hex(\random_bytes(4));
            $this->cache->set($key, 'ok', 60);
            $read = $this->cache->get($key);
            $this->cache->delete($key);

            if ($read !== 'ok') {
                throw new \RuntimeException('a value written to the cache did not come back');
            }
        });
    }

    /** @return array{status: string, pending?: array<string, int>} */
    private function queue(): array
    {
        // Sync runs jobs where they are dispatched; nothing ever waits.
        if ($this->config->string('queue.store', 'sync') === 'sync') {
            return ['status' => 'skipped'];
        }

        $pending = [];

        try {
            foreach ($this->queue->queues() as $name) {
                $pending[$name] = $this->queue->pending($name);
            }
        } catch (\Throwable $e) {
            $this->details['queue'] = $e->getMessage();

            return ['status' => 'fail'];
        }

        // A backlog is information, not a failure: a load balancer should not
        // pull an instance out of rotation because workers are behind.
        return ['status' => 'ok', 'pending' => $pending];
    }

    /** @return array{status: string} */
    private function disk(): array
    {
        return $this->attempt('disk', function (): void {
            $system = $this->application->basePath('system');

            if (!\is_dir($system) || !\is_writable($system)) {
                throw new \RuntimeException('system/ is not writable');
            }

            $free = @\disk_free_space($system);

            if ($free !== false && $free < self::MIN_FREE_BYTES) {
                throw new \RuntimeException(\sprintf('only %d bytes free in system/', (int) $free));
            }
        });
    }

    /** @return array{status: string} */
    private function attempt(string $check, \Closure $probe): array
    {
        try {
            $probe();

            return ['status' => 'ok'];
        } catch (\Throwable $e) {
            $this->details[$check] = $e->getMessage();

            return ['status' => 'fail'];
        }
    }
}
