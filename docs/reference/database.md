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
beyond the first level, so nesting means what it looks like. The savepoint
statements are the grammar's: `SAVEPOINT`/`RELEASE`/`ROLLBACK TO` on MySQL,
PostgreSQL and SQLite, `SAVE TRANSACTION`/`ROLLBACK TRANSACTION` on SQL Server,
which has no release. Any other driver refuses to nest, with a sentence rather
than a syntax error.

The caveat worth knowing, and it is inherent to nested transactions everywhere:
rolling back to a savepoint undoes the inner work and leaves the outer
transaction open, so if the calling code swallows the exception, the outer
transaction still commits with the inner work gone. Catch deliberately or not at
all.

When things go wrong inside the machinery itself:

| What happens | What you get |
|---|---|
| The callback throws, and the rollback fails too | The **callback's** exception. The connection is closed, so the database discards the transaction with the session |
| A rollback fails at an inner level | The connection is closed. The outer levels are *lost*: every statement and commit on the connection is refused until each has rolled back. An outer callback that swallowed the failure cannot go on writing in autocommit mode |
| The callback leaves a level open, or finishes one it did not begin | Everything since the callback began is rolled back, and the imbalance is reported |
| `disconnect()` inside a transaction | The connection closes (the work is discarded), then an exception names the connection and the depth. `disconnectAll()` closes every connection before reporting |

### Isolation levels and retrying

```php
$connection->transaction($callback, isolation: IsolationLevel::Serializable, retries: 3);
```

**`isolation:`** runs the transaction at `ReadUncommitted`, `ReadCommitted`,
`RepeatableRead` or `Serializable`. Each dialect writes the level where its
database needs it:

| | Where the level is set | What else |
|---|---|---|
| MySQL, MariaDB | before `BEGIN` | applies to that transaction only |
| PostgreSQL | first statement inside | `ReadUncommitted` runs as `ReadCommitted`, which is stricter, never weaker |
| SQL Server | before `BEGIN` | lasts for the session, so it is put back to `READ COMMITTED` afterwards. If that fails, the session is closed |
| SQLite | nowhere | only `Serializable` exists; every other level is refused |

A level a database cannot give is refused before anything runs. A different
level is never quietly substituted: code that asked for `READ COMMITTED` may be
relying on not blocking. The level and the retries belong to the **outermost**
transaction, and a nested `transaction()` given either is refused.

**`retries:`** runs the whole transaction again, from a clean state, when it
fails with a **deadlock or a serialization failure**. That means SQLSTATE
`40001` (which MySQL and SQL Server also use for their deadlocks), `40P01` on
PostgreSQL, or MySQL's error 1213, and nothing else. A constraint violation, a
lock-wait timeout, a lost connection or an exception of your own is never
retried. Between attempts there is a short, growing, jittered pause: 10 ms,
20 ms, 40 ms and so on, up to 200 ms. The last failure is thrown once the retries
are spent. `$connection->isRetryable($e)` makes the same judgement for code that
manages its own retries.

> **With retries, the callback may run more than once.** Its database work is
> rolled back between attempts, but nothing else is. An email sent, a card
> charged, a file written or a job queued inside the callback happens once per
> attempt. Do those after `transaction()` returns.

### Watching statements and transactions

In an application, each connection fires these hooks:

| Hook | Arguments | When |
|---|---|---|
| `database.query.failed` | `DatabaseException $failure, Connection $connection` | the database refused a statement |
| `database.transaction.committed` | `Connection $connection` | the outermost transaction committed |
| `database.transaction.rolled_back` | `Connection $connection, ?Throwable $cause` | the outermost transaction was rolled back: `$cause` is the failure that ended a `transaction()`, or null after a `rollBack()` |
| `database.transaction.retrying` | `Throwable $failure, int $attempt, Connection $connection` | `transaction(retries:)` is about to try again; `$attempt` is 2 for the first retry |

```php
$hooks->add('database.transaction.committed', function (Connection $connection) use ($outbox): void {
    $outbox->flush();
});
```

Savepoints are not announced: releasing one commits nothing, and rolling one
back leaves the transaction open. Each hook fires once what it reports has
happened, so a listener cannot change it:

- a listener that throws after a commit does not undo the commit;
- nothing a listener throws causes a transaction to be retried;
- a listener that throws on `query.failed`, or on a rollback inside
  `transaction()`, is ignored, so the caller still gets the database's failure.

The failure carries the SQL and how many values were bound, never the values.

The database layer itself knows nothing about hooks. Outside an application,
`listen()` on a connection or on the `ConnectionManager` receives the same events,
without the `database.` prefix:

```php
$connections->listen(function (string $event, mixed ...$arguments): void { /* ... */ });
```

`observe()` gets a callback after every statement, including a failed one. It
receives:

1. the SQL;
2. the nanoseconds it took;
3. the connection's name;
4. how many values were bound;
5. how many rows the statement returned or changed, or null when that was not
   known when it was reported (a failure, a cursor, or `run()`).

An observer may declare fewer parameters and ignore the rest. The bound values
themselves are never passed. The profiler and the
[slow-query warning](observability.md#slow-queries-profiling-or-not) are built on
it.

## Binding values

| PHP value | Bound as |
|---|---|
| `int`, `bool`, `null`, `string` | their own PDO types |
| `float` | the shortest decimal that reads back as the same float — PDO alone would write fourteen digits and store `0.1 + 0.2` as `0.3`. Infinity and NaN are refused |
| `DateTimeInterface` | `Y-m-d H:i:s`, with `.u` when there are microseconds. The wall-clock time as given: **the time zone is not converted**, because which zone a column holds is the application's decision |
| a stream resource | a large object, for binary data |
| `Stringable` | its string |

Anything else is refused by parameter position and type, never by value.

## The query builder

For reads that are SQL and should look like it, `table()` gives a builder:

```php
$invoices = $connection->table('invoices AS i')
    ->select('i.id', 'i.total', 'i.customer_id AS customer')
    ->whereNull('i.cancelled_at')
    ->where(fn (QueryBuilder $q) => $q->where('i.status', 'open')->orWhere('i.total', '>', 1000))
    ->whereIn('i.region', $regions)
    ->orderByDesc('i.id')
    ->limit(50)
    ->get();
```

| Building | Running |
|---|---|
| `select(...)` — names, `t.*`, `x AS y`, or a `RawExpression` | `get()` — every row |
| `where` / `orWhere` — `(col, value)`, `(col, op, value)`, a closure group, or a `RawExpression` | `first()` — one row or `null` |
| `whereNull` / `whereNotNull` | `cursor()` — a generator, one row at a time |
| `whereIn` / `whereNotIn` — an empty list matches nothing / excludes nothing | `count()` — ignores order, limit and offset |
| `whereBetween` / `whereNotBetween` — inclusive | `exists()` — reads at most one row |
| `orderBy(col, 'asc'\|'desc')`, `orderByDesc`, `limit`, `offset` | `compile()` — the SQL and bindings, not run |

- **Building runs nothing.** The connection opens when a terminal method runs.
- **Builders are immutable.** Every method returns a new builder, so a base query
  can be narrowed two ways without either seeing the other. A grouping closure
  must **return** the builder it built; one that returns nothing is refused.
- **Values are bound, names are checked.** A value never reaches the SQL string.
  A table or column name is checked part by part (`sales.orders`,
  `o.total AS amount`) against the identifier pattern. An operator must be one of
  `= != <> < <= > >= like not like`, and a direction must be `asc` or `desc`.
  Nothing else is accepted, so a name or a sort order taken from a request fails
  instead of becoming SQL. Map request input onto names you chose; never pass it
  through.
- **`where('col', null)` is refused.** `= NULL` is never true in SQL, and a query
  that silently matches nothing is worse than an exception pointing to
  `whereNull()`.
- **`RawExpression` is the escape hatch**, and the only one: hand-written SQL
  with its own bindings, written in parentheses when it is a condition. It is
  trusted exactly as far as the code that wrote it.

The builder is SQL-only. `Data\Query` is unchanged: AND only, no joins, the same
on every `DataSource`. A repository method uses the builder when a read is
relational, in the same way it would use raw SQL.

### Joins, grouping and aggregates

```php
$revenue = $db->table('customers AS c')
    ->select('c.region', Aggregate::count(as: 'orders'), Aggregate::sum('o.total', as: 'revenue'))
    ->join('orders AS o', 'o.customer_id', '=', 'c.id')
    ->leftJoin('refunds AS r', fn (JoinClause $j) => $j->on('r.order_id', '=', 'o.id')->where('r.status', 'settled'))
    ->whereNull('r.id')
    ->groupBy('c.region')
    ->having(Aggregate::sum('o.total'), '>', 10000)
    ->orderByDesc('revenue')
    ->get();

$db->table('orders')->where('status', 'paid')->sum('total');   // also avg(), min(), max()
```

- **`join()`, `leftJoin()` and `rightJoin()`.** The four-argument form compares
  two columns. A closure gets a `JoinClause`: `on()` and `orOn()` compare
  columns, `where()` compares a column with a bound value, and the closure must
  return the clause. A join with no condition is refused, because it would pair
  every row with every other.
- **`rightJoin()` needs `Capability::RightJoin`.** SQLite is not counted as having
  it: it arrived in 3.39, and older builds are still shipped. A left join with
  the tables swapped asks the same question.
- **`whereColumn('updated_at', '>', 'created_at')`** compares two columns. The
  second is a name, never a value.
- **`Aggregate`** is COUNT, SUM, AVG, MIN or MAX of a checked column, optionally
  with `as:`. It can be selected, used in `having()`, or asked for directly with
  `sum()`, `avg()`, `min()` and `max()`. Anything more elaborate is a
  `RawExpression`.
- **`having()` takes an `Aggregate`, a grouped column or a `RawExpression`, but
  not a selected alias.** PostgreSQL and SQL Server cannot see an alias in HAVING,
  so repeat the aggregate. `orderBy('revenue')` may name the alias: every
  database accepts that in ORDER BY.
- **`count()` on a grouped query counts the groups**, by counting the rows of the
  grouped query. `sum()` and the others are refused on a grouped query, where
  they would mean one value per group: select the aggregate and use `get()`.
- **Numbers come back as the database returns them.** A SUM of an integer column
  is an int, while a SUM or AVG of a DECIMAL is a string, so no digit of money is
  lost to a float. No matching rows gives `null`, as in SQL, not zero.
- Joins and groupings are for reads. A write that carries either is refused.

### Writing through the builder

```php
$id = $db->table('invoices')->insert(['customer_id' => 7, 'total' => 120.5], 'id');
$db->table('lines')->insertMany($lines);                        // how many were written
$db->table('stock')->where('sku', $sku)->update(['quantity' => new RawExpression('quantity - ?', [2])]);
$db->table('sessions')->where('expires_at', '<', $now)->delete();
$db->table('stock')->upsert($rows, uniqueBy: ['sku'], update: ['quantity']);
```

- **`insert($row, 'id')` returns the key the database generated**, when you name
  the key column. On PostgreSQL the value comes from `RETURNING`, and on SQL
  Server from `OUTPUT INSERTED`, in the same statement. On MySQL and SQLite it
  comes from the connection's last-insert id, which those databases keep per
  statement. Without a key, `insert()` returns null.
- **`update()` and `delete()` refuse to run without a condition.** A forgotten
  `where()` throws instead of rewriting the table. `updateAll()` and
  `deleteAll()` say "every row" in so many words.
- **A write refuses what only a read can honour:** a column list, an order, a
  limit or an offset (and conditions, for an insert). `UPDATE … LIMIT` works only
  on MySQL, and ignoring the limit elsewhere would write more rows than were
  asked for. To write "the first ten", select their keys, then `whereIn()` them.
- **Every row of `insertMany()` or `upsert()` names the same columns**, in any
  order. A missing column is refused rather than filled with null, which would
  override the column's default without anyone deciding to. Rows are split to
  fit each database's placeholder limit. Wrap the call in `transaction()` when it
  must be all or nothing.
- **A value may be a `RawExpression`** in `insert()` and `update()`, but not in the
  many-row writes, which are split by counting placeholders.
- **`upsert()` is offered only where the database has one** —
  `Capability::Upsert` on MySQL, PostgreSQL and SQLite. SQL Server's `MERGE` is a
  different statement with different locking, so it is refused rather than
  faked. `update` defaults to every column outside the key; `[]` leaves an
  existing row alone. MySQL judges a conflict by whichever unique key the row
  collides with, whatever `uniqueBy` says. PostgreSQL refuses a statement that
  names the same key twice.
- The counts are the database's. MySQL counts only the rows whose values actually
  changed.
- **The table in a write is named plainly.** A schema prefix (`sales.orders`) is
  fine, but an alias is refused: the databases disagree about where an alias
  goes in UPDATE and DELETE.

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
`Grammar` and its dialects, and every part of a name passes one check first:

```
/^[A-Za-z_][A-Za-z0-9_]*$/
```

A name is split on its dots (`sales.orders` is two parts; a table may have two,
a column three) and on
`AS` for an alias, and each part is checked and quoted on its own. Anything
else — a quote, a space, a comment, a semicolon, a function call, an empty part
— is refused with an exception naming it. Names reach the Grammar from
repository declarations rather than from requests; the check is what keeps that
true on the day somebody passes a sort column straight from a query string. An
architecture test asserts that no other file in `engine/Database/` builds SQL at
all.

Limits and offsets are written in rather than bound: they are typed `int` in PHP
by the time they arrive, so there is nothing to inject, and binding them is the
one thing several drivers get wrong.

## Dialects

Each connection speaks its database's dialect, picked from the driver in its
DSN by `Grammar::for()`:

| Driver | Dialect | Differs in |
|---|---|---|
| `mysql` | `MySqlGrammar` | backtick quoting; 65535 placeholders per statement |
| `pgsql` | `PostgresGrammar` | 65535 placeholders. Placeholders stay `?`, which PDO rewrites into PostgreSQL's numbered form |
| `sqlite` | `SqliteGrammar` | nothing: standard quoting and paging, 999 placeholders |
| `sqlsrv` | `SqlServerGrammar` | `[bracket]` quoting; `OFFSET … FETCH` paging, with `ORDER BY (SELECT NULL)` when a query has no order of its own; `SAVE TRANSACTION` savepoints; 2000 placeholders |
| anything else | `Grammar` | standard SQL, and no capabilities claimed |

What a database can do at all is asked of the connection:

```php
if ($connection->supports(Capability::Savepoints)) { ... }
```

| Capability | MySQL | PostgreSQL | SQLite | SQL Server |
|---|---|---|---|---|
| `Savepoints` — transactions nest | yes | yes | yes | yes |
| `Returning` — an INSERT hands back its generated key | no | yes | no¹ | yes (`OUTPUT`) |
| `Upsert` — insert or update by a unique key | yes | yes | yes | no |
| `RightJoin` — RIGHT JOIN | yes | yes | no² | yes |

¹ SQLite has had `RETURNING` since 3.35, but some distributions still ship
older builds. SQLite's last-insert id is correct per statement anyway.
² From 3.39, which is newer than some distributions ship.

A capability is added with the feature that needs it, never ahead of one. A test
holds this table for every dialect, so a new capability cannot be added without
deciding it for each database.

## Testing against a database

The database tests are real integration tests against SQLite in memory — real
PDO, real prepared statements, real savepoints — costing about a millisecond
each and needing nothing installed. `tests/Feature/SqlSourceTest.php` runs the
same repository against `ArraySource` and `SqlSource` and asserts the answers
are identical, which is the `DataSource` claim stated as a test rather than as a
paragraph.

SQLite agreeing with a dialect proves nothing about the other databases, so
`DialectConformanceTest` runs each dialect on its own database too: MySQL,
PostgreSQL or SQL Server, whichever `DB_TEST_*_DSN` names (see
[Development](../contributing/development.md#testing-against-database-servers)).
CI runs all three. MySQL (as MariaDB) and PostgreSQL are also run where the
framework is developed; SQL Server is not, so CI is the only place its dialect
meets a real server.

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
        'ledger' => [
            'driver' => 'mysql',
            'host' => 'ledger-db',
            'port' => 3306,
            'database' => 'ledger',
            'charset' => 'utf8mb4',
            'username' => 'ledger',
            'password' => Env::string('LEDGER_PASSWORD'),
        ],
    ],
];
```

A connection gives either a `dsn` or its **parts**: `driver`, `host`, `port`,
`database` and `charset`, assembled for `mysql`, `pgsql`, `sqlite` (where
`database` is the file, or `:memory:`) and `sqlsrv`. `charset` is written for
MySQL only; PostgreSQL takes the database's encoding, and SQL Server's is a PDO
attribute. A `dsn` wins when both are given, and any other driver needs one. A
part containing `;` — or a host containing `,` — is refused rather than escaped,
because no driver documents an escape and either would start a DSN part the
configuration never set.

An embedding application can still pass connections to `Bootstrap::create()`
directly; those outrank the file.

With nothing configured the application boots exactly as before and opens
nothing; the shared module's `DataSource` factory falls back to `ArraySource`.
