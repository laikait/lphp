<?php

declare(strict_types=1);

namespace App\Tests\Unit\Database;

use App\Engine\Data\ArraySource;
use App\Engine\Data\Operator;
use App\Engine\Data\Query;
use App\Engine\Database\DatabaseException;
use App\Engine\Database\Grammar;
use App\Engine\Model\ModelManager;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class GrammarTest extends TestCase
{
    private function query(): Query
    {
        return Query::on(new ArraySource(), 'customers', new ModelManager());
    }

    private function grammar(string $driver = 'sqlite'): Grammar
    {
        return new Grammar($driver);
    }

    // ---- identifiers: the injection boundary ------------------------------

    /**
     * No database accepts a parameter where a column goes, so identifiers are
     * the one part of a statement written in rather than bound. This is
     * therefore the only place injection could live, and the pattern is what
     * keeps it from doing so.
     */
    #[DataProvider('dangerousIdentifiers')]
    public function test_an_identifier_that_is_not_a_plain_name_is_refused(string $identifier): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/is not a plain name/');

        $this->grammar()->identifier($identifier);
    }

    /** @return array<string, array{0: string}> */
    public static function dangerousIdentifiers(): array
    {
        return [
            'a statement' => ['id; DROP TABLE customers'],
            'a comment' => ['id -- '],
            'a quote' => ["id' OR '1'='1"],
            'a backtick' => ['id`'],
            'a double quote' => ['id"'],
            'a space' => ['customer id'],
            'a dot' => ['customers.id'],
            'a star' => ['*'],
            'a function call' => ['COUNT(*)'],
            'a leading digit' => ['1st'],
            'empty' => [''],
            'a newline' => ["id\nDROP"],
            'a null byte' => ["id\0"],
            'unicode' => ['naïve'],
        ];
    }

    public function test_a_plain_name_is_quoted_for_its_driver(): void
    {
        self::assertSame('`name`', $this->grammar('mysql')->identifier('name'));
        self::assertSame('"name"', $this->grammar('pgsql')->identifier('name'));
        self::assertSame('"name"', $this->grammar('sqlite')->identifier('name'));
        self::assertSame('[name]', $this->grammar('sqlsrv')->identifier('name'));
    }

    public function test_underscores_and_digits_are_fine_after_the_first_character(): void
    {
        self::assertSame('"customer_id_2"', $this->grammar()->identifier('customer_id_2'));
        self::assertSame('"_internal"', $this->grammar()->identifier('_internal'));
    }

    /** An ordering column arriving from a query string must not reach SQL. */
    public function test_a_sort_column_from_a_request_cannot_reach_the_statement(): void
    {
        $this->expectException(DatabaseException::class);

        $this->grammar()->compileSelect($this->query()->orderBy('name; DELETE FROM customers'));
    }

    public function test_a_filtered_column_name_is_checked_too(): void
    {
        $this->expectException(DatabaseException::class);

        $this->grammar()->compileSelect($this->query()->whereIs('id = 1 OR 1', 1));
    }

    // ---- selects ----------------------------------------------------------

    public function test_a_bare_query_selects_everything(): void
    {
        self::assertSame(
            ['sql' => 'SELECT * FROM "customers"', 'bindings' => []],
            $this->grammar()->compileSelect($this->query()),
        );
    }

    public function test_selected_columns_are_listed_and_quoted(): void
    {
        self::assertSame(
            'SELECT "id", "name" FROM "customers"',
            $this->grammar()->compileSelect($this->query()->select('id', 'name'))['sql'],
        );
    }

    public function test_criteria_become_placeholders_and_bindings(): void
    {
        $compiled = $this->grammar()->compileSelect(
            $this->query()->whereIs('active', true)->where('balance', Operator::Gt, 100),
        );

        self::assertSame(
            'SELECT * FROM "customers" WHERE "active" = ? AND "balance" > ?',
            $compiled['sql'],
        );
        self::assertSame([true, 100], $compiled['bindings']);
    }

    public function test_null_comparisons_take_no_placeholder(): void
    {
        self::assertSame(
            'SELECT * FROM "customers" WHERE "ownerId" IS NULL',
            $this->grammar()->compileSelect($this->query()->whereNull('ownerId'))['sql'],
        );

        self::assertSame(
            [],
            $this->grammar()->compileSelect($this->query()->whereNotNull('ownerId'))['bindings'],
        );
    }

    public function test_a_list_gets_one_placeholder_per_value(): void
    {
        $compiled = $this->grammar()->compileSelect($this->query()->whereIn('id', [1, 2, 3]));

        self::assertSame('SELECT * FROM "customers" WHERE "id" IN (?, ?, ?)', $compiled['sql']);
        self::assertSame([1, 2, 3], $compiled['bindings']);

        self::assertStringContainsString(
            'NOT IN (?, ?)',
            $this->grammar()->compileSelect($this->query()->whereNotIn('id', [1, 2]))['sql'],
        );
    }

    public function test_a_like_pattern_is_bound_rather_than_written_in(): void
    {
        $compiled = $this->grammar()->compileSelect($this->query()->whereLike('name', "%'; DROP--%"));

        self::assertSame('SELECT * FROM "customers" WHERE "name" LIKE ?', $compiled['sql']);
        self::assertSame(["%'; DROP--%"], $compiled['bindings']);
    }

    public function test_ordering_is_compiled_in_the_order_declared(): void
    {
        self::assertStringEndsWith(
            'ORDER BY "active" ASC, "name" DESC',
            $this->grammar()->compileSelect($this->query()->orderBy('active')->orderByDesc('name'))['sql'],
        );
    }

    /**
     * Limits are written in rather than bound. They are typed int in PHP by the
     * time they arrive, so there is nothing to inject, and binding them is the
     * one thing several drivers get wrong.
     */
    public function test_limits_and_offsets_are_written_as_integers(): void
    {
        self::assertStringEndsWith(
            'LIMIT 10',
            $this->grammar()->compileSelect($this->query()->limit(10))['sql'],
        );

        self::assertStringEndsWith(
            'LIMIT 10 OFFSET 20',
            $this->grammar()->compileSelect($this->query()->limit(10)->offset(20))['sql'],
        );

        self::assertSame(
            [],
            $this->grammar()->compileSelect($this->query()->limit(10)->offset(20))['bindings'],
        );
    }

    /** Most drivers will not take an offset without a limit. */
    public function test_an_offset_without_a_limit_still_gets_one(): void
    {
        $sql = $this->grammar()->compileSelect($this->query()->offset(5))['sql'];

        self::assertStringContainsString('LIMIT ' . \PHP_INT_MAX, $sql);
        self::assertStringEndsWith('OFFSET 5', $sql);
    }

    public function test_a_query_with_no_limit_says_nothing_about_limits(): void
    {
        self::assertStringNotContainsString('LIMIT', $this->grammar()->compileSelect($this->query())['sql']);
    }

    // ---- counts -----------------------------------------------------------

    /** The data layer promises a count ignores the slice; so does the SQL. */
    public function test_a_count_keeps_the_criteria_and_drops_the_slice(): void
    {
        $compiled = $this->grammar()->compileCount(
            $this->query()->whereIs('active', true)->orderBy('name')->limit(10)->offset(20),
        );

        self::assertSame('SELECT COUNT(*) FROM "customers" WHERE "active" = ?', $compiled['sql']);
        self::assertSame([true], $compiled['bindings']);
    }

    // ---- writes -----------------------------------------------------------

    public function test_an_insert_lists_its_columns_and_binds_its_values(): void
    {
        $compiled = $this->grammar()->compileInsert('customers', ['name' => 'Ada', 'email' => 'ada@example.test']);

        self::assertSame('INSERT INTO "customers" ("name", "email") VALUES (?, ?)', $compiled['sql']);
        self::assertSame(['Ada', 'ada@example.test'], $compiled['bindings']);
    }

    public function test_an_insert_with_no_columns_is_refused(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/given no columns/');

        $this->grammar()->compileInsert('customers', []);
    }

    public function test_an_update_sets_only_what_changed_and_ends_with_the_key(): void
    {
        $compiled = $this->grammar()->compileUpdate('customers', 'id', 7, ['name' => 'Ada King']);

        self::assertSame('UPDATE "customers" SET "name" = ? WHERE "id" = ?', $compiled['sql']);
        self::assertSame(['Ada King', 7], $compiled['bindings']);
    }

    public function test_an_update_with_no_changes_is_refused(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/change no columns/');

        $this->grammar()->compileUpdate('customers', 'id', 7, []);
    }

    public function test_a_delete_targets_one_row_by_its_key(): void
    {
        $compiled = $this->grammar()->compileDelete('customers', 'code', 'GB');

        self::assertSame('DELETE FROM "customers" WHERE "code" = ?', $compiled['sql']);
        self::assertSame(['GB'], $compiled['bindings']);
    }

    public function test_a_column_name_in_a_write_is_checked_as_well(): void
    {
        $this->expectException(DatabaseException::class);

        $this->grammar()->compileInsert('customers', ['name) VALUES (1); --' => 'x']);
    }

    // ---- writing many -----------------------------------------------------

    public function test_a_multi_row_insert_is_one_statement_when_it_fits(): void
    {
        $statements = $this->grammar()->compileInsertMany('customers', ['name', 'email'], [
            ['name' => 'Ada', 'email' => 'ada@example.test'],
            ['email' => 'grace@example.test', 'name' => 'Grace'],
        ]);

        self::assertCount(1, $statements);
        self::assertSame('INSERT INTO "customers" ("name", "email") VALUES (?, ?), (?, ?)', $statements[0]['sql']);
        self::assertSame(['Ada', 'ada@example.test', 'Grace', 'grace@example.test'], $statements[0]['bindings'], 'bound in column order, whatever order the row was written in');
    }

    /**
     * 999 placeholders on SQLite, four columns: 249 rows a statement. The batch
     * boundary is where an off-by-one would lose or duplicate a row.
     */
    public function test_a_multi_row_insert_is_split_at_the_placeholder_limit(): void
    {
        $rows = \array_fill(0, 500, ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]);

        $statements = $this->grammar('sqlite')->compileInsertMany('t', ['a', 'b', 'c', 'd'], $rows);

        self::assertSame([249, 249, 2], \array_map(
            static fn(array $statement): int => \intdiv(\count($statement['bindings']), 4),
            $statements,
        ));

        foreach ($statements as $statement) {
            self::assertLessThanOrEqual(999, \count($statement['bindings']));
            self::assertSame(\count($statement['bindings']), \substr_count($statement['sql'], '?'));
        }
    }

    /** @return array<string, array{string, int}> */
    public static function placeholderLimits(): array
    {
        return [
            'sqlite' => ['sqlite', 999],
            'mysql' => ['mysql', 65535],
            'pgsql' => ['pgsql', 65535],
            'sqlsrv' => ['sqlsrv', 2000],
            'unknown driver gets the smallest' => ['odbc', 999],
        ];
    }

    #[DataProvider('placeholderLimits')]
    public function test_each_driver_has_a_placeholder_limit(string $driver, int $limit): void
    {
        self::assertSame($limit, $this->grammar($driver)->maxBindings());
    }

    public function test_a_set_based_update_binds_the_changes_before_the_criteria(): void
    {
        $query = $this->query()->whereIs('active', 1)->where('balance', Operator::Lt, 0);

        $compiled = $this->grammar()->compileUpdateWhere('customers', $query->criteria(), ['active' => 0, 'name' => 'x']);

        self::assertSame('UPDATE "customers" SET "active" = ?, "name" = ? WHERE "active" = ? AND "balance" < ?', $compiled['sql']);
        self::assertSame([0, 'x', 1, 0], $compiled['bindings']);
    }

    public function test_a_set_based_delete_is_its_criteria(): void
    {
        $query = $this->query()->whereIn('id', [1, 2, 3]);

        $compiled = $this->grammar()->compileDeleteWhere('customers', $query->criteria());

        self::assertSame('DELETE FROM "customers" WHERE "id" IN (?, ?, ?)', $compiled['sql']);
        self::assertSame([1, 2, 3], $compiled['bindings']);
    }
}
