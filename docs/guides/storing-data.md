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
For more than one connection, or to give `host`, `port` and `database` as
separate keys instead of a DSN, write `config/database.php` — see
[Configuring a connection](../reference/database.md#configuring-a-connection).

The switch from memory to database is made in one place,
`modules/Shared/module.php`, which binds `DataSource`. Repositories never know
which they have.

## Create the tables

Each module owns its tables, as **migrations** in its `Database/Migrations/`
directory. A migration describes the table with methods, not SQL, so the same
file creates it on MySQL, PostgreSQL, SQLite and SQL Server.
`modules/Plugins/Contact/Database/Migrations/2026_09_19_120000_create_messages.php`:

```php
<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        $tables->create('messages', static function (Table $table): void {
            $table->id();
            $table->string('email', 190)->index();
            $table->text('body');
            $table->boolean('spam')->default(false);
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('messages');
    }
};
```

```bash
php laika migrate --pretend   # this database's SQL, run nowhere
php laika migrate             # everything pending, in module order
php laika migrate:status
php laika migrate:rollback    # the last run, undone
```

- **The file name is the order:** `YYYY_MM_DD_HHMMSS_what_it_does.php`, in
  lower case, written by hand. There is no generator. A name that breaks the
  rule stops the run before anything runs.
- **Modules run in dependency order.** A table whose foreign key names another
  module's table belongs to a module that `requires()` that module, and so its
  migrations run after that module's.
- **A migration runs once.** What ran is recorded in the `migrations` table. A
  change to a table that exists is a new migration that calls
  `$tables->alter(...)`; never edit one that has run.
- **Column names are the model's constructor parameter names**, exactly, so a
  camelCase parameter means a camelCase column.

Rows a module starts with go in **seeders**, in `Database/Seeders/`. A seeder
writes through the query builder and runs every time `php laika db:seed` does,
so it looks before it inserts:

```php
return new class implements Seeder {
    public function run(Connection $db): void
    {
        if (!$db->table('messages')->where('email', 'welcome@example.test')->exists()) {
            $db->table('messages')->insert(['email' => 'welcome@example.test', 'body' => 'Hello.']);
        }
    }
};
```

Every column type, what each database is sent, and what is refused are in
[Migrations and seeders](../reference/database.md#migrations-and-seeders). With
`session.store` set to `database`, `migrate` creates the session table too.

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

Criteria combine with AND; there is **no OR and no join**, because this query
runs the same on every `DataSource`, memory included. A read that needs either
is a repository method over SQL — see [SQL directly](#sql-directly).

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

Where two requests can collide on the same rows, choose an isolation level and
let a deadlock run the transaction again:

```php
$connection->transaction($callback, isolation: IsolationLevel::Serializable, retries: 3);
```

Only deadlocks and serialization failures are retried, and every attempt starts
from a clean state. **The callback may then run more than once**, so send the
email or charge the card after `transaction()` returns, never inside it. A level
the database cannot give is refused, not approximated — see
[Isolation levels and retrying](../reference/database.md#isolation-levels-and-retrying).

To act once a transaction has committed, listen for
`database.transaction.committed`; see
[Watching statements and transactions](../reference/database.md#watching-statements-and-transactions).

## SQL directly

Reports, imports and anything `Query` does not express go to the connection.
Its query builder covers OR, joins, grouping and aggregates, with every value
bound and every name checked:

```php
final class RevenueReport
{
    public function __construct(private readonly ConnectionManager $connections) {}

    /** @return list<array<string, mixed>> revenue per region since a date */
    public function byRegion(\DateTimeImmutable $since): array
    {
        return $this->connections->connection()->table('customers AS c')
            ->select('c.region', Aggregate::sum('o.total', as: 'revenue'))
            ->join('orders AS o', 'o.customer_id', '=', 'c.id')
            ->where('o.issued_at', '>=', $since)
            ->where(fn (QueryBuilder $q) => $q->where('o.status', 'paid')->orWhere('o.status', 'settled'))
            ->groupBy('c.region')
            ->orderByDesc('revenue')
            ->get();
    }
}
```

It writes too — `insert()`, `update()`, `delete()` and, where the database has
one, `upsert()` — and refuses an `update()` or `delete()` with no `where()`. See
[The query builder](../reference/database.md#the-query-builder).

For what the builder does not express, write the SQL, with bindings always
separate from it:

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
  `$this->shippedApplication(['database' => ['connections' => ['default' => ['dsn' => 'sqlite::memory:']]]])`,
  then `$this->migrate($app)` to create the same tables production has.

See [Testing](testing.md).
