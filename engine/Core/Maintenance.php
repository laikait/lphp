<?php

declare(strict_types=1);

namespace App\Engine\Core;

use App\Engine\Http\Cookie;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Network\IpSet;

/**
 * Maintenance mode: answer 503 to the web while something is being changed.
 *
 *     php laika down --retry=120 --allow=10.0.0.0/8 --secret=preview --message="Back at 14:00"
 *     php laika up
 *
 * The state is one file, system/Runtime/maintenance.json, so while the
 * application is up a request pays one is_file() for this, and every process
 * on the machine sees the same answer at once. Several web servers each need
 * their own `down` -- the file is per machine, like the schedule locks.
 *
 * **Who still gets in:**
 *
 * - addresses listed with --allow, single or CIDR, through Request::ipAddress()
 *   -- so the trusted-proxy rules apply and a client cannot claim an address;
 * - a browser that visited /?lphp_bypass=<secret> once. It is redirected
 *   without the secret in the URL, with a cookie good for this maintenance
 *   window only: the cookie is a hash of when it started and of the secret's
 *   hash, so it neither reveals the secret nor survives `up` and a later
 *   `down`.
 *
 * **What is not affected:** the console, queue workers and the scheduler.
 * Stop the workers yourself if a migration needs the queue quiet. Assets are
 * still served, so the 503 page keeps its stylesheet.
 */
final class Maintenance
{
    public const FILE = 'system/Runtime/maintenance.json';

    public const BYPASS_PARAMETER = 'lphp_bypass';

    public const BYPASS_COOKIE = 'lphp_maintenance';

    /** @var array{since: int, retry: int, message: string, allow: list<string>, secret: ?string}|false|null not read yet, or false when up */
    private array|false|null $state = null;

    public function __construct(private readonly string $basePath) {}

    public function isDown(): bool
    {
        return $this->state() !== null;
    }

    /**
     * @return ?array{since: int, retry: int, message: string, allow: list<string>, secret: ?string}
     */
    public function state(): ?array
    {
        if ($this->state === null) {
            $this->state = $this->read() ?? false;
        }

        return $this->state === false ? null : $this->state;
    }

    /**
     * Take the web side down.
     *
     * @param list<string> $allow addresses and CIDR blocks that still get in
     *
     * @throws \App\Engine\Network\IpException for an entry that is not an address or a block
     */
    public function down(int $retry = 60, string $message = '', array $allow = [], ?string $secret = null): void
    {
        new IpSet($allow);

        $state = [
            'since' => \time(),
            'retry' => \max(0, $retry),
            'message' => $message,
            'allow' => \array_values($allow),
            'secret' => $secret === null || $secret === '' ? null : \hash('sha256', $secret),
        ];

        $path = $this->path();
        $directory = \dirname($path);

        if (!\is_dir($directory) && !@\mkdir($directory, 0o755, true) && !\is_dir($directory)) {
            throw new \RuntimeException(\sprintf('%s could not be created.', $directory));
        }

        // Written whole, then renamed into place: a request never reads half a file.
        $temporary = $path . '.' . \bin2hex(\random_bytes(4));

        if (@\file_put_contents($temporary, \json_encode($state, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR) . "\n") === false || !@\rename($temporary, $path)) {
            @\unlink($temporary);

            throw new \RuntimeException(\sprintf('%s could not be written.', $path));
        }

        $this->state = $state;
    }

    /** Bring it back. False when it was not down. */
    public function up(): bool
    {
        $this->state = false;

        return \is_file($this->path()) && @\unlink($this->path());
    }

    /**
     * What the kernel does with a request while down: null to handle it as
     * usual, or the response to send instead.
     *
     * @throws HttpException 503, with Retry-After, rendered by the error page
     */
    public function intercept(Request $request): ?Response
    {
        $state = $this->state();

        if ($state === null) {
            return null;
        }

        $candidate = $request->query(self::BYPASS_PARAMETER);

        if ($state['secret'] !== null && \is_string($candidate) && \hash_equals($state['secret'], \hash('sha256', $candidate))) {
            $query = $request->query();
            unset($query[self::BYPASS_PARAMETER]);
            $location = $request->basePath() . $request->path() . ($query === [] ? '' : '?' . \http_build_query($query));

            return (new RedirectResponse($location === '' ? '/' : $location))->withCookie(new Cookie(
                self::BYPASS_COOKIE,
                $this->cookieValue($state),
                0,
                '/',
                '',
                $request->isSecure(),
            ));
        }

        if ($this->allows($request, $state)) {
            return null;
        }

        throw HttpException::serviceUnavailable($state['retry'], $state['message']);
    }

    /** @param array{since: int, retry: int, message: string, allow: list<string>, secret: ?string} $state */
    private function allows(Request $request, array $state): bool
    {
        if ($state['allow'] !== [] && IpSet::lenient($state['allow'])->contains($request->ipAddress())) {
            return true;
        }

        $cookie = $request->cookie(self::BYPASS_COOKIE);

        return $state['secret'] !== null && \is_string($cookie) && \hash_equals($this->cookieValue($state), $cookie);
    }

    /** @param array{since: int, retry: int, message: string, allow: list<string>, secret: ?string} $state */
    private function cookieValue(array $state): string
    {
        // Derived from the secret's hash, which never leaves the server, and the
        // window's start: unguessable without the file, and different after
        // the next `down`. Compared with hash_equals().
        return \hash('sha256', 'lphp-maintenance|' . $state['since'] . '|' . (string) $state['secret']);
    }

    /** @return ?array{since: int, retry: int, message: string, allow: list<string>, secret: ?string} */
    private function read(): ?array
    {
        $path = $this->path();

        if (!\is_file($path)) {
            return null;
        }

        $data = \json_decode((string) @\file_get_contents($path), true);

        // A file that cannot be read still means down: someone ran `down`,
        // and failing open would put a half-migrated site back in front of
        // visitors.
        if (!\is_array($data)) {
            return ['since' => 0, 'retry' => 60, 'message' => '', 'allow' => [], 'secret' => null];
        }

        return [
            'since' => \is_int($data['since'] ?? null) ? $data['since'] : 0,
            'retry' => \is_int($data['retry'] ?? null) ? $data['retry'] : 60,
            'message' => \is_string($data['message'] ?? null) ? $data['message'] : '',
            'allow' => \is_array($data['allow'] ?? null) ? \array_values(\array_filter($data['allow'], \is_string(...))) : [],
            'secret' => \is_string($data['secret'] ?? null) ? $data['secret'] : null,
        ];
    }

    private function path(): string
    {
        return \rtrim($this->basePath, '/\\') . '/' . self::FILE;
    }
}
