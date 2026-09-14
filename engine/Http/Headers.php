<?php

declare(strict_types=1);

namespace App\Engine\Http;

/**
 * Header name and value hygiene.
 *
 * Header names are matched case-insensitively, so everything is keyed by the
 * lower-case form and rendered back in canonical form only at the boundary.
 *
 * sanitizeValue() is the single place CR/LF is stripped. Header injection is a
 * response-splitting vulnerability, and having exactly one chokepoint for it is
 * worth more than the convenience of setting headers from several places.
 */
final class Headers
{
    public static function normalize(string $name): string
    {
        return \strtolower(\trim($name));
    }

    /** Render "content-type" back as "Content-Type" for the wire. */
    public static function canonical(string $name): string
    {
        $normalized = self::normalize($name);

        return \implode('-', \array_map(
            static fn(string $part): string => \ucfirst($part),
            \explode('-', $normalized),
        ));
    }

    /**
     * Remove anything that could terminate the header or start a new one.
     *
     * Bare CR and LF are removed rather than escaped: there is no legitimate
     * reason for either to appear in a value we generate.
     */
    public static function sanitizeValue(string $value): string
    {
        return \trim(\str_replace(["\r", "\n", "\0"], '', $value));
    }

    /**
     * Build the header map from a $_SERVER-shaped array.
     *
     * HTTP_* entries carry most headers; Content-Type and Content-Length are the
     * two that PHP reports without the prefix.
     *
     * @param array<string, mixed> $server
     *
     * @return array<string, string> keyed by the lower-case header name
     */
    public static function fromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!\is_string($key) || !\is_scalar($value)) {
                continue;
            }

            if (\str_starts_with($key, 'HTTP_')) {
                $name = \str_replace('_', '-', \substr($key, 5));
                $headers[self::normalize($name)] = (string) $value;

                continue;
            }

            if ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH' || $key === 'CONTENT_MD5') {
                $name = \str_replace('_', '-', $key);
                $headers[self::normalize($name)] = (string) $value;
            }
        }

        return $headers;
    }
}
