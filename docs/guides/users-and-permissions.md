# Users and permissions

How to connect the framework to your accounts, let people log in, protect
routes, decide who may do what, and give API clients tokens.

The framework does not know what a user is — there is no users table it expects
and no `User` model in `engine/`. It asks one question of your code, through an
interface with two lookups, and handles sessions, passwords, tokens and
permission checks around the answer. Reference:
[Authentication and authorization](../reference/auth.md).

## Before anything else: the shared module's demo accounts

`modules/Shared/Auth/AccountProvider.php` is a **demonstration**. It knows two
accounts, `ada` and `grace`, whose password is `secret` and which exist whenever
no database is configured; `ada` is an administrator, and a fixed bearer token
logs in as her. The shared module also declares `POST /login`, `POST /logout`,
`GET /me` and `GET /users`.

**Replace the provider before anything is deployed where others can reach it.**
The rest of this page is how.

## Connect your accounts

Implement `UserProvider` — or `TokenProvider`, which adds API tokens. This one
reads a `staff` table:

```sql
CREATE TABLE staff (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    roles TEXT NOT NULL DEFAULT '',
    active INTEGER NOT NULL DEFAULT 1,
    token_fingerprint TEXT NULL UNIQUE
);
```

```php
final class StaffProvider implements TokenProvider
{
    private const COLUMNS = 'id, email, password_hash, roles, active';

    public function __construct(private readonly ConnectionManager $connections) {}

    public function describe(): string
    {
        return 'staff accounts, from the staff table';
    }

    public function byId(string $id): ?Account
    {
        return $this->account($this->connections->connection()->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM staff WHERE id = ?',
            [$id],
        ));
    }

    public function byLogin(string $login): ?Account
    {
        return $this->account($this->connections->connection()->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM staff WHERE email = ?',
            [$login],
        ));
    }

    public function byToken(string $token): ?Account
    {
        return $this->account($this->connections->connection()->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM staff WHERE token_fingerprint = ?',
            [TokenAuthenticator::fingerprint($token)],
        ));
    }

    /** @param array<string, mixed>|null $row */
    private function account(?array $row): ?Account
    {
        if ($row === null) {
            return null;
        }

        return new Account(
            new Identity(
                (string) $row['id'],
                (string) $row['email'],
                \array_values(\array_filter(\explode(',', (string) $row['roles']))),
            ),
            (string) $row['password_hash'],
            (bool) $row['active'],
        );
    }
}
```

Bind it in your module:

```php
$module->services(static function (ServiceRegistrar $services): void {
    $services->singleton(UserProvider::class, StaffProvider::class);
});
```

Plugins register after `shared`, so this binding replaces the demo provider.
`php bin/console security:check` shows which provider is in use. (Leaving
`shared`'s own line in place is harmless; deleting it and its demo
`AccountProvider` is tidier.)

What the pieces mean:

- **`Identity`** is who someone is for this request: an id, a display name, a
  list of role names, and any attributes you want to carry.
- **`Account`** adds what only login needs: the password hash, and whether the
  account may log in at all. An inactive account behaves exactly like a wrong
  password.
- **`byId()`** runs on every authenticated request, because roles are re-read
  each time — that is what makes revoking a role or suspending an account take
  effect on the next click. Make it fast.

## Passwords

Hash a password for a first account or a seed script:

```bash
php bin/console auth:hash 'correct horse battery staple'
```

In code, inject `Password` and call `hash(new Secret($plain))`. Never choose an
algorithm: hashes record which one made them, so old and new coexist. When the
default algorithm changes, `auth.rehash` fires at the next successful login with
the identity and the new hash — store it:

```php
$module->hook('auth.rehash', [StaffPasswords::class, 'store']);   // (Identity $identity, string $hash)
```

## Log in and out

The shared module's endpoints work with any provider:

| | |
|---|---|
| `POST /login` | form or JSON fields `username` and `password`. 200 with the identity, or 401. Rate limited to 5 a minute |
| `POST /logout` | ends the session |
| `GET /me` | who is logged in, with roles and capabilities |

`/login` is CSRF-protected like every other form — send the `_token` field or
`X-CSRF-TOKEN` header (see [Pages and forms](pages-and-forms.md#a-form)).

To write your own login — a page that redirects, a second factor — inject
`AuthManager`:

```php
$identity = $auth->attempt($email, new Secret($password));   // ?Identity

if ($identity === null) {
    // Say only that the details are wrong. Never which half.
}

$auth->logout();
```

`attempt()` changes the session id on success, which defeats session fixation,
and costs the same time whether the account exists or not.

## Protect routes

```php
$routes->get('/me/settings', MySettings::class)->meta(['auth' => true]);
$routes->post('/invoices/{id}/void', [Invoices::class, 'void'])->meta(['can' => 'invoice.void']);

$routes->group('/admin', static function (RouteCollector $routes): void {
    // ...
}, meta: ['can' => 'admin.access']);
```

- `auth` requires someone to be logged in.
- `can` requires a capability, and implies `auth`.
- A guest gets **401**; a logged-in account without the capability gets **403**.
- Nothing is private by default. `route:list` has an ACCESS column, and
  `security:check` warns about routes that change data and require nobody.

A handler can ask for the identity like any other parameter:

```php
public function __invoke(Identity $identity): array
{
    return ['hello' => $identity->name];
}
```

## Declare capabilities and roles

The module that enforces a capability declares it:

```php
$module->access(static function (AccessCollector $access): void {
    $access->capability('message.read', 'Read what visitors sent through the contact form.');
    $access->role('support', ['message.*'], ['member']);
});
```

- A **capability** is a name for one thing that can be done: `invoice.void`.
  Routes name capabilities, never roles.
- A **role** is a named set of capabilities, and may inherit other roles.
  `message.*` covers `message.read` but not `messages.read`.
- A route that asks for a capability **nobody declared** stops the application
  from starting, naming the route — a typo cannot silently lock everyone out.

```bash
php bin/console auth:access    # every capability, every role, which routes check what
```

## Check in code

```php
public function __construct(private readonly Authorizer $authorizer) {}

$this->authorizer->allows($identity, 'invoice.void', $invoice);     // bool
$this->authorizer->authorize($identity, 'invoice.void', $invoice);  // or 401 / 403
```

## Rules about a particular record

"May this person edit *this* invoice" depends on the invoice. A module may
**narrow** any decision with a filter — never widen it:

```php
$module->filter('authorization.decision', static function (
    bool $allowed, Capability $capability, Identity $identity, mixed $subject,
): bool {
    return $subject instanceof Invoice && $subject->ownerId !== $identity->id ? false : $allowed;
});
```

Anything but `true` is a refusal, so a listener that forgets to return fails
closed. The filter only sees records passed as the `$subject` of `allows()` or
`authorize()`; a route's `can` check has no record to give it.

## API clients: bearer tokens

With a `TokenProvider` bound, a request carrying

```
Authorization: Bearer <token>
```

is authenticated by `byToken()`. Store only the **fingerprint**,
`TokenAuthenticator::fingerprint($token)`, never the token. Generate tokens with
at least 32 random bytes — `bin2hex(random_bytes(32))` — and show them once.

A token is not something another website can make a browser send, so an API
used **only** with tokens may opt out of CSRF:

```php
$routes->group('/api/v1', static function (RouteCollector $routes): void {
    // ...
}, name: 'api.v1.', meta: ['api' => true, 'csrf' => false]);
```

An API that also accepts the session cookie must keep CSRF on.

**Behind Apache**, check that the header arrives: Apache drops `Authorization`
unless told otherwise. The shipped `.htaccess` passes it on; a custom virtual host
must too.

## Test it

The flow above — a staff table in `sqlite::memory:`, logging in through
`/login`, then reading a guarded route by session and by token — fits in one
test. See [Testing](testing.md#forms-cookies-and-csrf).
