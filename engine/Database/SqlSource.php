<?php

declare(strict_types=1);

namespace App\Engine\Database;

use App\Engine\Data\DataSource;
use App\Engine\Data\Query;

/**
 * The data layer, over a real database.
 *
 * This is the whole bridge between the two phases, and it is deliberately
 * thin: a Query describes what to read, the Grammar turns that into SQL, the
 * Connection runs it. Everything above -- repositories, hydration, read models,
 * relation linking, pagination -- is unchanged from the day it ran against an
 * array in memory, because none of it was ever written against a database.
 *
 * Swapping ArraySource for this is one line in a module:
 *
 *     $services->singleton(DataSource::class, static fn (Container $c): DataSource
 *         => new SqlSource($c->get(ConnectionManager::class)->connection()));
 *
 * fetch() returns a cursor rather than an array, so a query that streams really
 * streams: Query::stream() and Query::chunk() hold one row at a time rather than
 * a table.
 */
final class SqlSource implements DataSource
{
    private readonly Grammar $grammar;

    public function __construct(
        private readonly Connection $connection,
        ?Grammar $grammar = null,
    ) {
        $this->grammar = $grammar ?? new Grammar($connection->driver());
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function grammar(): Grammar
    {
        return $this->grammar;
    }

    /** @return \Generator<int, array<string, mixed>> */
    public function fetch(Query $query): iterable
    {
        $compiled = $this->grammar->compileSelect($query);

        return $this->connection->cursor($compiled['sql'], $compiled['bindings']);
    }

    public function count(Query $query): int
    {
        $compiled = $this->grammar->compileCount($query);
        $count = $this->connection->scalar($compiled['sql'], $compiled['bindings']);

        return \is_int($count) ? $count : (int) (\is_numeric($count) ? $count : 0);
    }

    public function insert(string $collection, string $key, array $row): int|string|null
    {
        $compiled = $this->grammar->compileInsert($collection, $row);
        $generated = $this->connection->insert($compiled['sql'], $compiled['bindings']);

        // A key the row already carried wins over whatever the sequence said:
        // the application supplied it deliberately.
        /** @var mixed $supplied */
        $supplied = $row[$key] ?? null;

        if (\is_int($supplied) || \is_string($supplied)) {
            return $supplied;
        }

        return $generated;
    }

    public function update(string $collection, string $key, int|string $identity, array $changes): int
    {
        if ($changes === []) {
            return 0;
        }

        $compiled = $this->grammar->compileUpdate($collection, $key, $identity, $changes);

        return $this->connection->execute($compiled['sql'], $compiled['bindings']);
    }

    public function delete(string $collection, string $key, int|string $identity): int
    {
        $compiled = $this->grammar->compileDelete($collection, $key, $identity);

        return $this->connection->execute($compiled['sql'], $compiled['bindings']);
    }
}
