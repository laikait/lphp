# Testing

How to test what you build on the framework: booting a real application in a
test, sending it requests, forms and JSON, running commands, checking hooks and
jobs, and using a database that starts empty every time.

Nothing needs mocking. A test boots the real container, the real modules and the
real kernel, and handles a request without a web server — a whole request takes
about a millisecond.

## Where tests go

`tests/Feature/` for tests that boot the application; `tests/Unit/` for plain
classes. Both use the `App\Tests\` namespace, which autoloads from `tests/`.

```bash
vendor/bin/phpunit tests/Feature/DeskTest.php
vendor/bin/phpunit --filter inbox
composer test                     # everything
```

## Boot the application

Extend `App\Tests\Support\TestCase` and ask for an application:

```php
final class DeskTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function app(array $config = []): Application
    {
        $config['app']['debug'] ??= false;

        return $this->shippedApplication($config)->boot();
    }
}
```

`shippedApplication()` loads everything in `modules/`, with `$config` layered
over the defaults and `config/` — the same shape as the configuration files, so
`['plugins/Desk' => ['maintenance' => true]]` sets what
`config/plugins/Desk.php` would. For every test it also:

- keeps sessions and rate-limit counters in memory, so nothing is written to
  `system/` and no test inherits another's 429;
- leaves PHP's error handler alone, so PHPUnit still sees warnings;
- resets the global helpers when the test ends, so an `add_hook()` in one test
  cannot reach the next. Each application also has its own hook and filter
  engines, so listeners never outlive the application they were added to.

Set `app.debug` to `false` when a test is about what users see: in debug mode an
error is the diagnostic page, not your error template.

## Send a request

```php
$response = $this->app()->handle(Request::create('GET', '/contact'));

self::assertSame(200, $response->status());
self::assertStringContainsString('<h1>Contact us</h1>', $response->body());
```

`Request::create()` takes the method, the path, and options:

| Option | |
|---|---|
| `query` | `['page' => 2]`, or put it in the path: `/api/v1/messages?page=2` |
| `body` | an array is sent as a form; a string is sent as-is |
| `headers` | `['Accept' => 'application/json', 'Content-Type' => 'application/json']` |
| `cookies` | `['session' => $id]` |
| `files` | `['avatar' => new UploadedFile(...)]` |
| `server` | `['SCRIPT_NAME' => '/framework/index.php']` to test under a subdirectory, with the path `/framework/contact` |

For JSON, send a string body with the content type, and read the data back:

```php
$response = $app->handle(Request::create('POST', '/api/v1/customers', [
    'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
    'body' => '{"name":"Ada","email":"ada@example.test"}',
]));

self::assertInstanceOf(JsonResponse::class, $response);
self::assertSame(201, $response->status());
self::assertSame('Ada', $response->data()['data']['name']);
```

## Forms, cookies and CSRF

One application handles several requests in a row, and sessions live as long as
it does, so a test can follow a browser — as long as it carries the cookies
itself:

```php
private function cookie(Response $response, string $name): ?string
{
    foreach ($response->cookies() as $cookie) {
        if ($cookie->name === $name) {
            return $cookie->value;
        }
    }

    return null;
}

public function test_a_sent_message_redirects_and_flashes_once(): void
{
    $app = $this->app();
    $token = (string) $this->cookie($app->handle(Request::create('GET', '/contact')), Csrf::COOKIE);

    $sent = $app->handle(Request::create('POST', '/contact', [
        'cookies' => [Csrf::COOKIE => $token],
        'body' => ['_token' => $token, 'email' => 'ada@example.test', 'body' => 'Hello'],
    ]));

    $session = (string) $this->cookie($sent, SessionManager::DEFAULT_COOKIE);
    $cookies = [Csrf::COOKIE => $token, SessionManager::DEFAULT_COOKIE => $session];

    $next = $app->handle(Request::create('GET', '/contact', ['cookies' => $cookies]));
    $after = $app->handle(Request::create('GET', '/contact', ['cookies' => $cookies]));

    self::assertSame(303, $sent->status());
    self::assertSame('/contact', $sent->header('Location'));
    self::assertStringContainsString('Thank you. We will reply soon.', $next->body());
    self::assertStringNotContainsString('Thank you.', $after->body());
}
```

A POST without `_token` matching the `XSRF-TOKEN` cookie is a 403 — which is
itself worth one test per form. Logging in works the same way: POST to `/login`
with the token, keep the `session` cookie, send it with the next request. An API
client instead sends `'headers' => ['Authorization' => 'Bearer <token>']`.

## A database

With no database configured, repositories use memory that belongs to that
application, so each `app()` starts empty. Seed through your own repository:

```php
$messages = $this->app()->container()->get(MessageRepository::class);
$messages->receive(['email' => 'a@example.test', 'body' => 'one']);
```

For SQL — raw queries, a user provider, anything SQLite can run — configure an
in-memory SQLite database, which is equally fresh per application:

```php
$app = $this->app(['database' => ['connections' => ['default' => ['dsn' => 'sqlite::memory:']]]]);
$db = $app->container()->get(ConnectionManager::class)->connection();

$db->execute('CREATE TABLE staff (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL)');
```

Keep the `CREATE TABLE` statements your module uses in one place, so tests and
installation run the same ones.

## Hooks and filters

Listen in the test with the helpers, after booting the application.

```php
$heard = [];

add_hook('note.added', static function (Note $note) use (&$heard): void {
    $heard[] = $note->body();
});

// ... do the thing ...

self::assertSame(['Call Ada'], $heard);
```

`add_filter()` lets a test change a value another module would see.

## Jobs

The default queue store is `sync`: a job runs inside `push()`, so its effects are
there when the handler returns. To assert what was queued, listen to
`job.queued`:

```php
$queued = [];

add_hook('job.queued', static function (mixed $envelope, Job $job) use (&$queued): void {
    $queued[] = $job;
});
```

To queue without running, boot with `['queue' => ['store' => 'memory']]`.

## Commands

A command is an object, so the simplest test calls it:

```php
private function console(): Output
{
    $stream = \fopen('php://memory', 'w');
    self::assertIsResource($stream);

    return new Output($stream);
}

$status = $app->container()->get(AddNote::class)($this->console(), 'Buy milk');
```

To test what the command line does — argument parsing, `--help`, exit codes for
a typo — run it through the console kernel, exactly as `bin/console` does:

```php
/** @return array{int, string} the exit code, and stdout and stderr together */
private function run(Application $app, string ...$arguments): array
{
    $container = $app->container();
    $stream = \fopen('php://memory', 'r+');
    self::assertIsResource($stream);

    $kernel = new ConsoleKernel(
        $container->get(CommandRegistry::class),
        $container->get(CommandDispatcher::class),
        $container->get(HookEngine::class),
        $container->get(ErrorHandler::class),
        new Output($stream),
    );

    $status = $kernel->handle(ExecutionContext::cli(\array_values(['bin/console', ...$arguments])));

    \rewind($stream);

    return [$status, (string) \stream_get_contents($stream)];
}
```

```php
[$status, $output] = $this->run($this->app(), 'notes:add', '');
self::assertSame(ConsoleKernel::USAGE, $status);    // 2
```

`ConsoleKernel::SUCCESS`, `FAILURE`, `USAGE` and `UNKNOWN` are 0, 1, 2 and 127.
The kernel and registry are internal classes: fine in a test, not in a module.

## Static analysis for your module

`composer check` analyses `engine/` and `tests/`. Analyse your module the same
way:

```bash
vendor/bin/phpstan analyse modules/plugins/Desk
vendor/bin/php-cs-fixer fix --dry-run --diff --path-mode=override modules/plugins/Desk
```

One framework test already reads your modules:
`DocumentationTest::test_no_module_uses_an_internal_class` fails when anything
under `modules/` uses a class `STABILITY.md` marks Internal, because Internal
classes change without notice. If your module seems to need one, that is a
question about the framework worth raising rather than working around.

## Traps

- **`output()`, `matches()` and friends are final in PHPUnit.** Naming a helper
  after one is a fatal error when the test file loads. Pick another name.
- **Tests that describe a fresh install.** The framework's suite includes
  `DefaultPagesSliceTest`, which checks that only `shared` ships and that `/` is
  the default home page. Once `modules/` holds your modules, those tests describe
  a different application; adapt or remove them.
- **The first form on a fresh browser.** In 0.1.0 a page's CSRF token and the
  cookie set with it differ on a browser's first visit. Tests that fetch the
  cookie first and send it back — as above — pass; a test that posts the token
  printed on a first page view will get 403.
