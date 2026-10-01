<?php

declare(strict_types=1);

namespace App\Engine\Http\Client;

/**
 * One outgoing request, as a transport sends it: absolute URL, headers, body.
 *
 * Immutable. The http.client.request filter receives one and may return a
 * changed copy -- a header added, a URL rewritten -- which is what goes out.
 */
final class ClientRequest
{
    /**
     * @param array<string, string> $headers by their canonical name
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly float $timeout = 30.0,
    ) {}

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (\strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = \array_filter($this->headers, static fn(string $key): bool => \strcasecmp($key, $name) !== 0, \ARRAY_FILTER_USE_KEY);
        $headers[$name] = $value;

        return new self($this->method, $this->url, $headers, $this->body, $this->timeout);
    }

    public function withUrl(string $url): self
    {
        return new self($this->method, $url, $this->headers, $this->body, $this->timeout);
    }

    /** The same request as a GET with no body -- what a 301, 302 or 303 turns a POST into. */
    public function asGet(): self
    {
        $headers = \array_filter(
            $this->headers,
            static fn(string $key): bool => !\in_array(\strtolower($key), ['content-type', 'content-length'], true),
            \ARRAY_FILTER_USE_KEY,
        );

        return new self('GET', $this->url, $headers, '', $this->timeout);
    }

    public function host(): string
    {
        $host = \parse_url($this->url, \PHP_URL_HOST);

        return \is_string($host) ? $host : '';
    }
}
