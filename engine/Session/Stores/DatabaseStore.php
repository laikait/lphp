<?php

declare(strict_types=1);

namespace App\Engine\Session\Stores;

use App\Engine\Database\Connection;
use App\Engine\Session\SessionException;
use App\Engine\Session\SessionId;
use App\Engine\Session\SessionRecord;
use App\Engine\Session\SessionStore;

/**
 * Sessions in a table, which is what "distributed sessions" actually means.
 *
 * The file store is correct and fast and only works on one machine. The moment
 * there are two web servers behind a load balancer, a user's next request lands
 * somewhere that has never heard of them. Sticky sessions push the problem
 * around -- and lose every session on that node when it restarts. A shared
 * table is the version with no asterisk, and the database is already there.
 *
 * **The lock is a row lock, taken inside a transaction.** SELECT ... FOR UPDATE
 * on the drivers that have it, which gives exactly the guarantee the interface
 * asks for: one writer at a time, per session, for the length of one
 * read-modify-write rather than the length of a request.
 *
 * SQLite has no row locking; it locks the whole database file for a write. That
 * is correct but not concurrent, and under two simultaneous writers one of them
 * gets "database is locked". It is fine for development and for the test suite,
 * which is what it is used for here.
 *
 * **The table is not created automatically.** A store that issues DDL on its
 * first request needs permissions in production that nothing should have, and
 * it does it at the worst possible time. `session:table` prints the statement
 * for the configured driver; where that statement goes is the application's
 * business.
 *
 * The id and the timestamps appear both as columns and inside the JSON, and
 * that duplication is deliberate. The columns exist so that the sweep is one
 * indexed DELETE rather than a read loop; the JSON is the canonical record, so
 * every store in this framework decodes the same bytes with the same code and
 * a session written by one can be read by another.
 */
final class DatabaseStore implements SessionStore
{
    public const DEFAULT_TABLE = 'sessions';

    /** Drivers whose SELECT can take a row lock. */
    private const ROW_LOCKING = ['mysql', 'pgsql'];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $table = self::DEFAULT_TABLE,
    ) {}

    public function describe(): string
    {
        return \sprintf('database %s.%s', $this->connection->name(), $this->table);
    }

    public function read(string $id): ?SessionRecord
    {
        if (!SessionId::isValid($id)) {
            return null;
        }

        return $this->decode($this->row($id, forUpdate: false));
    }

    public function commit(string $id, \Closure $apply): ?SessionRecord
    {
        if (!SessionId::isValid($id)) {
            return null;
        }

        /** @var SessionRecord|null $result */
        $result = $this->connection->transaction(function () use ($id, $apply): ?SessionRecord {
            $existing = $this->row($id, forUpdate: true);
            $record = $apply($this->decode($existing));

            if ($record === null) {
                if ($existing !== null) {
                    $this->connection->execute(
                        \sprintf('DELETE FROM %s WHERE id = ?', $this->quoted()),
                        [$id],
                    );
                }

                return null;
            }

            $encoded = $record->encode();

            if ($existing === null) {
                $this->connection->execute(
                    \sprintf(
                        'INSERT INTO %s (id, payload, created_at, touched_at, successor) VALUES (?, ?, ?, ?, ?)',
                        $this->quoted(),
                    ),
                    [$record->id, $encoded, $record->createdAt, $record->touchedAt, $record->successor],
                );
            } else {
                $this->connection->execute(
                    \sprintf(
                        'UPDATE %s SET payload = ?, created_at = ?, touched_at = ?, successor = ? WHERE id = ?',
                        $this->quoted(),
                    ),
                    [$encoded, $record->createdAt, $record->touchedAt, $record->successor, $record->id],
                );
            }

            return $record;
        });

        return $result;
    }

    public function destroy(string $id): bool
    {
        if (!SessionId::isValid($id)) {
            return false;
        }

        return $this->connection->execute(
            \sprintf('DELETE FROM %s WHERE id = ?', $this->quoted()),
            [$id],
        ) > 0;
    }

    /**
     * One statement, not a read loop.
     *
     * Sweeping by selecting and deleting row by row would hold the table open
     * for as long as there are expired sessions, which on a busy site is
     * exactly when it is worst. Both clocks are expressed as SQL because the
     * database can answer this without sending a row anywhere.
     */
    public function gc(int $idle, int $absolute = 0): int
    {
        $now = \time();
        $conditions = [];
        $bindings = [];

        if ($idle > 0) {
            $conditions[] = 'touched_at <= ?';
            $bindings[] = $now - $idle;
        }

        if ($absolute > 0) {
            $conditions[] = 'created_at <= ?';
            $bindings[] = $now - $absolute;
        }

        if ($conditions === []) {
            return 0;
        }

        return $this->connection->execute(
            \sprintf('DELETE FROM %s WHERE %s', $this->quoted(), \implode(' OR ', $conditions)),
            $bindings,
        );
    }

    /**
     * The statement that creates the table, printed by session:table.
     *
     * Written out per driver rather than generated, because there are four
     * columns and a schema builder that produced them would be a schema builder
     * this framework does not otherwise have. The types matter: the id is
     * fixed-width, the payload is TEXT because a session is usually small and
     * occasionally is not, and both timestamps are integers so that the sweep
     * above is an index range rather than a date function.
     */
    public static function ddl(string $driver, string $table = self::DEFAULT_TABLE): string
    {
        $index = \sprintf(
            'CREATE INDEX %s_touched_at_index ON %s (touched_at);',
            $table,
            $table,
        );

        return match ($driver) {
            'mysql' => \sprintf(
                "CREATE TABLE `%s` (\n"
                . "    `id` CHAR(64) NOT NULL PRIMARY KEY,\n"
                . "    `payload` TEXT NOT NULL,\n"
                . "    `created_at` INT UNSIGNED NOT NULL,\n"
                . "    `touched_at` INT UNSIGNED NOT NULL,\n"
                . "    `successor` CHAR(64) NULL,\n"
                . "    INDEX `%s_touched_at_index` (`touched_at`)\n"
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
                $table,
                $table,
            ),
            'pgsql' => \sprintf(
                "CREATE TABLE %s (\n"
                . "    id CHAR(64) NOT NULL PRIMARY KEY,\n"
                . "    payload TEXT NOT NULL,\n"
                . "    created_at BIGINT NOT NULL,\n"
                . "    touched_at BIGINT NOT NULL,\n"
                . "    successor CHAR(64) NULL\n"
                . ");\n%s",
                $table,
                $index,
            ),
            default => \sprintf(
                "CREATE TABLE %s (\n"
                . "    id TEXT NOT NULL PRIMARY KEY,\n"
                . "    payload TEXT NOT NULL,\n"
                . "    created_at INTEGER NOT NULL,\n"
                . "    touched_at INTEGER NOT NULL,\n"
                . "    successor TEXT NULL\n"
                . ");\n%s",
                $table,
                $index,
            ),
        };
    }

    /** @return array<string, mixed>|null */
    private function row(string $id, bool $forUpdate): ?array
    {
        $sql = \sprintf('SELECT id, payload, created_at, touched_at, successor FROM %s WHERE id = ?', $this->quoted());

        if ($forUpdate && \in_array($this->connection->driver(), self::ROW_LOCKING, true)) {
            $sql .= ' FOR UPDATE';
        }

        try {
            return $this->connection->selectOne($sql, [$id]);
        } catch (\Throwable $e) {
            // A missing table is a deployment mistake, and the message every
            // driver gives for it is useless to whoever has to fix it. Anything
            // else is the database being unavailable, which is not this layer's
            // to explain and is rethrown untouched.
            if ($this->meansTheTableIsMissing($e->getMessage())) {
                throw SessionException::missingTable($this->table, $this->connection->driver());
            }

            throw $e;
        }
    }

    /**
     * Each driver phrases it differently and none of them uses an error code
     * that survives PDO, so the phrasing is what there is.
     */
    private function meansTheTableIsMissing(string $message): bool
    {
        $message = \strtolower($message);

        foreach (['no such table', 'base table or view not found', 'does not exist', "doesn't exist"] as $phrase) {
            if (\str_contains($message, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed>|null $row */
    private function decode(?array $row): ?SessionRecord
    {
        if ($row === null || !isset($row['payload']) || !\is_string($row['payload'])) {
            return null;
        }

        return SessionRecord::decode($row['payload']);
    }

    private function quoted(): string
    {
        return $this->connection->driver() === 'mysql' ? '`' . $this->table . '`' : $this->table;
    }
}
