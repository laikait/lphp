# Security policy

## Reporting a vulnerability

**Please do not open a public issue for a security problem.**

Report it privately through GitHub:
[Report a vulnerability](https://github.com/laikait/lphp/security/advisories/new)
(the repository's **Security** tab, then **Report a vulnerability**).

Include what you can of:

- the version (`php laika about` prints it) and how the application is run (the
  built-in server, Apache, nginx, PHP-FPM);
- what the problem is and what an attacker gains;
- the smallest set of steps or requests that shows it;
- whether it needs a login, a particular configuration, or debug mode.

A report you are unsure about is still welcome.

## What happens next

The report is read, reproduced and answered in the advisory thread. A confirmed
issue is fixed in a release, the fix is described in
[`CHANGELOG.md`](CHANGELOG.md) and, where upgrading needs an action, in
[`UPGRADING.md`](UPGRADING.md), and the reporter is credited unless they would
rather not be. Please keep the details private until that release is out.

## Supported versions

Security fixes are made to the **latest 3.x release**. Upgrade to it before
reporting, if you can; it may already be fixed.

## Scope

In scope: the framework in `engine/`, the shipped `modules/Shared`, the console
commands, the default configuration and the bundled files the framework serves.

Out of scope:

- modules and applications built on the framework, which are their authors' code;
- third-party packages under `vendor/` and the bundled Swagger UI
  ([`THIRD_PARTY_NOTICES.md`](THIRD_PARTY_NOTICES.md)): report those upstream, and
  tell us if the framework's use of one is what makes it exploitable;
- `php laika serve` / `composer serve`, which are for development and say so;
- findings that need `APP_DEBUG` on in production, which the framework refuses to
  recommend (`php laika security:check` flags it).

## Dependencies

`composer audit` checks the installed Composer packages against known advisories. CI runs it on
every push and pull request and once a week, and Dependabot proposes updates. You
can run it yourself with `composer audit`.

## Hardening an installation

What the framework protects by default, and what is left to the application, is in
[Security](docs/reference/security.md) and
[Deployment and security](docs/operations/deployment.md). `php laika security:check`
reports a configuration that weakens either.
