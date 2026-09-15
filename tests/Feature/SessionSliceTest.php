<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Cli\CommandDispatcher;
use App\Engine\Cli\CommandRegistry;
use App\Engine\Cli\ConsoleKernel;
use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Core\ExecutionContext;
use App\Engine\Error\ErrorHandler;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\Request;
use App\Engine\Security\Csrf;
use App\Engine\Security\Signer;
use App\Engine\Session\Session;
use App\Engine\Session\SessionManager;
use App\Engine\Session\SessionStore;
use App\Tests\Support\TestCase;

/**
 * The session through a real application.
 *
 * The claims being checked are the ones that are only true end to end: that a
 * request which never touches the session pays nothing for it, that one which
 * does gets a cookie without anybody wiring one up, and that a handler asking
 * for a Session by type gets the one belonging to the request it is serving.
 *
 * Sessions are in memory throughout -- the shared TestCase defaults every test
 * to that, so a run writes no credentials to the machine it runs on.
 */
final class SessionSliceTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function app(array $config = []): Application
    {
        $config['security']['key'] ??= Signer::generate();

        return $this->application($config)->boot();
    }

    // ---- wiring ------------------------------------------------------------

    public function test_the_session_pieces_are_injectable(): void
    {
        $container = $this->app()->container();

        self::assertInstanceOf(SessionManager::class, $container->get(SessionManager::class));
        self::assertInstanceOf(SessionStore::class, $container->get(SessionStore::class));
    }

    /**
     * Resolved fresh every time, never shared.
     *
     * A shared binding would hand a long-running worker the first request's
     * session for the rest of its life, and the symptom is users seeing each
     * other's data -- which is the worst bug in this file's subject area.
     */
    public function test_asking_the_container_for_a_session_gives_this_requests_one(): void
    {
        $app = $this->app();
        $app->handle(Request::create('GET', '/visits'));

        $manager = $app->container()->get(SessionManager::class);

        self::assertSame($manager->session(), $app->container()->get(Session::class));
    }

    // ---- laziness ----------------------------------------------------------

    /** A route that never mentions a session does not acquire one. */
    public function test_an_untouched_request_gets_no_session_cookie(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/customers.json'));

        foreach ($response->cookies() as $cookie) {
            self::assertNotSame(SessionManager::DEFAULT_COOKIE, $cookie->name);
        }
    }

    public function test_a_route_that_uses_the_session_is_given_a_cookie(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/visits'));

        self::assertSame(200, $response->status());
        self::assertNotNull($this->sessionCookie($response->cookies()));
    }

    // ---- it actually persists ----------------------------------------------

    public function test_a_value_survives_into_the_next_request(): void
    {
        $app = $this->app();

        $first = $app->handle(Request::create('GET', '/visits'));
        $id = $this->sessionCookie($first->cookies());

        self::assertNotNull($id);
        self::assertSame(1, $this->data($first)['visits']);

        $second = $app->handle(Request::create('GET', '/visits', [
            'cookies' => [SessionManager::DEFAULT_COOKIE => $id],
        ]));

        self::assertSame(2, $this->data($second)['visits']);
        self::assertSame('resumed', $this->data($second)['session']);
        self::assertNull($this->sessionCookie($second->cookies()), 'the browser already has it');
    }

    public function test_two_browsers_do_not_share_a_session(): void
    {
        $app = $this->app();

        $one = $app->handle(Request::create('GET', '/visits'));
        $app->handle(Request::create('GET', '/visits', [
            'cookies' => [SessionManager::DEFAULT_COOKIE => (string) $this->sessionCookie($one->cookies())],
        ]));

        $two = $app->handle(Request::create('GET', '/visits'));

        self::assertSame(1, $this->data($two)['visits']);
    }

    // ---- flash -------------------------------------------------------------

    /**
     * The reset route invalidates the session and flashes a message, which the
     * NEXT request reads and the one after that does not.
     */
    public function test_a_flashed_message_is_read_once(): void
    {
        $app = $this->app();
        $csrf = $app->container()->get(Csrf::class);
        $token = $csrf->token(Request::create('GET', '/'));

        $reset = $app->handle(Request::create('POST', '/visits/reset', [
            'cookies' => [Csrf::COOKIE => $token],
            'body' => [Csrf::FIELD => $token],
        ]));

        self::assertSame(200, $reset->status());

        $id = $this->sessionCookie($reset->cookies());
        self::assertNotNull($id);

        $next = $app->handle(Request::create('GET', '/visits', [
            'cookies' => [SessionManager::DEFAULT_COOKIE => $id],
        ]));

        self::assertSame('Counting again from zero.', $this->data($next)['status']);

        $after = $app->handle(Request::create('GET', '/visits', [
            'cookies' => [SessionManager::DEFAULT_COOKIE => $id],
        ]));

        self::assertNull($this->data($after)['status'], 'flash data is read once, not for ever');
    }

    // ---- regeneration ------------------------------------------------------

    /**
     * Logging out has to change the id, or somebody still holding the old one
     * is still holding a valid one.
     */
    public function test_invalidating_changes_the_id_and_drops_the_data(): void
    {
        $app = $this->app();
        $csrf = $app->container()->get(Csrf::class);
        $token = $csrf->token(Request::create('GET', '/'));

        $first = $app->handle(Request::create('GET', '/visits'));
        $id = (string) $this->sessionCookie($first->cookies());

        $reset = $app->handle(Request::create('POST', '/visits/reset', [
            'cookies' => [SessionManager::DEFAULT_COOKIE => $id, Csrf::COOKIE => $token],
            'body' => [Csrf::FIELD => $token],
        ]));

        $replacement = $this->sessionCookie($reset->cookies());

        self::assertNotNull($replacement);
        self::assertNotSame($id, $replacement);

        $after = $app->handle(Request::create('GET', '/visits', [
            'cookies' => [SessionManager::DEFAULT_COOKIE => $replacement],
        ]));

        self::assertSame(1, $this->data($after)['visits'], 'the count went with the old session');
    }

    /**
     * The security layer's half of the same event.
     *
     * A CSRF token issued to the anonymous page must not stay valid against the
     * session that replaced it. Guard hears about the regeneration on a hook and
     * issues a new one -- without the security layer knowing that sessions
     * exist.
     */
    public function test_regenerating_the_session_also_rotates_the_csrf_token(): void
    {
        $app = $this->app();
        $csrf = $app->container()->get(Csrf::class);
        $token = $csrf->token(Request::create('GET', '/'));

        $response = $app->handle(Request::create('POST', '/visits/reset', [
            'cookies' => [Csrf::COOKIE => $token],
            'body' => [Csrf::FIELD => $token],
        ]));

        $issued = null;

        foreach ($response->cookies() as $cookie) {
            if ($cookie->name === Csrf::COOKIE) {
                $issued = $cookie->value;
            }
        }

        self::assertNotNull($issued, 'a new CSRF cookie must be issued');
        self::assertNotSame($token, $issued);
    }

    /** An ordinary request does not rotate it, or every form in every tab would break. */
    public function test_an_ordinary_request_leaves_the_csrf_token_alone(): void
    {
        $app = $this->app();
        $token = $app->container()->get(Csrf::class)->token(Request::create('GET', '/'));

        $response = $app->handle(Request::create('GET', '/visits', [
            'cookies' => [Csrf::COOKIE => $token],
        ]));

        foreach ($response->cookies() as $cookie) {
            self::assertNotSame(Csrf::COOKIE, $cookie->name);
        }
    }

    // ---- the console -------------------------------------------------------

    public function test_session_gc_reports_what_it_swept(): void
    {
        [$status, $output] = $this->console($this->app(), 'session:gc');

        self::assertSame(ConsoleKernel::SUCCESS, $status);
        self::assertStringContainsString('expired session', $output);
    }

    public function test_session_table_prints_a_statement_it_does_not_run(): void
    {
        [$status, $output] = $this->console($this->app(), 'session:table', '--driver=mysql');

        self::assertSame(ConsoleKernel::SUCCESS, $status);
        self::assertStringContainsString('CREATE TABLE', $output);
        self::assertStringContainsString('touched_at', $output);
    }

    /** In-memory sessions are a failure, and the audit says so. */
    public function test_security_check_reports_in_memory_sessions(): void
    {
        [$status, $output] = $this->console($this->app(), 'security:check');

        self::assertStringContainsString('Sessions are held in memory', $output);
        self::assertSame(ConsoleKernel::FAILURE, $status);
    }

    /** @return array<string, mixed> */
    private function data(\App\Engine\Http\Response $response): array
    {
        /** @var mixed $decoded */
        $decoded = \json_decode($response->body(), true, 16, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @param list<\App\Engine\Http\Cookie> $cookies */
    private function sessionCookie(array $cookies): ?string
    {
        foreach ($cookies as $cookie) {
            if ($cookie->name === SessionManager::DEFAULT_COOKIE) {
                return $cookie->value;
            }
        }

        return null;
    }

    /** @return array{int, string} */
    private function console(Application $app, string ...$arguments): array
    {
        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        $status = (new ConsoleKernel(
            $app->container()->get(CommandRegistry::class),
            $app->container()->get(CommandDispatcher::class),
            $app->container()->get(HookEngine::class),
            $app->container()->get(ErrorHandler::class),
            new Output($stream),
        ))->handle(ExecutionContext::cli(\array_values(['bin/console', ...$arguments])));

        \rewind($stream);
        $output = (string) \stream_get_contents($stream);
        \fclose($stream);

        return [$status, $output];
    }
}
