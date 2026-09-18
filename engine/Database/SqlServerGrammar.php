<?php

declare(strict_types=1);

namespace App\Engine\Database;

/**
 * SQL Server.
 *
 * The dialect that differs most: square brackets, no LIMIT, and savepoints
 * that are "saved transactions" with no release.
 */
final class SqlServerGrammar extends Grammar
{
    public function driver(): string
    {
        return 'sqlsrv';
    }

    public function supports(Capability $capability): bool
    {
        return match ($capability) {
            Capability::Savepoints => true,
            Capability::Returning => true,
            Capability::Upsert => false,
            Capability::RightJoin => true,
        };
    }

    public function supportsIsolation(IsolationLevel $level): bool
    {
        return true;
    }

    /**
     * Before BEGIN, where it lasts for the rest of the session, not just the
     * transaction -- so afterwards the session is put back to READ COMMITTED,
     * SQL Server's default, or the next unrelated query would inherit it.
     */
    public function compileIsolation(IsolationLevel $level): array
    {
        return [
            'before' => 'SET TRANSACTION ISOLATION LEVEL ' . $level->value,
            'after' => null,
            'reset' => $level === IsolationLevel::ReadCommitted
                ? null
                : 'SET TRANSACTION ISOLATION LEVEL ' . IsolationLevel::ReadCommitted->value,
        ];
    }

    protected function quote(string $name): string
    {
        return '[' . $name . ']';
    }

    /** 2100 parameters, a few of which the server keeps for itself. */
    public function maxBindings(): int
    {
        return 2000;
    }

    /**
     * OFFSET ... FETCH is part of ORDER BY and cannot appear without one.
     * "(SELECT NULL)" orders by nothing, which is what a query that asked for
     * no order meant.
     */
    protected function compileOrderAndSlice(string $order, ?int $limit, int $offset): string
    {
        $slice = $this->compileLimit($limit, $offset);

        if ($slice !== '' && $order === '') {
            $order = ' ORDER BY (SELECT NULL)';
        }

        return $order . $slice;
    }

    /**
     * SQL Server refuses to fetch zero rows, so a limit of zero skips past every
     * row instead, which answers with the same nothing.
     */
    public function compileLimit(?int $limit, int $offset): string
    {
        if ($limit === null && $offset === 0) {
            return '';
        }

        if ($limit !== null && $limit <= 0) {
            return ' OFFSET ' . \PHP_INT_MAX . ' ROWS FETCH NEXT 1 ROWS ONLY';
        }

        $sql = ' OFFSET ' . \max(0, $offset) . ' ROWS';

        return $limit === null ? $sql : $sql . ' FETCH NEXT ' . $limit . ' ROWS ONLY';
    }

    /**
     * OUTPUT INSERTED comes between the column list and VALUES. It is refused
     * by a table with a trigger; such a table needs its key read another way.
     */
    protected function insertStatement(string $table, string $columns, string $values, ?string $returning): string
    {
        return 'INSERT INTO ' . $table . ' (' . $columns . ')'
            . ($returning === null ? '' : ' OUTPUT INSERTED.' . $this->identifier($returning))
            . ' VALUES ' . $values;
    }

    public function compileSavepoint(string $name): string
    {
        return 'SAVE TRANSACTION ' . $this->plainName($name);
    }

    /** A saved transaction lasts until the transaction ends; there is no release. */
    public function compileReleaseSavepoint(string $name): ?string
    {
        return null;
    }

    public function compileRollbackToSavepoint(string $name): string
    {
        return 'ROLLBACK TRANSACTION ' . $this->plainName($name);
    }
}
