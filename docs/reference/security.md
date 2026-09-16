# Security

Security is attached by the framework at bootstrap, not by a module that could
forget. Three listeners on hooks the kernel already fires:

| | |
|---|---|
| `request.received` | the body size limit, before anything reads a body |
| `dispatch.before` | CSRF and the rate limit, with the route in hand |
| `response.instance` | the CSRF cookie and the security headers |

**This is what the ban on middleware looks like in practice**, and it is not a
workaround. A middleware stack is a pipeline every request walks whether or not
each layer has anything to say, ordered by a list somebody maintains, and the
usual failure is a layer that silently stopped running because it was registered
in the wrong place. Here the seams are named events, ordering is a priority
number, and **a listener refuses by throwing an `HttpException`** — which the
kernel already turns into a response, because that is how 404 and 405 work.

The cost, stated plainly: a listener cannot wrap the handler, so there is no
"do this after the response, in the same closure". The response filter covers
the other half, and nothing here has needed more.

```bash
php bin/console security:check      # audit this deployment; exits 1 on a problem
php bin/console security:key        # print a new APP_KEY
```

`security:check` is the command this section is really about. A security setting
is invisible when it is working and invisible when it is not, so "is HSTS on",
"is there a key", "is anything web-readable that should not be" get checked once
during setup and never again. It reports what the running process **actually
resolved to** rather than what a config file says, and exits non-zero, so it can
be a deployment step rather than something somebody remembers.

## CSRF is opt-out

```php
$routes->group('/api/v1', ..., meta: ['csrf' => false, 'rate_limit' => '60/1m']);
```

Every `POST`, `PUT`, `PATCH` and `DELETE` is checked unless its route opts out.
**The direction is the whole decision.** Opt-in means the route somebody adds in
a hurry is unprotected, and that is reliably the one that matters.

Opting out is for an API authenticated by a bearer token, which has no ambient
credential to abuse — the token has to be put on the request by whoever makes
it, and another site cannot do that. Note what does *not* imply it: an API
authenticated by a **session cookie** needs CSRF exactly as much as a form does,
which is why `'api' => true` grants nothing and the exemption is written out by
hand, one group at a time. `security:check` lists every route that took it.

Two independent checks, either of which fails the request. A **token** in a
cookie that must be echoed back in `_token` or `X-CSRF-TOKEN` — same-origin
policy stops another site reading the cookie, so it cannot echo it — and the
**`Origin` header**, which browsers send on unsafe cross-origin requests and
script cannot forge. Origin is checked only when present; absent means "cannot
tell", because proxies and privacy tools strip it and treating that as an attack
produces false rejections. `Referer` is not checked at all, for the same reason
more so.

`check()` returns **a reason, not a bool**. "CSRF token mismatch" is one of the
least useful errors a framework produces: the form is missing a field, cookies
are being dropped, the page is older than the cookie, or the request really is
cross-site — four completely different fixes behind one message.

An existing valid token is reused rather than rotated, because two tabs open on
the same site is ordinary browsing and rotating would show the second one a
security error for it.

**What this does not do.** It does not survive XSS: script on your own page can
read the cookie like your own page can. It is the layer underneath SameSite
cookies, not a replacement for them. And a token is bound to a browser rather
than to a login. Sessions close half of that: regenerating the session id
**rotates the token**, so a token cannot outlive the identity it was issued
under, and logging in regenerates it. Binding a token to one particular session,
so that one user's token cannot be presented by another, is **not built** — see
[What is not built](../what-is-not-built.md).

## APP_KEY, and what an application without one still gets

```bash
APP_KEY=$(php bin/console security:key --bare)
```

With a key, CSRF tokens are signed, so a sibling subdomain — or anyone able to
set a cookie over plain HTTP — cannot plant a matching cookie and field. Without
one, plain double-submit still works and still refuses cross-site requests; what
is lost is that one guarantee. **The framework says which mode it is in** rather
than implying the stronger one, and an application boots either way.

`security:key` **prints and does not write**. Every other framework's equivalent
edits `.env`, and that convenience is exactly wrong here: silently replacing a
live key invalidates every token and session the application has issued. A
command that cannot do that by accident is worth one copy and paste.

`Signer` is the only place an HMAC is computed, and an architecture test keeps it
that way — a second implementation is a second chance to compare the result with
`===`, which returns as soon as two bytes differ and so leaks how many leading
bytes were right. Signatures are bound to a **purpose**, so a CSRF token does not
verify as a signed URL.

## Secrets do not leak when somebody is debugging

```php
$key = new Secret($raw);

echo $key;                      // [redacted]
var_dump($key);                 // [redacted]
json_encode(['key' => $key]);   // {"key":"[redacted]"}
serialize($key);                // throws
$key->reveal();                 // the actual bytes
```

The problem is not storage; it is the moment after. A key in a plain string is
one `var_dump($config)` from a screenshot in a ticket, one `"bad key: $key"` from
a log aggregator, one `json_encode($settings)` from a debug endpoint. None of
those is a decision anybody made.

**`reveal()` is the only way out, and that is the design** — every place that
needs the value says so in one conspicuous word, so `grep -rn 'reveal()'` is a
complete list of where secrets are used. An architecture test refuses a second
accessor.

Serialising throws, because a secret inside a queued job or a cached value is a
secret written somewhere it was never meant to be, usually because a closure
captured it.

## Rate limiting

```php
meta(['rate_limit' => '60/1m'])   // also 5/15m, 1000/1h, 10/1d
```

**The key is the application's decision.** Per IP protects against one machine;
per username protects one account from every machine; per token protects a
quota. Those are different threats, and a framework that picked one would be
wrong for the other two. What the framework supplies is the place to put the
answer and a sensible default scoping — route plus client, so a limit on the
login form does not also stop the same office reading the catalogue.

Counts live in `CounterStore`, **not in the cache**, and the distinction is not
pedantry. A cache is allowed to forget — that is its contract — and a store that
may drop an entry is a limiter that may forget how many login attempts have been
made. More decisively, a counter must be incremented **atomically**, and
`get`/`set` cannot do that: two requests arriving together both read 5, both
write 6, and the limit is off by exactly as much as the traffic it exists to
stop. The file store holds a `flock()` across the read and the write, which is
the one place in this framework that needed a lock rather than a single atomic
syscall.

**Memory counters are no limit at all** — a web request is a process that ends —
and `security:check` calls that configuration a failure rather than a warning.

The window is **fixed, not sliding**, and this page says so because the
consequence is real: a client can spend its whole allowance at the end of one
window and the whole of the next at the start. A sliding log stores every
timestamp and a sliding counter needs two windows read atomically; for "five
login attempts" the boundary burst is not what matters, and claiming a precision
this does not have would be worse.

A refused attempt is **still counted**, or a client that keeps hammering would
start fresh the instant the window ends — rewarding the behaviour the limit
exists to discourage. `RateLimit::headers()` returns `RateLimit-*` and, only on a
refusal, `Retry-After`: sending it on an allowed request has been known to make
well-behaved clients wait.

## Request size, and the failure that looks like a bug

Bodies over `security.max_request_bytes` are refused with 413 before anything
reads them — unbounded bodies are how one client exhausts a server's memory.

The second check is the one worth having. **When an upload exceeds
`post_max_size`, PHP does not fail**: it hands the script an empty `$_POST` and
an empty `$_FILES` with `Content-Length` still describing what was sent. The
handler reports "name is required", the user swears the field was filled in, and
the cause is an ini setting nobody has looked at. The symptom is
indistinguishable from an application bug, so the framework detects the shape —
a form body, a length over the ini limit, and no fields at all — and says what
really happened.

## Uploads

```php
$problems = UploadPolicy::images()->check($request->file('avatar'));

if ($problems === []) {
    $path = UploadPolicy::images()->store($request->file('avatar'), $directory);
}
```

**Nothing is automatic.** A framework cannot know that this endpoint takes
avatars and that one takes CSVs, so a global policy is either wrong for one of
them or not a policy. `check()` returns **every** problem rather than the first,
because a user who fixes the size and is then told about the type has uploaded
twice for one answer the server already had.

An **allowlist, never a blocklist** — a blocklist has to enumerate `.php`,
`.phtml`, `.phar`, `.htaccess` and whatever the next server module adds.
**Exactly one extension**, because `avatar.php.jpg` is a `.jpg` to an extension
check and a script to an Apache with an old `AddHandler` line. **Contents
checked against the name** with `finfo`, since the client's `Content-Type` is
whatever the client said. The **stored name is generated**, never the client's:
every rule for making an attacker-controlled name safe is a rule that can be got
subtly wrong.

Path stripping happens one layer down, in `UploadedFile::clientName()`, and
`UploadPolicy` deliberately does not repeat it — a check that can never fire is
worse than none, because it reads as though this class were what stands between
`../../etc/passwd` and the filesystem.

## Headers, and the two that are off on purpose

`X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`,
`Referrer-Policy: strict-origin-when-cross-origin` and
`Cross-Origin-Opener-Policy: same-origin` go on every response, error pages
included — the ones most likely to be reached by somebody probing, and the ones a
per-route mechanism would miss. **An existing header is never overwritten**: a
handler that set its own policy has thought about it harder than a default can,
and the asset server's deliberately strict CSP would otherwise be loosened.

**Content-Security-Policy is off by default**, and that is a refusal rather than
an oversight. A useful policy names this application's own script and style
sources; a generic one is either so loose it permits what it exists to stop or so
strict it breaks the first page with an inline handler — and the one that gets
switched off in a hurry is worse than the one never claimed. `security:check`
says out loud that there is not one.

**HSTS is off by default and only ever sent over HTTPS.** It is the one header
here that cannot be taken back: a browser that has seen it refuses plain HTTP for
the whole `max-age`, so sending it from a development machine breaks every other
project on that hostname.

## What was already true

Four of the specification's twelve concerns were structural before this phase and
are now enforced rather than merely intended:

| | |
|---|---|
| SQL injection | the grammar is the only thing that builds a statement |
| Path traversal | `AssetResolver` is the only thing that turns a path into a file |
| XSS | `Escaper` escapes by context; Twig autoescapes |
| Directory access | `.htaccess`, the dev router and `nginx:make` route the same directories to the front controller, compared by a test |

That last one gained a member this phase: the development router itself. It is a
PHP file whose name does not end in `.php`, so a real web server will not execute
it and hands over the source instead — which is a map of the deny list to anyone
who asks for it. Both lists now name it.
