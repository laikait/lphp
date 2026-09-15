<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Auth\AuthGuard;
use App\Engine\Auth\AuthManager;
use App\Engine\Auth\Providers\EmptyProvider;
use App\Engine\Cli\Output;
use App\Engine\Config\Config;
use App\Engine\Core\Application;
use App\Engine\Routing\Router;
use App\Engine\Security\Counters\MemoryStore;
use App\Engine\Security\Csrf;
use App\Engine\Security\Guard;
use App\Engine\Security\RateLimiter;
use App\Engine\Security\RequestLimits;
use App\Engine\Security\SecurityHeaders;
use App\Engine\Security\Signer;
use App\Engine\Session\SessionManager;
use App\Engine\Session\Stores\ArrayStore as SessionArrayStore;
use App\Engine\Support\Path;

/**
 * Audit what this deployment actually has switched on.
 *
 * The question it answers is the one that is expensive to answer any other way:
 * a security setting is invisible when it is working and invisible when it is
 * not, so "is HSTS on", "is there a key", "is anything web-readable that should
 * not be" get checked once during setup and never again. This puts them on one
 * screen, reports what the running process really resolved to rather than what
 * a config file says, and exits non-zero when something is wrong -- so it can
 * be a deployment step rather than a thing somebody remembers to run.
 *
 * Three severities, and the distinction is the useful part:
 *
 *   fail   wrong now, in this environment. Exits 1.
 *   warn   a real weakness, deliberately allowed. Exits 0.
 *   ok     checked and fine.
 *
 * Debug mode in production is a fail. A missing APP_KEY is a warn, because an
 * application without one still works and still refuses cross-site requests --
 * it just does so with a weaker guarantee, which is a decision somebody is
 * allowed to have made.
 */
final class SecurityCheckCommand
{
    public const FAIL = 'fail';

    public const WARN = 'warn';

    public const OK = 'ok';

    /** Files that must not be readable over HTTP, whatever else is true. */
    public const MUST_BE_DENIED = ['engine', 'modules', 'templates', 'config', 'system', 'tests', 'bin', 'vendor'];

    public function __construct(
        private readonly Application $application,
        private readonly Config $config,
        private readonly Signer $signer,
        private readonly Csrf $csrf,
        private readonly RateLimiter $limiter,
        private readonly RequestLimits $limits,
        private readonly SecurityHeaders $headers,
        private readonly Router $router,
        private readonly SessionManager $sessions,
        private readonly AuthManager $auth,
    ) {}

    public function __invoke(Output $output, bool $verbose = false): int
    {
        $production = $this->config->string('app.env', 'production') === 'production';

        $findings = [
            ...$this->checkDebug($production),
            ...$this->checkKey(),
            ...$this->checkCsrf(),
            ...$this->checkLimits(),
            ...$this->checkSessions($production),
            ...$this->checkAccess(),
            ...$this->checkHeaders($production),
            ...$this->checkWebServer(),
            ...$this->checkExtensions(),
        ];

        $output->heading('Security');
        $output->pairs([
            'Environment' => $this->config->string('app.env', 'production') ?? 'production',
            'Signing' => $this->signer->isConfigured() ? 'APP_KEY is set' : 'no APP_KEY (tokens unsigned)',
            'CSRF' => $this->config->bool('security.csrf.enabled', true) ? 'on' : 'off',
            'Counters' => $this->limiter->store()->describe(),
            'Body limit' => $this->limits->describe(),
            'Sessions' => $this->sessions->describe(),
            'Users' => $this->auth->provider()->describe(),
        ]);

        $output->line();

        $failed = 0;
        $warned = 0;

        foreach ($findings as [$severity, $title, $detail]) {
            if ($severity === self::OK && !$verbose) {
                continue;
            }

            match ($severity) {
                self::FAIL => $output->error('  FAIL  ' . $title),
                self::WARN => $output->warning('  WARN  ' . $title),
                default => $output->line('  ok    ' . $title),
            };

            if ($detail !== '' && $severity !== self::OK) {
                $output->line('        ' . $detail);
            }

            $failed += $severity === self::FAIL ? 1 : 0;
            $warned += $severity === self::WARN ? 1 : 0;
        }

        $output->line();

        if ($failed > 0) {
            $output->error(\sprintf('%d problem(s), %d warning(s).', $failed, $warned));

            return 1;
        }

        if ($warned > 0) {
            $output->warning(\sprintf('%d warning(s). Nothing is wrong; something could be stronger.', $warned));

            return 0;
        }

        $output->success('Nothing to report.');

        return 0;
    }

    /** @return list<array{string, string, string}> */
    private function checkDebug(bool $production): array
    {
        $debug = $this->config->bool('app.debug', false);

        if ($debug && $production) {
            return [[
                self::FAIL,
                'Debug mode is on in production.',
                'Stack traces, file paths and configuration reach the browser. Set APP_DEBUG=false.',
            ]];
        }

        return [[self::OK, $debug ? 'Debug mode is on (not production).' : 'Debug mode is off.', '']];
    }

    /** @return list<array{string, string, string}> */
    private function checkKey(): array
    {
        if ($this->signer->isConfigured()) {
            return [[self::OK, 'APP_KEY is set, so tokens are signed.', '']];
        }

        return [[
            self::WARN,
            'No APP_KEY, so CSRF tokens are not signed.',
            'They still work. What is lost is protection against a sibling subdomain planting a '
            . 'matching cookie and field. Generate one: php bin/console security:key',
        ]];
    }

    /** @return list<array{string, string, string}> */
    private function checkCsrf(): array
    {
        if (!$this->config->bool('security.csrf.enabled', true)) {
            return [[
                self::FAIL,
                'CSRF checking is switched off application-wide.',
                'Every cookie-authenticated form in this application can be submitted by any site. '
                . 'Turn it off per route with meta([\'csrf\' => false]) instead.',
            ]];
        }

        // A route that opted out is a decision, not a fault. Listing them is
        // the point: this is the audit somebody would otherwise do by reading
        // every module.php.
        $exempt = [];

        foreach ($this->router->routes() as $route) {
            if ($route->metaValue(Guard::CSRF_META) === false && $this->csrf->protects($route->method())) {
                $exempt[] = $route->method() . ' ' . $route->path();
            }
        }

        $findings = [[self::OK, 'CSRF is checked on every unsafe method by default.', '']];

        if ($exempt !== []) {
            $findings[] = [
                self::OK,
                \sprintf('%d route(s) opt out of CSRF: %s', \count($exempt), \implode(', ', $exempt)),
                '',
            ];
        }

        return $findings;
    }

    /** @return list<array{string, string, string}> */
    private function checkLimits(): array
    {
        $findings = [];

        if ($this->limiter->store() instanceof MemoryStore) {
            $findings[] = [
                self::FAIL,
                'Rate-limit counts are held in memory.',
                'A web request is a process that ends, so every count is forgotten before the next '
                . 'request arrives. This is not a lenient limit; it is no limit. Set '
                . 'security.counters to "file".',
            ];
        } else {
            $findings[] = [self::OK, 'Rate-limit counts are shared: ' . $this->limiter->store()->describe(), ''];
        }

        $limited = 0;

        foreach ($this->router->routes() as $route) {
            $limited += \is_string($route->metaValue(Guard::RATE_LIMIT_META)) ? 1 : 0;
        }

        $findings[] = $limited === 0
            ? [
                self::WARN,
                'No route declares a rate limit.',
                'Nothing is throttled. Add meta([\'rate_limit\' => \'60/1m\']) to the routes that '
                . 'cost something to call -- login, search, anything that writes.',
            ]
            : [self::OK, \sprintf('%d route(s) declare a rate limit.', $limited), ''];

        $findings[] = [self::OK, 'Request bodies are capped at ' . $this->limits->describe() . '.', ''];

        return $findings;
    }

    /**
     * The session cookie is the credential. Everything here is about the cookie
     * rather than about the data behind it.
     *
     * @return list<array{string, string, string}>
     */
    private function checkSessions(bool $production): array
    {
        $findings = [];

        if ($this->sessions->store() instanceof SessionArrayStore) {
            $findings[] = [
                self::FAIL,
                'Sessions are held in memory.',
                'A web request is a process that ends, so nobody would stay logged in past one '
                . 'response. Set session.store to "file", or to "database" if more than one machine '
                . 'serves this application.',
            ];
        } else {
            $findings[] = [self::OK, 'Sessions are stored: ' . $this->sessions->describe(), ''];
        }

        $cookie = $this->sessions->cookie(\str_repeat('0', 64));

        if ($production && !$cookie->secure) {
            $findings[] = [
                self::WARN,
                'The session cookie is not marked Secure.',
                'It follows the request scheme, so it is Secure over HTTPS and not over plain HTTP. '
                . 'On a production site that is reachable both ways, one plain-HTTP request sends the '
                . 'credential in clear. Set session.cookie.secure to true.',
            ];
        } else {
            $findings[] = [self::OK, 'The session cookie is Secure where it can be.', ''];
        }

        $findings[] = $cookie->sameSite === 'None'
            ? [
                self::WARN,
                'The session cookie is SameSite=None.',
                'It will be sent on requests made by any other site, which is what CSRF exists to '
                . 'survive. Only correct for a deliberate cross-site integration.',
            ]
            : [self::OK, 'The session cookie is SameSite=' . $cookie->sameSite . '.', ''];

        if ($this->sessions->absolute() === 0) {
            $findings[] = [
                self::WARN,
                'Sessions have no absolute lifetime.',
                \sprintf(
                    'They expire after %d seconds of inactivity, but a session kept warm by a '
                    . 'background request never expires at all. Set session.absolute to a number of '
                    . 'seconds a login should never outlive.',
                    $this->sessions->idle(),
                ),
            ];
        } else {
            $findings[] = [self::OK, \sprintf('Sessions expire after %d seconds.', $this->sessions->absolute()), ''];
        }

        return $findings;
    }

    /**
     * Who may reach what.
     *
     * The warning here is the compensating control for requiring a login being
     * opt-in. Defaulting every route to private would be safer in the abstract
     * and would be switched off on the first day, because it makes the home
     * page private too -- so instead the framework makes the gap visible.
     *
     * A route that CHANGES something and requires nobody is the shape worth
     * listing: it may be deliberate (a login form, a webhook, a public
     * subscribe box) and it may be the endpoint somebody added in a hurry.
     *
     * @return list<array{string, string, string}>
     */
    private function checkAccess(): array
    {
        $findings = [];
        $unprotected = [];
        $protected = 0;

        foreach ($this->router->routes() as $route) {
            if (AuthGuard::isProtected($route)) {
                ++$protected;

                continue;
            }

            if (!\in_array($route->method(), Csrf::SAFE_METHODS, true)) {
                $unprotected[] = $route->method() . ' ' . $route->path();
            }
        }

        $findings[] = $protected === 0
            ? [
                self::WARN,
                'No route requires a login.',
                'Every endpoint is reachable by anybody. If that is the intention, nothing is '
                . 'wrong; if it is not, add meta([\'auth\' => true]) or meta([\'can\' => ...]).',
            ]
            : [self::OK, \sprintf('%d route(s) require a login or a capability.', $protected), ''];

        if ($unprotected !== []) {
            $findings[] = [
                self::WARN,
                \sprintf('%d route(s) change something and require nobody.', \count($unprotected)),
                \implode(', ', $unprotected) . '. Some of these are meant to be open -- a login '
                . 'form has to be. Check that all of them are.',
            ];
        } else {
            $findings[] = [self::OK, 'Every route that changes something requires somebody.', ''];
        }

        if ($this->auth->provider() instanceof EmptyProvider) {
            $findings[] = [
                self::WARN,
                'No UserProvider is registered.',
                'Nobody can log in, so every protected route answers 401. Bind one in a module: '
                . '$services->singleton(UserProvider::class, YourProvider::class).',
            ];
        } else {
            $findings[] = [self::OK, 'Users come from: ' . $this->auth->provider()->describe(), ''];
        }

        return $findings;
    }

    /** @return list<array{string, string, string}> */
    private function checkHeaders(bool $production): array
    {
        $headers = $this->headers->describe();
        $findings = [[
            self::OK,
            \sprintf('%d security header(s) on every response: %s', \count($headers), \implode(', ', \array_keys($headers))),
            '',
        ]];

        if (!\array_key_exists('Content-Security-Policy', $headers)) {
            $findings[] = [
                self::WARN,
                'There is no Content-Security-Policy.',
                'It is the single most effective defence against XSS and the framework will not '
                . 'guess one: a useful policy names this application\'s own script and style '
                . 'sources. Set security.headers.csp.',
            ];
        }

        if ($production && !\array_key_exists('Strict-Transport-Security', $headers)) {
            $findings[] = [
                self::WARN,
                'HSTS is not enabled.',
                'Without it a first visit over plain HTTP can be intercepted. Set '
                . 'security.headers.hsts_days once HTTPS is certain -- a browser that has seen '
                . 'this header refuses HTTP for the whole period and cannot be told otherwise.',
            ];
        }

        return $findings;
    }

    /**
     * The rule that matters most and is easiest to get wrong.
     *
     * The specified layout puts index.php, vendor/, engine/ and modules/ in one
     * web-served directory, so .htaccess is load-bearing -- and it does nothing
     * at all if AllowOverride is None. This can check that the file says the
     * right thing; only a request can prove the web server is reading it, which
     * is why the last line says so.
     *
     * @return list<array{string, string, string}>
     */
    private function checkWebServer(): array
    {
        $findings = [];

        foreach (['.htaccess', 'server'] as $file) {
            $path = Path::join($this->application->basePath(), $file);
            $contents = \is_file($path) ? @\file_get_contents($path) : false;

            if (!\is_string($contents)) {
                $findings[] = [self::FAIL, $file . ' is missing.', 'Application directories may be served directly.'];

                continue;
            }

            $missing = [];

            foreach (self::MUST_BE_DENIED as $directory) {
                if (!\str_contains($contents, $directory)) {
                    $missing[] = $directory;
                }
            }

            $findings[] = $missing === []
                ? [self::OK, $file . ' denies every application directory.', '']
                : [
                    self::FAIL,
                    \sprintf('%s does not deny: %s', $file, \implode(', ', $missing)),
                    'Those directories are inside the web root and would be served as files.',
                ];
        }

        $findings[] = [
            self::WARN,
            'This check reads .htaccess; it cannot prove Apache does.',
            'AllowOverride None makes the file inert with no error anywhere. Confirm with: '
            . 'curl -i http://your-host/engine/Core/Application.php -- it must be 403.',
        ];

        return $findings;
    }

    /** @return list<array{string, string, string}> */
    private function checkExtensions(): array
    {
        if (\function_exists('finfo_open')) {
            return [[self::OK, 'fileinfo is available, so uploads are checked against their contents.', '']];
        }

        return [[
            self::WARN,
            'The fileinfo extension is missing.',
            'UploadPolicy can still enforce the extension allowlist, but cannot tell a .jpg that is '
            . 'really a PHP script from one that is really a JPEG.',
        ]];
    }
}
