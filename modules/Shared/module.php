<?php

declare(strict_types=1);

use App\Engine\Container\Container;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Data\ArraySource;
use App\Engine\Data\DataSource;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\SqlSource;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Engine\Template\TemplateManager;
use App\Modules\Shared\Health\HealthCheck;

/**
 * The shared module.
 *
 * Shared is for things genuinely used across modules. It is not a dumping
 * ground: a model or service belongs here only when more than one module really
 * needs it, and it registers first so that everything else can rely on it.
 *
 * A fresh installation ships it with three things: the front page, a health
 * check, and the choice of where repositories store their data.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Shared')
        ->version('0.1.0')
        ->description('The front page, /health, and where repositories store their data.');

    $module->services(static function (ServiceRegistrar $services): void {
        // Where every repository in the application reads and writes.
        //
        // This is the only place in the application that knows which kind of
        // storage it has. Configure DB_DSN and every repository, query, read
        // model, relation and page runs against a database instead of memory,
        // unchanged -- which is the claim the DataSource interface makes, and
        // the reason it is worth having.
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
    });

    $module->routes(static function (RouteCollector $routes): void {
        // The front page, so a fresh installation answers "/" with a page
        // rather than a 404. It is a route like any other, owned by a module
        // like any other, because there is no global routes file to put it in.
        //
        // It is meant to be replaced, and replacing it needs nothing from this
        // file: every other module registers after shared, and the router keeps
        // the last route declared for a method and path, so a plugin that
        // declares "/" answers it instead. (It must not also call its route
        // "home" -- names are unique, and that one is taken here.)
        //
        // "home" is handed to the template as the current request addresses the
        // front page, so the link is right under Apache in a subdirectory too.
        $routes->get('/', static fn (Request $request, TemplateManager $templates): Response => (new Response(
            $templates->render('home', ['home' => $request->basePath() . '/']),
        ))->withContentType('text/html'))->name('home');

        // For a load balancer or an uptime monitor: 200 when this instance can
        // serve, 503 when it cannot. Replaceable the same way as "/".
        $routes->get('/health', HealthCheck::class)->name('health')->meta(['api' => true]);
    });
};
