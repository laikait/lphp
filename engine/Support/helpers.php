<?php

declare(strict_types=1);

/*
 * Global helper functions.
 *
 * This file is the only place in the framework that declares functions in the
 * global namespace, and the only file under engine/ permitted to reach for
 * global state. Everything else takes its collaborators by constructor
 * injection; see App\Engine\Support\Extensions for why these are not a facade, and
 * tests/Architecture for the rules that keep them honest.
 *
 * The set is closed, and a member earns its place by being a subsystem the
 * specification says authors reach globally -- or, for dump() and dd(), by
 * being a debugging aid that has to work anywhere code can be written,
 * including where nothing can be injected.
 *
 * Every function is guarded so that an application which defines its own is not
 * fatally broken by loading the framework.
 */

use App\Engine\Support\Extensions;

if (!function_exists('add_hook')) {
    /**
     * Listen for an event. Lower priority runs first; equal priorities run in
     * registration order.
     *
     * @param \Closure|callable-string|array{0: object|class-string, 1: string} $callback
     *
     * @return string a handle that removes this exact registration
     */
    function add_hook(string $hook, mixed $callback, int $priority = 10, ?int $acceptedArgs = null): string
    {
        return Extensions::hooks()->add($hook, $callback, $priority, null, $acceptedArgs);
    }
}

if (!function_exists('do_hook')) {
    /** Announce that something happened. Listener return values are ignored. */
    function do_hook(string $hook, mixed ...$arguments): void
    {
        Extensions::hooks()->do($hook, ...$arguments);
    }
}

if (!function_exists('remove_hook')) {
    /**
     * Remove by handle, or by the callable itself.
     *
     * @param \Closure|string|array{0: object|class-string, 1: string} $callback a handle from add_hook(), or the callable
     */
    function remove_hook(string $hook, mixed $callback, ?int $priority = null): bool
    {
        return Extensions::hooks()->remove($hook, $callback, $priority);
    }
}

if (!function_exists('has_hook')) {
    /**
     * With no callback, whether anything listens at all.
     *
     * @param \Closure|string|array{0: object|class-string, 1: string}|null $callback a handle, or the callable
     */
    function has_hook(string $hook, mixed $callback = null): bool
    {
        return Extensions::hooks()->has($hook, $callback);
    }
}

if (!function_exists('add_filter')) {
    /**
     * Transform a value. The callback must return one.
     *
     * @param \Closure|callable-string|array{0: object|class-string, 1: string} $callback
     *
     * @return string a handle that removes this exact registration
     */
    function add_filter(string $filter, mixed $callback, int $priority = 10, ?int $acceptedArgs = null): string
    {
        return Extensions::filters()->add($filter, $callback, $priority, null, $acceptedArgs);
    }
}

if (!function_exists('apply_filter')) {
    /**
     * Run $value through every listener and return the result. Extra arguments
     * are context passed to each listener; they are never transformed.
     */
    function apply_filter(string $filter, mixed $value, mixed ...$arguments): mixed
    {
        return Extensions::filters()->apply($filter, $value, ...$arguments);
    }
}

if (!function_exists('remove_filter')) {
    /**
     * @param \Closure|string|array{0: object|class-string, 1: string} $callback a handle from add_filter(), or the callable
     */
    function remove_filter(string $filter, mixed $callback, ?int $priority = null): bool
    {
        return Extensions::filters()->remove($filter, $callback, $priority);
    }
}

if (!function_exists('has_filter')) {
    /**
     * @param \Closure|string|array{0: object|class-string, 1: string}|null $callback a handle, or the callable
     */
    function has_filter(string $filter, mixed $callback = null): bool
    {
        return Extensions::filters()->has($filter, $callback);
    }
}

if (!function_exists('asset')) {
    /**
     * The asset manager, for building public URLs to published files.
     *
     *     asset()->core('js/app.js')
     *     asset()->module('Billing', 'js/billing.js')
     *
     * This one returns an object rather than doing the work, because the asset
     * API is five verbs rather than one and five more global functions would be
     * worse than one. It is still not a facade: the return type is a single
     * concrete class, so every call site is as analysable as a constructor
     * injection would have made it.
     */
    function asset(): \App\Engine\Asset\AssetManager
    {
        return Extensions::assets();
    }
}

if (!function_exists('template')) {
    /**
     * The template manager, for rendering a view to a string.
     *
     *     template()->render('customer/profile', ['customer' => $customer]);
     *
     * Like asset(), this returns the manager rather than doing the work. It is
     * still not a facade: one concrete return type, no dispatch by name.
     *
     * Inside a PHP template there is no need for it -- $view is already in
     * scope, and $view->render() is the same thing with the data explicit.
     */
    function template(): \App\Engine\Template\TemplateManager
    {
        return Extensions::templates();
    }
}

if (!function_exists('dump')) {
    /**
     * Print values for debugging, and carry on.
     *
     *     dump($customer, $request->query());
     *
     * Each value is printed with its type and with where dump() was called:
     * HTML in a browser, plain text on the command line. Private properties are
     * shown; a Secret stays "[redacted]".
     *
     * Only with APP_DEBUG=true. With debug off it prints nothing and logs a
     * warning naming the file and line, so one left in code is found in the
     * log rather than by a visitor.
     */
    function dump(mixed ...$values): void
    {
        $where = \App\Engine\Support\Dumper::caller(debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 2), 'dump');

        if (!Extensions::debug()) {
            Extensions::log()?->warning('dump() left in code', ['at' => $where]);

            return;
        }

        \App\Engine\Support\Dumper::emit(array_values($values), $where);
    }
}

if (!function_exists('dd')) {
    /**
     * Print values for debugging, and stop: "dump and die".
     *
     *     dd($customer);
     *
     * In a browser the dump is the whole page, with status 500 so no cache
     * keeps it; on the command line it exits with status 1. Never in a test --
     * it would stop PHPUnit too; use dump() there.
     *
     * Only with APP_DEBUG=true. With debug off it prints nothing and throws a
     * DebugException instead: the visitor sees the ordinary error page, the log
     * says where the dd() is, and the code after it still does not run.
     */
    function dd(mixed ...$values): never
    {
        $where = \App\Engine\Support\Dumper::caller(debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 2), 'dd');

        if (!Extensions::debug()) {
            throw \App\Engine\Support\DebugException::leftInCode($where);
        }

        \App\Engine\Support\Dumper::emit(array_values($values), $where);

        if (\PHP_SAPI !== 'cli' && !headers_sent()) {
            http_response_code(500);
        }

        exit(1);
    }
}
