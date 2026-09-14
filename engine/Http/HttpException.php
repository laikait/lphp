<?php

declare(strict_types=1);

namespace App\Engine\Http;

use App\Engine\Error\FrameworkException;

/**
 * An exception that carries the HTTP status it should become.
 *
 * This is how the kernel expresses 404, 405 and 400 without the router or the
 * dispatcher needing to know anything about how errors get rendered. The error
 * handler turns it into a response; nothing else has to special-case it.
 */
class HttpException extends FrameworkException
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly int $status,
        string $message = '',
        private readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message === '' ? Response::statusText($status) : $message, 0, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public static function badRequest(string $message = ''): self
    {
        return new self(400, $message);
    }

    public static function notFound(string $path = ''): self
    {
        return new self(404, $path === '' ? 'Not Found' : \sprintf('No route matches %s', $path));
    }

    /**
     * 405 must advertise what is allowed; the Allow header is not optional.
     *
     * @param list<string> $allowed
     */
    public static function methodNotAllowed(array $allowed): self
    {
        \sort($allowed);

        return new self(
            405,
            \sprintf('Method not allowed. Allowed: %s', \implode(', ', $allowed)),
            ['Allow' => \implode(', ', $allowed)],
        );
    }

    public static function unsupportedMediaType(string $message = ''): self
    {
        return new self(415, $message);
    }

    /**
     * 406, carrying what the endpoint could have produced.
     *
     * The list is the whole value of the response. A client that asked for
     * something unavailable needs to know what to ask for instead, and a bare
     * "Not Acceptable" sends whoever is debugging it to the source.
     *
     * @param list<string> $offered
     */
    public static function notAcceptable(array $offered): self
    {
        return new self(406, \sprintf(
            'None of the media types this endpoint produces were acceptable. It can return: %s.',
            \implode(', ', $offered),
        ));
    }
}
