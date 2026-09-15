<?php

declare(strict_types=1);

namespace App\Engine\Security;

use App\Engine\Http\Request;
use App\Engine\Http\Response;

/**
 * The headers that tell a browser what this application will and will not do.
 *
 * Applied as a `response.instance` filter, which is to say to every response
 * the kernel produces, including error pages -- the ones most likely to be
 * reached by somebody probing, and the ones a per-route mechanism would miss.
 *
 * **Existing headers are never overwritten.** A handler that set its own
 * Content-Security-Policy has thought about it harder than a default can, and
 * the asset server already sets a strict one of its own. A filter that stamped
 * over those would make the careful case indistinguishable from the careless
 * one.
 *
 * The defaults are chosen to be safe *and* deployable. A Content-Security-Policy
 * that breaks every page is a Content-Security-Policy somebody turns off, and
 * the ones below are the headers that cost nothing:
 *
 *   X-Content-Type-Options: nosniff
 *       Stops the browser second-guessing a Content-Type. Without it an upload
 *       served as text/plain can be executed as HTML because it happens to
 *       start with a tag -- which is stored XSS by way of a file upload.
 *
 *   X-Frame-Options: SAMEORIGIN
 *       Clickjacking. Superseded by frame-ancestors in CSP, and still sent
 *       because the browsers that need it are the ones without CSP support.
 *
 *   Referrer-Policy: strict-origin-when-cross-origin
 *       Stops a full URL -- including a path with an id or a reset token in it
 *       -- being handed to every third-party asset the page loads.
 *
 *   Cross-Origin-Opener-Policy: same-origin
 *       Severs the window.opener relationship, which is what stops a page you
 *       linked to from navigating the tab it came from.
 *
 * **Content-Security-Policy is off by default**, and that is a deliberate
 * refusal rather than an oversight. A useful CSP names this application's own
 * script and style sources; a generic one is either so loose it permits what it
 * exists to stop, or so strict it breaks the first page with an inline handler.
 * `security.headers.csp` takes the real one, and `security:check` says plainly
 * that there is not one yet.
 *
 * **HSTS is off by default and only ever sent over HTTPS.** It is the one
 * header here that cannot be taken back: a browser that has seen it refuses
 * plain HTTP for the whole max-age, so sending it from a development machine on
 * localhost breaks every other project on that hostname for a year.
 */
final class SecurityHeaders
{
    public const DEFAULTS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Cross-Origin-Opener-Policy' => 'same-origin',
    ];

    /**
     * @param array<string, string> $headers  overrides and additions; '' removes one
     * @param string                $csp      the policy, or '' for none
     * @param int                   $hstsDays 0 for none. Only ever sent over HTTPS.
     */
    public function __construct(
        private readonly array $headers = [],
        private readonly string $csp = '',
        private readonly int $hstsDays = 0,
        private readonly bool $hstsSubdomains = false,
    ) {}

    /**
     * What this will add, for `security:check` to print.
     *
     * @return array<string, string>
     */
    public function describe(): array
    {
        $headers = $this->resolved();

        if ($this->csp !== '') {
            $headers['Content-Security-Policy'] = $this->csp;
        }

        if ($this->hstsDays > 0) {
            $headers['Strict-Transport-Security'] = $this->hsts() . ' (https only)';
        }

        return $headers;
    }

    public function __invoke(Response $response, ?Request $request = null): Response
    {
        foreach ($this->resolved() as $name => $value) {
            $response = $this->add($response, $name, $value);
        }

        if ($this->csp !== '') {
            $response = $this->add($response, 'Content-Security-Policy', $this->csp);
        }

        // Over HTTP this header is ignored by browsers and is a hint to anyone
        // watching that the site expects HTTPS somewhere; over HTTPS it is a
        // year-long commitment. Either way, only send it where it means
        // something.
        if ($this->hstsDays > 0 && $request?->isSecure() === true) {
            $response = $this->add($response, 'Strict-Transport-Security', $this->hsts());
        }

        return $response;
    }

    /** @return array<string, string> */
    private function resolved(): array
    {
        $headers = self::DEFAULTS;

        foreach ($this->headers as $name => $value) {
            // An empty value removes a default rather than sending an empty
            // header, which is how an application turns one off without the
            // configuration needing a second shape for "no".
            if ($value === '') {
                unset($headers[$name]);

                continue;
            }

            $headers[$name] = $value;
        }

        return $headers;
    }

    private function hsts(): string
    {
        return 'max-age=' . ($this->hstsDays * 86400) . ($this->hstsSubdomains ? '; includeSubDomains' : '');
    }

    private function add(Response $response, string $name, string $value): Response
    {
        return $response->hasHeader($name) ? $response : $response->withHeader($name, $value);
    }
}
