<?php

declare(strict_types=1);

namespace App\Engine\Database;

/**
 * SQLite.
 *
 * Standard quoting, paging and savepoints. The placeholder limit stays at the
 * standard 999: raised to 32766 in 3.32, but still compiled lower on some
 * distributions, and the version on the server is not the one on the laptop.
 */
final class SqliteGrammar extends Grammar
{
    public function driver(): string
    {
        return 'sqlite';
    }

    public function supports(Capability $capability): bool
    {
        return match ($capability) {
            Capability::Savepoints => true,
            Capability::Returning => false,
            Capability::Upsert => true,
            Capability::RightJoin => false,
        };
    }

    /**
     * SQLite transactions are serializable, and only that: one writer at a
     * time. Asking for a weaker level is refused rather than silently given
     * this stronger one, because code that asked for READ COMMITTED may be
     * counting on not blocking.
     */
    public function supportsIsolation(IsolationLevel $level): bool
    {
        return $level === IsolationLevel::Serializable;
    }

    /** Nothing to say: it is the only level there is. */
    public function compileIsolation(IsolationLevel $level): array
    {
        if (!$this->supportsIsolation($level)) {
            throw DatabaseException::isolationUnsupported($level, $this->driver());
        }

        return ['before' => null, 'after' => null, 'reset' => null];
    }
}
