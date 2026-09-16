# Development

```bash
composer check      # everything below
composer cs         # coding standard, dry run
composer cs:fix     # apply it
composer stan       # PHPStan level 8
composer test       # PHPUnit
composer bench      # benchmarks; not part of the gate -- see Performance
```

There is no coverage gate, because neither Xdebug nor PCOV is assumed to be
present. The gate is `composer check`: clean coding standard, clean level 8,
all tests green. There is deliberately **no PHPStan baseline** — a baseline
created at the start of a project becomes permanent debt.

`tests/Architecture` holds executable architecture rules: the facade ban, the
frozen helper set, the engine/module layering, the HTTP/routing separation, the
model/schema/data/database separations, the repository base publishing no API,
statement construction living only in `Grammar`, the absence of a command base
class, the `.htaccess` deny rules, only discovery probing module directories, only
`cache:warm` writing the discovery cache, every specified subject having a
benchmark, no subsystem referencing the profiler, and observability keeping
nothing of its own. These are the invariants that erode quietly, so
each one is a test rather than a paragraph nobody re-reads.

`tests/Architecture/DocumentationTest.php` does the same for the documentation's
statements of fact: every engine class has a level in `STABILITY.md` and no
module uses an Internal one, the lifecycle table matches what the engine fires,
every framework command is mentioned, every link lands somewhere, and the
version, the changelog and `composer.json` agree.

## Implementation status

| Phase | Area | State |
|---|---|---|
| 0 | Repository foundation | done |
| 1 | Bootstrap / Kernel | done |
| 2 | HTTP Request / Response | done |
| 3 | Dependency injection | done |
| 4 | Module system | done |
| 5 | Hooks | done |
| 6 | Filters | done |
| 7 | Routing | done |
| 8 | Dispatcher | done |
| 9 | Model infrastructure | done |
| 10 | Schema system | done |
| 11 | Data / Repository / Query | done |
| 12 | Database | done |
| 13 | Asset manager | done |
| 14 | Template system | done |
| 15 | REST API | done |
| 16 | CLI | done |
| 17 | Error handling | done |
| 18 | Logging | done |
| 19 | Configuration | done |
| 20 | Cache | done |
| 21 | Queue / Worker | done |
| 22 | Scheduler | done |
| 23 | Security | done |
| 24 | Session | done |
| 25 | Authentication / Authorization | done |
| 26 | Module dependency system | done |
| 27 | Performance architecture | done |
| 28 | Observability | done |
| 29 | Demo application | **deferred** by the project owner; the showcase fixture covers most of §55 in the meantime |
| 30 | Documentation / release | done |

The specification's §56 workflow ends each phase with "commit the phase". That
step is the project owner's, and nothing here has been committed on their
behalf.
