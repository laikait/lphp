# Storing data

How to keep application data: connecting a database, creating tables, writing
models and repositories, reading only what a screen needs, loading related data
without N+1 queries, changing many rows at once, and transactions.

The layers are separate on purpose, and each has its own reference page:
[Models](../reference/models.md) (domain objects),
[Schemas](../reference/schemas.md) (the shape of data crossing a boundary),
[Repositories and queries](../reference/data.md) (reading and writing), and
[The database](../reference/database.md) (connections and SQL).

## Connect a database

With nothing configured, every repository runs on `ArraySource`: real queries
against memory that lasts one request. Good for tests; nothing survives.

For one database, set three variables — in the real environment, or in `.env`
on a development machine:

```bash
DB_DSN="mysql:host=127.0.0.1;port=3306;dbname=erp;charset=utf8mb4"
DB_USERNAME=erp
DB_PASSWORD=secret
```

Any PDO driver works: `pgsql:`, `sqlite:/absolute/path/app.sqlite`, `sqlsrv:`.
For more than one connection, write `config/database.php` — see
[Configuring a connection](../reference/database.md#configuring-a-connection).

The switch from memory to database is made in one place,
`modules/Shared/module.php`, which binds `DataSource`. Repositories never know
which they have.

## Create the tables

**There are no migrations yet.** Nothing creates tables for you, and there is no
migration runner to register them with. Until there is, keep each module's
schema as SQL in the module and apply it deliberately — from a deployment
script, or from a command the module declares:

```php
$this->connections->connection()->execute(
    'CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL,
        body TEXT NOT NULL,
        spam INTEGER NOT NULL DEFAULT 0
    )',
);
```

([Getting started](../getting-started.md#4-a-database-and-two-commands) builds
such a command.) Column names are the **model's constructor parameter names**,
exactly, so a camelCase parameter means a camelCase column. The session store's
table comes from `php bin/console session:table`.

## A model

```php
final class Message extends Model
{
    public function __construct(
        private ?int $id,
        private string $email,
        private string $body,
        private bool $spam = false,
    ) {}

    public function identity(): ?int
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function isSpam(): bool
    {
        return $this->spam;
    }

    public function markAsSpam(): void
    {
        $this->spam = true;
    }
}
```

- The constructor is how rows become objects, **by parameter name**, and where a
  model refuses bad state. A driver that returns `"1"` for an integer column is
  converted where the conversion is unambiguous.
- There are no setters and no `save()`. A domain method such as `markAsSpam()`
  changes state; the model tracks which properties changed, so an update writes
  only those columns.
- `identity()` is `null` until the row is stored.
- Models live in the module that owns them, never in a shared `Models/`
  directory. `modules/Shared/` holds only models more than one module needs.

## A repository

```php
final class MessageRepository extends Repository
{
    protected function model(): string
    {
        return Message::class;
    }

    protected function collection(): string
    {
        return 'messages';
    }

    /** @param array{email: string, body: string} $attributes already checked against the schema */
    public function receive(array $attributes): Message
    {
        $stored = $this->persist(new Message(null, $attributes['email'], $attributes['body']));

        return $stored instanceof Message ? $stored : throw new \LogicException('Not a Message.');
    }
}
```

Register it as a singleton in your module, `$services->singleton(MessageRepository::class)`,
and inject it where it is needed.

**The base class gives you plumbing, not an API**: `query()`, `persist()`,
`remove()`, `hydrate()`, and the bulk operations below, all `protected`. There is
no inherited `find()`, `all()` or `save()`. Every public method is named after
something the application does — `receive()`, `inbox()`, `purgeSpam()` — which
is what keeps a repository from turning into a table with methods.

`persist()` inserts a new model and returns it with its identity, or writes the
changed columns of an existing one.

## Query

Queries are built immutably and run only when a terminal method is called:

```php
$this->query()
    ->whereIs('spam', false)
    ->where('id', Operator::Gt, $after)
    ->whereIn('email', $addresses)
    ->orderByDesc('id')
    ->limit(50)
    ->get();                  // ModelCollection of Message
```

| To get | Call |
|---|---|
| full models | `get()`, `first()`, `stream()` |
| plain arrays | `rows()`, `firstRow()` |
| one column / one value | `column('email')`, `value('email')` |
| a number or a yes/no | `count()`, `exists()` |
| read models | `into()`, `firstInto()`, `pageInto()` |
| batches | `page()`, `chunk($size, $callback)` |

Criteria combine with AND; there is **no OR and no join**. A read that needs
either is a repository method over SQL — see [SQL directly](#sql-directly).

## Read cheaply

A list screen rarely needs domain objects. A **read model** declares the
fields a screen shows, and `pageInto()` selects only those columns:

```php
final class MessageSummary extends ReadModel
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
    ) {}
}
```

```php
/** One screen of the inbox: two columns, no domain objects built. */
public function inbox(int $page, int $perPage = 20): Page
{
    return $this->query()
        ->whereIs('spam', false)
        ->orderByDesc('id')
        ->pageInto(MessageSummary::class, $page, $perPage);
}
```

A `Page` has `items()`, `total`, `pages()`, `hasMore()` and `meta()` for an API
response. Read models serialise to JSON as exactly their fields.

## Related data

Relations are declared once and loaded explicitly, in a second query:

```php
// in onBoot, where RelationManager is injected
$relations->declare(Customer::class,
    Relation::one('owner', User::class, localKey: 'ownerId', foreignKey: 'id'));
```

```php
$customers = $this->customers->all();                                   // one query
$owners = $this->users->findAll($relations->keysFor($customers, 'owner')); // one more
$relations->link($customers, 'owner', $owners);                         // none

$customer->related('owner');   // the User, or null if it has none
```

Calling `related()` on something never linked **throws**, so a loop cannot
quietly issue a query per row.

## Change many rows at once

```php
/** Every spam message, in one statement. */
public function purgeSpam(): int
{
    return $this->deleteWhere($this->query()->whereIs('spam', true));
}
```

`insertMany($rows)`, `updateWhere($query, $values)` and `deleteWhere($query)`
issue as few statements as the driver allows and return the number of rows
affected. A query with no criteria is refused — write `whereNotNull('id')` to
mean "all of them" on purpose. Models already loaded are forgotten afterwards.
See [Bulk writes](../reference/data.md#bulk-writes).

## Transactions

The code that knows what belongs together opens the transaction — not each
repository method:

```php
$connection->transaction(function (Connection $db) use ($invoice): void {
    $this->invoices->store($invoice);
    $this->entries->post($invoice);
    $this->payments->record($invoice);
});
```

An exception rolls everything back and is rethrown. Nested calls become
savepoints. Get the `Connection` from an injected `ConnectionManager`:
`$connections->connection()`, or `connection('reports')` for a named one.

## SQL directly

Reports, imports and anything the query builder does not express go straight to
the connection, with bindings always separate from the SQL:

```php
$rows = $connections->connection('reports')->select(
    'SELECT customer_id, SUM(total) AS total FROM invoices WHERE issued_at >= ? GROUP BY customer_id',
    [$since],
);

$row = $connection->selectOne('SELECT * FROM staff WHERE email = ?', [$email]);   // ?array
$count = $connection->scalar('SELECT COUNT(*) FROM staff');
$connection->execute('UPDATE staff SET active = 0 WHERE id = ?', [$id]);
```

Never build a table or column name from input. Values are bound; names cannot be.

## Long-running processes

Within one process the same row loaded twice is the same object. A queue worker
handles many jobs in one process, so a job that loads models should start clean —
inject `ModelManager` and call `flush()` at the start of `handle()` when the job
reads data another job may have changed.

## Testing data code

- Repositories tested against `ArraySource` run the real queries and hydration
  with nothing to install. Boot the application with no database configured and
  seed through your own repository methods.
- For SQL, configure `sqlite::memory:` — a fresh, empty database per application:
  `$this->shippedApplication(['database' => ['connections' => ['default' => ['dsn' => 'sqlite::memory:']]]])`.

See [Testing](testing.md).
