<?php

declare(strict_types=1);

namespace App\Engine\Module;

use App\Engine\Asset\AssetKind;
use App\Engine\Asset\AssetRegistry;
use App\Engine\Asset\AssetSource;
use App\Engine\Cli\CommandCollector;
use App\Engine\Cli\CommandRegistry;
use App\Engine\Config\Config;
use App\Engine\Container\Container;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Routing\RouteCollector;
use App\Engine\Routing\Router;
use App\Engine\Scheduler\ScheduleCollector;
use App\Engine\Scheduler\ScheduleRegistry;
use App\Engine\Support\Path;
use App\Engine\Template\TemplateRegistry;
use App\Engine\Template\TemplateSource;

/**
 * Drives the module lifecycle: Discover, Load, Register, Boot, Ready.
 *
 * What is legal in each stage:
 *
 *   Discover   No user code runs at all. The filesystem is scanned for
 *              module.php files under the configured roots, each candidate is
 *              checked for containment, and definitions are built from plain
 *              scalars. This is the stage a cache can replace wholesale.
 *
 *   Load       Each module.php closure runs and RECORDS its declarations.
 *              Nothing is bound, routed or hooked yet, so a module cannot
 *              observe whether it happened to load before or after another.
 *
 *   Register   Declarations are replayed across every module, BY CATEGORY:
 *              config, then assets and templates, then services, then
 *              routes, then commands, then schedules, then hooks, then
 *              filters.
 *              This is the important detail. Replaying per module instead
 *              would mean one module's route registration could run before
 *              another's service bindings, which is precisely the ordering
 *              bug service providers are famous for. By category, all config
 *              exists before any factory is defined, and every service is
 *              bound before any route is registered.
 *
 *   Boot       onBoot callbacks run in module order, with their parameters
 *              injected. Everything is registered by now, so cross-module work
 *              is safe here and only here.
 *
 *   Ready      Nothing further happens; the contexts are frozen.
 */
final class ModuleManager
{
    private ModuleStage $stage = ModuleStage::Discovered;

    private bool $ran = false;

    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
        private readonly Router $router,
        private readonly HookEngine $hooks,
        private readonly FilterEngine $filters,
        private readonly AssetRegistry $assets = new AssetRegistry(),
        private readonly TemplateRegistry $templates = new TemplateRegistry(),
        private readonly CommandRegistry $commands = new CommandRegistry(),
        private readonly ScheduleRegistry $schedules = new ScheduleRegistry(),
        private readonly ModuleRegistry $registry = new ModuleRegistry(),
        private readonly string $basePath = '',
    ) {}

    public function registry(): ModuleRegistry
    {
        return $this->registry;
    }

    public function stage(): ModuleStage
    {
        return $this->stage;
    }

    /** Discover, load, register and boot. Idempotent. */
    public function run(): void
    {
        if ($this->ran) {
            return;
        }

        $this->ran = true;

        $this->discover();
        $this->load();
        $this->register();
        $this->boot();

        $this->stage = ModuleStage::Ready;
    }

    // ---- discover ---------------------------------------------------------

    /**
     * Scan the configured roots for module.php files.
     *
     * No module code runs here, which is what makes this stage cacheable and
     * what makes a broken module fail at Load with a clear message rather than
     * during a filesystem walk.
     */
    public function discover(): void
    {
        $this->stage = ModuleStage::Discovered;

        if ($this->readDiscoveryCache()) {
            return;
        }

        foreach ($this->moduleRoots() as $kindValue => $relative) {
            $kind = ModuleKind::tryFrom($kindValue);

            if ($kind === null) {
                continue;
            }

            $root = Path::isAbsolute($relative) ? $relative : Path::join($this->basePath, $relative);

            $kind->isContainer()
                ? $this->discoverContainer($kind, $root)
                : $this->discoverModule($kind, $root, $kind->value);
        }

        $this->writeDiscoveryCache();
    }

    private function discoverContainer(ModuleKind $kind, string $root): void
    {
        if (!\is_dir($root)) {
            return;
        }

        $entries = \scandir($root);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->discoverModule($kind, Path::join($root, $entry), $entry);
        }
    }

    private function discoverModule(ModuleKind $kind, string $path, string $directory): void
    {
        if (!\is_dir($path) || !\is_file(Path::join($path, 'module.php'))) {
            return;
        }

        $this->registry->add(ModuleDefinition::create($kind, $path, $directory));
    }

    /** @return array<string, string> */
    private function moduleRoots(): array
    {
        /** @var mixed $paths */
        $paths = $this->config->get('modules.paths', []);

        if (!\is_array($paths)) {
            return [];
        }

        $roots = [];

        foreach ($paths as $kind => $path) {
            if (\is_string($kind) && \is_string($path)) {
                $roots[$kind] = $path;
            }
        }

        return $roots;
    }

    // ---- load -------------------------------------------------------------

    /**
     * Run each module.php.
     *
     * The closure only records; see ModuleContext. A module.php that returns
     * anything else fails here, naming the file, rather than producing a
     * TypeError deeper in the manager.
     */
    public function load(): void
    {
        $this->stage = ModuleStage::Loading;

        foreach ($this->registry->definitions() as $definition) {
            if ($this->registry->context($definition->id) !== null) {
                continue;
            }

            $context = new ModuleContext($definition);
            $context->enterStage(ModuleStage::Loading);

            /** @var mixed $entry */
            $entry = require $definition->entryFile;

            if (!$entry instanceof \Closure) {
                throw ModuleException::entryMustReturnClosure(
                    $definition->id,
                    $definition->entryFile,
                    \get_debug_type($entry),
                );
            }

            $entry($context);

            $this->registry->setContext($context);
        }
    }

    // ---- register ---------------------------------------------------------

    /**
     * Replay every module's declarations, by category rather than per module.
     *
     * See the class docblock: this ordering is the point.
     */
    public function register(): void
    {
        $this->stage = ModuleStage::Registering;

        $this->shareCollaborators();

        $contexts = $this->registry->contexts();

        foreach ($contexts as $context) {
            $context->enterStage(ModuleStage::Registering);
        }

        foreach ($contexts as $context) {
            $values = $context->declaredConfig();

            if ($values !== []) {
                // defaults(), not merge(): a module declares defaults for its
                // own settings and the application's config/plugins/Example.php
                // -- read long before this runs -- outranks them.
                $this->config->defaults($context->id(), $values);
            }
        }

        $this->publishAssets();
        $this->publishTemplates();

        $registrar = new ServiceRegistrar($this->container);

        foreach ($contexts as $context) {
            foreach ($context->declaredServices() as $declare) {
                $declare($registrar);
            }
        }

        foreach ($contexts as $context) {
            $collector = new RouteCollector($this->router, $context->id());

            foreach ($context->declaredRoutes() as $declare) {
                $declare($collector);
            }
        }

        foreach ($contexts as $context) {
            $collector = new CommandCollector($this->commands, $context->id());

            foreach ($context->declaredCommands() as $declare) {
                $declare($collector);
            }
        }

        // After commands, never before: a scheduled command is checked against
        // the registry as it is declared, so every module's commands have to
        // exist by the time any module's schedules are read.
        foreach ($contexts as $context) {
            $collector = new ScheduleCollector($this->schedules, $this->commands, $context->id());

            foreach ($context->declaredSchedules() as $declare) {
                $declare($collector);
            }
        }

        // Now, and not inside the loop: ->identify() may still have been about
        // to change an id while the closure above was running.
        $this->schedules->assertUnique();

        foreach ($contexts as $context) {
            foreach ($context->declaredHooks() as $hook) {
                $this->hooks->add(
                    $hook['name'],
                    $hook['callback'],
                    $hook['priority'],
                    $context->id(),
                    $hook['acceptedArgs'],
                );
            }
        }

        foreach ($contexts as $context) {
            foreach ($context->declaredFilters() as $filter) {
                $this->filters->add(
                    $filter['name'],
                    $filter['callback'],
                    $filter['priority'],
                    $context->id(),
                    $filter['acceptedArgs'],
                );
            }
        }

        // Only now can anything be listening, which is why this is not emitted
        // inside the loops above.
        foreach ($contexts as $context) {
            $this->hooks->do('module.registered', $context->definition);
        }
    }

    /**
     * Publish every module that has an assets/ directory.
     *
     * A module does not declare this and cannot opt out of it, which is a
     * deliberate asymmetry with everything else in module.php. Publishing is
     * not a decision a module gets to make differently from its neighbours: the
     * URL space is /assets/plugin/<name>/ for every plugin, so a declaration
     * could only ever say "yes" or be wrong. Having the directory is the "yes".
     *
     * The shared module is deliberately excluded. Its id is just "shared" with
     * no name of its own, so there is no URL that could address it, and giving
     * it one would add a fifth namespace the specification does not have.
     * Assets belonging to the application as a whole are the application's own,
     * under assets/.
     */
    private function publishAssets(): void
    {
        foreach ($this->registry->definitions() as $definition) {
            $kind = match ($definition->kind) {
                ModuleKind::Plugin => AssetKind::Plugin,
                ModuleKind::Gateway => AssetKind::Gateway,
                ModuleKind::Shared => null,
            };

            if ($kind === null) {
                continue;
            }

            $root = $definition->file('assets');

            if (!\is_dir($root)) {
                continue;
            }

            $this->assets->register(new AssetSource($kind, $definition->directory, $root));
        }
    }

    /**
     * Register every module that has a Templates/ directory.
     *
     * Same bargain as assets, and for the same reason: having the directory is
     * the declaration. What differs is that the shared module IS included here.
     * A shared template is an ordinary thing to want -- a pagination control, a
     * money cell, an address block -- and unlike an asset URL there is a name
     * for it, "@shared/...", so nothing has to be invented to address it.
     *
     * The namespace mirrors the asset URL rather than the module id: a plugin
     * called Example is "plugin.Example", not "plugins/Example". The dot is not
     * decoration -- a Twig namespace cannot contain a slash, and the two
     * engines have to agree on how a template is named.
     */
    private function publishTemplates(): void
    {
        foreach ($this->registry->definitions() as $definition) {
            $root = $definition->file('Templates');

            if (!\is_dir($root)) {
                continue;
            }

            $namespace = $definition->kind === ModuleKind::Shared
                ? ModuleKind::Shared->value
                : \rtrim($definition->kind->value, 's') . '.' . $definition->directory;

            $this->templates->add($namespace, $root, TemplateSource::MODULE);
        }
    }

    /**
     * Make sure the container hands out the same engines this manager uses.
     *
     * An onBoot callback declares its dependencies and the container injects
     * them. If the container did not already know these instances it would
     * autowire brand-new ones, and a module's listeners would quietly land on
     * engines nothing ever fires. That failure is silent and extremely
     * confusing, so the guarantee is made structural here rather than left to
     * whoever assembled the container.
     */
    private function shareCollaborators(): void
    {
        foreach ([
            Config::class => $this->config,
            Router::class => $this->router,
            HookEngine::class => $this->hooks,
            FilterEngine::class => $this->filters,
        ] as $id => $instance) {
            if (!$this->container->resolved($id)) {
                $this->container->instance($id, $instance);
            }
        }
    }

    // ---- boot -------------------------------------------------------------

    public function boot(): void
    {
        $this->stage = ModuleStage::Booting;

        foreach ($this->registry->contexts() as $context) {
            $context->enterStage(ModuleStage::Booting);

            foreach ($context->declaredBootCallbacks() as $callback) {
                $this->container->call($callback);
            }

            $this->hooks->do('module.booted', $context->definition);
        }

        foreach ($this->registry->contexts() as $context) {
            $context->enterStage(ModuleStage::Ready);
        }
    }

    // ---- discovery cache --------------------------------------------------

    private function cacheEnabled(): bool
    {
        return (bool) $this->config->get('modules.cache', false);
    }

    private function readDiscoveryCache(): bool
    {
        return $this->cacheEnabled() && $this->registry->readCache(ModuleRegistry::cacheFile($this->basePath));
    }

    private function writeDiscoveryCache(): void
    {
        if ($this->cacheEnabled()) {
            $this->registry->writeCache(ModuleRegistry::cacheFile($this->basePath));
        }
    }
}
