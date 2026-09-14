<?php

declare(strict_types=1);

namespace App\Engine\Data;

use App\Engine\Model\Model;
use App\Engine\Model\ModelCollection;
use App\Engine\Model\ModelManager;
use App\Engine\Model\ReadModel;

/**
 * A description of a read, and the ways to run it.
 *
 * Building a query does nothing. No source is touched until a terminal method
 * is called, so a query can be passed around, refined by a repository method,
 * narrowed by a caller, and still cost nothing if it is never used.
 *
 *     $query->whereIs('active', true)
 *           ->where('balance', Operator::Gt, 0)
 *           ->orderBy('name')
 *           ->limit(50)
 *           ->get();
 *
 * Queries are immutable. Every builder method returns a new query, so a
 * repository can hold a base query and hand out narrowed copies without any
 * caller being able to change what the next one sees.
 *
 * **How much hydration is the caller's choice**, and that is the point. The
 * same query answers with raw arrays, single scalars, a column, read models or
 * domain models. A list screen that builds ten thousand domain objects to show
 * three columns is the most common way a fast query becomes a slow page, so the
 * cheap options are first-class rather than an optimisation to discover later.
 *
 * Two things this deliberately does not have:
 *
 *   **No OR.** Criteria are combined with AND. A general boolean tree is the
 *   point at which a query builder becomes a query language, with its own
 *   precedence rules and its own bugs. A read that genuinely needs one is a
 *   named repository method over SQL the database layer runs directly, where it
 *   can be seen and explained.
 *
 *   **No join.** A join is a relational idea, and an interface five methods
 *   wide cannot honour it for every kind of source. Data from two places is
 *   loaded in two queries and linked explicitly, which the model layer already
 *   does and which cannot degrade into N+1 the way a lazy association can.
 */
final class Query
{
    /** @var list<Criterion> */
    private array $criteria = [];

    /** @var list<Order> */
    private array $orders = [];

    /** @var list<string> */
    private array $columns = [];

    private ?int $limit = null;

    private int $offset = 0;

    /**
     * @param class-string<Model>|null $model the class get() builds, if any
     */
    private function __construct(
        private readonly DataSource $source,
        private readonly string $collection,
        private readonly ModelManager $models,
        private readonly ?string $model = null,
    ) {}

    /**
     * @param class-string<Model>|null $model
     */
    public static function on(
        DataSource $source,
        string $collection,
        ModelManager $models,
        ?string $model = null,
    ): self {
        return new self($source, $collection, $models, $model);
    }

    // ---- building ---------------------------------------------------------

    public function where(string $field, Operator $operator, mixed $value = null): self
    {
        return $this->withCriterion(new Criterion($field, $operator, $value));
    }

    public function whereIs(string $field, mixed $value): self
    {
        return $this->withCriterion(new Criterion($field, Operator::Eq, $value));
    }

    public function whereNot(string $field, mixed $value): self
    {
        return $this->withCriterion(new Criterion($field, Operator::NotEq, $value));
    }

    /** @param list<mixed> $values */
    public function whereIn(string $field, array $values): self
    {
        return $this->withCriterion(new Criterion($field, Operator::In, $values));
    }

    /** @param list<mixed> $values */
    public function whereNotIn(string $field, array $values): self
    {
        return $this->withCriterion(new Criterion($field, Operator::NotIn, $values));
    }

    public function whereNull(string $field): self
    {
        return $this->withCriterion(new Criterion($field, Operator::IsNull));
    }

    public function whereNotNull(string $field): self
    {
        return $this->withCriterion(new Criterion($field, Operator::IsNotNull));
    }

    /** SQL LIKE semantics: % matches any run of characters, _ matches one. */
    public function whereLike(string $field, string $pattern): self
    {
        return $this->withCriterion(new Criterion($field, Operator::Like, $pattern));
    }

    /**
     * Read only these columns.
     *
     * The cheapest optimisation there is, and the one most often missed: a
     * table with a text column costs the same to select as one without until
     * somebody writes this line.
     */
    public function select(string ...$columns): self
    {
        $clone = clone $this;
        $clone->columns = \array_values($columns);

        return $clone;
    }

    public function orderBy(string $field, Direction $direction = Direction::Asc): self
    {
        $clone = clone $this;
        $clone->orders = [...$this->orders, new Order($field, $direction)];

        return $clone;
    }

    public function orderByDesc(string $field): self
    {
        return $this->orderBy($field, Direction::Desc);
    }

    public function limit(?int $limit): self
    {
        $clone = clone $this;
        $clone->limit = $limit;

        return $clone;
    }

    public function offset(int $offset): self
    {
        $clone = clone $this;
        $clone->offset = \max(0, $offset);

        return $clone;
    }

    private function withCriterion(Criterion $criterion): self
    {
        $clone = clone $this;
        $clone->criteria = [...$this->criteria, $criterion];

        return $clone;
    }

    // ---- what a source needs to know --------------------------------------

    public function collection(): string
    {
        return $this->collection;
    }

    /** @return list<Criterion> */
    public function criteria(): array
    {
        return $this->criteria;
    }

    /** @return list<Order> */
    public function orders(): array
    {
        return $this->orders;
    }

    /** @return list<string> empty means every column */
    public function columns(): array
    {
        return $this->columns;
    }

    public function limitValue(): ?int
    {
        return $this->limit;
    }

    public function offsetValue(): int
    {
        return $this->offset;
    }

    /** @return class-string<Model>|null */
    public function modelClass(): ?string
    {
        return $this->model;
    }

    /**
     * The query as plain data, for logging and for tests that want to assert on
     * what would be read without reading anything.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'collection' => $this->collection,
            'columns' => $this->columns,
            'criteria' => \array_map(
                static fn(Criterion $criterion): array => $criterion->describe(),
                $this->criteria,
            ),
            'orders' => \array_map(
                static fn(Order $order): array => ['field' => $order->field, 'direction' => $order->direction->value],
                $this->orders,
            ),
            'limit' => $this->limit,
            'offset' => $this->offset,
        ];
    }

    // ---- running it: raw --------------------------------------------------

    /**
     * The matching rows, exactly as the source produced them.
     *
     * Nothing is built, nothing is tracked, nothing is mapped. For a report, an
     * export or an aggregate, this is the whole answer.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        $rows = [];

        foreach ($this->source->fetch($this) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function firstRow(): ?array
    {
        return $this->limit(1)->rows()[0] ?? null;
    }

    /**
     * One column across the matching rows.
     *
     * Selecting just that column as well, because reading a whole row to keep
     * one field is the thing this method exists to avoid.
     *
     * @return list<mixed>
     */
    public function column(string $field): array
    {
        $values = [];

        foreach ($this->select($field)->rows() as $row) {
            if (\array_key_exists($field, $row)) {
                /** @var mixed $value */
                $value = $row[$field];
                $values[] = $value;
            }
        }

        return $values;
    }

    /** One field of the first matching row. */
    public function value(string $field): mixed
    {
        $row = $this->select($field)->limit(1)->rows()[0] ?? null;

        return $row[$field] ?? null;
    }

    public function count(): int
    {
        return $this->source->count($this);
    }

    public function exists(): bool
    {
        return $this->limit(1)->count() > 0;
    }

    // ---- running it: projected -------------------------------------------

    /**
     * Read models, built from only the columns they declare.
     *
     * The columns come from the read model's own constructor, so asking for a
     * projection also narrows what is read. This is the answer to a list screen
     * over a large table.
     *
     * @template T of ReadModel
     *
     * @param class-string<T> $readModel
     *
     * @return list<T>
     */
    public function into(string $readModel): array
    {
        $records = [];

        foreach ($this->narrowedTo($readModel)->rows() as $row) {
            $records[] = $this->models->project($readModel, $row);
        }

        return $records;
    }

    /**
     * @template T of ReadModel
     *
     * @param class-string<T> $readModel
     *
     * @return T|null
     */
    public function firstInto(string $readModel): ?ReadModel
    {
        return $this->limit(1)->into($readModel)[0] ?? null;
    }

    // ---- running it: domain models ---------------------------------------

    /** Full domain models, hydrated and identity-mapped. */
    public function get(): ModelCollection
    {
        $model = $this->model ?? throw DataException::noModel($this->collection, 'get');

        return $this->models->hydrateAll($model, $this->source->fetch($this));
    }

    public function first(): ?Model
    {
        $model = $this->model ?? throw DataException::noModel($this->collection, 'first');
        $row = $this->limit(1)->firstRow();

        return $row === null ? null : $this->models->hydrate($model, $row);
    }

    /**
     * The matching models one at a time.
     *
     * The source decides whether it can genuinely stream; a generator here at
     * least means the caller is not handed an array of everything.
     *
     * @return \Generator<int, Model>
     */
    public function stream(): \Generator
    {
        $model = $this->model ?? throw DataException::noModel($this->collection, 'stream');

        foreach ($this->source->fetch($this) as $row) {
            yield $this->models->hydrate($model, $row);
        }
    }

    // ---- running it: in batches ------------------------------------------

    /**
     * Walk the whole result in batches, calling back with each one.
     *
     * For a job over a table too large to hold in memory. Returning nothing is
     * the usual case; returning exactly false stops the walk early.
     *
     * @param \Closure(ModelCollection): mixed $callback
     */
    public function chunk(int $size, \Closure $callback): void
    {
        if ($size < 1) {
            throw DataException::negativePage(1, $size);
        }

        $offset = $this->offset;

        while (true) {
            $batch = $this->limit($size)->offset($offset)->get();

            if ($batch->isEmpty()) {
                return;
            }

            if ($callback($batch) === false) {
                return;
            }

            if ($batch->count() < $size) {
                return;
            }

            $offset += $size;
        }
    }

    /** One page of domain models, with the totals needed to show a pager. */
    public function page(int $page, int $perPage): Page
    {
        [$page, $perPage] = $this->pageBounds($page, $perPage);

        return new Page(
            $this->limit($perPage)->offset(($page - 1) * $perPage)->get()->all(),
            $this->count(),
            $page,
            $perPage,
        );
    }

    /**
     * One page of read models.
     *
     * The paginated form of into(), and the one a list screen should reach for:
     * it reads the declared columns and never builds a domain object.
     *
     * @param class-string<ReadModel> $readModel
     */
    public function pageInto(string $readModel, int $page, int $perPage): Page
    {
        [$page, $perPage] = $this->pageBounds($page, $perPage);

        return new Page(
            $this->limit($perPage)->offset(($page - 1) * $perPage)->into($readModel),
            $this->count(),
            $page,
            $perPage,
        );
    }

    /** @return array{0: int, 1: int} */
    private function pageBounds(int $page, int $perPage): array
    {
        if ($page < 1 || $perPage < 1) {
            throw DataException::negativePage($page, $perPage);
        }

        return [$page, $perPage];
    }

    /**
     * Narrow the selection to the columns a read model declares.
     *
     * An explicit select() wins: the caller knew something the read model does
     * not, such as a column needed by an ordering.
     *
     * @param class-string<ReadModel> $readModel
     */
    private function narrowedTo(string $readModel): self
    {
        if ($this->columns !== []) {
            return $this;
        }

        $columns = $this->models->columnsFor($readModel);

        return $columns === [] ? $this : $this->select(...$columns);
    }
}
