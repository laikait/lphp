# Documentation

Pick the part that matches what you are doing. Everything here describes version
0.1.0; what is public and how stable it is, is in
[`STABILITY.md`](../STABILITY.md).

## New to the framework

- **[Getting started](getting-started.md)** — build a small module from nothing:
  a model, a page, a JSON endpoint, commands, a filter, a hook, configuration and
  a test. About half an hour.
- **[What is not built](what-is-not-built.md)** — the deliberate omissions, so you
  do not go looking for them.

## Guides

For building an application on the framework, one task at a time.

- **[Pages and forms](guides/pages-and-forms.md)** — templates, layouts, assets,
  forms with CSRF and validation, uploads, overriding pages you did not write.
- **[JSON APIs](guides/json-apis.md)** — versioned routes, validation, one error
  shape, pagination, deprecation.
- **[Storing data](guides/storing-data.md)** — connections, tables, models,
  repositories, read models, relations, bulk writes, transactions.
- **[Background work](guides/background-work.md)** — jobs, queue workers,
  retries, scheduled tasks.
- **[Users and permissions](guides/users-and-permissions.md)** — your own
  accounts, logging in, protecting routes, capabilities and roles, API tokens.
- **[Extending other modules](guides/extending-other-modules.md)** — hooks,
  filters, cross-cutting rules without middleware, module dependencies.
- **[Testing](guides/testing.md)** — booting the application in a test, requests,
  forms, databases, jobs and commands.

## Reference

How each part works, and why it is built that way.

| Area | Pages |
|---|---|
| Structure | [Modules](reference/modules.md) · [Hooks and filters](reference/hooks-and-filters.md) · [Project layout](reference/project-layout.md) |
| HTTP | [Routing](reference/routing.md) · [REST](reference/rest.md) · [Errors](reference/errors.md) |
| Output | [Templates](reference/templates.md) · [Assets](reference/assets.md) |
| Data | [Models](reference/models.md) · [Schemas](reference/schemas.md) · [Repositories and queries](reference/data.md) · [The database](reference/database.md) |
| Runtime | [Configuration](reference/configuration.md) · [Cache](reference/cache.md) · [Logging](reference/logging.md) · [CLI](reference/console.md) |
| Background | [Queue and worker](reference/queue.md) · [Scheduler](reference/scheduler.md) |
| Security | [Security](reference/security.md) · [Sessions](reference/sessions.md) · [Authentication and authorization](reference/auth.md) |
| Operations | [Performance](reference/performance.md) · [Observability](reference/observability.md) |

## Operating an application

- **[Deployment and security](operations/deployment.md)** — web server
  configuration, what must be refused, the production boot path.
- **[Running in production](operations/running.md)** — processes, environment,
  every-deployment steps, cron, workers, `system/`, several hosts, monitoring.
- **[Troubleshooting](troubleshooting.md)** — symptoms, causes and fixes.

## Working on the framework

- **[Contributing](contributing/README.md)** — the gate, the rules engine code
  follows, and what to update alongside a change.
- **[Development](contributing/development.md)** — tooling and implementation
  status.
- **[API stability and releases](contributing/releases.md)** — what the stability
  levels promise, versioning, and the release checklist.

Also at the project root: [`CHANGELOG.md`](../CHANGELOG.md) and
[`UPGRADING.md`](../UPGRADING.md).
