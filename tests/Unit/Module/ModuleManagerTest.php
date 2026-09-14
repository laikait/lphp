<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module;

use App\Engine\Asset\AssetRegistry;
use App\Engine\Cli\CommandRegistry;
use App\Engine\Config\Config;
use App\Engine\Container\Container;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Module\ModuleContext;
use App\Engine\Module\ModuleDefinition;
use App\Engine\Module\ModuleException;
use App\Engine\Module\ModuleKind;
use App\Engine\Module\ModuleManager;
use App\Engine\Module\ModuleRegistry;
use App\Engine\Module\ModuleStage;
use App\Engine\Routing\Router;
use App\Engine\Scheduler\ScheduleRegistry;
use App\Engine\Template\TemplateRegistry;
use App\Tests\Fixtures\Modules\Plugins\Alpha\Handlers\Recorder;
use App\Tests\Support\TestCase;

final class ModuleManagerTest extends TestCase
{
    private Container $container;

    private Config $config;

    private Router $router;

    private HookEngine $hooks;

    private FilterEngine $filters;

    private ModuleRegistry $registry;

    private ModuleManager $manager;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->config = new Config([
            'modules' => [
                'paths' => [
                    'shared' => 'tests/Fixtures/Modules/Shared',
                    'plugins' => 'tests/Fixtures/Modules/Plugins',
                    'gateways' => 'tests/Fixtures/Modules/Gateways',
                ],
                'cache' => false,
            ],
        ]);
        $this->router = new Router();
        $this->hooks = new HookEngine();
        $this->filters = new FilterEngine();
        $this->registry = new ModuleRegistry();

        $this->manager = new ModuleManager(
            $this->container,
            $this->config,
            $this->router,
            $this->hooks,
            $this->filters,
            new AssetRegistry(),
            new TemplateRegistry(),
            new CommandRegistry(),
            new ScheduleRegistry(),
            $this->registry,
            $this->basePath(),
        );
    }

    // ---- discovery --------------------------------------------------------

    public function test_discovery_finds_modules_across_all_three_roots(): void
    {
        $this->manager->discover();

        self::assertSame(
            ['shared', 'plugins/Alpha', 'plugins/Beta', 'gateways/Zeta'],
            $this->registry->ids(),
        );
    }

    /**
     * Order is by kind rank then directory name, never filesystem order:
     * readdir() ordering varies by filesystem and platform, and behaviour that
     * depends on it differs between a laptop and production.
     */
    public function test_module_order_is_deterministic_and_puts_shared_first(): void
    {
        $this->manager->discover();

        $ids = $this->registry->ids();

        self::assertSame('shared', $ids[0]);
        self::assertSame(['plugins/Alpha', 'plugins/Beta'], \array_slice($ids, 1, 2));
        self::assertSame('gateways/Zeta', $ids[3]);
    }

    public function test_a_directory_without_a_module_file_is_ignored(): void
    {
        $this->manager->discover();

        self::assertDirectoryExists($this->basePath('tests/Fixtures/Modules/Plugins/NotAModule'));
        self::assertFalse($this->registry->has('plugins/NotAModule'));
    }

    /**
     * The specification's own tree has a plugin and a gateway both called
     * "Example", so bare directory names cannot be the identity.
     */
    public function test_ids_are_qualified_by_kind(): void
    {
        $this->manager->discover();

        self::assertSame('plugins/Alpha', $this->registry->definition('plugins/Alpha')?->id);
        self::assertSame(ModuleKind::Gateway, $this->registry->definition('gateways/Zeta')?->kind);
        self::assertSame('shared', $this->registry->definition('shared')?->id);
    }

    public function test_discovery_runs_no_module_code(): void
    {
        $this->manager->discover();

        // If a module.php had run, its routes and hooks would exist by now.
        self::assertSame(0, $this->router->count());
        self::assertSame([], $this->hooks->names());
        self::assertNull($this->registry->context('plugins/Alpha'));
    }

    public function test_a_missing_root_is_not_an_error(): void
    {
        $config = new Config(['modules' => ['paths' => ['plugins' => 'tests/Fixtures/DoesNotExist']]]);

        $manager = new ModuleManager(
            $this->container,
            $config,
            $this->router,
            $this->hooks,
            $this->filters,
            new AssetRegistry(),
            new TemplateRegistry(),
            new CommandRegistry(),
            new ScheduleRegistry(),
            $registry = new ModuleRegistry(),
            $this->basePath(),
        );

        $manager->discover();

        self::assertSame(0, $registry->count());
    }

    public function test_a_duplicate_id_from_a_different_path_is_rejected(): void
    {
        $registry = new ModuleRegistry();
        $registry->add(ModuleDefinition::create(ModuleKind::Plugin, '/a/Alpha', 'Alpha'));

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('Two modules claim the id "plugins/Alpha"');

        $registry->add(ModuleDefinition::create(ModuleKind::Plugin, '/b/Alpha', 'Alpha'));
    }

    // ---- loading ----------------------------------------------------------

    public function test_loading_records_declarations_without_executing_them(): void
    {
        $this->manager->discover();
        $this->manager->load();

        $alpha = $this->registry->context('plugins/Alpha');

        self::assertInstanceOf(ModuleContext::class, $alpha);
        self::assertNotSame([], $alpha->declaredRoutes());

        // Recorded, not executed: nothing has reached the router or the engines.
        self::assertSame(0, $this->router->count());
        self::assertSame([], $this->hooks->names());
        self::assertSame([], $this->filters->names());
    }

    public function test_metadata_is_captured(): void
    {
        $this->manager->discover();
        $this->manager->load();

        $alpha = $this->registry->context('plugins/Alpha');
        $shared = $this->registry->context('shared');

        self::assertNotNull($alpha);
        self::assertNotNull($shared);

        self::assertSame('Alpha', $alpha->moduleName());
        self::assertSame('2.1.0', $alpha->moduleVersion());
        self::assertSame('Cross-module services and values.', $shared->moduleDescription());
    }

    public function test_the_module_name_defaults_to_its_id(): void
    {
        $definition = ModuleDefinition::create(ModuleKind::Plugin, '/x/Thing', 'Thing');

        self::assertSame('plugins/Thing', (new ModuleContext($definition))->moduleName());
    }

    public function test_an_entry_file_that_returns_the_wrong_thing_is_reported_clearly(): void
    {
        $directory = $this->temporaryModule('return ["routes" => []];');

        $manager = $this->managerFor($directory, $registry = new ModuleRegistry());

        try {
            $manager->discover();
            $manager->load();
            self::fail('expected a module failure');
        } catch (ModuleException $e) {
            self::assertStringContainsString('must return a closure', $e->getMessage());
            self::assertStringContainsString('returned array', $e->getMessage());
            self::assertStringContainsString('plugins/Temp', $e->getMessage());
        } finally {
            $this->removeTemporaryModule($directory);
        }

        self::assertTrue($registry->has('plugins/Temp'));
    }

    // ---- registration -----------------------------------------------------

    public function test_registration_applies_every_declaration(): void
    {
        $this->manager->run();

        self::assertGreaterThan(0, $this->router->count());
        self::assertTrue($this->hooks->has('order.recorded'));
        self::assertTrue($this->filters->has('items.list'));
        self::assertSame('USD', $this->config->get('shared.currency'));
        self::assertSame(25, $this->config->get('plugins/Alpha.page_size'));
    }

    public function test_routes_are_attributed_to_the_module_that_declared_them(): void
    {
        $this->manager->run();

        self::assertSame('plugins/Alpha', $this->router->route('items.index')?->module());
        self::assertSame('shared', $this->router->route('shared.ping')?->module());
    }

    public function test_group_names_and_metadata_survive_module_registration(): void
    {
        $this->manager->run();

        $route = $this->router->route('api.v1.items.show');

        self::assertNotNull($route);
        self::assertSame('/api/v1/items/{id}', $route->path());
        self::assertTrue($route->metaValue('api'));
    }

    public function test_hooks_and_filters_record_their_owning_module(): void
    {
        $this->manager->run();

        self::assertSame('shared', $this->hooks->listeners('order.recorded')[0]['module']);
        self::assertSame('plugins/Alpha', $this->filters->listeners('items.list')[0]['module']);
    }

    /**
     * Registration replays by category, so every service is bound before any
     * route is registered and all config exists before any factory is defined.
     * Replaying per module instead reintroduces the ordering bugs that service
     * providers are known for.
     */
    public function test_registration_replays_by_category_not_per_module(): void
    {
        $this->manager->discover();
        $this->manager->load();

        $order = [];

        // Config is merged first, so observing it from a service factory proves
        // the category ordering held.
        $this->hooks->add('module.registered', function () use (&$order): void {
            $order[] = 'registered';
        });

        $this->manager->register();

        // Every module's config and services exist before any module.registered
        // fires, which is only true if the replay was by category.
        self::assertSame(['registered', 'registered', 'registered', 'registered'], $order);
        self::assertTrue($this->container->has(Recorder::class));
        self::assertSame(25, $this->config->get('plugins/Alpha.page_size'));
    }

    public function test_module_registered_fires_once_per_module_in_order(): void
    {
        $seen = [];

        $this->hooks->add('module.registered', static function (ModuleDefinition $definition) use (&$seen): void {
            $seen[] = $definition->id;
        });

        $this->manager->run();

        self::assertSame(['shared', 'plugins/Alpha', 'plugins/Beta', 'gateways/Zeta'], $seen);
    }

    /**
     * A boot callback declares its dependencies and the container injects them.
     * If the container did not already know the engines, it would autowire new
     * ones and the module's listeners would land on engines nothing ever fires
     * -- a silent, very confusing failure.
     */
    public function test_a_boot_callback_receives_the_engines_the_framework_actually_uses(): void
    {
        $this->manager->run();

        self::assertSame($this->hooks, $this->container->get(HookEngine::class));
        self::assertSame($this->filters, $this->container->get(FilterEngine::class));
        self::assertSame($this->router, $this->container->get(Router::class));
        self::assertSame($this->config, $this->container->get(Config::class));

        // The shared fixture registers this listener from onBoot, so it only
        // appears here if the injected engine was the real one.
        self::assertTrue($this->hooks->has('order.recorded'));
        self::assertSame('shared', $this->hooks->listeners('order.recorded')[0]['module']);
    }

    public function test_an_already_bound_engine_is_not_replaced(): void
    {
        $ours = new HookEngine();
        $this->container->instance(HookEngine::class, $ours);

        $this->manager->run();

        self::assertSame($ours, $this->container->get(HookEngine::class));
    }

    // ---- boot -------------------------------------------------------------

    public function test_boot_callbacks_receive_injected_dependencies_in_module_order(): void
    {
        $this->manager->run();

        $recorder = $this->container->get(Recorder::class);

        self::assertSame(['shared', 'plugins/Alpha', 'plugins/Beta', 'gateways/Zeta'], $recorder->booted);
    }

    public function test_module_booted_fires_per_module(): void
    {
        $seen = [];

        $this->hooks->add('module.booted', static function (ModuleDefinition $definition) use (&$seen): void {
            $seen[] = $definition->id;
        });

        $this->manager->run();

        self::assertSame(['shared', 'plugins/Alpha', 'plugins/Beta', 'gateways/Zeta'], $seen);
    }

    public function test_every_module_ends_ready(): void
    {
        $this->manager->run();

        self::assertSame(ModuleStage::Ready, $this->manager->stage());

        foreach ($this->registry->contexts() as $context) {
            self::assertSame(ModuleStage::Ready, $context->stage());
        }
    }

    public function test_running_twice_is_idempotent(): void
    {
        $this->manager->run();
        $routes = $this->router->count();
        $listeners = \count($this->hooks->listeners('order.recorded'));

        $this->manager->run();

        self::assertSame($routes, $this->router->count());
        self::assertSame($listeners, \count($this->hooks->listeners('order.recorded')));
        self::assertCount(4, $this->container->get(Recorder::class)->booted);
    }

    // ---- discovery cache --------------------------------------------------

    public function test_the_discovery_cache_round_trips_identically(): void
    {
        $this->manager->discover();
        $expected = $this->registry->toArray();

        $file = $this->basePath('system/Cache/modules-test.php');
        self::assertTrue($this->registry->writeCache($file));

        $restored = new ModuleRegistry();
        self::assertTrue($restored->readCache($file));
        self::assertSame($expected, $restored->toArray());

        \unlink($file);
    }

    public function test_reading_an_absent_cache_reports_false(): void
    {
        self::assertFalse((new ModuleRegistry())->readCache($this->basePath('system/Cache/nope.php')));
    }

    // ---- helpers ----------------------------------------------------------

    private function managerFor(string $pluginsRoot, ModuleRegistry $registry): ModuleManager
    {
        return new ModuleManager(
            $this->container,
            new Config(['modules' => ['paths' => ['plugins' => $pluginsRoot]]]),
            $this->router,
            $this->hooks,
            $this->filters,
            new AssetRegistry(),
            new TemplateRegistry(),
            new CommandRegistry(),
            new ScheduleRegistry(),
            $registry,
            $this->basePath(),
        );
    }

    /** @return string the plugins root containing the temporary module */
    private function temporaryModule(string $body): string
    {
        $root = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'fw-modules-' . \bin2hex(\random_bytes(4));
        $module = $root . \DIRECTORY_SEPARATOR . 'Temp';

        \mkdir($module, 0o775, true);
        \file_put_contents($module . \DIRECTORY_SEPARATOR . 'module.php', "<?php\n\n" . $body . "\n");

        return $root;
    }

    private function removeTemporaryModule(string $root): void
    {
        $module = $root . \DIRECTORY_SEPARATOR . 'Temp';

        @\unlink($module . \DIRECTORY_SEPARATOR . 'module.php');
        @\rmdir($module);
        @\rmdir($root);
    }
}
