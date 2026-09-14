<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Engine\Http\Request;
use App\Engine\Http\UploadedFile;
use App\Tests\Support\TestCase;

final class RequestTest extends TestCase
{
    // ---- base path derivation --------------------------------------------
    //
    // This is the most environment-specific logic in the framework: the same
    // application has to work at http://localhost/framework/ under Apache and
    // at http://127.0.0.1:8080/ under the built-in server.

    public function test_apache_in_a_subdirectory_with_rewriting(): void
    {
        $request = Request::create('GET', '/framework/customers', [
            'server' => ['SCRIPT_NAME' => '/framework/index.php'],
        ]);

        self::assertSame('/framework', $request->basePath());
        self::assertSame('/customers', $request->path());
    }

    public function test_apache_in_a_subdirectory_without_rewriting(): void
    {
        $request = Request::create('GET', '/framework/index.php/customers', [
            'server' => ['SCRIPT_NAME' => '/framework/index.php'],
        ]);

        self::assertSame('/framework/index.php', $request->basePath());
        self::assertSame('/customers', $request->path());
    }

    public function test_the_builtin_server_has_no_base_path(): void
    {
        $request = Request::create('GET', '/customers', [
            'server' => ['SCRIPT_NAME' => '/server.php'],
        ]);

        self::assertSame('', $request->basePath());
        self::assertSame('/customers', $request->path());
    }

    public function test_the_subdirectory_root_resolves_to_a_single_slash(): void
    {
        $request = Request::create('GET', '/framework/', [
            'server' => ['SCRIPT_NAME' => '/framework/index.php'],
        ]);

        self::assertSame('/framework', $request->basePath());
        self::assertSame('/', $request->path());
    }

    public function test_the_subdirectory_root_without_a_trailing_slash(): void
    {
        $request = Request::create('GET', '/framework', [
            'server' => ['SCRIPT_NAME' => '/framework/index.php'],
        ]);

        self::assertSame('/framework', $request->basePath());
        self::assertSame('/', $request->path());
    }

    public function test_an_explicit_base_path_overrides_derivation(): void
    {
        $request = Request::create('GET', '/proxied/customers', [
            'server' => ['SCRIPT_NAME' => '/index.php'],
            'basePath' => '/proxied',
        ]);

        self::assertSame('/proxied', $request->basePath());
        self::assertSame('/customers', $request->path());
    }

    public function test_a_sibling_directory_is_not_mistaken_for_the_base(): void
    {
        $request = Request::create('GET', '/frameworks/customers', [
            'server' => ['SCRIPT_NAME' => '/framework/index.php'],
        ]);

        self::assertSame('', $request->basePath());
        self::assertSame('/frameworks/customers', $request->path());
    }

    // ---- path normalisation ----------------------------------------------

    public function test_the_path_excludes_the_query_string(): void
    {
        $request = Request::create('GET', '/customers?page=2');

        self::assertSame('/customers', $request->path());
        self::assertSame('/customers?page=2', $request->uri());
    }

    public function test_the_path_is_percent_decoded(): void
    {
        self::assertSame('/customers/Ada Lovelace', Request::create('GET', '/customers/Ada%20Lovelace')->path());
    }

    public function test_a_trailing_slash_is_not_a_different_resource(): void
    {
        self::assertSame('/customers', Request::create('GET', '/customers/')->path());
    }

    // ---- query and body ---------------------------------------------------

    public function test_the_query_string_is_parsed_from_the_uri(): void
    {
        $request = Request::create('GET', '/customers?page=2&sort=name');

        self::assertSame('2', $request->query('page'));
        self::assertSame(['page' => '2', 'sort' => 'name'], $request->query());
        self::assertSame('fallback', $request->query('missing', 'fallback'));
    }

    public function test_an_explicit_query_array_wins_over_the_uri(): void
    {
        $request = Request::create('GET', '/customers?page=2', ['query' => ['page' => '9']]);

        self::assertSame('9', $request->query('page'));
    }

    public function test_a_json_body_is_decoded(): void
    {
        $request = Request::create('POST', '/customers', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{"name":"Ada","age":36}',
        ]);

        self::assertTrue($request->isJson());
        self::assertSame(['name' => 'Ada', 'age' => 36], $request->json());
        self::assertSame('Ada', $request->json('name'));
        self::assertSame('Ada', $request->input('name'));
    }

    public function test_a_malformed_json_body_yields_null_rather_than_a_fatal(): void
    {
        $request = Request::create('POST', '/customers', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{"name":',
        ]);

        self::assertNull($request->json());
        self::assertSame('default', $request->json('name', 'default'));
        self::assertSame([], $request->input());
    }

    public function test_a_form_body_is_available_through_input(): void
    {
        $request = Request::create('POST', '/customers', ['body' => ['name' => 'Ada']]);

        self::assertSame('Ada', $request->input('name'));
        self::assertSame('application/x-www-form-urlencoded', $request->header('Content-Type'));
        self::assertSame('name=Ada', $request->body());
    }

    public function test_query_parameters_are_not_merged_into_input(): void
    {
        $request = Request::create('POST', '/customers?source=web', ['body' => ['name' => 'Ada']]);

        self::assertSame('web', $request->query('source'));
        self::assertNull($request->input('source'));
    }

    // ---- headers and cookies ---------------------------------------------

    public function test_headers_are_read_case_insensitively(): void
    {
        $request = Request::create('GET', '/', ['headers' => ['X-Custom-Header' => 'value']]);

        self::assertSame('value', $request->header('x-custom-header'));
        self::assertSame('value', $request->header('X-CUSTOM-HEADER'));
        self::assertTrue($request->hasHeader('X-Custom-Header'));
        self::assertSame(['X-Custom-Header' => 'value'], $request->headers());
    }

    public function test_headers_are_built_from_server_variables(): void
    {
        $request = Request::create('POST', '/', [
            'server' => [
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'CONTENT_LENGTH' => '42',
            ],
        ]);

        // create() takes headers explicitly, so build them the way fromGlobals does.
        $fromServer = \App\Engine\Http\Headers::fromServer([
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '42',
            'REQUEST_METHOD' => 'POST',
        ]);

        self::assertSame([
            'x-requested-with' => 'XMLHttpRequest',
            'accept' => 'application/json',
            'content-type' => 'application/json',
            'content-length' => '42',
        ], $fromServer);

        self::assertSame('POST', $request->method());
    }

    public function test_cookies_are_readable(): void
    {
        $request = Request::create('GET', '/', ['cookies' => ['session' => 'abc']]);

        self::assertSame('abc', $request->cookie('session'));
        self::assertNull($request->cookie('missing'));
        self::assertSame(['session' => 'abc'], $request->cookies());
    }

    // ---- uploads ----------------------------------------------------------

    public function test_a_single_upload_is_normalised(): void
    {
        $files = UploadedFile::normalizeAll([
            'avatar' => [
                'tmp_name' => '/tmp/php123',
                'name' => 'photo.png',
                'type' => 'image/png',
                'size' => 1024,
                'error' => \UPLOAD_ERR_OK,
            ],
        ]);

        $request = Request::create('POST', '/', ['files' => $files]);
        $avatar = $request->file('avatar');

        self::assertNotNull($avatar);
        self::assertSame('photo.png', $avatar->clientName());
        self::assertSame(1024, $avatar->size());
        self::assertTrue($avatar->isValid());
    }

    public function test_a_multi_upload_is_normalised_into_a_list(): void
    {
        $files = UploadedFile::normalizeAll([
            'documents' => [
                'tmp_name' => ['/tmp/a', '/tmp/b'],
                'name' => ['a.pdf', 'b.pdf'],
                'type' => ['application/pdf', 'application/pdf'],
                'size' => [10, 20],
                'error' => [\UPLOAD_ERR_OK, \UPLOAD_ERR_OK],
            ],
        ]);

        $request = Request::create('POST', '/', ['files' => $files]);

        self::assertIsArray($request->files()['documents']);
        self::assertCount(2, $request->files()['documents']);
        self::assertSame('a.pdf', $request->file('documents')?->clientName());
    }

    public function test_an_absent_upload_is_null(): void
    {
        self::assertNull(Request::create('POST', '/')->file('avatar'));
    }

    // ---- connection -------------------------------------------------------

    public function test_https_is_detected_from_the_server_variable(): void
    {
        $request = Request::create('GET', '/', ['server' => ['HTTPS' => 'on']]);

        self::assertTrue($request->isSecure());
        self::assertSame('https', $request->scheme());
    }

    public function test_https_off_is_not_secure(): void
    {
        self::assertFalse(Request::create('GET', '/', ['server' => ['HTTPS' => 'off']])->isSecure());
    }

    public function test_https_is_detected_from_port_443(): void
    {
        self::assertTrue(Request::create('GET', '/', ['server' => ['SERVER_PORT' => '443']])->isSecure());
    }

    public function test_host_and_port_come_from_the_host_header(): void
    {
        $request = Request::create('GET', '/', ['headers' => ['Host' => 'example.test:8080']]);

        self::assertSame('example.test', $request->host());
        self::assertSame(8080, $request->port());
    }

    public function test_the_url_omits_a_standard_port(): void
    {
        $request = Request::create('GET', '/customers', ['headers' => ['Host' => 'example.test']]);

        self::assertSame('http://example.test/customers', $request->url());
    }

    /**
     * Trusting X-Forwarded-For unconditionally would let any client claim any
     * address, which silently breaks rate limiting and audit trails.
     */
    public function test_forwarded_for_is_ignored_without_a_trusted_proxy(): void
    {
        $request = Request::create('GET', '/', [
            'server' => ['REMOTE_ADDR' => '10.0.0.5'],
            'headers' => ['X-Forwarded-For' => '203.0.113.9'],
        ]);

        self::assertSame('10.0.0.5', $request->ip());
    }

    public function test_forwarded_for_is_honoured_from_a_trusted_proxy(): void
    {
        $request = Request::create('GET', '/', [
            'server' => ['REMOTE_ADDR' => '10.0.0.5'],
            'headers' => ['X-Forwarded-For' => '203.0.113.9, 10.0.0.5'],
            'trustedProxies' => ['10.0.0.5'],
        ]);

        self::assertSame('203.0.113.9', $request->ip());
    }

    public function test_forwarded_proto_is_only_honoured_from_a_trusted_proxy(): void
    {
        $options = [
            'server' => ['REMOTE_ADDR' => '10.0.0.5'],
            'headers' => ['X-Forwarded-Proto' => 'https'],
        ];

        self::assertFalse(Request::create('GET', '/', $options)->isSecure());

        $options['trustedProxies'] = ['10.0.0.5'];
        self::assertTrue(Request::create('GET', '/', $options)->isSecure());
    }

    // ---- content negotiation ---------------------------------------------

    public function test_expects_json_for_an_ajax_request(): void
    {
        $request = Request::create('GET', '/', ['headers' => ['X-Requested-With' => 'XMLHttpRequest']]);

        self::assertTrue($request->isAjax());
        self::assertTrue($request->expectsJson());
    }

    public function test_expects_json_for_a_json_accept_header(): void
    {
        self::assertTrue(Request::create('GET', '/', ['headers' => ['Accept' => 'application/json']])->expectsJson());
        self::assertTrue(Request::create('GET', '/', ['headers' => ['Accept' => 'application/vnd.api+json']])->expectsJson());
    }

    public function test_a_browser_does_not_expect_json(): void
    {
        $request = Request::create('GET', '/', [
            'headers' => ['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8'],
        ]);

        self::assertFalse($request->expectsJson());
    }

    public function test_no_accept_header_does_not_expect_json(): void
    {
        self::assertFalse(Request::create('GET', '/')->expectsJson());
    }

    // ---- method and attributes -------------------------------------------

    public function test_the_method_is_upper_cased(): void
    {
        $request = Request::create('post', '/');

        self::assertSame('POST', $request->method());
        self::assertTrue($request->isMethod('post'));
        self::assertFalse($request->isMethod('GET'));
    }

    public function test_with_attribute_returns_a_new_instance(): void
    {
        $request = Request::create('GET', '/');
        $tagged = $request->withAttribute('tenant', 42);

        self::assertNotSame($request, $tagged);
        self::assertNull($request->attribute('tenant'));
        self::assertSame(42, $tagged->attribute('tenant'));
        self::assertSame(['tenant' => 42], $tagged->attributes());
    }

    public function test_with_attribute_preserves_everything_else(): void
    {
        $request = Request::create('POST', '/framework/customers?page=2', [
            'server' => ['SCRIPT_NAME' => '/framework/index.php'],
            'headers' => ['X-Trace' => 'abc'],
            'cookies' => ['session' => 'xyz'],
            'body' => '{"name":"Ada"}',
        ]);

        $tagged = $request->withAttribute('tenant', 1);

        self::assertSame('POST', $tagged->method());
        self::assertSame('/framework', $tagged->basePath());
        self::assertSame('/customers', $tagged->path());
        self::assertSame('2', $tagged->query('page'));
        self::assertSame('abc', $tagged->header('X-Trace'));
        self::assertSame('xyz', $tagged->cookie('session'));
        self::assertSame('{"name":"Ada"}', $tagged->body());
    }

    /**
     * Routing must not leak into the request object. If any of these ever
     * appear, route state has started living in two places.
     */
    public function test_the_request_knows_nothing_about_routing(): void
    {
        $reflection = new \ReflectionClass(Request::class);

        foreach (['route', 'routeParam', 'routeName', 'user'] as $forbidden) {
            self::assertFalse(
                $reflection->hasMethod($forbidden),
                \sprintf('Request::%s() exists; routing has leaked into the HTTP layer.', $forbidden),
            );
        }
    }
}
