# Sessions

```php
$routes->get('/visits', static function (Session $session): array {
    $visits = (int) $session->get('visits', 0) + 1;
    $session->set('visits', $visits);

    return ['visits' => $visits];
});
```

Type-hint `Session` and you get this request's. **There is no `$_SESSION`, no
`session_start()` and no global to reach for**, and an architecture test refuses
all of them anywhere in the project — the specification asks for this directly,
and the reasons are worth writing down. PHP's session machinery keeps the data
in a superglobal, the id in engine state and the policy in ini settings, so a
session cannot be constructed in a test, cannot be swapped for a fake, and
behaves differently depending on what the SAPI did before the script ran. It
also **holds a lock on the session for the whole request**, which is why one
slow endpoint blocks every other request from the same browser.

```bash
php bin/console session:gc       # delete what is past its lifetime
php bin/console session:table    # print the CREATE TABLE for the database store
```

## Nothing is read until something asks

A request that never mentions the session does no storage read, no storage write
and sets no cookie. That is not an optimisation. It is what keeps a JSON API
from handing session cookies to clients that will never send them back — and it
fixes a bug people usually live with, because **flash data ages only on requests
that touch the session**. A background poll no longer eats the message meant for
the next page the user opens.

## The request writes what it changed, not what it read

A browser makes several requests at once — a page and three fetches — and all of
them carry the same session. PHP's answer is to lock it for the whole request.
The usual alternative is to read at the start, write the whole array at the end,
and quietly lose whichever request finished first.

Here `Session` records a **change log**, and `SessionStore::commit()` takes a
closure that the store applies to whatever is stored *now*, under its own lock:

```php
public function commit(string $id, \Closure $apply): ?SessionRecord;
```

Two concurrent requests, one setting `cart` and one setting `locale`, both
survive. Two requests writing the *same* key still resolve to the last writer,
which is inherent — there is no answer to "both of us set it" that a session
layer can pick for you. The lock covers a read-modify-write of one record, so it
is measured in microseconds rather than in the length of a request.

`clear()` is the one operation that is not per-key: it discards whatever is
stored rather than unsetting the keys this request happened to read. Since
`invalidate()` is built on it, the alternative would be a logout that leaves
data behind.

## Regeneration, and the grace window

```php
$session->regenerate();   // same data, new id -- call this on login
$session->invalidate();   // no data, new id -- call this on logout
```

Changing the id is the defence against **session fixation**: an attacker who
planted an id in the victim's browser holds one that stopped meaning anything
the moment the user's privileges changed.

The naive version — write the new id, delete the old — breaks a page that logs
in with three fetches already in flight. Each arrives holding an id that no
longer exists, gets a brand new empty session, and **the user is logged out by
the act of logging in**. So `regenerate()` leaves the old record as a *pointer*
with no payload in it, which a request inside `session.grace` seconds follows
exactly once. Once, not until it stops pointing: a chain would let one stale id
walk through every regeneration a session ever had. `invalidate()` leaves no
pointer at all, because a logout should not be followable.

Regenerating also **rotates the CSRF token**, through the `session.regenerated`
hook rather than a dependency — the security layer still does not know that
sessions exist, and an architecture test keeps it that way. A token issued to
the anonymous page that showed the login form must not stay valid against the
session the login created.

## Two clocks

| | |
|---|---|
| `session.idle` | 2 hours. The one users feel: "it logged me out while I was at lunch" |
| `session.absolute` | off by default. A ceiling regardless of activity |

Without the second, a session that a background request keeps warm never expires
at all. It is off by default because it is the setting most often switched on
after an incident rather than before one, and a framework that forced a value
would have to pick one that is wrong for both a kiosk and an internal tool.
`security:check` says out loud that there is not one. Regenerating does **not**
reset the absolute clock.

## Flash data is ordinary data

```php
$session->flash('status', 'Saved.');   // this request and the next
$session->now('status', 'Saved.');     // this request only, stored nowhere
$session->reflash();                   // keep it all for one more
$session->keep('status');              // keep some of it
```

Flashed values are ordinary keys, plus a list of names to delete one request
later, so reading one is just `get('status')`. A separate flash bag with its own
accessors would mean every template showing a message has to know which kind of
value it is holding.

## Storage

| | |
|---|---|
| `file` | the default. JSON per session, merged under `flock()`. One machine |
| `database` | a shared table. What "distributed sessions" actually means |
| `memory` | tests and the console only |

**A session payload must be JSON-serialisable**, and the memory store encodes
exactly like the others so that "it worked in tests" means something. PHP's
sessions `serialize()`, so an object dropped into one comes back as an object —
usually a stale copy of a row that changed an hour ago, occasionally a class
that no longer exists, which is a fatal error on a page nobody touched. Keep an
id in the session and load the object from it.

**File names are SHA-256 hashes, not ids.** A session id is a live credential:
whoever holds one is the user. A directory listing, a backup manifest or an `ls`
in a support ticket should not hand over an account, so the id appears only
inside the file, which needs read permission to reach.

The database store takes a **row lock** — `SELECT ... FOR UPDATE` where the
driver has it — and **does not create its own table**. A store that issues DDL on
its first request needs permissions in production that nothing should have, and
it uses them at the worst available moment. `session:table` prints the statement
for the configured driver; where it goes is the application's business.

## Sweeping

Expiry is decided **on read**, by the timestamps, so a sweep that has not run for
a week costs disk space and not correctness. `session:gc` reclaims it:

```php
$schedules->command('session:gc')->hourly();
```

A command rather than PHP's lottery, in which roughly one request in a hundred
pays for scanning the whole directory — the cost lands on a random user, at a
random moment, and most often on the busiest sites. (Worse, Debian-derived
systems switch the lottery off and replace it with a cron job, so the behaviour
of a default installation depends on the distribution.)

## The cookie

`HttpOnly`, `SameSite=Lax`, `Secure` following the request scheme unless forced,
and it expires when the browser does. **HttpOnly is not configurable**, and an
architecture test refuses to let it become so: this cookie *is* the credential,
and a setting that exists is a setting somebody switches off at four in the
afternoon to make a widget work. Everything else here is a judgement call
somebody may reasonably need to make differently.

`Lax` rather than `Strict` because Strict makes arriving from an email link or a
search result look logged out, and the usual fix for that is to turn the whole
thing off.

**One thing to watch when injecting `Session`:** ask for it where the request is
— a closure route, a handler bound with `bind()`, a method parameter. A
*singleton* service taking `Session` in its constructor would hold the first
request's session for the life of a worker, and the symptom is users seeing each
other's data.
