<?php

declare(strict_types=1);

namespace App\Engine\Http\Client;

/**
 * PHP's own http and https stream wrapper: no extension, available everywhere.
 *
 * The response headers come from stream_get_meta_data(), which works the same
 * on every PHP this framework supports -- http_get_last_response_headers() is
 * 8.4+ and $http_response_header is deprecated from 8.5. TLS certificates are
 * verified, as PHP does by default.
 */
final class StreamTransport implements Transport
{
    public function send(ClientRequest $request): ClientResponse
    {
        $headers = '';

        foreach ($request->headers as $name => $value) {
            $headers .= $name . ': ' . $value . "\r\n";
        }

        $context = \stream_context_create([
            'http' => [
                'method' => $request->method,
                'header' => $headers,
                'content' => $request->body,
                'timeout' => $request->timeout,
                'follow_location' => 0,
                'ignore_errors' => true,
                'protocol_version' => 1.1,
            ],
        ]);

        $started = \microtime(true);
        $stream = @\fopen($request->url, 'r', false, $context);

        if ($stream === false) {
            if (\microtime(true) - $started >= $request->timeout) {
                throw HttpClientException::timeout($request->method, $request->url, $request->timeout);
            }

            $error = \error_get_last();

            throw HttpClientException::connection($request->method, $request->url, self::reason($error['message'] ?? 'no connection'));
        }

        try {
            $meta = \stream_get_meta_data($stream);
            $body = \stream_get_contents($stream);
            $timedOut = \stream_get_meta_data($stream)['timed_out'];
        } finally {
            \fclose($stream);
        }

        if ($timedOut || $body === false) {
            throw HttpClientException::timeout($request->method, $request->url, $request->timeout);
        }

        $lines = \array_values(\array_filter(\is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [], \is_string(...)));

        return ClientResponse::fromHeaderLines($lines, $body, $request->method, $request->url);
    }

    /** PHP's warning without the "fopen(<url>):" prefix, which would repeat the query string. */
    private static function reason(string $message): string
    {
        $message = (string) \preg_replace('/^fopen\([^)]*\):\s*/', '', $message);

        return \rtrim($message, '.') ?: 'no connection';
    }
}
