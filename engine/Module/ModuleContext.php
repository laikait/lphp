<?php

declare(strict_types=1);

namespace App\Engine\Module;

use App\Engine\Cli\CommandCollector;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Routing\RouteCollector;
use App\Engine\Scheduler\ScheduleCollector;

/**
 * The entire API a module author touches.
 *
 * A module.php returns a closure taking one of these:
 *
 *     return static function (ModuleContext $module): void {
 *         $module->name('Customers')->version('1.0.0');
 *
 *         $module->services(static function (ServiceRegistrar $services): void {
 *             $services->singleton(CustomerCatalog::class);
 *         });
 *
 *         $module->routes(static function (RouteCollector $routes): void {
 *             $routes->get('/customers', ListCustomers::class)->name('customers.index');
 *         });
 *
 *         $module->hook('customer.created', [CustomerHooks::class, 'onCreated'], priority: 20);
 *     };
 *
 * Three properties of that shape are deliberate.
 *
 * One argument is the whole API. There is nothing to extend and nothing to
 * implement: a module is a file that describes itself, and this class's method
 * list is both the documentation and what the IDE completes.
 *
 * Declarations are RECORDED, not executed. The closure runs during Load and
 * only writes down intentions; nothing is bound, routed or hooked while it is
 * running. That is what makes ordering deterministic instead of dependent on
 * the order the filesystem happened to hand back directories.
 *
 * Stage legality is enforced by construction. Every method asserts the current
 * stage and throws naming the module and the method, so a mistake surfaces at
 * the line that made it.
 *
 * This is not a service provider: there is no class to subclass, the Register
 * stage sees only a write-only ServiceRegistrar, and boot callbacks receive
 * their dependencies as injected parameters rather than a container handle.
 */
final class ModuleContext
{
    private ModuleStage $stage = ModuleStage::Discovered;

    private string $name;

    private string $version = '0.0.0';

    private string $description = '';

    /** @var list<\Closure(ServiceRegistrar): void> */
    private array $serviceRegistrars = [];

    /** @var list<\Closure(RouteCollector): void> */
    private array $routeRegistrars = [];

    /** @var list<\Closure(CommandCollector): void> */
    private array $commandRegistrars = [];

    /** @var list<\Closure(ScheduleCollector): void> */
    private array $scheduleRegistrars = [];

    /** @var array<string, mixed> */
    private array $config = [];

    /** @var list<array{name: string, callback: mixed, priority: int, acceptedArgs: int|null}> */
    private array $hooks = [];

    /** @var list<array{name: string, callback: mixed, priority: int, acceptedArgs: int|null}> */
    private array $filters = [];

    /** @var list<\Closure> */
    private array $bootCallbacks = [];

    public function __construct(public readonly ModuleDefinition $definition)
    {
        $this->name = $definition->id;
    }

    // ---- metadata ---------------------------------------------------------

    public function name(string $name): self
    {
        return $this->declaring('name', function () use ($name): void {
            $this->name = $name;
        });
    }

    public function version(string $version): self
    {
        return $this->declaring('version', function () use ($version): void {
            $this->version = $version;
        });
    }

    public function description(string $description): self
    {
        return $this->declaring('description', function () use ($description): void {
            $this->description = $description;
        });
    }

    // ---- declarations -----------------------------------------------------

    /**
     * Declare services.
     *
     * The closure receives a ServiceRegistrar, which can write to the container
     * and cannot read from it. Service location during registration is
     * therefore impossible rather than merely discouraged.
     *
     * @param \Closure(ServiceRegistrar): void $registrar
     */
    public function services(\Closure $registrar): self
    {
        return $this->declaring('services', function () use ($registrar): void {
            $this->serviceRegistrars[] = $registrar;
        });
    }

    /**
     * Declare routes.
     *
     * There is no global route file; a module owns the routes for the
     * capability it provides.
     *
     * @param \Closure(RouteCollector): void $registrar
     */
    public function routes(\Closure $registrar): self
    {
        return $this->declaring('routes', function () use ($registrar): void {
            $this->routeRegistrars[] = $registrar;
        });
    }

    /**
     * Declare console commands.
     *
     * The same shape as routes, deliberately: a module owns the commands for
     * the capability it provides, and a command handler is resolved through the
     * container exactly as a route handler is. There is no Commands/ directory
     * scanned by reflection -- a file's existence should not change what an
     * application does, and what a module contributes stays readable here.
     *
     * @param \Closure(CommandCollector): void $registrar
     */
    public function commands(\Closure $registrar): self
    {
        return $this->declaring('commands', function () use ($registrar): void {
            $this->commandRegistrars[] = $registrar;
        });
    }

    /**
     * Declare scheduled work.
     *
     * The third registrar with this shape, after routes and commands, and the
     * argument is the same one: a module owns a capability, and when that
     * capability runs unattended is part of it. The alternative is a machine's
     * crontab, which is not in version control, is not installed with the
     * module and is invisible to everyone who did not ssh in.
     *
     * @param \Closure(ScheduleCollector): void $registrar
     */
    public function schedules(\Closure $registrar): self
    {
        return $this->declaring('schedules', function () use ($registrar): void {
            $this->scheduleRegistrars[] = $registrar;
        });
    }

    /**
     * Contribute configuration, merged under this module's id.
     *
     * @param array<string, mixed> $values
     */
    public function config(array $values): self
    {
        return $this->declaring('config', function () use ($values): void {
            $this->config = [...$this->config, ...$values];
        });
    }

    /**
     * Listen for an event.
     *
     * @param \Closure|callable-string|array{0: object|class-string, 1: string} $callback
     */
    public function hook(string $hook, mixed $callback, int $priority = 10, ?int $acceptedArgs = null): self
    {
        return $this->declaring('hook', function () use ($hook, $callback, $priority, $acceptedArgs): void {
            $this->hooks[] = [
                'name' => $hook,
                'callback' => $callback,
                'priority' => $priority,
                'acceptedArgs' => $acceptedArgs,
            ];
        });
    }

    /**
     * Transform a value.
     *
     * @param \Closure|callable-string|array{0: object|class-string, 1: string} $callback
     */
    public function filter(string $filter, mixed $callback, int $priority = 10, ?int $acceptedArgs = null): self
    {
        return $this->declaring('filter', function () use ($filter, $callback, $priority, $acceptedArgs): void {
            $this->filters[] = [
                'name' => $filter,
                'callback' => $callback,
                'priority' => $priority,
                'acceptedArgs' => $acceptedArgs,
            ];
        });
    }

    /**
     * Run once every module has registered.
     *
     * The callback is invoked through the container, so its parameters are
     * injected. There is no $app or $container handle to reach for, which is
     * what keeps service location from creeping back in at boot time.
     */
    public function onBoot(\Closure $callback): self
    {
        return $this->declaring('onBoot', function () use ($callback): void {
            $this->bootCallbacks[] = $callback;
        });
    }

    // ---- introspection ----------------------------------------------------

    public function id(): string
    {
        return $this->definition->id;
    }

    public function kind(): ModuleKind
    {
        return $this->definition->kind;
    }

    public function path(string $relative = ''): string
    {
        return $this->definition->file($relative);
    }

    public function moduleName(): string
    {
        return $this->name;
    }

    public function moduleVersion(): string
    {
        return $this->version;
    }

    public function moduleDescription(): string
    {
        return $this->description;
    }

    public function stage(): ModuleStage
    {
        return $this->stage;
    }

    // ---- used by the manager ----------------------------------------------

    public function enterStage(ModuleStage $stage): void
    {
        $this->stage = $stage;
    }

    /** @return list<\Closure(ServiceRegistrar): void> */
    public function declaredServices(): array
    {
        return $this->serviceRegistrars;
    }

    /** @return list<\Closure(RouteCollector): void> */
    public function declaredRoutes(): array
    {
        return $this->routeRegistrars;
    }

    /** @return list<\Closure(CommandCollector): void> */
    public function declaredCommands(): array
    {
        return $this->commandRegistrars;
    }

    /** @return list<\Closure(ScheduleCollector): void> */
    public function declaredSchedules(): array
    {
        return $this->scheduleRegistrars;
    }

    /** @return array<string, mixed> */
    public function declaredConfig(): array
    {
        return $this->config;
    }

    /** @return list<array{name: string, callback: mixed, priority: int, acceptedArgs: int|null}> */
    public function declaredHooks(): array
    {
        return $this->hooks;
    }

    /** @return list<array{name: string, callback: mixed, priority: int, acceptedArgs: int|null}> */
    public function declaredFilters(): array
    {
        return $this->filters;
    }

    /** @return list<\Closure> */
    public function declaredBootCallbacks(): array
    {
        return $this->bootCallbacks;
    }

    /**
     * Every declaration goes through here, so the stage rule is stated once.
     *
     * @param \Closure(): void $record
     */
    private function declaring(string $method, \Closure $record): self
    {
        if ($this->stage !== ModuleStage::Loading) {
            throw ModuleException::wrongStage(
                $this->definition->id,
                $method,
                $this->stage,
                'Everything a module declares must be declared while module.php is running. '
                . 'By the time registration starts, the set of declarations is fixed.',
            );
        }

        $record();

        return $this;
    }
}
