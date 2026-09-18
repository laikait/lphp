<?php

declare(strict_types=1);

namespace App\Engine\Database;

/** MySQL and MariaDB. */
final class MySqlGrammar extends Grammar
{
    public function driver(): string
    {
        return 'mysql';
    }

    public function supports(Capability $capability): bool
    {
        return match ($capability) {
            Capability::Savepoints => true,
            Capability::Returning => false,
            Capability::Upsert => true,
            Capability::RightJoin => true,
        };
    }

    public function supportsIsolation(IsolationLevel $level): bool
    {
        return true;
    }

    /**
     * Before BEGIN, without SESSION or GLOBAL: MySQL applies it to the next
     * transaction only, so there is nothing to put back afterwards.
     */
    public function compileIsolation(IsolationLevel $level): array
    {
        return ['before' => 'SET TRANSACTION ISOLATION LEVEL ' . $level->value, 'after' => null, 'reset' => null];
    }

    /** 1213 is InnoDB's deadlock, which some versions report under HY000 rather than 40001. */
    public function isRetryable(\PDOException $failure): bool
    {
        return parent::isRetryable($failure) || ($failure->errorInfo[1] ?? null) === 1213;
    }

    /** Backticks: MySQL reads a double-quoted name as a string unless ANSI_QUOTES is set. */
    protected function quote(string $name): string
    {
        return '`' . $name . '`';
    }

    /**
     * ON DUPLICATE KEY UPDATE, judged by whichever unique key the row collides
     * with: MySQL takes no conflict target, so $uniqueBy is not written here.
     *
     * VALUES(column) is the incoming value. MySQL 8 deprecates it in favour of
     * a row alias that MariaDB does not understand; VALUES() is what both run.
     * An empty $update still needs an assignment, so the first unique column
     * is set to itself, which changes nothing.
     */
    protected function upsertClause(array $uniqueBy, array $update): string
    {
        if ($update === []) {
            $column = $this->identifier($uniqueBy[0] ?? '');

            return ' ON DUPLICATE KEY UPDATE ' . $column . ' = ' . $column;
        }

        return ' ON DUPLICATE KEY UPDATE ' . \implode(', ', \array_map(
            fn(string $column): string => $this->identifier($column) . ' = VALUES(' . $this->identifier($column) . ')',
            $update,
        ));
    }

    /** The protocol counts placeholders in two bytes. */
    public function maxBindings(): int
    {
        return 65535;
    }
}
