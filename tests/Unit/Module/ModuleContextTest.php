<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module;

use App\Engine\Container\ServiceRegistrar;
use App\Engine\Module\ModuleContext;
use App\Engine\Module\ModuleDefinition;
use App\Engine\Module\ModuleException;
use App\Engine\Module\ModuleKind;
use App\Engine\Module\ModuleStage;
use App\Engine\Routing\RouteCollector;
use App\Tests\Support\TestCase;

final class ModuleContextTest extends TestCase
{
    private function context(ModuleStage $stage = ModuleStage::Loading): ModuleContext
    {
        $context = new ModuleContext(
            ModuleDefinition::create(ModuleKind::Plugin, '/modules/plugins/Example', 'Example'),
        );

        $context->enterStage($stage);

        return $context;
    }

    // ---- identity ---------------------------------------------------------

    public function test_it_reports_its_identity(): void
    {
        $context = $this->context();

        self::assertSame('plugins/Example', $context->id());
        self::assertSame(ModuleKind::Plugin, $context->kind());
        self::assertSame('/modules/plugins/Example', $context->path());
        self::assertSame('/modules/plugins/Example/Templates', $context->path('Templates'));
    }

    public function test_metadata_is_fluent(): void
    {
        $context = $this->context();

        $returned = $context->name('Example')->version('1.2.3')->description('A demo.');

        self::assertSame($context, $returned);
        self::assertSame('Example', $context->moduleName());
        self::assertSame('1.2.3', $context->moduleVersion());
        self::assertSame('A demo.', $context->moduleDescription());
    }

    // ---- declarations are recorded, not executed --------------------------

    /**
     * This is what makes module ordering deterministic. If declaring ran the
     * registrar immediately, a module could observe whether it happened to load
     * before or after another, and the whole by-category replay would be moot.
     */
    public function test_declaring_services_does_not_run_the_registrar(): void
    {
        $ran = false;

        $context = $this->context();
        $context->services(static function (ServiceRegistrar $services) use (&$ran): void {
            $ran = true;
        });

        self::assertFalse($ran);
        self::assertCount(1, $context->declaredServices());
    }

    public function test_declaring_routes_does_not_run_the_registrar(): void
    {
        $ran = false;

        $context = $this->context();
        $context->routes(static function (RouteCollector $routes) use (&$ran): void {
            $ran = true;
        });

        self::assertFalse($ran);
        self::assertCount(1, $context->declaredRoutes());
    }

    public function test_declarations_accumulate(): void
    {
        $context = $this->context();

        $context->hook('a', static fn(): null => null);
        $context->hook('b', static fn(): null => null, 20, 1);
        $context->filter('c', static fn(mixed $v): mixed => $v, 30);
        $context->onBoot(static fn(): null => null);

        self::assertCount(2, $context->declaredHooks());
        self::assertCount(1, $context->declaredFilters());
        self::assertCount(1, $context->declaredBootCallbacks());

        self::assertSame('b', $context->declaredHooks()[1]['name']);
        self::assertSame(20, $context->declaredHooks()[1]['priority']);
        self::assertSame(1, $context->declaredHooks()[1]['acceptedArgs']);
        self::assertSame(30, $context->declaredFilters()[0]['priority']);
    }

    public function test_config_declarations_merge(): void
    {
        $context = $this->context();

        $context->config(['a' => 1]);
        $context->config(['b' => 2, 'a' => 3]);

        self::assertSame(['a' => 3, 'b' => 2], $context->declaredConfig());
    }

    // ---- stage enforcement ------------------------------------------------

    /**
     * The error has to name the module, the method and the stage. "You cannot
     * do that here" is useless without all three.
     */
    public function test_declaring_after_loading_names_the_module_the_method_and_the_stage(): void
    {
        $context = $this->context(ModuleStage::Booting);

        try {
            $context->routes(static function (RouteCollector $routes): void {});
            self::fail('expected a stage failure');
        } catch (ModuleException $e) {
            self::assertStringContainsString('plugins/Example', $e->getMessage());
            self::assertStringContainsString('routes()', $e->getMessage());
            self::assertStringContainsString('booting', $e->getMessage());
        }
    }

    /**
     * @return list<array{0: string, 1: \Closure(ModuleContext): mixed}>
     */
    public static function declarationProvider(): array
    {
        return [
            ['name', static fn(ModuleContext $c): mixed => $c->name('x')],
            ['version', static fn(ModuleContext $c): mixed => $c->version('1.0.0')],
            ['description', static fn(ModuleContext $c): mixed => $c->description('x')],
            ['config', static fn(ModuleContext $c): mixed => $c->config(['a' => 1])],
            ['services', static fn(ModuleContext $c): mixed => $c->services(static function (): void {})],
            ['routes', static fn(ModuleContext $c): mixed => $c->routes(static function (): void {})],
            ['hook', static fn(ModuleContext $c): mixed => $c->hook('a', static fn(): null => null)],
            ['filter', static fn(ModuleContext $c): mixed => $c->filter('a', static fn(mixed $v): mixed => $v)],
            ['onBoot', static fn(ModuleContext $c): mixed => $c->onBoot(static fn(): null => null)],
        ];
    }

    /**
     * Every declaring method is gated, not just the ones that obviously matter.
     * A gap here is a gap in the guarantee.
     *
     * @param \Closure(ModuleContext): mixed $declare
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('declarationProvider')]
    public function test_every_declaring_method_is_stage_gated(string $method, \Closure $declare): void
    {
        $context = $this->context(ModuleStage::Ready);

        try {
            $declare($context);
            self::fail(\sprintf('%s() is not stage-gated', $method));
        } catch (ModuleException $e) {
            self::assertStringContainsString($method . '()', $e->getMessage());
        }
    }

    public function test_declaring_before_loading_is_also_refused(): void
    {
        $context = $this->context(ModuleStage::Discovered);

        $this->expectException(ModuleException::class);

        $context->config(['a' => 1]);
    }

    public function test_introspection_works_at_every_stage(): void
    {
        foreach (ModuleStage::cases() as $stage) {
            $context = $this->context($stage);

            self::assertSame('plugins/Example', $context->id());
            self::assertSame($stage, $context->stage());
            self::assertSame(ModuleKind::Plugin, $context->kind());
        }
    }

    // ---- the contract shape -----------------------------------------------

    /**
     * A module is a file that describes itself. If this ever gains a parent or
     * an interface, module authors have acquired something to inherit from, and
     * the design has drifted towards the service provider it deliberately is
     * not.
     */
    public function test_the_module_api_requires_nothing_to_be_extended_or_implemented(): void
    {
        $reflection = new \ReflectionClass(ModuleContext::class);

        self::assertTrue($reflection->isFinal());
        self::assertFalse($reflection->getParentClass());
        self::assertSame([], $reflection->getInterfaceNames());
    }

    public function test_module_kinds_rank_shared_first_and_gateways_last(): void
    {
        self::assertSame(0, ModuleKind::Shared->rank());
        self::assertSame(1, ModuleKind::Plugin->rank());
        self::assertSame(2, ModuleKind::Gateway->rank());

        self::assertFalse(ModuleKind::Shared->isContainer());
        self::assertTrue(ModuleKind::Plugin->isContainer());
        self::assertTrue(ModuleKind::Gateway->isContainer());
    }

    public function test_definitions_sort_by_kind_then_directory(): void
    {
        $shared = ModuleDefinition::create(ModuleKind::Shared, '/m/shared', 'shared');
        $alpha = ModuleDefinition::create(ModuleKind::Plugin, '/m/plugins/Alpha', 'Alpha');
        $zeta = ModuleDefinition::create(ModuleKind::Gateway, '/m/gateways/Zeta', 'Zeta');

        self::assertLessThan($alpha->sortKey(), $shared->sortKey());
        self::assertLessThan($zeta->sortKey(), $alpha->sortKey());
    }

    public function test_a_definition_round_trips_through_its_array_form(): void
    {
        $definition = ModuleDefinition::create(ModuleKind::Plugin, '/m/plugins/Alpha', 'Alpha');

        self::assertEquals($definition, ModuleDefinition::fromArray($definition->toArray()));
    }

    public function test_a_definition_points_at_its_entry_file(): void
    {
        $definition = ModuleDefinition::create(ModuleKind::Plugin, '/m/plugins/Alpha/', 'Alpha');

        self::assertSame('/m/plugins/Alpha', $definition->path);
        self::assertSame('/m/plugins/Alpha/module.php', $definition->entryFile);
        self::assertSame('/m/plugins/Alpha/Api/List.php', $definition->file('Api/List.php'));
    }
}
