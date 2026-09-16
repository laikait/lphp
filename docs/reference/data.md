# Repositories and queries

`DataSource` is the whole seam between the data layer and storage — five
methods, no connection, no statement, no dialect:

```php
fetch(Query): iterable   count(Query): int
insert(collection, key, row)   update(...)   delete(...)
```

`ArraySource` implements it in memory, and it is not a toy: a repository tested
against it runs the real repository, the real query and the real hydration at
full speed, with no database to install and nothing to clean up between tests.
`SqlSource` is the PDO-backed implementation (see
[Running the data layer on it](database.md#running-the-data-layer-on-it)), and nothing above
this interface changes between the two.

**A repository is written, never generated.** The base class publishes *nothing*
but its constructor — an architecture test enforces it — so every public method
on a repository is one somebody named after something the application does:

```php
final class CustomerRepository extends Repository
{
    protected function model(): string      { return Customer::class; }
    protected function collection(): string { return 'customers'; }

    public function findByEmail(string $email): ?Customer
    {
        return $this->query()->whereIs('email', $email)->first();
    }

    public function register(array $attributes): Customer
    {
        return $this->persist(new Customer(null, $attributes['name'], $attributes['email']));
    }
}
```

`query()`, `persist()`, `remove()` and `hydrate()` are `final protected` — the
plumbing, not the API. There is no inherited `find()`, `findAll()`, `save()` or
`delete()`, because a base with all of those is a table gateway with a longer
name: it says nothing about the domain and grows a method per column.

An update writes **only what changed** — that is what the model layer's change
tracking is for. `persist()` returns what is now stored, because a new model has
no identity until it is written; reading the row back beats every model exposing
a writable identity for the engine's benefit.

**A query is built lazily and immutably.** Nothing is read until a terminal
method is called, so a repository can hold a base query and hand out narrowed
copies without one caller changing what the next one sees.

```php
$query->whereIs('active', true)
      ->where('balance', Operator::Gt, 0)
      ->orderBy('name')
      ->limit(50);
```

**How much hydration is the caller's choice**, and that is the point:

| | |
|---|---|
| `rows()` `firstRow()` | plain arrays, nothing built |
| `column()` `value()` | one field — and it selects only that field |
| `count()` `exists()` | no rows read at all |
| `into()` `firstInto()` `pageInto()` | read models, reading only the columns they declare |
| `get()` `first()` `stream()` | full domain models, hydrated and identity-mapped |
| `page()` `chunk()` | batches, with the totals a pager needs |

A list screen that builds ten thousand domain objects to show three columns is
the most common way a fast query becomes a slow page, so the cheap options are
first-class rather than an optimisation to find later. `pageInto()` derives its
column list from the read model's own constructor, so selecting the right
columns cannot drift from the fields being built.

Two things a query deliberately does not have:

- **No OR.** Criteria combine with AND. A general boolean tree is the point at
  which a query builder becomes a query language, with its own precedence rules
  and its own bugs. A read that genuinely needs one is a named repository method
  over SQL the database layer runs directly.
- **No join.** A join is a relational idea and a five-method interface cannot
  honour it for every kind of source. Data from two places is loaded in two
  queries and linked explicitly — which the model layer already does, and which
  cannot degrade into N+1 the way a lazy association can.

Transactions, connections and nested-transaction strategy belong to the database
layer — `Connection::transaction()`, in [The database](database.md). A `transaction()` on `DataSource`
would make every source pretend to have one, including the array in a test.

## Bulk writes

A billing run that marks four thousand invoices overdue should be one statement,
not four thousand loads, change sets and single-row updates. Row-at-a-time is the
right default for domain work, because rules run per object; it is the wrong
tool for a set. So a repository has three more pieces of `final protected`
plumbing, and — like the rest — names its own public methods around them:

```php
public function import(array $rows): int
{
    return $this->insertMany($rows);                  // as few INSERTs as the driver allows
}

public function deactivateOwnedBy(int $ownerId): int
{
    return $this->updateWhere($this->query()->whereIs('ownerId', $ownerId), ['active' => false]);
}

public function purgeInactive(): int
{
    return $this->deleteWhere($this->query()->whereIs('active', false));
}
```

They live on a second interface, `BulkWrites`, rather than as three more methods
on `DataSource`. `DataSource` is five methods on purpose — it is the seam a
non-relational store can implement — and a source that cannot write a set is
still a perfectly good source. Ask one for a bulk write and the repository says
so by name instead of looping quietly. Both shipped sources implement it, and a
conformance suite runs every behaviour below against both.

What is refused, and why:

| | |
|---|---|
| a query with an order, a limit, an offset or a column list | a bulk write touches every row the criteria match and nothing else about the query applies. `UPDATE ... LIMIT` means different things on different databases |
| a query with **no criteria** | "change every row" should be written on purpose — `whereNotNull('id')` — not arrived at by a filter somebody forgot |
| rows naming different columns | a multi-row `INSERT` has one column list, and filling the gap with `NULL` would override the column's default |

Three consequences worth knowing:

- **No identities come back** from `insertMany()`. Returning them portably means
  one statement per row again, which is the cost being avoided.
- **Loaded models are forgotten** after `updateWhere()` and `deleteWhere()`. The
  database changed underneath objects the identity map would otherwise keep
  handing out.
- **No transaction is opened.** A large insert is split to stay under the driver's
  placeholder limit (999 on SQLite, 2000 on SQL Server, 65535 on MySQL and
  PostgreSQL), and whether a failure in the third statement should undo the first
  two is the caller's decision — which is where the specification puts it:

```php
$connection->transaction(fn () => $customers->import($rows));
```
