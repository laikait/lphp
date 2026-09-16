<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module;

use App\Engine\Asset\AssetKind;
use App\Engine\Asset\AssetRegistry;
use App\Engine\Auth\AccessRegistry;
use App\Engine\Cli\CommandRegistry;
use App\Engine\Config\Config;
use App\Engine\Container\Container;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Module\ModuleDefinition;
use App\Engine\Module\ModuleDiscovery;
use App\Engine\Module\ModuleKind;
use App\Engine\Module\ModuleManager;
use App\Engine\Module\ModuleRegistry;
use App\Engine\Module\ModuleStage;
use App\Engine\Routing\Router;
use App\Engine\Scheduler\ScheduleRegistry;
use App\Engine\Template\TemplateRegistry;
use App\Tests\Support\TestCase;

/**
 * Discovery, its cache, and the asset path that stops after it.
 *
 * Each test builds its own application tree in a temporary directory rather
 * than using the shared fixtures, for two reasons: it needs modules with and
 * without assets/ and Templates/, and it writes cache files, which have no
 * business appearing in the project's own system/Cache.
 *
 * Every module.php here records that it ran before returning its closure, so
 * "no module code ran" is an assertion rather than an inference.
 */
final class DiscoveryCacheTest extends TestCase
{
    private const LOADED = 'discovery_cache_test_loaded';

    private string $root;

    private AssetRegistry $assets;

    private TemplateRegistry $templates;

    private ModuleRegistry $registry;

    protected function setUp(): void
    {
        $this->root = \str_replace('\\', '/', \sys_get_temp_dir()) . '/discovery-cache-' . \bin2hex(\random_bytes(6));
        $GLOBALS[self::LOADED] = [];

        $this->module('shared', null, templates: true);
        $this->module('plugins', 'Lazy', assets: true, templates: true);
        $this->module('plugins', 'Plain');
        $this->module('gateways', 'Pay', assets: true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        unset($GLOBALS[self::LOADED]);

        parent::tearDown();
    }

    // ---- discovery ---------------------------------------------------------

    public function test_discovery_records_whether_a_module_has_assets_and_templates(): void
    {
        $found = [];

        foreach ($this->discovery()->scan() as $definition) {
            $found[$definition->id] = [$definition->hasAssets, $definition->hasTemplates];
        }

        \ksort($found);

        self::assertSame([
            'gateways/Pay' => [true, false],
            'plugins/Lazy' => [true, true],
            'plugins/Plain' => [false, false],
            'shared' => [false, true],
        ], $found);
    }

    public function test_the_roots_are_absolute_and_normalised(): void
    {
        self::assertSame([
            'shared' => $this->root . '/modules/Shared',
            'plugins' => $this->root . '/modules/Plugins',
            'gateways' => $this->root . '/modules/Gateways',
        ], $this->discovery()->roots());
    }

    public function test_discovery_runs_no_module_code(): void
    {
        $this->manager()->discover();

        self::assertSame([], $GLOBALS[self::LOADED]);
    }

    // ---- the cache ---------------------------------------------------------

    /**
     * A request never writes it. The version of this cache that a first request
     * builds is the version built on a laptop halfway through adding a module.
     */
    public function test_discovery_never_writes_the_cache(): void
    {
        $this->manager()->run();

        self::assertFileDoesNotExist(ModuleRegistry::cacheFile($this->root));
    }

    /**
     * The cache names one module; four are on disk. Only one being found is the
     * proof that the directories were not walked.
     */
    public function test_a_warmed_cache_is_used_instead_of_a_scan(): void
    {
        $this->warm(static fn(ModuleDefinition $definition): bool => $definition->id === 'shared');

        $manager = $this->manager();
        $manager->discover();

        self::assertSame(['shared'], $this->registry->ids());
        self::assertTrue($manager->discoveredFromCache());
    }

    /** The specification's development mode: "uncached module discovery". */
    public function test_a_debug_process_never_reads_the_cache(): void
    {
        $this->warm(static fn(ModuleDefinition $definition): bool => $definition->id === 'shared');

        $manager = $this->manager(['app' => ['debug' => true]]);
        $manager->discover();

        self::assertCount(4, $this->registry->ids());
        self::assertFalse($manager->discoveredFromCache());
    }

    /**
     * modules.paths changed, or the application moved, or -- the case that
     * would actually happen -- a test suite points at other modules in a
     * checkout where somebody once ran cache:warm.
     */
    public function test_a_cache_built_under_other_roots_is_ignored(): void
    {
        $this->warm(
            static fn(ModuleDefinition $definition): bool => $definition->id === 'shared',
            ['plugins' => '/somewhere/else/plugins'],
        );

        $manager = $this->manager();
        $manager->discover();

        self::assertCount(4, $this->registry->ids());
        self::assertFalse($manager->discoveredFromCache());
    }

    public function test_a_cache_written_by_an_older_version_is_ignored(): void
    {
        $this->writeCacheFile(\var_export([
            ['id' => 'shared', 'kind' => 'shared', 'path' => $this->root . '/modules/Shared', 'entryFile' => 'x', 'directory' => 'shared'],
        ], true));

        $manager = $this->manager();
        $manager->discover();

        self::assertCount(4, $this->registry->ids());
        self::assertFalse($manager->discoveredFromCache());
    }

    /** One bad entry among good ones means scan, never a boot that found half its modules. */
    public function test_a_cache_with_one_malformed_entry_is_ignored_entirely(): void
    {
        $good = ModuleDefinition::create(ModuleKind::Shared, $this->root . '/modules/Shared', 'shared')->toArray();
        $bad = [...$good, 'id' => 'plugins/Broken', 'assets' => 'yes'];

        $this->writeCacheFile(\var_export(['roots' => $this->discovery()->roots(), 'modules' => [$good, $bad]], true));

        $registry = new ModuleRegistry();

        self::assertFalse($registry->readCache(ModuleRegistry::cacheFile($this->root), $this->discovery()->roots()));
        self::assertSame([], $registry->ids());
    }

    public function test_the_cache_round_trips_the_directory_facts(): void
    {
        $this->warm(static fn(): bool => true);

        $registry = new ModuleRegistry();
        self::assertTrue($registry->readCache(ModuleRegistry::cacheFile($this->root), $this->discovery()->roots()));

        $lazy = $registry->definition('plugins/Lazy');
        self::assertNotNull($lazy);
        self::assertTrue($lazy->hasAssets);
        self::assertTrue($lazy->hasTemplates);
        self::assertFalse($registry->definition('plugins/Plain')?->hasAssets);
    }

    /**
     * Registration asks discovery's answer, not the filesystem. The cache below
     * says plugins/Lazy has no assets/ -- it does, on disk -- and it is not
     * published. That is the observable form of "a cached boot probes no module
     * directory".
     */
    public function test_publishing_uses_what_discovery_recorded_rather_than_asking_again(): void
    {
        $this->warm(static fn(): bool => true, transform: static fn(ModuleDefinition $definition): ModuleDefinition
            => $definition->id === 'plugins/Lazy'
                ? new ModuleDefinition($definition->id, $definition->kind, $definition->path, $definition->entryFile, $definition->directory, false, false)
                : $definition);

        $this->manager()->run();

        self::assertFalse($this->assets->has(AssetKind::Plugin, 'Lazy'));
        self::assertTrue($this->assets->has(AssetKind::Gateway, 'Pay'));
        self::assertFalse($this->templates->hasNamespace('plugin.Lazy'));
        self::assertTrue($this->templates->hasNamespace('shared'));
    }

    // ---- the asset path ----------------------------------------------------

    public function test_preparing_for_an_asset_request_runs_no_module_code(): void
    {
        $manager = $this->manager();
        $manager->prepareAssets();

        self::assertSame([], $GLOBALS[self::LOADED], 'a module.php ran to serve an asset');
        self::assertSame([], $this->registry->contexts());
        self::assertSame(ModuleStage::Discovered, $manager->stage());

        self::assertTrue($this->assets->has(AssetKind::Plugin, 'Lazy'));
        self::assertTrue($this->assets->has(AssetKind::Gateway, 'Pay'));
        self::assertFalse($this->assets->has(AssetKind::Plugin, 'Plain'));
    }

    public function test_a_disabled_module_publishes_nothing_on_the_asset_path_either(): void
    {
        $this->manager(['modules' => ['disabled' => ['plugins/Lazy']]])->prepareAssets();

        self::assertFalse($this->assets->has(AssetKind::Plugin, 'Lazy'));
        self::assertTrue($this->assets->has(AssetKind::Gateway, 'Pay'));
    }

    /** A long-lived process may serve an asset and then a page. Nothing repeats. */
    public function test_a_full_boot_after_the_asset_path_repeats_nothing(): void
    {
        $manager = $this->manager();
        $manager->prepareAssets();
        $manager->run();

        $loaded = $GLOBALS[self::LOADED];
        \sort($loaded);

        self::assertSame(['gateways/Pay', 'plugins/Lazy', 'plugins/Plain', 'shared'], $loaded);
        self::assertSame(ModuleStage::Ready, $manager->stage());
    }

    // ---- helpers -----------------------------------------------------------

    /** @param array<string, mixed> $config */
    private function manager(array $config = []): ModuleManager
    {
        $this->assets = new AssetRegistry();
        $this->templates = new TemplateRegistry();
        $this->registry = new ModuleRegistry();

        return new ModuleManager(
            new Container(),
            new Config(\array_replace_recursive(['app' => ['debug' => false], 'modules' => ['paths' => self::PATHS]], $config)),
            new Router(),
            new HookEngine(),
            new FilterEngine(),
            $this->assets,
            $this->templates,
            new CommandRegistry(),
            new ScheduleRegistry(),
            new AccessRegistry(),
            $this->registry,
            $this->root,
        );
    }

    private const PATHS = [
        'shared' => 'modules/Shared',
        'plugins' => 'modules/Plugins',
        'gateways' => 'modules/Gateways',
    ];

    private function discovery(): ModuleDiscovery
    {
        return new ModuleDiscovery($this->root, self::PATHS);
    }

    /**
     * Write the cache the way cache:warm does, keeping only some modules.
     *
     * @param \Closure(ModuleDefinition): bool                  $keep
     * @param array<string, string>|null                        $roots     the roots to record, if not the real ones
     * @param (\Closure(ModuleDefinition): ModuleDefinition)|null $transform
     */
    private function warm(\Closure $keep, ?array $roots = null, ?\Closure $transform = null): void
    {
        $registry = new ModuleRegistry();

        foreach ($this->discovery()->scan() as $definition) {
            if ($keep($definition)) {
                $registry->add($transform === null ? $definition : $transform($definition));
            }
        }

        self::assertTrue($registry->writeCache(ModuleRegistry::cacheFile($this->root), $roots ?? $this->discovery()->roots()));
    }

    private function writeCacheFile(string $exported): void
    {
        $file = ModuleRegistry::cacheFile($this->root);
        @\mkdir(\dirname($file), 0o777, true);
        \file_put_contents($file, "<?php\n\nreturn " . $exported . ";\n");
    }

    private function module(string $kind, ?string $name, bool $assets = false, bool $templates = false): void
    {
        // The directory is the namespace segment -- modules/Plugins, not the kind's
        // value "plugins" -- which only a case-sensitive filesystem tells apart.
        $directory = $this->root . '/modules/' . \ucfirst($kind) . ($name === null ? '' : '/' . $name);
        \mkdir($directory, 0o777, true);

        $id = $name === null ? 'shared' : $kind . '/' . $name;

        \file_put_contents($directory . '/module.php', \str_replace(
            ['{KEY}', '{ID}'],
            [self::LOADED, $id],
            <<<'PHP'
                <?php

                declare(strict_types=1);

                $GLOBALS['{KEY}'][] = '{ID}';

                return static function (App\Engine\Module\ModuleContext $module): void {
                    $module->version('1.0.0');
                };

                PHP,
        ));

        if ($assets) {
            \mkdir($directory . '/assets/js', 0o777, true);
            \file_put_contents($directory . '/assets/js/app.js', "// asset\n");
        }

        if ($templates) {
            \mkdir($directory . '/Templates');
        }
    }

    private function remove(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $entry->isDir() ? @\rmdir($entry->getPathname()) : @\unlink($entry->getPathname());
            }
        }

        @\rmdir($path);
    }
}
