<?php

declare(strict_types=1);

use App\Engine\Container\Container;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Data\ArraySource;
use App\Engine\Data\DataSource;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\SqlSource;
use App\Engine\Module\ModuleContext;
use App\Modules\Shared\Data\UserRepository;
use App\Modules\Shared\Filters\ApiFilters;
use App\Modules\Shared\Filters\ResponseFilters;

/**
 * The shared module.
 *
 * Shared is for things genuinely used across modules. It is not a dumping
 * ground: a model or service belongs here only when more than one module really
 * needs it, and it registers first so that everything else can rely on it.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Shared')
        ->version('0.1.0')
        ->description('Cross-module services, values and response conventions.');

    $module->config([
        'currency' => 'USD',
        'locale' => 'en_GB',
    ]);

    $module->services(static function (ServiceRegistrar $services): void {
        // Where every repository in the application reads and writes.
        //
        // This is the only place in the application that knows which kind of
        // storage it has. Configure DB_DSN and every repository, query, read
        // model, relation and page above this line runs against a database
        // instead, unchanged -- which is the claim the DataSource interface
        // makes, and the reason it is worth having.
        //
        // The factory is handed the container rather than reaching for one, and
        // it runs on first use, so an application that never reads never opens
        // a connection.
        $services->singleton(DataSource::class, static function (Container $container): DataSource {
            $connections = $container->get(ConnectionManager::class);

            return $connections->isConfigured()
                ? new SqlSource($connections->connection())
                : new ArraySource();
        });

        // A shared repository, available to every module that asks for it.
        // Shared registers first, so a plugin can depend on this at boot.
        $services->singleton(UserRepository::class);
    });

    $module->onBoot(static function (DataSource $source): void {
        // Demo data, and only for the in-memory source. A real database gets
        // its rows from each module's own migrations -- which this framework
        // does not have yet, so pointing DB_DSN at a database means creating
        // the tables yourself for now.
        if ($source instanceof ArraySource) {
            $source->seed('users', [
                ['id' => 10, 'username' => 'ada', 'email' => 'ada@example.test'],
                ['id' => 11, 'username' => 'grace', 'email' => 'grace@example.test'],
            ]);
        }
    });

    // Priority 100 puts this last, so the header reflects the finished response.
    $module->filter('response.instance', [ResponseFilters::class, 'stampEngine'], priority: 100);

    // API conventions, applied to every route that declares itself an API
    // route. This is the framework's answer to middleware: one filter, reading
    // route metadata, composing with everything else without any route having
    // to know a pipeline exists.
    $module->filter('dispatch.response', [ApiFilters::class, 'stampVersion'], priority: 20);
    $module->filter('dispatch.response', [ApiFilters::class, 'announceDeprecation'], priority: 30);
};
