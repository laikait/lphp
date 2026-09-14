<?php

declare(strict_types=1);

namespace App\Engine\Http;

/**
 * An incoming HTTP request.
 *
 * Immutable, and deliberately ignorant of routing: there is no route(), no
 * routeParam(), no user(). Route parameters reach handlers as named arguments,
 * not by being stuffed in here. The generic attribute bag exists for
 * application use, and the dispatcher does not depend on it.
 */
final class Request
{
    private bool $jsonParsed = false;

    private mixed $json = null;

    /**
     * @param array<string, mixed>                            $query
     * @param array<string, mixed>                            $parsedBody
     * @param array<string, string>                           $headers    keyed by lower-case name
     * @param array<string, string>                           $cookies
     * @param array<string, UploadedFile|list<UploadedFile>>  $files
     * @param array<string, mixed>                            $server
     * @param array<string, mixed>                            $attributes
     * @param list<string>                                    $trustedProxies
     */
    private function __construct(
        private readonly string $method,
        private readonly string $uri,
        private readonly string $basePath,
        private readonly string $path,
        private readonly array $query,
        private readonly array $parsedBody,
        private readonly string $body,
        private readonly array $headers,
        private readonly array $cookies,
        private readonly array $files,
        private readonly array $server,
        private readonly array $attributes = [],
        private readonly array $trustedProxies = [],
    ) {}

    /**
     * @param list<string> $trustedProxies
     */
    public static function fromGlobals(?string $basePath = null, array $trustedProxies = []): self
    {
        /** @var array<string, mixed> $server */
        $server = $_SERVER;
        /** @var array<string, mixed> $query */
        $query = $_GET;
        /** @var array<string, mixed> $post */
        $post = $_POST;
        /** @var array<string, string> $cookies */
        $cookies = $_COOKIE;
        /** @var array<string, mixed> $files */
        $files = $_FILES;

        $body = (string) \file_get_contents('php://input');

        return self::build(
            method: self::readMethod($server),
            server: $server,
            query: $query,
            parsedBody: $post,
            body: $body,
            headers: Headers::fromServer($server),
            cookies: $cookies,
            files: UploadedFile::normalizeAll($files),
            basePath: $basePath,
            trustedProxies: $trustedProxies,
        );
    }

    /**
     * Build a request directly. This is what tests and the CLI use, and it takes
     * exactly the same path through base-path derivation as fromGlobals().
     *
     * @param array{
     *     query?: array<string, mixed>,
     *     body?: array<string, mixed>|string,
     *     headers?: array<string, string>,
     *     cookies?: array<string, string>,
     *     files?: array<string, UploadedFile|list<UploadedFile>>,
     *     server?: array<string, mixed>,
     *     basePath?: string,
     *     trustedProxies?: list<string>
     * } $options
     */
    public static function create(string $method, string $uri, array $options = []): self
    {
        $server = $options['server'] ?? [];
        $server['REQUEST_URI'] ??= $uri;
        $server['REQUEST_METHOD'] ??= \strtoupper($method);

        $headers = [];

        foreach ($options['headers'] ?? [] as $name => $value) {
            $headers[Headers::normalize($name)] = $value;
        }

        $rawBody = $options['body'] ?? '';
        $parsedBody = \is_array($rawBody) ? $rawBody : [];
        $body = \is_string($rawBody) ? $rawBody : \http_build_query($rawBody);

        if (\is_array($rawBody) && $rawBody !== [] && !isset($headers['content-type'])) {
            $headers['content-type'] = 'application/x-www-form-urlencoded';
        }

        $query = $options['query'] ?? [];

        if ($query === [] && \str_contains($uri, '?')) {
            $parsed = [];
            \parse_str((string) \substr($uri, (int) \strpos($uri, '?') + 1), $parsed);

            // parse_str() produces integer keys for names like "0"; the rest of
            // the request treats parameter names as strings, so normalise here.
            foreach ($parsed as $name => $value) {
                $query[(string) $name] = $value;
            }
        }

        return self::build(
            method: \strtoupper($method),
            server: $server,
            query: $query,
            parsedBody: $parsedBody,
            body: $body,
            headers: $headers,
            cookies: $options['cookies'] ?? [],
            files: $options['files'] ?? [],
            basePath: $options['basePath'] ?? null,
            trustedProxies: $options['trustedProxies'] ?? [],
        );
    }

    /**
     * @param array<string, mixed>                           $server
     * @param array<string, mixed>                           $query
     * @param array<string, mixed>                           $parsedBody
     * @param array<string, string>                          $headers
     * @param array<string, string>                          $cookies
     * @param array<string, UploadedFile|list<UploadedFile>> $files
     * @param list<string>                                   $trustedProxies
     */
    private static function build(
        string $method,
        array $server,
        array $query,
        array $parsedBody,
        string $body,
        array $headers,
        array $cookies,
        array $files,
        ?string $basePath,
        array $trustedProxies,
    ): self {
        $uri = \is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';
        $resolvedBase = $basePath ?? self::deriveBasePath($server);

        return new self(
            method: $method,
            uri: $uri,
            basePath: $resolvedBase,
            path: self::derivePath($uri, $resolvedBase),
            query: $query,
            parsedBody: $parsedBody,
            body: $body,
            headers: $headers,
            cookies: $cookies,
            files: $files,
            server: $server,
            trustedProxies: $trustedProxies,
        );
    }

    /**
     * Work out the prefix of the URI that addresses the front controller rather
     * than the application.
     *
     * Three deployments have to come out right:
     *
     *   Apache + rewrite   SCRIPT_NAME=/framework/index.php  URI=/framework/customers  -> "/framework"
     *   Apache, no rewrite SCRIPT_NAME=/framework/index.php  URI=/framework/index.php/customers
     *                                                                                 -> "/framework/index.php"
     *   php -S + server.php SCRIPT_NAME=/customers           URI=/customers            -> ""
     *
     * Public because the answer is needed before a Request exists: the asset
     * manager builds URLs whether or not this process is serving a request, and
     * they have to come out identical to the ones built while it is. Two
     * implementations of this derivation would be two subtly different answers.
     *
     * @param array<string, mixed> $server
     */
    public static function basePathFrom(array $server): string
    {
        return self::deriveBasePath($server);
    }

    /** @param array<string, mixed> $server */
    private static function deriveBasePath(array $server): string
    {
        $scriptName = \is_string($server['SCRIPT_NAME'] ?? null) ? $server['SCRIPT_NAME'] : '';
        $uri = \is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';
        $uri = (string) \strtok($uri, '?');

        if ($uri === '') {
            $uri = '/';
        }

        // The front controller is named in the URI: no rewriting happened.
        if ($scriptName !== '' && \str_starts_with($uri, $scriptName)) {
            return $scriptName;
        }

        $directory = \rtrim(\str_replace('\\', '/', \dirname($scriptName)), '/');

        if ($directory !== '' && ($uri === $directory || \str_starts_with($uri, $directory . '/'))) {
            return $directory;
        }

        return '';
    }

    private static function derivePath(string $uri, string $basePath): string
    {
        $path = (string) \strtok($uri, '?');

        if ($basePath !== '' && \str_starts_with($path, $basePath)) {
            $path = \substr($path, \strlen($basePath));
        }

        $path = \rawurldecode($path);
        $path = '/' . \ltrim($path, '/');

        // A trailing slash is not a different resource, but "/" itself is.
        return $path === '/' ? $path : \rtrim($path, '/');
    }

    /** @param array<string, mixed> $server */
    private static function readMethod(array $server): string
    {
        $method = \is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET';

        return \strtoupper($method);
    }

    // ---- request line ----------------------------------------------------

    public function method(): string
    {
        return $this->method;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === \strtoupper($method);
    }

    /** The raw REQUEST_URI, exactly as it arrived. */
    public function uri(): string
    {
        return $this->uri;
    }

    /** The routable path: base stripped, percent-decoded, no trailing slash. */
    public function path(): string
    {
        return $this->path;
    }

    /** The prefix that addresses the front controller, e.g. "/framework". */
    public function basePath(): string
    {
        return $this->basePath;
    }

    public function scheme(): string
    {
        return $this->isSecure() ? 'https' : 'http';
    }

    public function host(): string
    {
        $host = $this->header('Host');

        if ($host === null || $host === '') {
            $name = $this->server('SERVER_NAME');

            return \is_string($name) ? $name : 'localhost';
        }

        // Strip the port; host() is the name, port() is the port.
        return (string) \preg_replace('/:\d+$/', '', $host);
    }

    public function port(): int
    {
        $host = $this->header('Host');

        if ($host !== null && \preg_match('/:(\d+)$/', $host, $matches) === 1) {
            return (int) $matches[1];
        }

        $port = $this->server('SERVER_PORT');

        if (\is_numeric($port)) {
            return (int) $port;
        }

        return $this->isSecure() ? 443 : 80;
    }

    public function isSecure(): bool
    {
        $https = $this->server('HTTPS');

        if (\is_string($https) && $https !== '' && \strtolower($https) !== 'off') {
            return true;
        }

        if ((int) $this->server('SERVER_PORT', 0) === 443) {
            return true;
        }

        if ($this->fromTrustedProxy()) {
            return \strtolower((string) $this->header('X-Forwarded-Proto', '')) === 'https';
        }

        return false;
    }

    public function url(): string
    {
        $port = $this->port();
        $standard = ($this->isSecure() && $port === 443) || (!$this->isSecure() && $port === 80);

        return \sprintf(
            '%s://%s%s%s',
            $this->scheme(),
            $this->host(),
            $standard ? '' : ':' . $port,
            $this->uri,
        );
    }

    // ---- input -----------------------------------------------------------

    /** @return ($key is null ? array<string, mixed> : mixed) */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }

        return $this->query[$key] ?? $default;
    }

    /**
     * Body input, whether the client sent a form or JSON.
     *
     * Query parameters are deliberately NOT merged in: "where did this value
     * come from?" should have one answer.
     *
     * @return ($key is null ? array<string, mixed> : mixed)
     */
    public function input(?string $key = null, mixed $default = null): mixed
    {
        $data = $this->parsedBody;

        if ($data === [] && $this->isJson()) {
            $decoded = $this->json();
            $data = \is_array($decoded) ? $decoded : [];
        }

        if ($key === null) {
            /** @var array<string, mixed> $data */
            return $data;
        }

        return $data[$key] ?? $default;
    }

    /**
     * The decoded JSON body, or null when the body is absent or malformed.
     *
     * Malformed JSON returns null rather than throwing: deciding whether that
     * is a 400 belongs to the handler, not to the request object.
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        if (!$this->jsonParsed) {
            $this->jsonParsed = true;
            $this->json = $this->body === '' ? null : \json_decode($this->body, true);

            if (\json_last_error() !== \JSON_ERROR_NONE) {
                $this->json = null;
            }
        }

        if ($key === null) {
            return $this->json;
        }

        return \is_array($this->json) ? ($this->json[$key] ?? $default) : $default;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function isJson(): bool
    {
        return \str_contains(\strtolower((string) $this->header('Content-Type', '')), 'json');
    }

    // ---- headers, cookies, files ----------------------------------------

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[Headers::normalize($name)] ?? $default;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[Headers::normalize($name)]);
    }

    /** @return array<string, string> keyed by the canonical header name */
    public function headers(): array
    {
        $headers = [];

        foreach ($this->headers as $name => $value) {
            $headers[Headers::canonical($name)] = $value;
        }

        return $headers;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    /** @return array<string, string> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function file(string $name): ?UploadedFile
    {
        $file = $this->files[$name] ?? null;

        if ($file instanceof UploadedFile) {
            return $file;
        }

        return \is_array($file) ? ($file[0] ?? null) : null;
    }

    /** @return array<string, UploadedFile|list<UploadedFile>> */
    public function files(): array
    {
        return $this->files;
    }

    // ---- environment -----------------------------------------------------

    /** @return ($key is null ? array<string, mixed> : mixed) */
    public function server(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->server;
        }

        return $this->server[$key] ?? $default;
    }

    /**
     * The client address.
     *
     * X-Forwarded-For is honoured only when REMOTE_ADDR is itself a configured
     * trusted proxy. Trusting it unconditionally would let any client claim any
     * address, which quietly breaks rate limiting and audit logs.
     */
    public function ip(): ?string
    {
        $remote = $this->server('REMOTE_ADDR');
        $remote = \is_string($remote) ? $remote : null;

        if (!$this->fromTrustedProxy()) {
            return $remote;
        }

        $forwarded = $this->header('X-Forwarded-For');

        if ($forwarded === null || $forwarded === '') {
            return $remote;
        }

        $first = \trim((string) \strtok($forwarded, ','));

        return $first === '' ? $remote : $first;
    }

    public function userAgent(): ?string
    {
        return $this->header('User-Agent');
    }

    public function isAjax(): bool
    {
        return \strtolower((string) $this->header('X-Requested-With', '')) === 'xmlhttprequest';
    }

    /**
     * Whether a JSON error body is more useful to this client than an HTML one.
     *
     * Two shortcuts first, because both are statements rather than preferences:
     * a client that sent JSON can read JSON, and XMLHttpRequest is not going to
     * render a page. Otherwise the Accept header decides, through real
     * negotiation rather than a substring test -- which is the difference
     * between honouring
     *
     *     Accept: application/json;q=0.9, text/html;q=0.8
     *
     * and serving that client a web page because "text/html" appears in the
     * string somewhere.
     *
     * HTML is the tie-break, so a browser sending Accept: *\/* still gets a page.
     */
    public function expectsJson(): bool
    {
        if ($this->isAjax() || $this->isJson()) {
            return true;
        }

        return Negotiator::prefersJson($this->header('Accept'));
    }

    /**
     * Negotiate what this endpoint should produce, or refuse with a 406.
     *
     * On Request because that is where a handler already is, and because the
     * offers belong next to the code that can produce them. Nothing negotiates
     * on the handler's behalf: the framework does not know what a route can
     * return.
     *
     * @param list<string> $offered most preferred first
     *
     * @throws HttpException 406
     */
    public function negotiate(array $offered): string
    {
        return Negotiator::require($this, $offered);
    }

    /**
     * Refuse a request body this endpoint cannot read, with a 415.
     *
     * Worth the line it costs: without it a POST whose Content-Type is
     * text/plain reaches the schema as an empty payload, and the client is told
     * its fields are missing -- which sends whoever is debugging it to look at
     * the fields instead of at the header.
     *
     * @param list<string> $accepted
     *
     * @throws HttpException 415
     */
    public function requirePayload(array $accepted = ['application/json']): void
    {
        Negotiator::requirePayload($this, $accepted);
    }

    private function fromTrustedProxy(): bool
    {
        if ($this->trustedProxies === []) {
            return false;
        }

        $remote = $this->server('REMOTE_ADDR');

        return \is_string($remote) && \in_array($remote, $this->trustedProxies, true);
    }

    // ---- attributes ------------------------------------------------------

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $attributes = $this->attributes;
        $attributes[$key] = $value;

        return new self(
            method: $this->method,
            uri: $this->uri,
            basePath: $this->basePath,
            path: $this->path,
            query: $this->query,
            parsedBody: $this->parsedBody,
            body: $this->body,
            headers: $this->headers,
            cookies: $this->cookies,
            files: $this->files,
            server: $this->server,
            attributes: $attributes,
            trustedProxies: $this->trustedProxies,
        );
    }
}
