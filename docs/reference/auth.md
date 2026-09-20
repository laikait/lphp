# Authentication and authorization

Two different questions:

- **Authentication** — who is making this request? The answer is an `Identity`.
- **Authorization** — may they do this? The answer is yes or no.

This page covers both. To build a working login from nothing, follow
[Users and permissions](../guides/users-and-permissions.md) first; this page
explains the pieces it uses.

## Protect a route

```php
$routes->post('/invoices/{id}/void', [Invoices::class, 'void'])
       ->meta(['auth' => true, 'can' => 'invoice.void']);
```

- `'auth' => true` — somebody must be logged in.
- `'can' => 'invoice.void'` — and they must hold that capability.

`can` implies `auth`, so writing both is optional. Two commands show you the
result:

```bash
php laika auth:access           # every capability, role and guarded route
php laika auth:hash 'hunter2'   # a hash, for seeding the first account
```

## The framework does not know what a user is

There is no `User` model in `engine/`, no users table it expects, and no column
it names. Instead there is an interface with three methods:

```php
interface UserProvider
{
    public function describe(): string;      // a line for `php laika about`
    public function byId(string $id): ?Account;
    public function byLogin(string $login): ?Account;
}
```

Your module writes a class implementing it and binds it:

```php
$services->singleton(UserProvider::class, StaffProvider::class);
```

Everything that knows what a user *is* lives in that module. Point it at LDAP
and nothing in `engine/` changes.

An architecture test freezes that list of three methods, because a fourth lookup
is how an interface starts describing a database schema it does not own.

**A fresh installation binds no provider**: there are no demo accounts and no
login route. Until a module binds one, every request is a guest and every
protected route answers 401. That is the right behaviour for an application with
no user store, and `php laika security:check` says so rather than leaving you to
discover it.

## Capabilities and roles

| Word | Means |
|---|---|
| **Capability** | a name, such as `invoice.void`. A noun, not a question |
| **Permission** | a capability some module declared, with a sentence saying what it means |
| **Role** | a named set of capabilities, which may inherit other roles |
| **Subject** | whatever the decision is about — passed through, never interpreted |

Declare them in `module.php`:

```php
$module->access(static function (AccessCollector $access): void {
    $access->capability('invoice.void', 'Cancel an invoice that has been issued.');
    $access->role('accountant', ['invoice.*'], ['clerk']);
});
```

That role holds every `invoice.` capability, plus everything the `clerk` role
holds.

A check asks one question: do this identity's roles, flattened out, cover the
capability? **No closure runs.** `invoice.*` covers `invoice.void` and
deliberately does **not** cover `invoices.void` — matching a prefix without the
dot is how a new capability quietly falls inside somebody's old role.

An identity carries its roles, not its capabilities:

```php
$auth->check();                                   // is anybody logged in?
$auth->identity();                                // the Identity (guest if nobody)
$authorizer->allows($identity, 'invoice.void');   // true or false
$authorizer->authorize($identity, 'invoice.void'); // or throws
```

## Narrowing a decision for one record

A set of capabilities cannot answer "may this user edit *this* invoice", because
that is a fact about the data, not about the user. A module may narrow the
decision with a filter:

```php
$module->filter('authorization.decision', static function (
    bool $allowed, Capability $capability, Identity $identity, mixed $subject,
): bool {
    return $subject instanceof Invoice && $subject->ownerId !== $identity->id ? false : $allowed;
});
```

**A filter may refuse. It may not grant.** If the set of capabilities already
said no, the filter is not even run. Anything other than an explicit `true` is
a refusal, so a listener that forgets its `return` fails closed.

## A missing capability stops the boot

```
Nothing declares the capability "invoice.viod", required by route POST /invoices/{id}/void.
```

Without this check the route would refuse **everybody**, including an
administrator holding every role, and would look like a routing fault or a
broken login. The one thing it would never say is that the capability does not
exist.

The check runs after every module has registered, because the module that
declares a capability may well load after the module that routes to it.

## 401 and 403 are different answers

**401 means "say who you are". 403 means "I know who you are, and the answer is
still no."**

Retrying a 401 with credentials may work; retrying a 403 with the same ones
never will. A client that cannot tell them apart retries for ever.

Every 401 carries `WWW-Authenticate: Bearer`, which RFC 9110 requires. Basic
authentication is deliberately not offered: it makes the browser show a login
box your application cannot style, cancel or explain.

## Logging in

```php
$identity = $auth->attempt($username, new Secret($password));

if ($identity === null) {
    // wrong credentials — say only that
}
```

**Every failure is the same failure.** No such account, wrong password,
suspended account — one answer, and your code must not explain which. "No
account with that email" tells a stranger which addresses are registered, and
the usual next step is to try that address on other sites. A missing account
still costs a password hash, because a wrong username answering in microseconds
and a wrong password answering in fifty milliseconds is the same disclosure by
another route.

Three things happen on a successful `attempt()`:

1. **The session id is regenerated.** An attacker who planted a session id in
   the victim's browser before the login holds one that stopped meaning
   anything the moment it succeeded.
2. **The CSRF token rotates with it.**
3. **The session stores the user's id — never the `Identity` itself.**

`logout()` invalidates the whole session rather than forgetting one key: the id
changes and the data goes with it, so a basket, a half-finished form or the
previous user's filters do not survive for whoever sits down at that machine
next.

Storing only an id means roles are re-read on every request. Revoking a role
takes effect on the next click rather than the next login, and suspending an
account ends its session immediately. The cost is one `byId()` lookup per
authenticated request, which is the method worth making fast.

The session key holding that id is **reserved**: keys beginning with `_` cannot
be written through `Session::set()`. Otherwise any code that puts a user-supplied
key into the session — a `fill()` over request input is the obvious one — would
be a way to log in as anybody.

## API tokens

```
Authorization: Bearer <token>
```

Token authentication is registered only when your provider also implements
`TokenProvider`, which adds one method:

```php
public function byToken(string $token): ?Account;
```

An application without tokens therefore does not carry a listener that can never
succeed.

**A token beats a session when both are present.** A token was put on the
request deliberately; a cookie was attached by the browser on its own.

That difference is also why an API authenticated by tokens may opt out of CSRF
protection: another site cannot make a request that carries your token. An API
authenticated by a *session cookie* needs CSRF exactly as much as a form does.

**Store a fingerprint, not the token.** `TokenAuthenticator::fingerprint()` is
SHA-256, and tokens are looked up by that. A table of usable tokens is a
password table that skipped the last thirty years. A fast hash is right here and
a slow one would be wrong: 32 random bytes are not guessable offline, and bcrypt
on every API request would make a read endpoint slower than the query behind it.

### Apache eats the `Authorization` header

Apache receives `Authorization` and does not pass it to PHP. It is absent from
`$_SERVER` entirely unless `CGIPassAuth` is set or a rewrite copies it.

The failure is silent and specific to the environment: token authentication
passes every test, works under `php -S`, and answers 401 to everything once
deployed. The framework recovers the header from `getallheaders()` and from
`REDIRECT_HTTP_AUTHORIZATION`, and the shipped `.htaccess` sets the latter. Both,
because `getallheaders()` does not exist under FastCGI and the rewrite does not
exist on nginx.

## Passwords

Hashing uses `PASSWORD_DEFAULT`, never a named algorithm. Naming bcrypt pins the
application to whatever was current when somebody typed it.

A PHP hash records which algorithm made it, so old and new hashes coexist with
no migration. When `password_needs_rehash()` says a hash is out of date, the
`auth.rehash` hook fires — at the one moment the application holds the plaintext,
which is somebody logging in. Writing the new hash is your module's job, because
only your provider knows where hashes live.

The plaintext arrives as a `Secret`. Not so that it cannot be read, but so that
reading it says `reveal()` at the call site. An architecture test keeps
`Auth\Password` the only place in the project that calls `password_hash()`.

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| Every protected route answers 401 | No module binds a `UserProvider` | Bind one; see [Users and permissions](../guides/users-and-permissions.md) |
| The boot fails naming a capability | A route requires a capability nobody declared — usually a typo | Declare it with `$module->access()`, or fix the name |
| A user has the role but is still refused | An `authorization.decision` filter refused for that record | The filter is in the module that owns the data |
| Tokens work locally, 401 in production | Apache is dropping `Authorization` | Keep the shipped `.htaccess`, or set `CGIPassAuth On` |
| Everyone is refused after adding a role | `invoice.*` does not cover `invoices.void` | Check the capability names with `php laika auth:access` |

## Why it works this way

### Why this is not a Gate

Laravel's Gate is a registry of closures keyed by ability strings: the closure
*is* the decision, it can say yes to anything, and finding out what the
application can express means reading every one of them. Policies are the same
thing, discovered by class-name convention. Both make "who may do what" a
question you can answer only by running the code.

Here, grants are data. `php laika auth:access` prints all of it, and an
architecture test fails if `Authorizer` ever gains a method that takes a
callback.

The filter above is the one contextual escape, and it is deliberately one-way.
Granting stays declarative and greppable; refusing stays contextual and lives in
the module that owns the data. A filter that could also grant would be a Gate
with extra steps.

### Why requiring a login is opt-in, when CSRF is on by default

The two defaults point in opposite directions, and the reasoning is not
inconsistent.

CSRF protection guards a route's side effects and costs a correct client
nothing, so having it on by default is free. Requiring a login by default would
make every page private, including the home page — so it would be switched off
wholesale on the first day, and a default that everybody disables protects
nothing while looking like it does.

What replaces it is visibility:

```
METHOD  PATH       NAME         MODULE         ACCESS      HANDLER
GET     /staff     staff.index  plugins/Staff  staff.list  ...
POST    /login     auth.login   plugins/Staff  public      ...
```

`php laika route:list` has an ACCESS column, `auth:access` lists which routes
check what, and **`security:check` warns about routes that change something and
require nobody**. Some of those are meant to be open — a login form has to be —
and the point is that you can see them.
