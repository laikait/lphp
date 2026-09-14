<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\CommandRegistry;
use App\Engine\Cli\Output;
use App\Engine\Module\ModuleContext;
use App\Engine\Module\ModuleManager;
use App\Engine\Routing\Router;

/**
 * Every discovered module, in the order it loads.
 *
 * The order is the interesting column. Registration order decides which
 * module's hook runs first at equal priority, and "why did my listener run
 * after theirs" is answered by reading this listing top to bottom.
 */
final class ModuleListCommand
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly Router $router,
        private readonly CommandRegistry $commands,
    ) {}

    public function __invoke(Output $output): int
    {
        $contexts = $this->modules->registry()->contexts();

        if ($contexts === []) {
            $output->line('No modules were discovered.');

            return 0;
        }

        $output->table(
            ['ID', 'KIND', 'NAME', 'VERSION', 'ROUTES', 'COMMANDS', 'HOOKS', 'FILTERS'],
            \array_map(
                fn(ModuleContext $context): array => [
                    $context->id(),
                    $context->kind()->value,
                    $context->moduleName(),
                    $context->moduleVersion(),
                    (string) $this->routesOwnedBy($context->id()),
                    (string) $this->commandsOwnedBy($context->id()),
                    (string) \count($context->declaredHooks()),
                    (string) \count($context->declaredFilters()),
                ],
                $contexts,
            ),
        );

        return 0;
    }

    private function routesOwnedBy(string $module): int
    {
        $count = 0;

        foreach ($this->router->routes() as $route) {
            if ($route->module() === $module) {
                ++$count;
            }
        }

        return $count;
    }

    private function commandsOwnedBy(string $module): int
    {
        $count = 0;

        foreach ($this->commands->all() as $command) {
            if ($command->module === $module) {
                ++$count;
            }
        }

        return $count;
    }
}
