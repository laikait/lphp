# Errors

Everything that goes wrong arrives in one place and leaves as one document: a
handler's validation failure, a route that did not match, a PHP warning, an
uncaught exception, and a fatal that killed the process. What differs is the
rendering, and the rendering is chosen by **who is reading**.

```
Browser   the application's own error template, or a built-in page
Api       the JSON error document
Console   two lines, and in debug a trace
```

Production versus development is a **separate axis**. The specification lists
it alongside the three above, but it answers a different question: those decide
*to whom*, production/development decides *how much*. Keeping them apart is
what makes "this message is fine on a terminal and not in a response"
expressible at all — with one axis it is not.

## What may be said, and to whom

| | Browser | Api | Console |
|---|---|---|---|
| `HttpException` message | shown | shown | shown |
| any other framework message | withheld | withheld | **shown** |
| an application exception's message | withheld | withheld | withheld |
| class, file, line, trace | debug only | debug only | debug only |

The middle row is the one worth explaining. `Command "customer:sync" is already
registered by plugins/Example` is exactly what an operator needs and exactly
what an anonymous client should not have: it is an inventory of the
application. An operator already has the source, the configuration and the
directory listing, so withholding it from them protects nobody and costs them
an afternoon.

Which messages are the framework's own is a property of the exception, not a
check against a class list:

```php
return (new self(\sprintf('Could not open "%s": %s', $name, $previous->getMessage())))
    ->withheld();
```

`FrameworkException` messages disclose by default, because the framework wrote
them. A factory that quotes something it did not write — nearly always
`$previous->getMessage()` — calls `withheld()`, because PDO quotes the DSN back
on a connection failure and a DSN is one keystroke from a password. An
architecture test fails if a factory interpolates another exception's message
without withholding it, which is the tripwire for the one mistake here that
costs something real.

Outside debug a withheld message is replaced **wholesale** with the status
text, never filtered. Filtering means guessing which substrings are secret, and
that guess is wrong eventually.

## The application's own error page

```
templates/default/views/errors/404.twig     a lost visitor
templates/default/views/errors/error.twig   everything else
```

A template named for the status wins; `errors/error` catches the rest; with
neither, the framework's built-in page renders. A branded 404 costs one file
rather than a subsystem, and it extends the ordinary layout because it is an
ordinary template. Both are handed `error` (the `ErrorDocument`) and `home` —
the front page as the failing request addresses it, so "Back to the home page"
still leads home under Apache in a subdirectory. Neither repeats the requested
path back, so the address cannot put markup on the page.

Two rules keep it from making things worse. **A template that throws falls back
to the built-in page** instead of propagating — a typo in `errors/500` is
discovered the day the first 500 happens, and at that point the visitor needs a
page far more than the framework needs to be right about which one. And **the
built-in page is used whenever there are diagnostics to show**, which means in
debug mode: an error template is written for a visitor, and if it won, the day
somebody added a branded 500 page would be the day stack traces stopped
appearing. The consequence is that error templates are previewed with debug
off, which is the right way round.

The built-in page has no `<link>`, no `<script>` and no asset URL, and an
architecture test keeps it that way. It has to render when the thing that broke
is the asset pipeline.

## Logging is a listener, not a dependency

```php
$module->hook('error.reported', [Telemetry::class, 'record']);
```

Every handled error fires `error.reported` with the throwable itself — not a
formatted string, so a listener that wants the previous exception or the trace
does not have to parse them back out of a sentence — plus the `ErrorContext`
and the request. That is the seam `Logging\ErrorLog` attaches to (see
[Logging](logging.md)), and it is why there is no logger interface here to
implement.

An architecture test asserts that nothing under `engine/Error/` calls
`error_log()`, `syslog()` or `fopen()`. The moment a file handle appears there,
rendering and recording are one thing again, and changing either means touching
both.

## Taking over from PHP

`register()` installs four things:

- **`display_errors` off** in production. This is the single most
  security-relevant line in the phase. Left on, PHP writes a warning — with the
  absolute path of the file that raised it — straight into the response body,
  above the doctype, before any framework code runs and with no way to
  intercept it afterwards. "Never expose filesystem paths" is not achievable
  without it. `error_reporting` stays at `E_ALL` either way, because the handler
  still needs to see everything.
- **errors become `ErrorException`**, so they can be caught like anything else,
  while a suppressed notice stays suppressed.
- **an exception handler**, so an uncaught throwable renders instead of
  printing a trace.
- **a shutdown handler**, so a fatal produces a 500 rather than a blank 200.

That last one needs memory it will not have. `RESERVED_MEMORY` is 256KB held
from registration and released at the top of the shutdown handler, and the size
is measured rather than guessed: at 32KB the handler ran out of memory a second
time and produced **nothing at all** — building the `ErrorException` captures a
backtrace, and the document and page are strings. 64KB was enough for
production; 256KB leaves room for a debug trace on a deep stack.

The handler is also told which request is in flight:

```php
$this->container->get(ErrorHandler::class)->serving($request);
```

The one piece of mutable state in the class, and it earns its place — PHP's
exception and shutdown handlers take no arguments, so when a fatal happens
between the kernel returning and the last byte being written, this is the only
way to know whether a browser or a program is waiting. Without it an API client
receives an HTML page.
