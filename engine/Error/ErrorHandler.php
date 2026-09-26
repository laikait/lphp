<?php

declare(strict_types=1);

namespace App\Engine\Error;

use App\Engine\Config\Config;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\HttpException;
use App\Engine\Http\Request;
use App\Engine\Http\Response;

/**
 * One place where anything thrown becomes something the caller can be shown.
 *
 * Centralised means centralised: a handler's validation failure, a route that
 * did not match, a PHP warning, an uncaught exception and a fatal that killed
 * the process all arrive here and leave as the same document. What differs is
 * the rendering, and the rendering is chosen by who is reading -- see
 * ErrorContext.
 *
 *   Browser   an HTML page, the application's own if it has one
 *   Api       the JSON error document
 *   Console   two lines and, in debug, a trace
 *
 * Production versus development is a separate axis and decides how much is
 * said, never who says it.
 *
 * Nothing here logs, and there is no logger interface to implement. Every
 * handled error fires the error.reported hook with the throwable and its
 * context, which is the seam the logging phase attaches to: a logger is a
 * listener, not a dependency of this class. That is what keeps error rendering
 * and error recording independent -- an application can record errors without
 * changing how they are shown, and change how they are shown without touching
 * what is recorded.
 */
final class ErrorHandler
{
    /**
     * Memory held back so that a fatal out-of-memory can still be rendered.
     *
     * When PHP hits the memory limit every later allocation fails, including
     * the ones the shutdown handler needs to build a page. Releasing this at the
     * top of that handler buys back enough room to finish, and without it an
     * exhausted process answers with a blank page instead of a 500.
     *
     * The size is measured rather than guessed. Rendering a fatal costs more
     * than it looks: building the ErrorException captures a backtrace, the
     * document and the page are strings, and in debug the trace is exploded
     * into an array. At 32KB the shutdown handler ran out of memory a second
     * time and produced nothing at all; 64KB was enough for production and
     * 256KB leaves room for a debug trace on a deep stack. A quarter of a
     * megabyte of a process's lifetime is a cheap insurance premium against the
     * failure mode that is hardest to diagnose -- no output whatsoever.
     */
    public const RESERVED_MEMORY = 262144;

    private bool $registered = false;

    private ?string $reserve = null;

    private ?Request $request = null;

    public function __construct(
        private readonly Config $config,
        private readonly HookEngine $hooks = new HookEngine(),
        private readonly ErrorPage $page = new ErrorPage(),
    ) {}

    /**
     * Take over PHP's own error reporting.
     *
     * Four things are installed. PHP's own output is silenced, because a
     * warning printed by the engine carries a filesystem path and arrives
     * before any framework code can stop it. Errors become ErrorException so
     * they can be caught like anything else. Uncaught exceptions get rendered
     * rather than printing a trace. And a shutdown handler catches the fatals
     * that none of the first three can see.
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;
        $this->reserve = \str_repeat('x', self::RESERVED_MEMORY);

        $this->silencePhpsOwnOutput();

        \set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            // Respect the configured error_reporting level: a suppressed notice
            // should stay suppressed rather than become a fatal exception.
            if ((\error_reporting() & $severity) === 0) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        \set_exception_handler(function (\Throwable $e): void {
            $this->renderUncaught($e);
        });

        \register_shutdown_function(function (): void {
            $this->releaseReserve();

            $error = \error_get_last();

            if ($error === null) {
                return;
            }

            $fatal = \E_ERROR | \E_PARSE | \E_CORE_ERROR | \E_COMPILE_ERROR;

            if (($error['type'] & $fatal) === 0) {
                return;
            }

            // A fatal bypasses the error and exception handlers entirely, so
            // without this the client gets a blank 200.
            $this->renderUncaught(new \ErrorException(
                $error['message'],
                0,
                $error['type'],
                $error['file'],
                $error['line'],
            ));
        });
    }

    /**
     * Whether the rendering headroom is still held.
     *
     * Public so that the mechanism is observable rather than a property nothing
     * ever looks at. "We reserved memory" and "the reserve is given back before
     * the page is built" are two different claims, and only the second one is
     * worth anything.
     */
    public function holdsReserve(): bool
    {
        return $this->reserve !== null;
    }

    /** Give the headroom back, so that a fatal still has room to render. */
    public function releaseReserve(): void
    {
        $this->reserve = null;
    }

    /**
     * Remember the request being served.
     *
     * The one piece of mutable state in this class, and it is here because
     * PHP's exception and shutdown handlers take no arguments: when a fatal
     * happens between the kernel returning and the response being sent, this is
     * the only way to know whether the thing waiting for an answer is a browser
     * or a program. Without it, an API client receives an HTML page.
     */
    public function serving(Request $request): void
    {
        $this->request = $request;
    }

    public function toResponse(\Throwable $e, ?Request $request = null): Response
    {
        $request ??= $this->request;
        $context = $request === null ? ErrorContext::Browser : ErrorContext::of($request);

        $this->report($e, $context);

        $document = ErrorDocument::fromThrowable($e, $this->debug(), $context);
        $headers = $e instanceof HttpException ? $e->headers() : [];

        // One document, two renderings. Before this, a validation failure from
        // a handler and an uncaught exception from the kernel were built by two
        // different pieces of code that agreed on a shape by coincidence.
        if ($context->isMachine()) {
            return $document->toResponse($headers);
        }

        return (new Response($this->page->render($document, $request, $e), $document->status, $headers))
            ->withContentType('text/html');
    }

    /**
     * The same error, for a terminal.
     *
     * Built from an ErrorDocument rather than from the exception directly, so
     * that the rule about which messages are safe to show is stated once and
     * applies everywhere. Without this the console was the one context that
     * printed an arbitrary exception message in production -- and "dsn=..." is
     * exactly the kind of message a data-layer exception carries.
     *
     * The class name survives outside debug mode. A path, a trace or a message
     * can carry a secret; a class name is a fact about the code, and the person
     * reading a console error is running the code already.
     */
    public function renderCli(\Throwable $e): string
    {
        $this->report($e, ErrorContext::Console);

        $document = ErrorDocument::fromThrowable($e, $this->debug(), ErrorContext::Console);

        $lines = [
            '',
            \sprintf('  %s', $e::class),
            \sprintf('  %s', $document->message),
        ];

        if ($this->debug()) {
            $lines[] = '';
            $lines[] = \sprintf('  at %s:%d', $e->getFile(), $e->getLine());
            $lines[] = '';
            $lines[] = $e->getTraceAsString();

            return \implode(\PHP_EOL, $lines) . \PHP_EOL;
        }

        // Withholding the message is right, and leaving the operator with
        // nowhere to go is not. A declaration mistake -- two modules claiming
        // one command name, a route that cannot compile -- fails here long
        // before the console kernel gets a chance to explain it, and the person
        // reading this is the person who can turn debug on.
        $lines[] = '';
        $lines[] = '  Set APP_DEBUG=1 for the full message and a stack trace.';

        return \implode(\PHP_EOL, $lines) . \PHP_EOL;
    }

    /**
     * Announce that an error was handled.
     *
     * Fired from inside the renderers rather than left to their callers, so
     * that nothing which produces an error body can forget to report it. The
     * argument is the throwable itself, not a formatted string: a listener that
     * wants the class, the previous exception or the trace should not have to
     * parse them back out of a sentence.
     */
    public function report(\Throwable $e, ErrorContext $context): void
    {
        $this->hooks->do('error.reported', $e, $context, $this->request);
    }

    private function debug(): bool
    {
        return (bool) $this->config->get('app.debug', false);
    }

    /**
     * Stop PHP printing anything on its own account.
     *
     * display_errors is the one that matters: left on in production it writes a
     * warning, complete with the absolute path of the file that raised it,
     * directly into the response body -- above the doctype, before any
     * framework code runs, and impossible to intercept afterwards. The
     * specification's "never expose filesystem paths in production" is not
     * achievable without this line.
     *
     * error_reporting stays at E_ALL either way, because the handler above
     * turns errors into exceptions and needs to see them. Reporting everything
     * while displaying nothing is the combination that is usually assumed and
     * rarely actually set.
     */
    private function silencePhpsOwnOutput(): void
    {
        \error_reporting(\E_ALL);

        @\ini_set('display_errors', $this->debug() ? '1' : '0');
        @\ini_set('display_startup_errors', $this->debug() ? '1' : '0');
    }

    private function renderUncaught(\Throwable $e): void
    {
        if (\PHP_SAPI === 'cli') {
            \fwrite(\STDERR, $this->renderCli($e));

            return;
        }

        // Once a byte has gone out there is no status line left to set and no
        // headers to send, so a second half-rendered page on top of a partial
        // one would only make the wreckage harder to read. The error is still
        // reported, which is what a listener needs.
        if (\headers_sent()) {
            $this->report($e, ErrorContext::Browser);

            return;
        }

        $this->toResponse($e, $this->request)->send();
    }
}
