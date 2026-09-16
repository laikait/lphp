# The database

`ConnectionManager` holds the configured connections; `Connection` is one of
them. Heavy backends rarely have one database — a reporting replica, a legacy
system being migrated from, a separate ledger — so each is a name:

```php
$connections->connection('reports')->select('SELECT ...', [$id]);
```

**Connecting is lazy.** Building a `Connection` opens nothing; the PDO handle is
created on first use. A request that never reads the database never opens a
socket, which is what makes it reasonable to declare every database an
application *might* touch.

**Nothing here knows what a model is.** A `Connection` is for a report, a
migration or a one-off script as much as for a repository — enforced by an
architecture test. The data layer sits on top of it rather than being the only
way in.

```php
$connection->select('SELECT * FROM invoices WHERE customer_id = ?', [$id]);
$connection->selectOne(...);  $connection->scalar(...);  $connection->cursor(...);
$connection->execute(...);    $connection->insert(...);
```

Every method takes SQL and a **separate** list of bindings, and prepared
statements are real rather than emulated (`ATTR_EMULATE_PREPARES => false`) —
which matters twice: emulation interpolates values into the statement string,
and it hands every column back as a string. A failing statement reports the SQL
and the *number* of bound values, never the values themselves; a bound value is
the one thing in a query likely to be a password.

**Transactions are the application's boundary**, not each repository method's:

```php
$connection->transaction(function (Connection $db) use ($invoice): void {
    $invoices->store($invoice);
    $entries->post($invoice);
    $payments->record($invoice);
});
```

PDO has no nested transactions — a second `beginTransaction()` throws or is
ignored, and the first `commit()` writes everything. `begin()` uses **savepoints**
beyond the first level, so nesting means what it looks like. The caveat worth
knowing, and it is inherent to nested transactions everywhere: rolling back to a
savepoint undoes the inner work and leaves the outer transaction open, so if the
calling code swallows the exception, the outer transaction still commits with
the inner work gone. Catch deliberately or not at all.

## Running the data layer on it

`SqlSource` implements `DataSource`, so every repository, query, read model,
relation and page from [Repositories and queries](data.md) runs against a database **unchanged**.
Switching is one factory in a module:

```php
$services->singleton(DataSource::class, static function (Container $c): DataSource {
    $connections = $c->get(ConnectionManager::class);

    return $connections->isConfigured()
        ? new SqlSource($connections->connection())
        : new ArraySource();
});
```

That is the only place in the application that knows which kind of storage it
has. The framework does not bind `DataSource` itself — which source an
application reads through is its decision, not the framework's, and that is
architecture-tested too.

`SqlSource::fetch()` returns a **cursor**, so `Query::stream()` and
`Query::chunk()` genuinely hold one row at a time rather than a table.

## The injection boundary is one file wide

No database accepts a parameter where a column goes, so table and column names
are the one part of a statement built by interpolation. All of that lives in
`Grammar`, and every identifier passes one check first:

```
/^[A-Za-z_][A-Za-z0-9_]*$/
```

Anything else — a quote, a space, a dot, a comment, a semicolon, a function
call — is refused with an exception naming it. Names reach the Grammar from
repository declarations rather than from requests; the check is what keeps that
true on the day somebody passes a sort column straight from a query string. An
architecture test asserts that no other file in `engine/Database/` builds SQL at
all.

Limits and offsets are written in rather than bound: they are typed `int` in PHP
by the time they arrive, so there is nothing to inject, and binding them is the
one thing several drivers get wrong.

## Testing against a database

The database tests are real integration tests against SQLite in memory — real
PDO, real prepared statements, real savepoints — costing about a millisecond
each and needing nothing installed. `tests/Feature/SqlSourceTest.php` runs the
same repository against `ArraySource` and `SqlSource` and asserts the answers
are identical, which is the `DataSource` claim stated as a test rather than as a
paragraph.

## Configuring a connection

A single connection needs no file at all, which is what a container image
wants:

| Variable | |
|---|---|
| `DB_DSN` | the PDO DSN, e.g. `mysql:host=localhost;dbname=erp;charset=utf8mb4` |
| `DB_USERNAME` `DB_PASSWORD` | credentials, if the driver needs them |
| `DB_CONNECTION` | what to call it, and which one is the default (default: `default`) |

More than one connection, or anything with options, goes in
`config/database.php` -- where the environment is still reachable, because a
config file may call `Env` itself:

```php
// config/database.php
return [
    'default' => 'main',
    'connections' => [
        'main' => [
            'dsn' => Env::string('DB_DSN', 'mysql:host=db;dbname=erp'),
            'username' => Env::string('DB_USERNAME'),
            'password' => Env::string('DB_PASSWORD'),
        ],
        'reports' => ['dsn' => 'pgsql:host=replica;dbname=warehouse', 'username' => 'reader'],
    ],
];
```

An embedding application can still pass connections to `Bootstrap::create()`
directly; those outrank the file.

With nothing configured the application boots exactly as before and opens
nothing; the shared module's `DataSource` factory falls back to `ArraySource`.
