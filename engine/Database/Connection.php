<?php

declare(strict_types=1);

namespace App\Engine\Database;

/**
 * One database connection.
 *
 * Nothing here knows what a model, a repository or a query object is. That is
 * the point: the database layer is usable on its own, for a report, a migration
 * or a one-off script, and the data layer sits on top of it rather than being
 * the only way in.
 *
 *     $connection->select('SELECT * FROM invoices WHERE customer_id = ?', [$id]);
 *     $connection->transaction(function (Connection $db) use ($invoice): void {
 *         $db->execute('UPDATE accounts SET balance = balance - ? WHERE id = ?', [...]);
 *         $db->insert('INSERT INTO entries (invoice_id, amount) VALUES (?, ?)', [...]);
 *     });
 *
 * **Connecting is lazy.** The PDO handle is opened on first use, so a request
 * that never reads the database never opens a socket -- which is what makes it
 * reasonable to configure connections an application only sometimes needs.
 *
 * **Values are always bound, never interpolated.** Every method here takes SQL
 * and a separate list of bindings, and prepared statements are real rather than
 * emulated, so a value cannot become part of the statement.
 */
final class Connection
{
    private ?\PDO $pdo = null;

    /** How deep the current transaction is nested; 0 means none is open. */
    private int $depth = 0;

    /** @var (\Closure(string, int, string): void)|null */
    private ?\Closure $observer = null;

    public function __construct(private readonly ConnectionConfig $config) {}

    public function name(): string
    {
        return $this->config->name;
    }

    public function driver(): string
    {
        return $this->config->driver();
    }

    public function config(): ConnectionConfig
    {
        return $this->config;
    }

    // ---- the handle -------------------------------------------------------

    /**
     * The PDO handle, opened on first use.
     *
     * Public because there is always something PDO can do that this class has
     * not wrapped, and a layer that forces people to work around it is worse
     * than one that admits an escape hatch.
     */
    public function pdo(): \PDO
    {
        if ($this->pdo instanceof \PDO) {
            return $this->pdo;
        }

        try {
            return $this->pdo = new \PDO(
                $this->config->dsn,
                $this->config->username,
                $this->config->password,
                $this->config->pdoOptions(),
            );
        } catch (\PDOException $e) {
            // PDO puts the DSN, and sometimes the credentials, into its own
            // message. Ours names the connection instead.
            throw DatabaseException::cannotConnect($this->name(), $this->driver(), $e);
        }
    }

    public function isConnected(): bool
    {
        return $this->pdo instanceof \PDO;
    }

    /**
     * Close the connection.
     *
     * A long-running worker between jobs, or anything that has finished with
     * the database and does not want to hold a server-side session open.
     */
    public function disconnect(): void
    {
        $this->pdo = null;
        $this->depth = 0;
    }

    // ---- reading ----------------------------------------------------------

    /**
     * @param array<array-key, mixed> $bindings
     *
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->run($sql, $bindings)->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * @param array<array-key, mixed> $bindings
     *
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->run($sql, $bindings)->fetch(\PDO::FETCH_ASSOC);

        return \is_array($row) ? $row : null;
    }

    /**
     * The first column of the first row: a count, a sum, an existence check.
     *
     * @param array<array-key, mixed> $bindings
     */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        $value = $this->run($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * The rows one at a time, without loading them all.
     *
     * For an export or a job over a table that does not fit in memory. The
     * statement stays open until the generator is exhausted or discarded.
     *
     * @param array<array-key, mixed> $bindings
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function cursor(string $sql, array $bindings = []): \Generator
    {
        $statement = $this->run($sql, $bindings);

        while (true) {
            $row = $statement->fetch(\PDO::FETCH_ASSOC);

            if (!\is_array($row)) {
                break;
            }

            /** @var array<string, mixed> $row */
            yield $row;
        }

        $statement->closeCursor();
    }

    // ---- writing ----------------------------------------------------------

    /**
     * Run a statement and report how many rows it changed.
     *
     * @param array<array-key, mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings)->rowCount();
    }

    /**
     * Insert and return the identity the database assigned, if it assigned one.
     *
     * A table whose key the application supplies returns null here, and that is
     * not an error -- the caller already knows the key it sent.
     *
     * @param array<array-key, mixed> $bindings
     */
    public function insert(string $sql, array $bindings = []): int|string|null
    {
        $this->run($sql, $bindings);

        try {
            $identity = $this->pdo()->lastInsertId();
        } catch (\PDOException) {
            // Some drivers raise rather than returning false when there is no
            // sequence to report. Nothing was inserted badly; there is just
            // nothing to say.
            return null;
        }

        if ($identity === false || $identity === '' || $identity === '0') {
            return null;
        }

        return \ctype_digit($identity) ? (int) $identity : $identity;
    }

    /**
     * Prepare, bind and execute.
     *
     * @param array<array-key, mixed> $bindings
     */
    public function run(string $sql, array $bindings = []): \PDOStatement
    {
        $started = $this->observer === null ? 0 : \hrtime(true);

        try {
            $statement = $this->pdo()->prepare($sql);
            $this->bind($statement, $bindings);
            $statement->execute();

            return $statement;
        } catch (\PDOException $e) {
            throw DatabaseException::statementFailed($sql, $bindings, $e);
        } finally {
            if ($this->observer !== null) {
                ($this->observer)($sql, \hrtime(true) - $started, $this->name());
            }
        }
    }

    /**
     * Be told how long each statement took.
     *
     * Called after every statement, including one that failed, with the SQL,
     * the nanoseconds from prepare to execute, and this connection's name. The
     * time is the database's answer, not the reading of the rows: a cursor
     * streaming a table is timed up to its first row, because the rest is spent
     * in the caller's loop.
     *
     * **The bindings are not passed**, deliberately and permanently. The SQL
     * is safe to write down because every value in it is bound; the bindings
     * are the values -- a password hash, a card number, a customer's address --
     * and whatever observes queries writes somewhere somebody will read.
     *
     * @param (\Closure(string, int, string): void)|null $observer
     */
    public function observe(?\Closure $observer): void
    {
        $this->observer = $observer;
    }

    /**
     * Bind each value with the type PDO should use.
     *
     * Positional parameters are one-based in PDO and zero-based in PHP arrays,
     * which is the sort of detail that produces an off-by-one nobody enjoys
     * finding.
     *
     * @param array<array-key, mixed> $bindings
     */
    private function bind(\PDOStatement $statement, array $bindings): void
    {
        $position = 1;

        foreach ($bindings as $key => $value) {
            $parameter = \is_int($key) ? $position++ : $key;

            $statement->bindValue($parameter, $value, match (true) {
                \is_int($value) => \PDO::PARAM_INT,
                \is_bool($value) => \PDO::PARAM_BOOL,
                $value === null => \PDO::PARAM_NULL,
                default => \PDO::PARAM_STR,
            });
        }
    }

    // ---- transactions -----------------------------------------------------

    /**
     * Run a callback inside a transaction, committing it or rolling it back.
     *
     * The application decides the boundary, which is why this takes a callback
     * rather than every repository method opening one of its own. An invoice,
     * its lines, its accounting entries and its payment are one transaction
     * because the application says so.
     *
     * @template T
     *
     * @param \Closure(self): T $callback
     *
     * @return T
     */
    public function transaction(\Closure $callback): mixed
    {
        $this->begin();

        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();

            throw $e;
        }
    }

    /**
     * Open a transaction, or a savepoint inside the one already open.
     *
     * PDO has no nested transactions, so the second begin() would silently be a
     * no-op and the first commit() would write everything. Savepoints make
     * nesting mean what it looks like it means.
     *
     * The caveat worth knowing: rolling back to a savepoint undoes the inner
     * work and leaves the outer transaction open. If the calling code swallows
     * the exception that caused it, the outer transaction still commits -- with
     * the inner work gone. Nested transactions are like that everywhere; catch
     * deliberately or not at all.
     */
    public function begin(): void
    {
        if ($this->depth === 0) {
            $this->pdo()->beginTransaction();
            $this->depth = 1;

            return;
        }

        $this->assertSavepointsSupported();
        $this->pdo()->exec('SAVEPOINT ' . $this->savepoint($this->depth + 1));
        ++$this->depth;
    }

    public function commit(): void
    {
        if ($this->depth === 0) {
            throw DatabaseException::notInTransaction('commit');
        }

        if ($this->depth === 1) {
            $this->pdo()->commit();
            $this->depth = 0;

            return;
        }

        // Releasing a savepoint does not commit anything; the outer transaction
        // still decides.
        $this->pdo()->exec('RELEASE SAVEPOINT ' . $this->savepoint($this->depth));
        --$this->depth;
    }

    public function rollBack(): void
    {
        if ($this->depth === 0) {
            throw DatabaseException::notInTransaction('roll back');
        }

        if ($this->depth === 1) {
            $this->pdo()->rollBack();
            $this->depth = 0;

            return;
        }

        $this->pdo()->exec('ROLLBACK TO SAVEPOINT ' . $this->savepoint($this->depth));
        --$this->depth;
    }

    public function transactionDepth(): int
    {
        return $this->depth;
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }

    /** Generated, never supplied, so it is always a safe identifier. */
    private function savepoint(int $depth): string
    {
        return 'framework_savepoint_' . $depth;
    }

    private function assertSavepointsSupported(): void
    {
        // The drivers this framework can reach through PDO either support
        // savepoints or are not relational at all; the check exists so the
        // failure is a sentence rather than a syntax error from the server.
        if (!\in_array($this->driver(), ['mysql', 'pgsql', 'sqlite', 'sqlsrv', 'oci'], true)) {
            throw DatabaseException::driverLacksSavepoints($this->driver());
        }
    }
}
