<?php

declare(strict_types=1);

namespace App\Tests\Unit\Database;

use App\Engine\Data\ArraySource;
use App\Engine\Data\Direction;
use App\Engine\Data\Operator;
use App\Engine\Data\Query;
use App\Engine\Database\Capability;
use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionConfig;
use App\Engine\Database\DatabaseException;
use App\Engine\Database\IsolationLevel;
use App\Engine\Database\Query\Aggregate;
use App\Engine\Database\Query\JoinClause;
use App\Engine\Database\Query\QueryBuilder;
use App\Engine\Database\Query\RawExpression;
use App\Engine\Database\SqlSource;
use App\Engine\Model\ModelManager;
use App\Tests\Support\TestCase;
use App\Tests\Support\TestDatabases;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What each dialect writes, run on the database it is written for.
 *
 * GrammarTest asserts the SQL as text; this asserts that the database agrees
 * with it. SQLite runs everywhere. MySQL, PostgreSQL and SQL Server run when
 * the environment names a server (see TestDatabases) -- the Windows gate points
 * at the local MariaDB, and CI starts all three.
 *
 * SQLite passing proves nothing about the others, which is the whole reason
 * this file exists: every test runs on every database available.
 */
final class DialectConformanceTest extends TestCase
{
    private const TABLE = 'laika_dialect';

    /** A second table, for joins: tags on the rows of the first. */
    private const TAGS = 'laika_dialect_tags';

    private ?Connection $connection = null;

    /** @return array<string, array{ConnectionConfig}> */
    public static function databases(): array
    {
        return TestDatabases::available();
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null && $this->connection->driver() !== 'sqlite') {
            // A test that failed mid-transaction leaves one open; closing it
            // is the rollback, and the table goes with a fresh session.
            try {
                $this->connection->disconnect();
            } catch (DatabaseException) {
            }

            $this->connection->execute('DROP TABLE IF EXISTS ' . $this->connection->grammar()->identifier(self::TAGS));
            $this->connection->execute('DROP TABLE IF EXISTS ' . $this->connection->grammar()->identifier(self::TABLE));
            $this->connection->disconnect();
        }

        $this->connection = null;
    }

    /**
     * A fresh table in the database's own DDL, with a column named after a
     * reserved word so that every statement below also proves the quoting.
     */
    private function connect(ConnectionConfig $config): Connection
    {
        $db = $this->connection = new Connection($config);
        $grammar = $db->grammar();
        $table = $grammar->identifier(self::TABLE);

        $db->execute('DROP TABLE IF EXISTS ' . $table);

        [$id, $float, $time, $binary] = match ($db->driver()) {
            'mysql' => ['INT AUTO_INCREMENT PRIMARY KEY', 'DOUBLE', 'DATETIME(6)', 'LONGBLOB'],
            'pgsql' => ['SERIAL PRIMARY KEY', 'DOUBLE PRECISION', 'TIMESTAMP(6)', 'BYTEA'],
            'sqlsrv' => ['INT IDENTITY(1,1) PRIMARY KEY', 'FLOAT(53)', 'DATETIME2(6)', 'VARBINARY(MAX)'],
            default => ['INTEGER PRIMARY KEY AUTOINCREMENT', 'REAL', 'TEXT', 'BLOB'],
        };

        $db->execute(\sprintf(
            'CREATE TABLE %s (id %s, name VARCHAR(100), %s INT, amount %s, happened_at %s, content %s)',
            $table,
            $id,
            $grammar->identifier('order'),
            $float,
            $time,
            $binary,
        ));

        return $db;
    }

    private function query(): Query
    {
        return Query::on(new ArraySource(), self::TABLE, new ModelManager());
    }

    /** Five rows, n1 to n5, with "order" 1 to 5. */
    private function seed(Connection $db): void
    {
        $rows = [];

        foreach (\range(1, 5) as $i) {
            $rows[] = ['name' => 'n' . $i, 'order' => $i];
        }

        foreach ($db->grammar()->compileInsertMany(self::TABLE, ['name', 'order'], $rows) as $statement) {
            $db->execute($statement['sql'], $statement['bindings']);
        }
    }

    /** @return list<mixed> */
    private function names(Connection $db, Query $query): array
    {
        $compiled = $db->grammar()->compileSelect($query);

        return \array_column($db->select($compiled['sql'], $compiled['bindings']), 'name');
    }

    private function rowsIn(Connection $db): int
    {
        return (int) $db->scalar('SELECT COUNT(*) FROM ' . $db->grammar()->identifier(self::TABLE));
    }

    // ---- quoting and paging -----------------------------------------------

    #[DataProvider('databases')]
    public function test_a_reserved_word_works_as_a_column_once_quoted(ConnectionConfig $config): void
    {
        $db = $this->connect($config);

        $insert = $db->grammar()->compileInsert(self::TABLE, ['name' => 'quoted', 'order' => 7]);
        $db->execute($insert['sql'], $insert['bindings']);

        $compiled = $db->grammar()->compileSelect(
            $this->query()->select('name', 'order')->whereIs('order', 7)->orderBy('order'),
        );

        self::assertSame([['name' => 'quoted', 'order' => 7]], \array_map(
            static fn(array $row): array => ['name' => $row['name'], 'order' => (int) $row['order']],
            $db->select($compiled['sql'], $compiled['bindings']),
        ));
    }

    #[DataProvider('databases')]
    public function test_limit_and_offset_page_through_the_rows(ConnectionConfig $config): void
    {
        $db = $this->connect($config);
        $this->seed($db);

        self::assertSame(['n2', 'n3'], $this->names($db, $this->query()->orderBy('order')->limit(2)->offset(1)));
        self::assertSame(['n4', 'n5'], $this->names($db, $this->query()->orderBy('order')->offset(3)));
        self::assertSame(['n5', 'n4'], $this->names($db, $this->query()->orderBy('order', Direction::Desc)->limit(2)));
        self::assertSame([], $this->names($db, $this->query()->orderBy('order')->limit(0)));
    }

    /** SQL Server needs an ORDER BY to page at all; the dialect supplies one. */
    #[DataProvider('databases')]
    public function test_a_query_with_no_order_can_still_be_paged(ConnectionConfig $config): void
    {
        $db = $this->connect($config);
        $this->seed($db);

        self::assertCount(2, $this->names($db, $this->query()->limit(2)));
        self::assertCount(3, $this->names($db, $this->query()->offset(2)));
        self::assertSame([], $this->names($db, $this->query()->limit(0)));
    }

    #[DataProvider('databases')]
    public function test_a_count_ignores_the_slice(ConnectionConfig $config): void
    {
        $db = $this->connect($config);
        $this->seed($db);

        $compiled = $db->grammar()->compileCount($this->query()->where('order', Operator::Gt, 1)->limit(2));

        self::assertSame(4, (int) $db->scalar($compiled['sql'], $compiled['bindings']));
    }

    /** One more row than fits in a statement: two statements, every row written. */
    #[DataProvider('databases')]
    public function test_a_bulk_insert_splits_at_the_placeholder_limit(ConnectionConfig $config): void
    {
        $db = $this->connect($config);
        $rows = [];

        foreach (\range(1, \intdiv($db->grammar()->maxBindings(), 2) + 1) as $i) {
            $rows[] = ['name' => 'r' . $i, 'order' => $i];
        }

        $statements = $db->grammar()->compileInsertMany(self::TABLE, ['name', 'order'], $rows);
        self::assertCount(2, $statements);

        $db->transaction(function (Connection $db) use ($statements): void {
            foreach ($statements as $statement) {
                $db->execute($statement['sql'], $statement['bindings']);
            }
        });

        self::assertSame(\count($rows), $this->rowsIn($db));
    }

    // ---- the query builder ------------------------------------------------

    #[DataProvider('databases')]
    public function test_the_builder_filters_groups_and_pages(ConnectionConfig $config): void
    {
        $db = $this->connect($config);
        $this->seed($db);

        $names = static fn(array $rows): array => \array_column($rows, 'name');

        self::assertSame(['n2', 'n5'], $names($db->table(self::TABLE)
            ->select('name')
            ->whereIn('order', [2, 4, 5])
            ->where(fn(QueryBuilder $q): QueryBuilder => $q->where('name', 'n2')->orWhere('order', '>', 4))
            ->orderBy('order')
            ->get()));

        self::assertSame(['n3', 'n2'], $names($db->table(self::TABLE)
            ->select('name')
            ->whereBetween('order', 2, 4)
            ->whereNotNull('name')
            ->whereNull('happened_at')
            ->orderByDesc('order')
            ->limit(2)
            ->offset(1)
            ->get()));

        self::assertSame([], $db->table(self::TABLE)->whereIn('order', [])->get());
        self::assertSame(5, $db->table(self::TABLE)->whereNotIn('order', [])->count());
    }

    /** Qualified names, aliases and a reserved word, each quoted in the dialect. */
    #[DataProvider('databases')]
    public function test_the_builder_quotes_qualified_names_and_aliases(ConnectionConfig $config): void
    {
        $db = $this->connect($config);
        $this->seed($db);

        $row = $db->table(self::TABLE . ' AS d')
            ->select('d.name AS label', 'd.order')
            ->where('d.order', '>=', 4)
            ->orderBy('d.order')
            ->first();

        self::assertNotNull($row);
        self::assertSame('n4', $row['label']);
        self::assertSame(4, (int) $row['order']);
    }

    #[DataProvider('databases')]
    public function test_the_builder_counts_checks_and_runs_raw_sql(ConnectionConfig $config): void
    {
        $db = $this->connect($config);
        $this->seed($db);

        self::assertSame(3, $db->table(self::TABLE)->where('order', '>', 2)->orderBy('order')->limit(1)->count());
        self::assertTrue($db->table(self::TABLE)->where('name', 'n5')->exists());
        self::assertFalse($db->table(self::TABLE)->where('name', 'n9')->exists());
        self::assertNull($db->table(self::TABLE)->where('name', 'n9')->first());

        $raw = $db->table(self::TABLE)
            ->select(new RawExpression('COUNT(*) AS matched'))
            ->where(new RawExpression('UPPER(name) = ? OR UPPER(name) = ?', ['N1', 'N2']))
            ->first();

        self::assertNotNull($raw);
        self::assertSame(2, (int) $raw['matched']);

        $streamed = [];

        foreach ($db->table(self::TABLE)->select('name')->where('order', '<=', 2)->orderBy('order')->cursor() as $row) {
            $streamed[] = $row['name'];
        }

        self::assertSame(['n1', 'n2'], $streamed);
    }

    /** A value is bound on every database, whatever it looks like. */
    #[DataProvider('databases')]
    public function test_the_builder_binds_a_value_that_looks_like_sql(ConnectionConfig $config): void
    {
        $db = $this->connect($config);
        $this->seed($db);

        self::assertSame([], $db->table(self::TABLE)->where('name', "' OR '1'='1")->get());
        self::assertSame(5, $db->table(self::TABLE)->count(), 'the table is still whole');
    }

    // ---- joins, grouping and aggregates -----------------------------------

    /** n1 to n5 as in seed(), and tags: n1 red and blue, n2 red, n5 green. */
    private function seedTags(Connection $db): void
    {
        $this->seed($db);

        $tags = $db->grammar()->identifier(self::TAGS);
        $db->execute('DROP TABLE IF EXISTS ' . $tags);
        $db->execute('CREATE TABLE ' . $tags . ' (dialect_id INT, tag VARCHAR(20), weight INT)');

        $db->table(self::TAGS)->insertMany([
            ['dialect_id' => 1, 'tag' => 'red', 'weight' => 3],
            ['dialect_id' => 1, 'tag' => 'blue', 'weight' => 1],
            ['dialect_id' => 2, 'tag' => 'red', 'weight' => 4],
            ['dialect_id' => 5, 'tag' => 'green', 'weight' => 10],
        ]);
    }

    #[DataProvider('databases')]
    public function test_inner_and_left_joins(ConnectionConfig $config): void
    {
        $db = $this->connect($config);
        $this->seedTags($db);

        $pairs = \array_map(
            static fn(array $row): string => $row['name'] . ':' . $row['tag'],
            $db->table(self::TABLE . ' AS d')
                ->select('d.name', 't.tag')
                ->join(self::TAGS . ' AS t', 't.dialect_id', '=', 'd.id')
                ->orderBy('d.id')
                ->orderBy('t.tag')
                ->get(),
        );
        self::assertSame(['n1:blue', 'n1:red', 'n2:red', 'n5:green'], $pairs);

        $untagged = $db->table(self::TABLE . ' AS d')
            ->select('d.name')
            ->leftJoin(self::TAGS . ' AS t', fn(JoinClause $j): JoinClause => $j->on('t.dialect_id', '=', 'd.id')->where('t.tag', 'red'))
            ->whereNull('t.tag')
            ->orderBy('d.id')
            ->get();
        self::assertSame(['n3', 'n4', 'n5'], \array_column($untagged, 'name'), 'the rows with no red tag');
    }

    #[DataProvider('databases')]
    public function test_a_right_join_or_its_refusal(ConnectionConfig $config): void
    {
        $db = $this->connect($config);
        $this->seedTags($db);

        $query = $db->table(self::TAGS . ' AS t')
            ->select('t.tag', 'd.name')
            ->rightJoin(self::TABLE . ' AS d', 'd.id', '=', 't.dialect_id')
            ->whereNull('t.tag');

        if (!$db->supports(Capability::RightJoin)) {
            $this->expectException(DatabaseException::class);
            $this->expectExceptionMessage('cannot do "right_join"');
        }

        self::assertSame(['n3', 'n4'], \array_column($query->orderBy('d.id')->get(), 'name'));
    }

    #[DataProvider('databases')]
    public function test_groups_having_and_a_grouped_count(ConnectionConfig $config): void
    {
        $db = $this->connect($config);
        $this->seedTags($db);

        $grouped = $db->table(self::TAGS)
            ->select('tag', Aggregate::count(as: 'uses'), Aggregate::sum('weight', as: 'total'))
            ->groupBy('tag');

        $rows = \array_map(
            static fn(array $row): array => [$row['tag'], (int) $row['uses'], (int) $row['total']],
            $grouped->orderBy('tag')->get(),
        );
        self::assertSame([['blue', 1, 1], ['green', 1, 10], ['red', 2, 7]], $rows);

        self::assertSame(3, $grouped->count(), 'three groups');
        self::assertSame(
            ['green', 'red'],
            \array_column($grouped->having(Aggregate::sum('weight'), '>', 5)->orderBy('tag')->get(), 'tag'),
        );
        self::assertSame(
            ['red'],
            \array_column($grouped->having(Aggregate::count(), '>=', 2)->get(), 'tag'),
        );
        self::assertTrue($grouped->having(Aggregate::count(), '=', 2)->exists());
        self::assertFalse($grouped->having(Aggregate::count(), '>', 2)->exists());
    }

    #[DataProvider('databases')]
    public function test_sum_avg_min_and_max(ConnectionConfig $config): void
    {
        $db = $this->connect($config);
        $this->seedTags($db);

        $tags = $db->table(self::TAGS);

        self::assertSame(18, (int) $tags->sum('weight'));
        self::assertSame(7, (int) $tags->where('tag', 'red')->sum('weight'));
        self::assertSame(3.5, (float) $tags->where('tag', 'red')->avg('weight'));
        self::assertSame(1, (int) $tags->min('weight'));
        self::assertSame('red', $tags->max('tag'));
        self::assertNull($tags->where('tag', 'purple')->sum('weight'), 'no rows is null, not zero');
    }

    // ---- writing ----------------------------------------------------------

    #[DataProvider('databases')]
    public function test_an_insert_hands_back_the_key_the_database_generated(ConnectionConfig $config): void
    {
        $db = $this->connect($config);

        self::assertSame(1, $db->table(self::TABLE)->insert(['name' => 'first'], 'id'));
        self::assertSame(2, $db->table(self::TABLE)->insert(['name' => 'second'], 'id'));

        // The data layer's insert goes the same way.
        $source = new SqlSource($db);
        self::assertSame(3, $source->insert(self::TABLE, 'id', ['name' => 'third']));
        self::assertSame(['n' => 'third'], $db->table(self::TABLE)->select('name AS n')->where('id', 3)->first());
    }

    /**
     * PostgreSQL's last-insert id is LASTVAL(): stale after an insert into a
     * table with no sequence, and inside a transaction that used none, an error
     * that aborts the transaction. It is never asked any more.
     */
    #[DataProvider('databases')]
    public function test_an_insert_into_a_table_without_a_generated_key_leaves_the_transaction_usable(ConnectionConfig $config): void
    {
        $db = $this->connect($config);
        $codes = $db->grammar()->identifier('laika_dialect_codes');
        $db->execute('DROP TABLE IF EXISTS ' . $codes);
        $db->execute('CREATE TABLE ' . $codes . ' (code VARCHAR(20) PRIMARY KEY, name VARCHAR(100))');

        try {
            $db->transaction(function (Connection $db) use ($config): void {
                $db->table('laika_dialect_codes')->insert(['code' => 'A', 'name' => 'no sequence yet'], 'code');
                $db->table(self::TABLE)->insert(['name' => 'serial'], 'id');

                $identity = $db->insert('INSERT INTO laika_dialect_codes (code, name) VALUES (?, ?)', ['B', 'after a sequence']);

                if ($config->driver() === 'pgsql') {
                    self::assertNull($identity, 'a stale key from another table was reported');
                }

                // Still one transaction, still usable.
                self::assertSame(2, $db->table('laika_dialect_codes')->count());
            });

            self::assertSame(2, $db->table('laika_dialect_codes')->count());
        } finally {
            $db->execute('DROP TABLE IF EXISTS ' . $codes);
        }
    }

    #[DataProvider('databases')]
    public function test_the_builder_writes_and_counts_what_it_wrote(ConnectionConfig $config): void
    {
        $db = $this->connect($config);

        self::assertSame(3, $db->table(self::TABLE)->insertMany([
            ['name' => 'a', 'order' => 1],
            ['order' => 2, 'name' => 'b'],
            ['name' => 'c', 'order' => 3],
        ]));

        self::assertSame(2, $db->table(self::TABLE)->where('order', '>=', 2)->update(['name' => 'late']));
        self::assertSame(1, $db->table(self::TABLE)->where('name', 'a')->update(['order' => new RawExpression($db->grammar()->identifier('order') . ' + ?', [10])]));
        self::assertSame(['order' => 11], \array_map('intval', $db->table(self::TABLE)->select('order')->where('name', 'a')->first() ?? []));

        self::assertSame(2, $db->table(self::TABLE)->where('name', 'late')->delete());
        self::assertSame(1, $db->table(self::TABLE)->deleteAll());
        self::assertSame(0, $db->table(self::TABLE)->count());
    }

    #[DataProvider('databases')]
    public function test_an_upsert_inserts_and_updates_or_is_refused_where_there_is_none(ConnectionConfig $config): void
    {
        $db = $this->connect($config);

        if (!$db->supports(Capability::Upsert)) {
            $this->expectException(DatabaseException::class);
            $this->expectExceptionMessage('cannot do "upsert"');
        }

        $db->table(self::TABLE)->insert(['id' => 1, 'name' => 'old', 'order' => 1]);

        $db->table(self::TABLE)->upsert([
            ['id' => 1, 'name' => 'new', 'order' => 9],
            ['id' => 2, 'name' => 'added', 'order' => 2],
        ], uniqueBy: ['id'], update: ['name']);

        self::assertSame(
            [['id' => 1, 'name' => 'new', 'order' => 1], ['id' => 2, 'name' => 'added', 'order' => 2]],
            \array_map(
                static fn(array $row): array => ['id' => (int) $row['id'], 'name' => $row['name'], 'order' => (int) $row['order']],
                $db->table(self::TABLE)->select('id', 'name', 'order')->orderBy('id')->get(),
            ),
        );
    }

    // ---- isolation and retry ----------------------------------------------

    /**
     * The level the current transaction runs at, as the database itself reports
     * it. Not asked of MySQL: MariaDB's information_schema.innodb_trx reports a
     * stale level for a reused connection, so MySQL is judged by what the
     * transaction can see instead (see the next test).
     */
    private function isolationNow(Connection $db): string
    {
        $level = match ($db->driver()) {
            'pgsql' => $db->scalar('SHOW transaction_isolation'),
            'sqlsrv' => match ((int) $db->scalar('SELECT transaction_isolation_level FROM sys.dm_exec_sessions WHERE session_id = @@SPID')) {
                1 => 'read uncommitted',
                2 => 'read committed',
                3 => 'repeatable read',
                4 => 'serializable',
                default => 'snapshot',
            },
            default => 'serializable',
        };

        return \strtolower(\is_string($level) ? $level : '');
    }

    #[DataProvider('databases')]
    public function test_a_transaction_runs_at_the_level_it_asked_for_and_the_next_one_does_not(ConnectionConfig $config): void
    {
        if ($config->driver() === 'mysql') {
            self::markTestSkipped('MySQL reports no reliable level; its isolation is tested by behaviour.');
        }

        $db = $this->connect($config);

        $levels = $db->driver() === 'sqlite'
            ? [IsolationLevel::Serializable]
            : [IsolationLevel::ReadCommitted, IsolationLevel::RepeatableRead, IsolationLevel::Serializable];

        $default = $db->transaction(fn(Connection $db): string => $this->isolationNow($db));

        foreach ($levels as $level) {
            self::assertSame(
                \strtolower($level->value),
                $db->transaction(fn(Connection $db): string => $this->isolationNow($db), isolation: $level),
                $level->value,
            );

            // SQL Server sets the level for the session; it must not leak into the next transaction.
            self::assertSame($default, $db->transaction(fn(Connection $db): string => $this->isolationNow($db)), 'after ' . $level->value);
        }
    }

    /**
     * Judged by what the transaction sees: it reads a row, another connection
     * changes and commits it, and it reads again. READ COMMITTED sees the
     * change; REPEATABLE READ, MySQL's default, does not. SERIALIZABLE is left
     * out, because there the second connection's update would wait for this
     * transaction to finish, which one process cannot do.
     */
    #[DataProvider('databases')]
    public function test_mysql_runs_at_the_level_asked_for_judged_by_what_it_sees(ConnectionConfig $config): void
    {
        if ($config->driver() !== 'mysql') {
            self::markTestSkipped('The other databases report their level; see the previous test.');
        }

        $db = $this->connect($config);
        $db->table(self::TABLE)->insert(['id' => 1, 'order' => 0]);
        $rival = new Connection($config);
        $version = 0;

        $seesTheCommit = function (?IsolationLevel $level) use ($db, $rival, &$version): bool {
            return $db->transaction(function (Connection $db) use ($rival, &$version): bool {
                $before = $db->table(self::TABLE)->where('id', 1)->max('order');
                $rival->table(self::TABLE)->where('id', 1)->update(['order' => ++$version]);

                return $db->table(self::TABLE)->where('id', 1)->max('order') !== $before;
            }, isolation: $level);
        };

        try {
            self::assertFalse($seesTheCommit(null), 'the default is REPEATABLE READ');
            self::assertTrue($seesTheCommit(IsolationLevel::ReadCommitted));
            self::assertFalse($seesTheCommit(null), 'the level was for one transaction only');
            self::assertFalse($seesTheCommit(IsolationLevel::RepeatableRead));
            self::assertTrue($seesTheCommit(IsolationLevel::ReadCommitted));
        } finally {
            $rival->disconnect();
        }
    }

    /**
     * A real serialization failure: two SERIALIZABLE transactions each read what
     * the other writes. The second to commit fails with 40001, and a retry --
     * this time without the competing writer -- succeeds.
     *
     * PostgreSQL only: its serializable level detects the conflict without
     * blocking, so both transactions can be driven from one process. MySQL and
     * SQL Server lock instead, and the second writer would wait on the first.
     */
    #[DataProvider('databases')]
    public function test_a_real_serialization_failure_is_retried(ConnectionConfig $config): void
    {
        if ($config->driver() !== 'pgsql') {
            self::markTestSkipped($config->driver() . ' locks rather than detecting the conflict, so one process cannot race itself.');
        }

        $db = $this->connect($config);
        $db->table(self::TABLE)->insertMany([['name' => 'a', 'order' => 1], ['name' => 'b', 'order' => 1]]);

        $rival = new Connection($config);
        $attempts = 0;

        try {
            $db->transaction(function (Connection $db) use ($rival, &$attempts): void {
                ++$attempts;
                $total = (int) $db->table(self::TABLE)->sum('order');

                if ($attempts === 1) {
                    $rival->transaction(function (Connection $rival): void {
                        $rival->table(self::TABLE)->sum('order');
                        $rival->table(self::TABLE)->insert(['name' => 'rival', 'order' => 1]);
                    }, isolation: IsolationLevel::Serializable);
                }

                $db->table(self::TABLE)->insert(['name' => 'total ' . $total, 'order' => 1]);
            }, isolation: IsolationLevel::Serializable, retries: 2);
        } finally {
            $rival->disconnect();
        }

        self::assertSame(2, $attempts, 'the first attempt should have failed to serialize');
        self::assertSame(
            ['a', 'b', 'rival', 'total 3'],
            \array_column($db->table(self::TABLE)->select('name')->orderBy('id')->get(), 'name'),
            'the retry saw the rival\'s row',
        );
    }

    // ---- transactions -----------------------------------------------------

    #[DataProvider('databases')]
    public function test_an_inner_rollback_keeps_the_outer_work(ConnectionConfig $config): void
    {
        $db = $this->connect($config);
        $insert = $db->grammar()->compileInsert(self::TABLE, ['name' => 'kept']);

        $db->transaction(function (Connection $outer) use ($insert): void {
            $outer->execute($insert['sql'], $insert['bindings']);

            try {
                $outer->transaction(function (Connection $inner) use ($insert): void {
                    $inner->execute($insert['sql'], ['discarded']);

                    throw new \RuntimeException('inner failed');
                });
            } catch (\RuntimeException) {
            }

            $outer->execute($insert['sql'], ['also kept']);
        });

        self::assertSame(['kept', 'also kept'], $this->names($db, $this->query()->orderBy('id')));
    }

    #[DataProvider('databases')]
    public function test_three_levels_commit_together_and_roll_back_together(ConnectionConfig $config): void
    {
        $db = $this->connect($config);
        $insert = $db->grammar()->compileInsert(self::TABLE, ['name' => 'x']);

        $nest = static function (Connection $db) use ($insert): void {
            $db->transaction(static function (Connection $a) use ($insert): void {
                $a->execute($insert['sql'], ['one']);
                $a->transaction(static function (Connection $b) use ($insert): void {
                    $b->execute($insert['sql'], ['two']);
                    $b->transaction(static function (Connection $c) use ($insert): void {
                        $c->execute($insert['sql'], ['three']);
                    });
                });
            });
        };

        $nest($db);
        self::assertSame(3, $this->rowsIn($db));

        try {
            $db->transaction(static function (Connection $db) use ($nest): void {
                $nest($db);

                throw new \RuntimeException('outermost failed');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(3, $this->rowsIn($db), 'released savepoints committed nothing on their own');
        self::assertSame(0, $db->transactionDepth());
    }

    #[DataProvider('databases')]
    public function test_the_callbacks_exception_survives_a_failing_rollback(ConnectionConfig $config): void
    {
        $db = $this->connect($config);
        $caught = null;

        try {
            $db->transaction(static function (Connection $db): void {
                $db->pdo()->commit();

                throw new \RuntimeException('the real failure');
            });
        } catch (\Throwable $e) {
            $caught = $e;
        }

        self::assertInstanceOf(\RuntimeException::class, $caught);
        self::assertSame('the real failure', $caught->getMessage());
        self::assertFalse($db->isConnected());
    }

    /** SQL Server keeps a saved transaction until the end, so it cannot be removed early to force this. */
    #[DataProvider('databases')]
    public function test_an_inner_rollback_that_fails_loses_the_transaction(ConnectionConfig $config): void
    {
        $db = $this->connect($config);
        $release = $db->grammar()->compileReleaseSavepoint('framework_savepoint_2');

        if ($release === null) {
            self::markTestSkipped($config->driver() . ' cannot release a savepoint early.');
        }

        $insert = $db->grammar()->compileInsert(self::TABLE, ['name' => 'x']);
        $refused = 'the write was allowed';

        try {
            $db->transaction(static function (Connection $outer) use ($release, $insert): void {
                $outer->execute($insert['sql'], ['outer']);

                try {
                    $outer->transaction(static function (Connection $inner) use ($release): void {
                        $inner->pdo()->exec($release);

                        throw new \RuntimeException('inner failed');
                    });
                } catch (\RuntimeException) {
                }

                $outer->execute($insert['sql'], ['after the loss']);
            });
        } catch (DatabaseException $e) {
            $refused = $e->getMessage();
        }

        self::assertStringContainsString('was lost', $refused);
        self::assertSame(0, $db->transactionDepth());

        // An in-memory SQLite database closes with its session, table and all,
        // which is its own proof that nothing survived.
        if ($config->driver() !== 'sqlite') {
            self::assertSame(0, $this->rowsIn($db), 'nothing from the lost transaction may survive');
        }
    }

    // ---- values -----------------------------------------------------------

    #[DataProvider('databases')]
    public function test_floats_and_dates_come_back_as_they_went_in(ConnectionConfig $config): void
    {
        $db = $this->connect($config);
        $grammar = $db->grammar();
        $at = new \DateTimeImmutable('2026-01-02 03:04:05.25');

        $insert = $grammar->compileInsert(self::TABLE, ['name' => 'v', 'amount' => 0.1 + 0.2, 'happened_at' => $at]);
        $db->execute($insert['sql'], $insert['bindings']);

        $row = $db->selectOne(\sprintf(
            'SELECT amount, happened_at FROM %s',
            $grammar->identifier(self::TABLE),
        ));

        self::assertNotNull($row);
        self::assertSame(0.1 + 0.2, (float) $row['amount']);
        self::assertIsString($row['happened_at']);
        self::assertSame(
            '2026-01-02 03:04:05.250000',
            (new \DateTimeImmutable($row['happened_at']))->format('Y-m-d H:i:s.u'),
        );
    }

    #[DataProvider('databases')]
    public function test_binary_data_comes_back_byte_for_byte(ConnectionConfig $config): void
    {
        if ($config->driver() === 'sqlsrv') {
            self::markTestSkipped('Binding a stream as binary on SQL Server needs its own encoding attribute, not yet written.');
        }

        $db = $this->connect($config);
        $bytes = "\x00\xFF\x7F binary \x00";

        $stream = \fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        \fwrite($stream, $bytes);
        \rewind($stream);

        $insert = $db->grammar()->compileInsert(self::TABLE, ['name' => 'b', 'content' => $stream]);
        $db->execute($insert['sql'], $insert['bindings']);

        $content = $db->scalar('SELECT content FROM ' . $db->grammar()->identifier(self::TABLE));

        // PostgreSQL hands a bytea back as a stream.
        if (\is_resource($content)) {
            $content = \stream_get_contents($content);
        }

        self::assertSame($bytes, $content);
    }
}
