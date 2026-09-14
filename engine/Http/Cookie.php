<?php

declare(strict_types=1);

namespace App\Engine\Http;

/**
 * A cookie to be set on a response.
 *
 * The defaults are the safe ones: HttpOnly on, SameSite=Lax, and Secure left to
 * the caller because forcing it would break plain-HTTP development. A cookie
 * that needs to be readable from JavaScript has to say so explicitly.
 */
final class Cookie
{
    public function __construct(
        public readonly string $name,
        public readonly string $value = '',
        public readonly int $expires = 0,
        public readonly string $path = '/',
        public readonly string $domain = '',
        public readonly bool $secure = false,
        public readonly bool $httpOnly = true,
        public readonly string $sameSite = 'Lax',
    ) {
        if ($name === '' || \strpbrk($name, "=,; \t\r\n\v\f") !== false) {
            throw new \InvalidArgumentException(\sprintf('Invalid cookie name "%s".', $name));
        }

        if (!\in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new \InvalidArgumentException(\sprintf('Invalid SameSite value "%s".', $sameSite));
        }

        if ($sameSite === 'None' && !$secure) {
            throw new \InvalidArgumentException('SameSite=None requires the Secure attribute.');
        }
    }

    /** A cookie that instructs the browser to drop the existing one. */
    public static function forget(string $name, string $path = '/', string $domain = ''): self
    {
        return new self($name, '', 1, $path, $domain);
    }

    public function toHeaderValue(): string
    {
        $parts = [$this->name . '=' . \rawurlencode($this->value)];

        if ($this->expires > 0) {
            $parts[] = 'Expires=' . \gmdate('D, d M Y H:i:s T', $this->expires);
            $parts[] = 'Max-Age=' . \max(0, $this->expires - \time());
        }

        if ($this->path !== '') {
            $parts[] = 'Path=' . $this->path;
        }

        if ($this->domain !== '') {
            $parts[] = 'Domain=' . $this->domain;
        }

        if ($this->secure) {
            $parts[] = 'Secure';
        }

        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }

        $parts[] = 'SameSite=' . $this->sameSite;

        return \implode('; ', $parts);
    }
}
