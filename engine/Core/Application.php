<?php

declare(strict_types=1);

namespace App\Engine\Core;

use App\Engine\Cli\ConsoleKernel;
use App\Engine\Config\Config;
use App\Engine\Container\Container;
use App\Engine\Error\ErrorHandler;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Module\ModuleManager;
use App\Engine\Routing\Router;
use App\Engine\Support\Path;

/**
 * The application.
 *
 * boot() brings the modules up. run() hands off to the kernel for whichever
 * context we are in and returns a process exit code.
 *
 * This is the only class in the framework that touches the outside world:
 * superglobals go in here and output comes out here, so that everything else
 * stays a function from values to values.
 */
final class Application
{
    public const VERSION = '0.1.0';

    private bool $booted = false;

    public function __construct(
        private readonly Container $container,
        private readonly ExecutionContext $context,
        private readonly string $basePath,
    ) {}

    public function container(): Container
    {
        return $this->container;
    }

    public function context(): ExecutionContext
    {
        return $this->context;
    }

    public function config(): Config
    {
        return $this->container->get(Config::class);
    }

    public function basePath(string $relative = ''): string
    {
        return $relative === '' ? $this->basePath : Path::join($this->basePath, $relative);
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    /** Discover, load, register and boot every module. Idempotent. */
    public function boot(): self
    {
        if ($this->booted) {
            return $this;
        }

        $this->booted = true;

        $this->container->get(ModuleManager::class)->run();

        $hooks = $this->container->get(HookEngine::class);
        $hooks->do('app.booted', $this);
        $hooks->do('app.ready', $this);

        return $this;
    }

    /** Boot, then run the kernel for this context. Returns the exit code. */
    public function run(): int
    {
        $this->boot();

        return $this->context->isCli() ? $this->runConsole() : $this->runHttp();
    }

    private function runHttp(): int
    {
        // resolved(), not has(): the question is whether something already
        // supplied a Request, not whether the container could build one. It
        // cannot -- Request has a private constructor and its named
        // constructors are the only supported way in.
        $request = $this->container->resolved(Request::class)
            ? $this->container->get(Request::class)
            : Request::fromGlobals(
                $this->configuredBasePath(),
                $this->trustedProxies(),
            );

        $this->container->instance(Request::class, $request);
        $this->container->get(Router::class)->setBasePath($request->basePath());

        // So that a fatal between here and the last byte still knows whether a
        // browser or a program is waiting. PHP's shutdown handler takes no
        // arguments, so the request has to be left somewhere it can find it.
        $this->container->get(ErrorHandler::class)->serving($request);

        $response = $this->container->get(HttpKernel::class)->handle($request);

        // HEAD must carry the same headers as the GET it mirrors, and no body.
        $response->send(omitBody: $request->isMethod('HEAD'));

        $this->container->get(HookEngine::class)->do('response.sent', $response, $request);

        $this->terminate(0);

        return 0;
    }

    private function runConsole(): int
    {
        $status = $this->container->get(ConsoleKernel::class)->handle($this->context);

        $this->terminate($status);

        return $status;
    }

    /**
     * Finish up after the response has been written.
     *
     * fastcgi_finish_request() is called first where it exists, so that
     * anything listening on app.terminating runs after the client already has
     * its response rather than delaying it. For a backend application doing
     * post-response bookkeeping, that is the difference between a fast endpoint
     * and a slow one.
     */
    public function terminate(int $status): void
    {
        if (\function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        $this->container->get(HookEngine::class)->do('app.terminating', $status);
    }

    /** @return Response the response the kernel produced, without sending it */
    public function handle(Request $request): Response
    {
        $this->boot();

        $this->container->instance(Request::class, $request);
        $this->container->get(Router::class)->setBasePath($request->basePath());
        $this->container->get(ErrorHandler::class)->serving($request);

        return $this->container->get(HttpKernel::class)->handle($request);
    }

    private function configuredBasePath(): ?string
    {
        /** @var mixed $configured */
        $configured = $this->config()->get('http.base_path');

        return \is_string($configured) ? $configured : null;
    }

    /** @return list<string> */
    private function trustedProxies(): array
    {
        /** @var mixed $proxies */
        $proxies = $this->config()->get('http.trusted_proxies', []);

        if (!\is_array($proxies)) {
            return [];
        }

        return \array_values(\array_filter($proxies, \is_string(...)));
    }
}
