<?php

declare(strict_types=1);

namespace App\Engine\Database;

use App\Engine\Data\Criterion;
use App\Engine\Data\Operator;
use App\Engine\Data\Order;
use App\Engine\Data\Query;

/**
 * Turns a Query into SQL and a list of values to bind.
 *
 * Everything a caller supplied as a *value* is bound. Everything written into
 * the statement is either generated here or an identifier, and an identifier is
 * checked against one pattern before it goes anywhere near the string:
 *
 *     /^[A-Za-z_][A-Za-z0-9_]*$/
 *
 * Table and column names cannot be bound -- no database accepts a parameter
 * where a column goes -- so they are the one part of a statement built by
 * interpolation, and that makes this the place injection would live if it lived
 * anywhere. Names reach here from repository declarations, not from requests.
 * The check is what keeps that true on the day somebody passes a sort column
 * straight from a query string.
 *
 * Limits and offsets are written in rather than bound. They are typed int in
 * PHP by the time they arrive, so there is nothing to inject, and binding them
 * is the one thing several drivers get wrong.
 */
final class Grammar
{
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public function __construct(private readonly string $driver = '') {}

    // ---- identifiers ------------------------------------------------------

    public function identifier(string $name): string
    {
        if (\preg_match(self::IDENTIFIER, $name) !== 1) {
            throw DatabaseException::unsafeIdentifier($name);
        }

        return match ($this->driver) {
            'mysql' => '`' . $name . '`',
            'sqlsrv' => '[' . $name . ']',
            default => '"' . $name . '"',
        };
    }

    /** @param list<string> $names */
    public function columnList(array $names): string
    {
        if ($names === []) {
            return '*';
        }

        return \implode(', ', \array_map(
            fn(string $name): string => $this->identifier($name),
            $names,
        ));
    }

    // ---- reading ----------------------------------------------------------

    /** @return array{sql: string, bindings: list<mixed>} */
    public function compileSelect(Query $query): array
    {
        $where = $this->compileWhere($query->criteria());

        $sql = \sprintf(
            'SELECT %s FROM %s',
            $this->columnList($query->columns()),
            $this->identifier($query->collection()),
        );

        $sql .= $where['sql'];
        $sql .= $this->compileOrder($query->orders());
        $sql .= $this->compileLimit($query->limitValue(), $query->offsetValue());

        return ['sql' => $sql, 'bindings' => $where['bindings']];
    }

    /**
     * A count ignores limit and offset, the way the data layer promises.
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function compileCount(Query $query): array
    {
        $where = $this->compileWhere($query->criteria());

        return [
            'sql' => \sprintf(
                'SELECT COUNT(*) FROM %s%s',
                $this->identifier($query->collection()),
                $where['sql'],
            ),
            'bindings' => $where['bindings'],
        ];
    }

    /**
     * @param list<Criterion> $criteria
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function compileWhere(array $criteria): array
    {
        if ($criteria === []) {
            return ['sql' => '', 'bindings' => []];
        }

        $clauses = [];
        $bindings = [];

        foreach ($criteria as $criterion) {
            $compiled = $this->compileCriterion($criterion);
            $clauses[] = $compiled['sql'];
            $bindings = [...$bindings, ...$compiled['bindings']];
        }

        // AND only. The data layer has no OR by design; see Data\Query.
        return ['sql' => ' WHERE ' . \implode(' AND ', $clauses), 'bindings' => $bindings];
    }

    /** @return array{sql: string, bindings: list<mixed>} */
    private function compileCriterion(Criterion $criterion): array
    {
        $column = $this->identifier($criterion->field);

        return match ($criterion->operator) {
            Operator::IsNull => ['sql' => $column . ' IS NULL', 'bindings' => []],
            Operator::IsNotNull => ['sql' => $column . ' IS NOT NULL', 'bindings' => []],
            Operator::In, Operator::NotIn => $this->compileIn($column, $criterion),
            Operator::Like => ['sql' => $column . ' LIKE ?', 'bindings' => [$criterion->value]],
            default => [
                'sql' => $column . ' ' . $criterion->operator->value . ' ?',
                'bindings' => [$criterion->value],
            ],
        };
    }

    /** @return array{sql: string, bindings: list<mixed>} */
    private function compileIn(string $column, Criterion $criterion): array
    {
        /** @var list<mixed> $values */
        $values = \is_array($criterion->value) ? \array_values($criterion->value) : [];

        // The data layer refuses an empty list before it reaches here, because
        // "IN ()" is a syntax error on some drivers and silently matches
        // nothing on others.
        $placeholders = \implode(', ', \array_fill(0, \max(1, \count($values)), '?'));
        $keyword = $criterion->operator === Operator::In ? 'IN' : 'NOT IN';

        return ['sql' => $column . ' ' . $keyword . ' (' . $placeholders . ')', 'bindings' => $values];
    }

    /** @param list<Order> $orders */
    public function compileOrder(array $orders): string
    {
        if ($orders === []) {
            return '';
        }

        return ' ORDER BY ' . \implode(', ', \array_map(
            fn(Order $order): string => $this->identifier($order->field)
                . ($order->isDescending() ? ' DESC' : ' ASC'),
            $orders,
        ));
    }

    public function compileLimit(?int $limit, int $offset): string
    {
        if ($limit === null && $offset === 0) {
            return '';
        }

        // An offset without a limit needs one anyway on most drivers, and the
        // largest signed 64-bit value is the conventional way to say "all".
        $sql = ' LIMIT ' . \max(0, $limit ?? \PHP_INT_MAX);

        return $offset > 0 ? $sql . ' OFFSET ' . $offset : $sql;
    }

    // ---- writing ----------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function compileInsert(string $collection, array $row): array
    {
        if ($row === []) {
            throw DatabaseException::noColumnsToInsert($collection);
        }

        $columns = \array_keys($row);

        return [
            'sql' => \sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $this->identifier($collection),
                $this->columnList($columns),
                \implode(', ', \array_fill(0, \count($columns), '?')),
            ),
            'bindings' => \array_values($row),
        ];
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function compileUpdate(string $collection, string $key, int|string $identity, array $changes): array
    {
        if ($changes === []) {
            throw DatabaseException::noRowsToUpdate($collection);
        }

        $assignments = \implode(', ', \array_map(
            fn(string $column): string => $this->identifier($column) . ' = ?',
            \array_keys($changes),
        ));

        return [
            'sql' => \sprintf(
                'UPDATE %s SET %s WHERE %s = ?',
                $this->identifier($collection),
                $assignments,
                $this->identifier($key),
            ),
            'bindings' => [...\array_values($changes), $identity],
        ];
    }

    /** @return array{sql: string, bindings: list<mixed>} */
    public function compileDelete(string $collection, string $key, int|string $identity): array
    {
        return [
            'sql' => \sprintf(
                'DELETE FROM %s WHERE %s = ?',
                $this->identifier($collection),
                $this->identifier($key),
            ),
            'bindings' => [$identity],
        ];
    }
}
