<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bootstrap;

use App\Engine\Bootstrap\Bootstrap;
use App\Engine\Cli\ConsoleKernel;
use App\Engine\Config\Config;
use App\Engine\Container\Container;
use App\Engine\Core\Application;
use App\Engine\Core\ExecutionContext;
use App\Engine\Core\ExecutionMode;
use App\Engine\Core\HttpKernel;
use App\Engine\Database\ConnectionManager;
use App\Engine\Dispatch\Dispatcher;
use App\Engine\Error\ErrorHandler;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Model\ModelManager;
use App\Engine\Model\RelationManager;
use App\Engine\Module\ModuleManager;
use App\Engine\Routing\Router;
use App\Engine\Support\Extensions;
use App\Tests\Support\TestCase;

final class BootstrapTest extends TestCase
{
    public function test_it_returns_an_application_that_has_not_booted(): void
    {
        $app = $this->application();

        self::assertInstanceOf(Application::class, $app);
        self::assertFalse($app->isBooted());

        // Bootstrap is pure wiring: nothing has been discovered or registered.
        self::assertSame(0, $app->container()->get(Router::class)->count());
        self::assertSame(0, $app->container()->get(ModuleManager::class)->registry()->count());
    }

    public function test_the_core_services_are_bound_and_shared(): void
    {
        $container = $this->application()->container();

        $services = [
            Container::class,
            Config::class,
            HookEngine::class,
            FilterEngine::class,
            Router::class,
            ErrorHandler::class,
            ExecutionContext::class,
            ModelManager::class,
            RelationManager::class,
            ConnectionManager::class,
            ModuleManager::class,
            Application::class,
            Dispatcher::class,
            HttpKernel::class,
            ConsoleKernel::class,
        ];

        foreach ($services as $service) {
            self::assertTrue($container->has($service), $service . ' is not bound');
            self::assertSame($container->get($service), $container->get($service), $service . ' is not shared');
        }
    }

    public function test_the_container_contains_itself(): void
    {
        $container = $this->application()->container();

        self::assertSame($container, $container->get(Container::class));
    }

    public function test_the_helper_bridge_is_wired(): void
    {
        Extensions::reset();
        self::assertFalse(Extensions::isInitialised());

        $app = $this->application();

        self::assertTrue(Extensions::isInitialised());
        self::assertSame($app->container()->get(HookEngine::class), Extensions::hooks());
        self::assertSame($app->container()->get(FilterEngine::class), Extensions::filters());
    }

    public function test_booting_is_idempotent(): void
    {
        $app = $this->fixtureApplication();

        self::assertFalse($app->isBooted());

        $app->boot();
        self::assertTrue($app->isBooted());

        $routes = $app->container()->get(Router::class)->count();
        $app->boot();

        self::assertSame($routes, $app->container()->get(Router::class)->count());
    }

    public function test_the_boot_hooks_fire_once_and_in_order(): void
    {
        $app = $this->fixtureApplication();
        $seen = [];

        $app->container()->get(HookEngine::class)->add('app.booted', static function () use (&$seen): void {
            $seen[] = 'app.booted';
        });
        $app->container()->get(HookEngine::class)->add('app.ready', static function () use (&$seen): void {
            $seen[] = 'app.ready';
        });

        $app->boot();
        $app->boot();

        self::assertSame(['app.booted', 'app.ready'], $seen);
    }

    // ---- configuration ----------------------------------------------------

    public function test_the_defaults_cover_exactly_the_documented_keys(): void
    {
        $config = new Config(Bootstrap::defaults());

        foreach ([
            'app.env',
            'app.debug',
            'app.handle_errors',
            'http.base_path',
            'http.trusted_proxies',
            'database.default',
            'database.connections',
            'assets.url',
            'assets.versioning',
            'assets.manifests',
            'assets.max_age',
            'templates.active',
            'templates.cache',
            'modules.paths',
            'modules.disabled',
            'observability.profile',
            'observability.slow_query_ms',
            'observability.trust_incoming_ids',
            'cache.store',
            'cache.ttl',
            'queue.store',
            'queue.tries',
            'queue.backoff.cap',
            'security.key',
            'security.csrf.enabled',
            'security.max_request_bytes',
            'security.headers.csp',
            'scheduler.lock',
            'scheduler.lock_ttl',
            'auth.password.options',
            'auth.guest_roles',
            'session.store',
            'session.idle',
            'session.absolute',
            'session.grace',
            'session.cookie.name',
            'session.cookie.same_site',
            'system.enabled',
            'system.execution.default_timeout',
            'system.execution.max_output',
            'system.execution.max_concurrent',
            'system.execution.http_timeout',
            'system.shell.enabled',
            'system.shell.binary',
            'system.services',
            'system.filesystem.read',
            'system.filesystem.write',
            'system.permissions.owners',
            'system.permissions.groups',
            'system.cron.enabled',
            'system.audit.enabled',
            'mcp.enabled',
            'mcp.server.name',
            'mcp.server.version',
            'mcp.transports.stdio',
            'mcp.transports.http',
            'mcp.http.path',
            'mcp.allow_guests',
            'mcp.log.enabled',
        ] as $key) {
            self::assertTrue($config->has($key), $key . ' is missing from the defaults');
        }

        // Every top-level block the framework itself defines. A file in config/
        // may add to this, but a new block appearing here is the framework
        // growing a subsystem, which is a deliberate act rather than a drive-by.
        self::assertSame(
            [
                'app', 'http', 'database', 'assets', 'cache', 'queue', 'security', 'auth', 'session',
                'scheduler', 'system', 'mcp', 'logging', 'observability', 'templates', 'modules',
            ],
            \array_keys($config->all()),
        );
    }

    /**
     * Declaring a connection must not open one, and an application with none
     * configured must boot exactly as before.
     */
    public function test_no_connections_are_configured_or_opened_by_default(): void
    {
        $connections = $this->application()->container()->get(ConnectionManager::class);

        self::assertFalse($connections->isConfigured());
        self::assertSame([], $connections->names());
        self::assertSame([], $connections->opened());
    }

    public function test_connections_come_from_configuration(): void
    {
        $app = $this->application(['database' => [
            'default' => 'reports',
            'connections' => [
                'main' => ['dsn' => 'sqlite::memory:'],
                'reports' => ['dsn' => 'sqlite::memory:'],
            ],
        ]]);

        $connections = $app->container()->get(ConnectionManager::class);

        self::assertSame(['main', 'reports'], $connections->names());
        self::assertSame('reports', $connections->defaultName());
        self::assertSame([], $connections->opened(), 'configuring is not connecting');
    }

    /**
     * There is no setting to switch the module cache on. It used to be one, and
     * the first request to find the cache missing wrote it -- which is how a
     * cache gets built on a laptop halfway through adding a module. The file is
     * the switch now, and only cache:warm writes it.
     */
    public function test_there_is_no_module_cache_setting(): void
    {
        self::assertFalse((new Config(Bootstrap::defaults()))->has('modules.cache'));
    }

    public function test_the_default_module_paths_match_the_real_layout(): void
    {
        $paths = (new Config(Bootstrap::defaults()))->get('modules.paths');

        self::assertSame([
            'shared' => 'modules/Shared',
            'plugins' => 'modules/Plugins',
            'gateways' => 'modules/Gateways',
        ], $paths);

        // Only shared has to exist. The framework ships no plugin and no
        // gateway, git keeps no empty directory, and an invariant forbids one
        // kept for appearance -- so modules/Plugins/ appears when the first
        // plugin does, and discovery reads an absent root as an empty one.
        // DefaultPagesSliceTest boots exactly that.
        self::assertDirectoryExists($this->basePath('modules/Shared'));
    }

    public function test_overrides_merge_recursively_over_the_defaults(): void
    {
        $untouched = new Config(Bootstrap::defaults());
        $config = new Config(Bootstrap::defaults(['app' => ['debug' => true]]));

        self::assertTrue($config->get('app.debug'));

        // The sibling key must survive the override, whatever it resolved to.
        self::assertSame($untouched->get('app.env'), $config->get('app.env'));
        self::assertSame($untouched->get('modules.paths'), $config->get('modules.paths'));
    }

    public function test_the_environment_can_set_app_env(): void
    {
        // phpunit.xml sets APP_ENV=testing, so this also proves the env is read
        // rather than the hard-coded default being returned unconditionally.
        self::assertSame(\getenv('APP_ENV'), (new Config(Bootstrap::defaults()))->get('app.env'));
    }

    public function test_debug_reaches_the_filter_engine(): void
    {
        $app = $this->application(['app' => ['debug' => true]]);

        self::assertTrue($app->config()->get('app.debug'));

        $filters = $app->container()->get(FilterEngine::class);
        $filters->add('x', static fn(mixed $v): mixed => null);

        // The debug-only null guard is active, which is only true if the flag
        // reached the engine at construction.
        $this->expectException(\App\Engine\Filter\FilterException::class);
        $filters->apply('x', 'value');
    }

    // ---- execution contexts -----------------------------------------------

    public function test_an_http_context_is_reported_as_http(): void
    {
        $app = $this->application();

        self::assertTrue($app->context()->isHttp());
        self::assertFalse($app->context()->isCli());
        self::assertSame(ExecutionMode::Http, $app->context()->mode);
    }

    public function test_a_cli_context_exposes_the_command_and_its_arguments(): void
    {
        $context = ExecutionContext::cli(['laika', 'module:list', '--verbose']);

        self::assertTrue($context->isCli());
        self::assertSame('module:list', $context->command());
        self::assertSame(['--verbose'], $context->arguments());
    }

    public function test_a_cli_context_with_no_command_reports_null(): void
    {
        self::assertNull(ExecutionContext::cli(['laika'])->command());
        self::assertSame([], ExecutionContext::cli(['laika'])->arguments());
    }

    public function test_the_context_tracks_elapsed_time(): void
    {
        $context = ExecutionContext::http(['REQUEST_TIME_FLOAT' => \microtime(true) - 1.0]);

        self::assertGreaterThanOrEqual(1.0, $context->elapsed());
    }

    /**
     * One bootstrap serves both contexts, which is the concrete form of "the
     * CLI uses the same application bootstrap".
     */
    public function test_the_same_bootstrap_builds_both_contexts(): void
    {
        $http = Bootstrap::create($this->basePath(), ExecutionContext::http(), ['app' => ['handle_errors' => false]]);
        $cli = Bootstrap::create($this->basePath(), ExecutionContext::cli(['laika']), ['app' => ['handle_errors' => false]]);

        self::assertTrue($http->container()->has(HttpKernel::class));
        self::assertTrue($cli->container()->has(HttpKernel::class));
        self::assertTrue($http->container()->has(ConsoleKernel::class));
        self::assertTrue($cli->container()->has(ConsoleKernel::class));
    }

    // ---- the entry points -------------------------------------------------

    /**
     * index.php must stay a front controller and nothing else. The moment
     * framework logic appears in it, the boot path has two homes.
     */
    public function test_index_php_contains_no_framework_logic(): void
    {
        $source = \file_get_contents($this->basePath('public/index.php'));
        self::assertIsString($source);

        $statements = \array_values(\array_filter(
            \array_map('trim', \explode("\n", $source)),
            static fn(string $line): bool => $line !== ''
                && !\str_starts_with($line, '<?php')
                && !\str_starts_with($line, '//')
                && !\str_starts_with($line, 'declare('),
        ));

        self::assertCount(3, $statements, 'index.php should require the autoloader, the bootstrap, and run.');
        self::assertStringContainsString('vendor/autoload.php', $statements[0]);
        self::assertStringContainsString('engine/bootstrap.php', $statements[1]);
        self::assertStringContainsString('run()', $statements[2]);
    }

    public function test_the_console_entry_point_delegates_to_the_same_bootstrap(): void
    {
        $source = \file_get_contents($this->basePath('laika'));
        self::assertIsString($source);

        self::assertStringContainsString('engine/bootstrap.php', $source);
        self::assertStringContainsString('run()', $source);
    }

    public function test_base_path_joins_correctly_on_any_platform(): void
    {
        $app = $this->application();

        self::assertSame($this->basePath(), $app->basePath());
        self::assertStringEndsWith('/engine/Support', $app->basePath('engine/Support'));
        self::assertStringNotContainsString('//', $app->basePath('engine/Support'));
    }
}
