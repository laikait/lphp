# Schemas

A schema describes the shape of structured data — a request body, a response
representation, a configuration block, an API contract. It is not tied to a
model and not tied to a table.

```php
final class CustomerSchema
{
    public static function input(): Schema
    {
        return Schema::of('customer.input',
            Field::string('name')->length(1, 120),
            Field::string('email')->check(
                'an email address',
                static fn (mixed $v): bool => \is_string($v)
                    && \filter_var($v, \FILTER_VALIDATE_EMAIL) !== false,
            ),
        );
    }

    public static function resource(): Schema
    {
        return self::input()->with(Field::int('id'))->named('customer.resource');
    }
}
```

Typed field constructors, not `'required|string|max:255'`. A rule string is
shorter to type and worse in every other way: nothing checks it, an editor
cannot complete it, a typo is found by a user, and it needs a parser for a
second language inside the first. Here a mistake is a PHP error — and a
constraint that cannot mean anything, like a `length()` on an integer, is
refused **while the schema is built** rather than on the first request that
happens to exercise the field.

**This is not a form-request object.** It is not a base class anyone extends, it
is not resolved out of a handler signature, and it throws no status codes. The
schema layer cannot reference `Http`, `Routing`, `Dispatch`, `Model`, `Container`
or `Module` at all — enforced by an architecture test, so it cannot grow into one
later. A handler calls a schema where it chooses and decides itself what a
failure means.

Three operations:

| | |
|---|---|
| `validate()` | collects everything wrong, throws nothing |
| `deserialize()` | data from outside — converts, applies defaults, **drops unknown keys**. A failure is the sender's fault |
| `serialize()` | data going outside — same shaping, but a failure means the application broke its own promise, which is a bug and is reported as one |

Validation does not stop at the first problem, and every error carries a path,
so nested data reports `address.postcode` and `contacts.2.email` rather than
"validation failed":

```json
{"error": {"status": 400,
  "fields": {"name": ["is required"], "email": ["must be an email address"]},
  "expected": {"name": {"type": "string", "min": 1, "max": 120}, …}}}
```

The `expected` block is `Schema::describe()` — the contract as plain data,
derived from the same declaration that enforced it, so documentation cannot
drift from behaviour.

`deserialize()` returns an **array**, not an object. That is what keeps a schema
from becoming a model factory:

```php
$attributes = CustomerSchema::input()->deserialize($request->json());
$customer = $models->hydrate(Customer::class, $attributes);
```

Neither side knows about the other, and dropping unknown keys means a client
cannot set a field you did not declare.

Fields are immutable values, so one declared once can be reused across schemas.
**Required is the default** — a field you declared and forgot to mark fails
loudly rather than going quietly missing. `null` and absent are different
questions: `nullable()` governs one, `optional()` and `default()` the other.

The constraint set is closed on purpose: `range()`, `length()`, `size()`,
`pattern()`, `oneOf()`, and `check()` for everything else. There is no `email`,
`url`, `uuid` or `date` rule, because that list never ends and every entry is an
opinion someone disagrees with — `check('an email address', …)` is one line and
the engine keeps no view on what a valid postcode looks like in your country.
