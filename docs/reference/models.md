# Models

A model is domain state and the behaviour that guards it. It is **not** an
active record: there is no `save()`, no `delete()`, no static `find()`, no query
builder and no lazy relationship property.

```php
final class Customer extends Model
{
    public function __construct(
        private ?int $id,
        private string $name,
        private string $email,
        private ?int $ownerId = null,
        private bool $active = true,
    ) {}

    public function identity(): ?int { return $this->id; }

    public function deactivate(): void { $this->active = false; }
}
```

Ordinary typed properties, a constructor that can refuse bad state, domain
methods instead of setters. What the engine adds is the part persistence cannot
infer:

**Change tracking.** `markClean()` snapshots the declared properties; `changes()`
reports what has diverged. A repository writes the changed columns rather than
every column, and it gets that from `deactivate()` without a single setter —
tracking reads the properties instead of intercepting the writes. A model that
has never been clean reports all of its attributes, so `changes()` serves an
insert and an update alike.

**Explicit relations.** A `Relation` is a declaration and nothing more. Loading
is written out where it can be seen:

```php
$relations->declare(Customer::class,
    Relation::one('owner', User::class, localKey: 'ownerId', foreignKey: 'id'));

$customers = $catalog->all();                                      // one query
$owners = $users->findAll($relations->keysFor($customers, 'owner')); // one more
$relations->link($customers, 'owner', $owners);                    // none
```

`$customer->related('owner')` returns what was attached and **throws** if
nothing was. There is no lazy loader to trigger by accident, so a loop over ten
thousand customers cannot quietly become ten thousand queries. A relation that
was loaded and found nothing is attached as `null` or an empty collection, so
"empty" and "never loaded" stay distinguishable.

**Identity.** `ModelManager` hydrates rows by matching them to the model's
constructor **by parameter name**, converting only where the conversion is
unambiguous — which matters because a driver may return every column as a
string. Within one unit of work the same row hydrated twice is the same object,
so a change made through one reference is visible through the other.

> The identity map is memory. A long-running worker must call
> `ModelManager::flush()` between units of work, or state from one job is
> visible to the next.

**Read models.** Domain models are deliberately not serialisable — a domain
entity is not an API representation. A list endpoint projects instead:

```php
final class CustomerListRecord extends ReadModel
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
    ) {}
}
```

Three columns, no tracking, no relations. The fields in the payload are the ones
someone chose to put there.

Business models never live in `engine/Model/`, which holds infrastructure only.
They belong to the module that owns the capability, or to `modules/Shared/` when
genuinely more than one module needs them. Both rules are enforced by tests.
