<?php

declare(strict_types=1);

namespace App\Engine\Database;

/**
 * PostgreSQL.
 *
 * Standard quoting, paging and savepoints. Placeholders stay `?`: PDO rewrites
 * them into PostgreSQL's own numbered form, and writing `$1` here would only
 * confuse it.
 */
final class PostgresGrammar extends Grammar
{
    public function driver(): string
    {
        return 'pgsql';
    }

    public function supports(Capability $capability): bool
    {
        return match ($capability) {
            Capability::Savepoints => true,
            Capability::Returning => true,
            Capability::Upsert => true,
            Capability::RightJoin => true,
        };
    }

    /**
     * Every level. READ UNCOMMITTED runs as READ COMMITTED -- PostgreSQL never
     * shows uncommitted rows -- which is stricter than asked, never weaker.
     * The standard compileIsolation() is PostgreSQL's own.
     */
    public function supportsIsolation(IsolationLevel $level): bool
    {
        return true;
    }

    /** The wire protocol counts parameters in two bytes. */
    public function maxBindings(): int
    {
        return 65535;
    }
}
