# Authentication and authorization

```php
$routes->post('/invoices/{id}/void', [Invoices::class, 'void'])
       ->meta(['auth' => true, 'can' => 'invoice.void']);
```

```bash
php bin/console auth:access           # every capability, role and guarded route
php bin/console auth:hash 'hunter2'   # a hash, for seeding the first account
```

**The framework does not know what a user is.** There is no `User` model in
`engine/`, no users table it expects and no column it names — there is a
`UserProvider` interface with two lookups, `byId()` and `byLogin()`, plus
`describe()` for the console. An architecture test freezes that list, because a
third lookup is how an interface starts describing a schema it does not own. Everything that knows what a user actually is lives in a module; in
this repository that is `modules/Shared/Auth/AccountProvider.php`. Point it at
LDAP and nothing in `engine/` changes.

```php
$services->singleton(UserProvider::class, AccountProvider::class);
```

Until a module binds one, every request is a guest and every protected route
answers 401. That is the correct behaviour for an application with no user
store, and `security:check` says so rather than leaving it to be discovered.

## Authorization is set membership, and then it can only get smaller

This is the part the specification asked to be designed rather than copied.

| | |
|---|---|
| **Capability** | a name: `invoice.void`. A noun, not a question |
| **Permission** | a capability some module declared, with a sentence saying what it means |
| **Role** | a named set of capabilities, optionally inheriting other roles |
| **Subject** | whatever a decision is about — passed through, never interpreted |

```php
$module->access(static function (AccessCollector $access): void {
    $access->capability('invoice.void', 'Cancel an invoice that has been issued.');
    $access->role('accountant', ['invoice.*'], ['clerk']);
});
```

A check asks whether the identity's flattened grants cover the capability. **No
closure runs.** `invoice.*` covers `invoice.void` and deliberately does not
cover `invoices.void` — prefix matching without the separator is how a new
capability quietly falls inside somebody's old role.

**Why this is not a Gate.** Laravel's Gate is a registry of closures keyed by
ability strings: the closure *is* the decision, it can say yes to anything, and
finding out what the application can express means reading every one of them.
Policies are the same thing discovered by class-name convention. Both make "who
may do what" a question you can only answer by running the code. Here grants are
data, `auth:access` prints all of it, and an architecture test fails if
`Authorizer` ever gains a method that takes a callback.

## The one thing a set cannot answer

"May this user edit *this* invoice" is a fact about data, not about the user. So
a module may narrow a decision:

```php
$module->filter('authorization.decision', static function (
    bool $allowed, Capability $capability, Identity $identity, mixed $subject,
): bool {
    return $subject instanceof Invoice && $subject->ownerId !== $identity->id ? false : $allowed;
});
```

**A filter may refuse and may not grant.** That asymmetry is the whole design.
Granting stays declarative and greppable; refusing stays contextual, and lives
in the module that owns the data. A filter that could also grant would be a Gate
with extra steps — the same "some closure somewhere says yes" that makes an
access model unauditable. The chain is not even run for a decision it could not
change, and anything other than an explicit `true` is a refusal, so a listener
that returns nothing fails closed.

## A route asking for a capability nobody declared will not boot

```
Nothing declares the capability "invoice.viod", required by route POST /invoices/{id}/void.
```

Without this, that route refuses **everybody** — including the administrator
holding every role — and looks like a routing fault or a broken login, because
the one thing it never says is that the capability does not exist. The check
runs after every module has registered, since the module that enforces a
capability may well register after the one that routes to it.

## Requiring a login is opt-in, and CSRF is not

The two defaults point in opposite directions and the reasoning is not
inconsistent. CSRF protects a route's side effects and costs a correct client
nothing, so defaulting it on is free. A login defaults every page to private,
including the home page — so it gets switched off wholesale on the first day,
and a default everybody disables protects nothing while looking like it does.

What replaces it is visibility rather than hope:

```
METHOD  PATH       NAME         MODULE  ACCESS     HANDLER
GET     /users     users.index  shared  user.list  Closure
POST    /login     auth.login   shared  public     ...
```

`route:list` has an ACCESS column, `auth:access` lists which routes check what,
and **`security:check` warns about routes that change something and require
nobody**. Some of those are meant to be open — a login form has to be — and the
point is that you can see them.

`can` implies `auth`: a capability check on a guest is a login prompt, and
writing both would be a chance to write only one.

## 401 and 403 are different answers

**401 means "say who you are"; 403 means "I know who you are and the answer is
still no."** Retrying a 401 with credentials may work; retrying a 403 with the
same ones never will, and a client that cannot tell them apart retries for ever.
Every 401 carries `WWW-Authenticate: Bearer`, which RFC 9110 requires. Basic is
deliberately not offered — it makes browsers show a dialog the application
cannot style, cancel or explain.

## Logging in

```php
$identity = $auth->attempt($username, new Secret($password));
```

**Every failure is the same failure.** No such account, wrong password,
suspended — one answer, and the caller must not explain which. "No account with
that email" is a way to find out which addresses are registered, and the usual
next step is to try that address on other sites. A missing account still costs a
password hash, because a wrong username answering in microseconds and a wrong
password answering in fifty milliseconds is the same disclosure by another
route.

**`attempt()` regenerates the session id**, which is the reason
`Session::regenerate()` exists. An attacker who planted a session id in the
victim's browser before the login holds one that stopped meaning anything the
moment it succeeded. The CSRF token rotates with it.

`logout()` invalidates the session rather than forgetting a key: the id changes
and the data goes with it, so a basket, a half-finished form or the previous
user's filters do not survive for whoever sits down at that machine next.

**The session holds an id, never an Identity.** Roles are re-read on every
request, so revoking one takes effect on the next click rather than the next
login, and suspending an account ends its session immediately. The cost is one
provider lookup per authenticated request, which is why `byId()` is the method
worth making fast.

The key holding that id is **reserved**: keys beginning with `_` cannot be
written through `Session::set()`. Otherwise any path that puts a user-supplied
key into the session — a `fill()` over request input is the obvious one — would
be a way to log in as anybody.

## Bearer tokens, and the header Apache eats

```
Authorization: Bearer <token>
```

Registered only when the provider implements `TokenProvider`, so an application
without tokens does not carry a listener that can never succeed. A token beats a
session when both are present: a token was put on the request deliberately, a
cookie was attached by the browser on its own.

**This is why an API may opt out of CSRF.** A bearer token is not an ambient
credential, so another site cannot make a request that carries it. An API
authenticated by a *session cookie* needs CSRF exactly as much as a form does.

Tokens are looked up by **fingerprint, not by value** — `TokenAuthenticator::
fingerprint()` is SHA-256, because a table of usable tokens is a password table
that skipped the last thirty years. A fast hash is right here and a slow one
would be wrong: 32 random bytes are not guessable offline, and bcrypt on every
API request would make a read endpoint slower than the query behind it.

**Apache receives `Authorization` and does not pass it on.** It is absent from
`$_SERVER` entirely unless `CGIPassAuth` is set or a rewrite copies it, so a
bearer token the client definitely sent is invisible to PHP. The failure is
silent and environment-specific: token auth passes every test, works under
`php -S`, and answers 401 to everything once deployed. The framework recovers it
from `getallheaders()` and from `REDIRECT_HTTP_AUTHORIZATION`, and `.htaccess`
sets the latter — both, because `getallheaders()` is absent under FastCGI and
the rewrite is absent from an nginx install.

## Passwords

`PASSWORD_DEFAULT`, never a named algorithm: naming bcrypt pins the application
to whatever was current when somebody typed it. A hash records which algorithm
made it, so old and new coexist without a migration, and `needsRehash()` fires
the `auth.rehash` hook at the one moment an application holds the plaintext —
somebody logging in. Writing the new hash is the application's job, because only
the provider knows where hashes live.

The plaintext arrives as a `Secret`. Not so it cannot be read, but so that
reading it says `reveal()` at the call site — and an architecture test keeps
`Auth\Password` the only place in the project that calls `password_hash()`.
