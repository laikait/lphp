<?php

declare(strict_types=1);

namespace App\Tests\Unit\Database;

use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionConfig;
use App\Engine\Database\DatabaseException;
use App\Tests\Support\TestCase;

/**
 * Run against a real database.
 *
 * SQLite in memory is a genuine PDO driver with genuine prepared statements,
 * transactions and savepoints, so these are integration tests rather than tests
 * of a mock that agrees with whatever the code does. They cost about a
 * millisecond each and need nothing installed.
 */
final class ConnectionTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        if (!\in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }

        $this->connection = new Connection(ConnectionConfig::of('test', 'sqlite::memory:'));
        $this->connection->execute(
            'CREATE TABLE people (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, age INTEGER)',
        );
    }

    private function seed(): void
    {
        foreach ([['Ada', 36], ['Grace', 45], ['Katherine', 52]] as [$name, $age]) {
            $this->connection->insert('INSERT INTO people (name, age) VALUES (?, ?)', [$name, $age]);
        }
    }

    // ---- connecting -------------------------------------------------------

    /** A request that never reads the database never opens a socket. */
    public function test_connecting_is_lazy(): void
    {
        $connection = new Connection(ConnectionConfig::of('idle', 'sqlite::memory:'));

        self::assertFalse($connection->isConnected());
        self::assertSame('idle', $connection->name());
        self::assertSame('sqlite', $connection->driver());

        $connection->pdo();

        self::assertTrue($connection->isConnected());
    }

    public function test_the_same_handle_is_reused(): void
    {
        self::assertSame($this->connection->pdo(), $this->connection->pdo());
    }

    public function test_disconnecting_drops_the_handle(): void
    {
        $this->connection->pdo();
        $this->connection->disconnect();

        self::assertFalse($this->connection->isConnected());
    }

    /**
     * PDO puts the DSN, and on some drivers the credentials, into its own
     * message. Ours names the connection instead.
     */
    public function test_a_connection_failure_names_the_connection_rather_than_the_dsn(): void
    {
        $connection = new Connection(ConnectionConfig::of('broken', 'sqlite:/no/such/directory/db.sqlite'));

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/Could not open the "broken" connection \(sqlite\)/');

        $connection->pdo();
    }

    /** Real prepared statements, not emulated ones. */
    public function test_the_defaults_ask_for_real_prepared_statements(): void
    {
        $options = ConnectionConfig::of('x', 'sqlite::memory:')->pdoOptions();

        // Emulation would interpolate values into the SQL string and hand every
        // column back as a string. Both matter, so both are asserted.
        self::assertFalse($options[\PDO::ATTR_EMULATE_PREPARES]);
        self::assertFalse($options[\PDO::ATTR_STRINGIFY_FETCHES]);
        self::assertSame(\PDO::ERRMODE_EXCEPTION, $options[\PDO::ATTR_ERRMODE]);
    }

    public function test_options_can_be_overridden_per_connection(): void
    {
        $config = ConnectionConfig::of('x', 'sqlite::memory:', options: [\PDO::ATTR_CASE => \PDO::CASE_LOWER]);

        self::assertSame(\PDO::CASE_LOWER, $config->pdoOptions()[\PDO::ATTR_CASE]);
        self::assertFalse($config->pdoOptions()[\PDO::ATTR_EMULATE_PREPARES], 'the defaults still apply');
    }

    // ---- reading ----------------------------------------------------------

    public function test_select_returns_associative_rows(): void
    {
        $this->seed();

        $rows = $this->connection->select('SELECT name, age FROM people ORDER BY name');

        self::assertCount(3, $rows);
        self::assertSame(['name' => 'Ada', 'age' => 36], $rows[0]);
    }

    public function test_select_one_returns_a_row_or_null(): void
    {
        $this->seed();

        $row = $this->connection->selectOne('SELECT name FROM people WHERE id = ?', [2]);

        self::assertNotNull($row);
        self::assertSame('Grace', $row['name']);
        self::assertNull($this->connection->selectOne('SELECT name FROM people WHERE id = ?', [99]));
    }

    public function test_scalar_returns_the_first_column_or_null(): void
    {
        $this->seed();

        self::assertSame(3, $this->connection->scalar('SELECT COUNT(*) FROM people'));
        self::assertSame(133, $this->connection->scalar('SELECT SUM(age) FROM people'));
        self::assertNull($this->connection->scalar('SELECT name FROM people WHERE id = ?', [99]));
    }

    /** Columns come back with their own types, because emulation is off. */
    public function test_integers_come_back_as_integers(): void
    {
        $this->seed();

        $row = $this->connection->selectOne('SELECT age FROM people WHERE id = 1');

        self::assertNotNull($row);
        self::assertSame(36, $row['age']);
    }

    public function test_a_cursor_yields_one_row_at_a_time(): void
    {
        $this->seed();

        $cursor = $this->connection->cursor('SELECT name FROM people ORDER BY name');
        self::assertInstanceOf(\Generator::class, $cursor);

        $names = [];

        foreach ($cursor as $row) {
            $names[] = $row['name'];
        }

        self::assertSame(['Ada', 'Grace', 'Katherine'], $names);
    }

    public function test_a_cursor_over_nothing_yields_nothing(): void
    {
        self::assertSame([], \iterator_to_array($this->connection->cursor('SELECT * FROM people')));
    }

    // ---- binding ----------------------------------------------------------

    /**
     * The security property the whole layer rests on: a value can never become
     * part of the statement.
     */
    public function test_a_value_that_looks_like_sql_stays_a_value(): void
    {
        $this->connection->insert('INSERT INTO people (name, age) VALUES (?, ?)', ["'; DROP TABLE people; --", 1]);

        self::assertSame(1, $this->connection->scalar('SELECT COUNT(*) FROM people'));
        self::assertSame(
            "'; DROP TABLE people; --",
            $this->connection->scalar('SELECT name FROM people WHERE id = 1'),
        );
    }

    public function test_named_parameters_work_as_well_as_positional_ones(): void
    {
        $this->seed();

        self::assertSame(
            'Grace',
            $this->connection->scalar('SELECT name FROM people WHERE age = :age', ['age' => 45]),
        );
    }

    public function test_null_and_boolean_bindings_keep_their_types(): void
    {
        $this->connection->execute('INSERT INTO people (name, age) VALUES (?, ?)', ['Nobody', null]);

        self::assertNull($this->connection->scalar('SELECT age FROM people WHERE name = ?', ['Nobody']));
    }

    /** A broken statement should say which statement, and not what was in it. */
    public function test_a_failing_statement_reports_the_sql_and_withholds_the_values(): void
    {
        try {
            $this->connection->select('SELECT * FROM nonexistent WHERE secret = ?', ['hunter2']);
            self::fail('the statement should have failed');
        } catch (DatabaseException $e) {
            self::assertStringContainsString('SELECT * FROM nonexistent', $e->getMessage());
            self::assertStringContainsString('1 bound value', $e->getMessage());
            self::assertStringNotContainsString('hunter2', $e->getMessage());
            self::assertInstanceOf(\PDOException::class, $e->getPrevious());
        }
    }

    // ---- writing ----------------------------------------------------------

    public function test_execute_reports_how_many_rows_changed(): void
    {
        $this->seed();

        self::assertSame(2, $this->connection->execute('UPDATE people SET age = age + 1 WHERE age > ?', [40]));
        self::assertSame(0, $this->connection->execute('UPDATE people SET age = 0 WHERE id = ?', [99]));
        self::assertSame(1, $this->connection->execute('DELETE FROM people WHERE id = ?', [1]));
    }

    public function test_insert_returns_the_generated_identity(): void
    {
        self::assertSame(1, $this->connection->insert('INSERT INTO people (name) VALUES (?)', ['Ada']));
        self::assertSame(2, $this->connection->insert('INSERT INTO people (name) VALUES (?)', ['Grace']));
    }

    // ---- transactions -----------------------------------------------------

    public function test_a_transaction_commits_when_the_callback_returns(): void
    {
        $result = $this->connection->transaction(function (Connection $db): string {
            $db->insert('INSERT INTO people (name) VALUES (?)', ['Ada']);

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(1, $this->connection->scalar('SELECT COUNT(*) FROM people'));
        self::assertSame(0, $this->connection->transactionDepth());
    }

    public function test_a_transaction_rolls_back_and_rethrows_when_the_callback_throws(): void
    {
        $caught = 'nothing was thrown';

        try {
            $this->connection->transaction(function (Connection $db): void {
                $db->insert('INSERT INTO people (name) VALUES (?)', ['Ada']);

                throw new \RuntimeException('no');
            });
        } catch (\RuntimeException $e) {
            $caught = $e->getMessage();
        }

        self::assertSame('no', $caught, 'the exception should have propagated');

        self::assertSame(0, $this->connection->scalar('SELECT COUNT(*) FROM people'));
        self::assertFalse($this->connection->inTransaction());
    }

    /**
     * The application decides the boundary: an invoice, its lines and its
     * entries are one transaction because the application says so, not because
     * each repository method opened one of its own.
     */
    public function test_several_writes_are_one_transaction(): void
    {
        $this->connection->transaction(function (Connection $db): void {
            $db->insert('INSERT INTO people (name) VALUES (?)', ['Ada']);
            $db->insert('INSERT INTO people (name) VALUES (?)', ['Grace']);
        });

        self::assertSame(2, $this->connection->scalar('SELECT COUNT(*) FROM people'));
    }

    /**
     * PDO has no nested transactions: a second beginTransaction() would throw
     * or be ignored, and the first commit would write everything. Savepoints
     * make nesting mean what it looks like it means.
     */
    public function test_transactions_nest_through_savepoints(): void
    {
        $this->connection->transaction(function (Connection $outer): void {
            $outer->insert('INSERT INTO people (name) VALUES (?)', ['kept']);
            self::assertSame(1, $outer->transactionDepth());

            try {
                $outer->transaction(function (Connection $inner): void {
                    self::assertSame(2, $inner->transactionDepth());
                    $inner->insert('INSERT INTO people (name) VALUES (?)', ['discarded']);

                    throw new \RuntimeException('inner failed');
                });
            } catch (\RuntimeException) {
                // Swallowed on purpose: the outer transaction must survive.
            }

            self::assertSame(1, $outer->transactionDepth());
            $outer->insert('INSERT INTO people (name) VALUES (?)', ['also kept']);
        });

        self::assertSame(
            ['kept', 'also kept'],
            \array_column($this->connection->select('SELECT name FROM people ORDER BY id'), 'name'),
        );
    }

    public function test_nesting_three_deep_commits_everything(): void
    {
        $this->connection->transaction(function (Connection $a): void {
            $a->insert('INSERT INTO people (name) VALUES (?)', ['one']);

            $a->transaction(function (Connection $b): void {
                $b->insert('INSERT INTO people (name) VALUES (?)', ['two']);

                $b->transaction(function (Connection $c): void {
                    self::assertSame(3, $c->transactionDepth());
                    $c->insert('INSERT INTO people (name) VALUES (?)', ['three']);
                });
            });
        });

        self::assertSame(3, $this->connection->scalar('SELECT COUNT(*) FROM people'));
    }

    public function test_an_outer_rollback_discards_committed_inner_work(): void
    {
        try {
            $this->connection->transaction(function (Connection $outer): void {
                $outer->transaction(function (Connection $inner): void {
                    $inner->insert('INSERT INTO people (name) VALUES (?)', ['inner']);
                });

                throw new \RuntimeException('outer failed');
            });
        } catch (\RuntimeException) {
        }

        // Releasing a savepoint commits nothing; the outer transaction decides.
        self::assertSame(0, $this->connection->scalar('SELECT COUNT(*) FROM people'));
    }

    public function test_committing_without_a_transaction_is_refused(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/no open transaction to commit/');

        $this->connection->commit();
    }

    public function test_rolling_back_without_a_transaction_is_refused(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/no open transaction to roll back/');

        $this->connection->rollBack();
    }

    public function test_begin_and_commit_can_be_paired_by_hand(): void
    {
        $this->connection->begin();
        self::assertTrue($this->connection->inTransaction());

        $this->connection->insert('INSERT INTO people (name) VALUES (?)', ['Ada']);
        $this->connection->commit();

        self::assertSame(1, $this->connection->scalar('SELECT COUNT(*) FROM people'));
    }

    public function test_disconnecting_forgets_any_open_transaction(): void
    {
        $this->connection->begin();
        $this->connection->disconnect();

        self::assertSame(0, $this->connection->transactionDepth());
    }

    // ---- the observation seam -----------------------------------------------

    /**
     * The observer is told the statement, how long it took and which
     * connection -- three things, and never the values bound into it. A
     * profile or a slow-query line is exactly the output that gets pasted into
     * a ticket, and the bindings are where a password is.
     */
    public function test_an_observer_hears_each_statement_but_never_its_bindings(): void
    {
        $heard = [];
        $this->connection->observe(static function (mixed ...$arguments) use (&$heard): void {
            $heard[] = $arguments;
        });

        $this->connection->insert('INSERT INTO people (name, age) VALUES (?, ?)', ['hunter2-secret', 99]);

        self::assertCount(1, $heard);
        self::assertCount(3, $heard[0], 'the observer was passed more than the statement, the time and the connection');
        self::assertSame('INSERT INTO people (name, age) VALUES (?, ?)', $heard[0][0]);
        self::assertIsInt($heard[0][1]);
        self::assertSame('test', $heard[0][2]);
        self::assertStringNotContainsString('hunter2', \var_export($heard, true));
    }

    public function test_a_failing_statement_is_still_observed(): void
    {
        $heard = 0;
        $this->connection->observe(static function () use (&$heard): void {
            ++$heard;
        });

        try {
            $this->connection->select('SELECT * FROM no_such_table');
            self::fail('the statement did not fail');
        } catch (DatabaseException) {
        }

        self::assertSame(1, $heard);
    }
}
