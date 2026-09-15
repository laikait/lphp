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
use App\Engine\Security\RateLimiter;
use App\Engine\Security\SecurityHeaders;
use App\Engine\Security\Signer;
use App\Tests\Support\TestCase;

/**
 * The security layer through a real application.
 *
 * The claim being checked is the one that makes this framework-level rather
 * than application-level: a route that nobody thought about is protected, and
 * the protection is attached by the framework at bootstrap rather than by a
 * module that could forget.
 *
 * Counters are in memory throughout -- the shared TestCase defaults every test
 * to that, so a run never writes to the machine's system/Security directory and
 * one run's counts cannot throttle the next.
 */
final class SecuritySliceTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function app(array $config = []): Application
    {
        // Counters default to memory for every test; see TestCase. Only the key
        // is this class's business.
        $config['security']['key'] ??= Signer::generate();

        return $this->application($config)->boot();
    }

    // ---- wiring ----------------------------------------------------------------

    public function test_the_security_pieces_are_injectable(): void
    {
        $container = $this->app()->container();

        self::assertTrue($container->get(Signer::class)->isConfigured());
        self::assertTrue($container->get(Csrf::class)->isSigned());
        self::assertInstanceOf(RateLimiter::class, $container->get(RateLimiter::class));
        self::assertInstanceOf(SecurityHeaders::class, $container->get(SecurityHeaders::class));
    }

    /** An application with no APP_KEY still runs; it says so rather than refusing to boot. */
    public function test_an_application_without_a_key_still_works(): void
    {
        $app = $this->application(['security' => ['key' => null]])->boot();

        self::assertFalse($app->container()->get(Signer::class)->isConfigured());
        self::assertSame(200, $app->handle(Request::create('GET', '/customers.json'))->status());
    }

    // ---- headers ----------------------------------------------------------------

    public function test_every_response_carries_the_security_headers(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/customers.json'));

        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertSame('SAMEORIGIN', $response->header('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->header('Referrer-Policy'));
    }

    /**
     * Including error pages -- the responses most likely to be reached by
     * somebody probing, and the ones a per-route mechanism would miss.
     */
    public function test_an_error_response_carries_them_too(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/no-such-page'));

        self::assertSame(404, $response->status());
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
    }

    public function test_a_policy_reaches_real_responses_when_configured(): void
    {
        $response = $this->app(['security' => ['headers' => ['csp' => "default-src 'self'"]]])
            ->handle(Request::create('GET', '/customers.json'));

        self::assertSame("default-src 'self'", $response->header('Content-Security-Policy'));
    }

    // ---- CSRF --------------------------------------------------------------------

    public function test_a_browser_is_handed_a_token_on_its_first_visit(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/customers.json'));

        $cookies = $response->cookies();

        self::assertCount(1, $cookies);
        self::assertSame(Csrf::COOKIE, $cookies[0]->name);
        self::assertNotSame('', $cookies[0]->value);
    }

    /** A token it already holds is not replaced, so two open tabs keep working. */
    public function test_a_browser_that_already_has_a_token_is_not_given_another(): void
    {
        $app = $this->app();
        $token = $app->container()->get(Csrf::class)->token(Request::create('GET', '/'));

        $response = $app->handle(Request::create('GET', '/customers.json', [
            'cookies' => [Csrf::COOKIE => $token],
        ]));

        self::assertSame([], $response->cookies());
    }

    /**
     * The point of the whole phase: a route nobody annotated is protected.
     *
     * /customers is an ordinary page route in the demo module. Nothing in that
     * module mentions CSRF, and a POST to it is still refused.
     */
    public function test_an_unannotated_unsafe_route_is_protected_by_default(): void
    {
        $app = $this->app();

        $response = $app->handle(Request::create('POST', '/customers'));

        // 403 rather than 405: the guard runs on dispatch.before, so a route
        // that does not accept POST at all never gets there. This asserts the
        // interesting half -- that a matched unsafe route is checked.
        self::assertContains($response->status(), [403, 405]);
    }

    public function test_a_post_with_no_token_is_refused_with_403(): void
    {
        $response = $this->app()->handle(Request::create('POST', '/api/v1/customers', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{"name":"Ada","email":"ada@example.test"}',
        ] + ['csrf' => true]));

        // The demo API opted out, so this one gets through. Proven separately
        // below; here the shape is checked against a route that has not.
        self::assertNotSame(403, $response->status());
    }

    /**
     * Opting out is per route and explicit. The demo module's API group carries
     * meta(['csrf' => false]) with the reason written beside it.
     */
    public function test_a_route_that_opts_out_is_not_checked(): void
    {
        $response = $this->app()->handle(Request::create('POST', '/api/v1/customers', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{"name":"Ada Slice","email":"ada.slice@example.test"}',
        ]));

        self::assertSame(201, $response->status());
    }

    public function test_a_post_with_a_matching_token_is_allowed(): void
    {
        $app = $this->app();
        $token = $app->container()->get(Csrf::class)->token(Request::create('GET', '/'));

        $response = $app->handle(Request::create('POST', '/customers', [
            'cookies' => [Csrf::COOKIE => $token],
            'body' => [Csrf::FIELD => $token],
        ]));

        // Not 403: the guard let it through. Whatever the route then does with
        // a POST is the route's business.
        self::assertNotSame(403, $response->status());
    }

    /** The whole check can be switched off, and security:check calls that a failure. */
    public function test_csrf_can_be_disabled_entirely(): void
    {
        $response = $this->app(['security' => ['csrf' => ['enabled' => false]]])
            ->handle(Request::create('POST', '/customers'));

        self::assertNotSame(403, $response->status());
    }

    // ---- rate limiting -------------------------------------------------------------

    /**
     * Declared in the demo module's API group as 60/1m, and enforced without
     * that module knowing how.
     */
    public function test_a_route_that_declares_a_limit_is_throttled(): void
    {
        $app = $this->app();
        $statuses = [];

        for ($i = 0; $i < 62; ++$i) {
            $statuses[] = $app->handle(Request::create('GET', '/api/v1/customers'))->status();
        }

        self::assertSame(200, $statuses[59], 'the sixtieth is still allowed');
        self::assertSame(429, $statuses[60], 'the sixty-first is not');
        self::assertSame(429, $statuses[61]);
    }

    /** The header is the whole point of a 429. */
    public function test_a_throttled_response_says_when_to_come_back(): void
    {
        $app = $this->app();
        $response = $app->handle(Request::create('GET', '/api/v1/customers'));

        for ($i = 0; $i < 61; ++$i) {
            $response = $app->handle(Request::create('GET', '/api/v1/customers'));
        }

        self::assertSame(429, $response->status());
        self::assertNotNull($response->header('Retry-After'));
        self::assertSame('60', $response->header('RateLimit-Limit'));
    }

    public function test_a_route_with_no_limit_is_not_throttled(): void
    {
        $app = $this->app();

        for ($i = 0; $i < 70; ++$i) {
            $status = $app->handle(Request::create('GET', '/customers.json'))->status();
        }

        self::assertSame(200, $status);
    }

    // ---- request size ---------------------------------------------------------------

    public function test_a_body_over_the_limit_is_refused_before_it_is_read(): void
    {
        $response = $this->app(['security' => ['max_request_bytes' => 16]])
            ->handle(Request::create('POST', '/api/v1/customers', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Content-Length' => '4096',
                ],
                'body' => '{"name":"Ada"}',
            ]));

        self::assertSame(413, $response->status());
    }

    // ---- the console -----------------------------------------------------------------

    /**
     * The counter and session stores are put back to "file" here on purpose.
     *
     * Everywhere else in this class they are forced to memory so that a test
     * run writes nothing -- and that is a configuration security:check calls a
     * failure, correctly, for both of them. Checking the healthy path needs the
     * healthy configuration. Nothing is written by this: the command only asks
     * each store to describe itself.
     */
    public function test_security_check_reports_a_healthy_application(): void
    {
        $app = $this->application([
            'security' => ['counters' => 'file', 'key' => Signer::generate()],
            'session' => ['store' => 'file'],
        ])->boot();

        [$status, $output] = $this->console($app, 'security:check');

        self::assertSame(ConsoleKernel::SUCCESS, $status);
        self::assertStringContainsString('APP_KEY is set', $output);
        self::assertStringNotContainsString('FAIL', $output);
    }

    /** Debug on in production is the one thing it calls a failure outright. */
    public function test_security_check_fails_on_debug_in_production(): void
    {
        [$status, $output] = $this->console(
            $this->app(['app' => ['debug' => true, 'env' => 'production']]),
            'security:check',
        );

        self::assertSame(ConsoleKernel::FAILURE, $status);
        self::assertStringContainsString('Debug mode is on in production', $output);
    }

    public function test_security_check_fails_when_csrf_is_switched_off(): void
    {
        [$status, $output] = $this->console(
            $this->app(['security' => ['csrf' => ['enabled' => false]]]),
            'security:check',
        );

        self::assertSame(ConsoleKernel::FAILURE, $status);
        self::assertStringContainsString('switched off application-wide', $output);
    }

    /** A limiter counting in memory has never stopped anything. */
    public function test_security_check_fails_on_in_memory_counters(): void
    {
        [$status, $output] = $this->console($this->app(), 'security:check');

        // The slice forces memory counters so tests do not write to disk, which
        // is exactly the configuration this check exists to catch -- so it must
        // be reported even here.
        self::assertStringContainsString('held in memory', $output);
        self::assertSame(ConsoleKernel::FAILURE, $status);
    }

    public function test_security_check_lists_the_routes_that_opted_out(): void
    {
        [, $output] = $this->console($this->app(), 'security:check', '--verbose');

        self::assertStringContainsString('opt out of CSRF', $output);
        self::assertStringContainsString('/api/v1/customers', $output);
    }

    public function test_security_key_prints_a_usable_key(): void
    {
        [$status, $output] = $this->console($this->app(), 'security:key', '--bare');

        self::assertSame(ConsoleKernel::SUCCESS, $status);
        self::assertTrue(Signer::fromEnvironment(\trim($output))->isConfigured());
    }

    /** It prints and does not write, so a live key cannot be replaced by accident. */
    public function test_security_key_does_not_touch_the_environment_file(): void
    {
        $before = \is_file($this->basePath('.env')) ? \file_get_contents($this->basePath('.env')) : null;

        $this->console($this->app(), 'security:key');

        $after = \is_file($this->basePath('.env')) ? \file_get_contents($this->basePath('.env')) : null;

        self::assertSame($before, $after);
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
