# JSON APIs

How to build endpoints that programs call: a versioned group of routes, reading
and validating JSON, answering with consistent success and error bodies,
pagination, and deprecating a version without surprising anyone.

There is no API framework to learn. An API endpoint is a handler that returns
JSON, on the same router and the same kernel as a page. What follows are
conventions the framework makes cheap to follow. The full reasoning is in
[REST](../reference/rest.md).

The examples come from the showcase plugin, `tests/Fixtures/Showcase/Plugins/Example/`,
which the test suite runs, and from a `Desk` module with a `messages` inbox.

## Group the routes

```php
$routes->group('/api/v1', static function (RouteCollector $routes): void {
    $routes->get('/customers', ListCustomers::class)->name('customers.index');

    $routes->get('/customers/{id}', [CustomerApi::class, 'show'])
        ->where('id', '\d+')
        ->name('customers.show');

    $routes->post('/customers', [CustomerApi::class, 'store'])->name('customers.store');
}, name: 'api.v1.', meta: [
    'api' => true,
    'version' => 'v1',
    'rate_limit' => '60/1m',
]);
```

- The version is **the URL prefix**. The shared module turns `version` into an
  `X-Api-Version` header on every response in the group.
- `name: 'api.v1.'` prefixes every route name, so `api.v1.customers.show` can be
  turned back into a URL with `$router->url('api.v1.customers.show', ['id' => 7])`.
- `rate_limit` is per route and per client. Formats: `60/1m`, `5/15m`, `1000/1h`,
  `10/1d`. Refusals are 429 with `Retry-After`.
- `{id}` binds to a handler parameter named `$id`. Declared as `int`, a value that
  is not a whole number is a 400 before your code runs.

**CSRF applies to APIs too.** A `POST` from a browser holding a session cookie is
exactly what CSRF protection is for. Add `'csrf' => false` to the group **only**
when clients authenticate with a bearer token and never with the session cookie —
see [Bearer tokens](users-and-permissions.md#api-clients-bearer-tokens).

## Answer with one resource

```php
public function show(int $id): JsonResponse
{
    $customer = $this->customers->find($id)
        ?? throw HttpException::notFound(\sprintf('/api/v1/customers/%d', $id));

    return ApiResponse::item(CustomerSchema::resource()->serialize([
        'id' => $customer->identity(),
        'name' => $customer->name(),
        'email' => $customer->email(),
    ]));
}
```

`ApiResponse::item()` wraps the value as `{"data": …}`. Serialising through a
schema means the response is shaped by the same declaration that documents it.
A domain model is never returned directly: what goes over the wire is chosen
field by field.

| | |
|---|---|
| `ApiResponse::item($data)` | 200, `{"data": …}` |
| `ApiResponse::collection($items, $meta)` | 200, `{"data": […], "meta": …}` |
| `ApiResponse::created($data, $url)` | 201 with `Location` |
| `ApiResponse::noContent()` | 204 |
| `ApiResponse::accepted($data)` | 202, for work taken but not finished |

Returning a plain array also works — it becomes a 200 JSON response — so none of
these are required.

## Accept a new resource

```php
public function store(Request $request): JsonResponse
{
    $request->requirePayload();

    $payload = $request->json();
    $schema = CustomerSchema::input();
    $result = $schema->validate(\is_array($payload) ? $payload : []);

    if (!$result->isValid()) {
        return ErrorDocument::validation(
            fields: $result->messages(),
            message: 'The customer could not be created.',
            expected: $schema->describe()['fields'],
        )->toResponse();
    }

    /** @var array<string, mixed> $payload */
    $customer = $this->customers->register($schema->deserialize($payload));
    $id = $customer->identity() ?? throw new \LogicException('A stored customer has no id.');

    return ApiResponse::created(
        CustomerSchema::resource()->serialize(['id' => $id, 'name' => $customer->name(), 'email' => $customer->email()]),
        $this->router->url('api.v1.customers.show', ['id' => $id]),
    );
}
```

1. **`requirePayload()`** answers 415 when the body is not JSON. Without it, a
   client that forgot `Content-Type` is told its fields are missing, and goes
   looking in the wrong place.
2. **`validate()`** reports every invalid field at once, with a path such as
   `address.postcode`.
3. **`ErrorDocument::validation()`** is returned, not thrown, because the handler
   knows which fields failed and the error handler would not.
4. **`deserialize()`** converts types, applies defaults and **drops undeclared
   keys**, so a client cannot set `is_admin` by adding it to the body.

Schemas are described in [Schemas](../reference/schemas.md).

## Lists and pagination

Build a page of read models — only the columns the response needs — and hand its
metadata to `collection()`:

```php
final class MessagesApi
{
    public function __construct(private readonly MessageRepository $messages) {}

    public function index(Request $request): JsonResponse
    {
        $page = \max(1, (int) $request->query('page', 1));
        $inbox = $this->messages->inbox($page);

        return ApiResponse::collection($inbox->items(), $inbox->meta());
    }
}
```

where the repository method is:

```php
public function inbox(int $page, int $perPage = 20): Page
{
    return $this->query()
        ->whereIs('spam', false)
        ->orderByDesc('id')
        ->pageInto(MessageSummary::class, $page, $perPage);
}
```

`MessageSummary` is a [read model](storing-data.md#read-cheaply): a class with
public readonly constructor properties, serialised as exactly those fields.
Links to the next and previous pages are built in the handler with
`$router->url()` if you want them; `ApiResponse` deliberately knows nothing
about pages.

## Errors

Every error in the application has one shape, whether a handler returned it or
the framework produced it:

```json
{"error": {"status": 404, "title": "Not Found", "message": "…"}}
```

- Throw `HttpException` for a status with nothing more to say:
  `HttpException::notFound()`, `badRequest()`, `unauthorized()`, `forbidden()`,
  `tooManyRequests()`, or `new HttpException(409, 'Already paid.')`. Its message
  is shown to the client.
- Any other exception is a 500, and **its message is replaced** with
  "Internal Server Error" unless `APP_DEBUG` is on. Do not rely on an exception
  message reaching a client.
- A client that sends `Accept: application/json` gets this JSON for routing
  errors too — an unknown URL, a wrong method.

See [One error shape](../reference/rest.md#one-error-shape).

## One route, two representations

A page and its data can share a route:

```php
if ($request->negotiate(['text/html', 'application/json']) === 'application/json') {
    return ApiResponse::collection($rows);
}
```

`negotiate()` honours quality values (`Accept: application/json;q=0.9, text/html;q=0.8`
wants JSON) and answers 406 when nothing offered is acceptable.

## Deprecate a version

Keep the old group answering and say when it goes:

```php
$routes->group('/api/v0', static function (RouteCollector $routes): void {
    $routes->get('/customers', ListCustomers::class)->name('customers.index');
}, name: 'api.v0.', meta: [
    'api' => true,
    'version' => 'v0',
    'deprecated' => '2026-01-01',
    'sunset' => '2027-01-01',
]);
```

Every response in the group then carries `Deprecation` and `Sunset` headers, so
clients learn from the responses they already receive.

## Try it

```bash
curl -i http://127.0.0.1:8080/api/v1/customers/1
curl -i -X POST -H 'Content-Type: application/json' \
     -d '{"name":"Ada","email":"ada@example.test"}' \
     http://127.0.0.1:8080/api/v1/customers
curl -i -X POST -H 'Content-Type: text/plain' -d 'x' http://127.0.0.1:8080/api/v1/customers   # 415
php bin/console route:list
```
