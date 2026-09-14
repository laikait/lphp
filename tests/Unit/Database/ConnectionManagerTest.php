<?php

declare(strict_types=1);

namespace App\Tests\Unit\Database;

use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionConfig;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\DatabaseException;
use App\Tests\Support\TestCase;

final class ConnectionManagerTest extends TestCase
{
    private function manager(): ConnectionManager
    {
        return new ConnectionManager([
            ConnectionConfig::of('main', 'sqlite::memory:'),
            ConnectionConfig::of('reports', 'sqlite::memory:'),
        ], 'main');
    }

    public function test_connections_are_reached_by_name(): void
    {
        $manager = $this->manager();

        self::assertSame(['main', 'reports'], $manager->names());
        self::assertTrue($manager->has('reports'));
        self::assertFalse($manager->has('nope'));
        self::assertSame('reports', $manager->connection('reports')->name());
    }

    public function test_the_default_connection_is_returned_when_none_is_named(): void
    {
        self::assertSame('main', $this->manager()->defaultName());
        self::assertSame('main', $this->manager()->connection()->name());
    }

    /** One configured connection is unambiguously the default, whatever it is called. */
    public function test_a_single_connection_is_the_default_even_if_it_is_named_otherwise(): void
    {
        $manager = new ConnectionManager([ConnectionConfig::of('only', 'sqlite::memory:')]);

        self::assertSame('only', $manager->defaultName());
        self::assertSame('only', $manager->connection()->name());
    }

    public function test_a_default_pointing_at_nothing_falls_back_to_what_exists(): void
    {
        $manager = new ConnectionManager([ConnectionConfig::of('main', 'sqlite::memory:')], 'missing');

        self::assertSame('main', $manager->defaultName());
    }

    public function test_the_same_connection_object_is_handed_out_each_time(): void
    {
        $manager = $this->manager();

        self::assertSame($manager->connection('main'), $manager->connection('main'));
        self::assertNotSame($manager->connection('main'), $manager->connection('reports'));
    }

    /**
     * Building connection objects must not open anything. An application can
     * declare every database it might touch without paying for the ones it does
     * not use on a given request.
     */
    public function test_naming_a_connection_does_not_open_it(): void
    {
        $manager = $this->manager();

        $manager->connection('main');
        $manager->connection('reports');

        self::assertSame([], $manager->opened());

        $manager->connection('main')->pdo();

        self::assertSame(['main'], $manager->opened());
    }

    public function test_everything_can_be_disconnected(): void
    {
        $manager = $this->manager();
        $manager->connection('main')->pdo();
        $manager->connection('reports')->pdo();

        self::assertCount(2, $manager->opened());

        $manager->disconnectAll();

        self::assertSame([], $manager->opened());
    }

    public function test_asking_for_an_unknown_connection_names_the_ones_that_exist(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/Configured: main, reports/');

        $this->manager()->connection('warehouse');
    }

    public function test_asking_for_a_connection_when_none_is_configured_says_so(): void
    {
        $manager = new ConnectionManager();

        self::assertFalse($manager->isConfigured());

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/No database connections are configured/');

        $manager->connection();
    }

    public function test_a_connection_can_be_added_later(): void
    {
        $manager = new ConnectionManager();
        $manager->add(ConnectionConfig::of('late', 'sqlite::memory:'));

        self::assertTrue($manager->isConfigured());
        self::assertSame('late', $manager->defaultName());
    }

    public function test_adding_a_connection_can_make_it_the_default(): void
    {
        $manager = $this->manager();
        $manager->add(ConnectionConfig::of('warehouse', 'sqlite::memory:'), asDefault: true);

        self::assertSame('warehouse', $manager->defaultName());
    }

    // ---- configuration ----------------------------------------------------

    public function test_it_builds_from_a_configuration_block(): void
    {
        $manager = ConnectionManager::fromArray([
            'main' => ['dsn' => 'mysql:host=localhost;dbname=erp', 'username' => 'app', 'password' => 'secret'],
            'reports' => ['dsn' => 'pgsql:host=db;dbname=warehouse'],
        ], 'reports');

        self::assertSame(['main', 'reports'], $manager->names());
        self::assertSame('reports', $manager->defaultName());
        self::assertSame('mysql', $manager->config('main')->driver());
        self::assertSame('pgsql', $manager->config('reports')->driver());
        self::assertSame('app', $manager->config('main')->username);
    }

    public function test_a_malformed_configuration_entry_is_ignored_rather_than_fatal(): void
    {
        $manager = ConnectionManager::fromArray([
            'main' => ['dsn' => 'sqlite::memory:'],
            'broken' => 'not an array',
        ]);

        self::assertSame(['main'], $manager->names());
    }

    /**
     * A credential that reaches a log, an error page or a bug report is a
     * credential that has to be rotated.
     */
    public function test_describing_a_connection_withholds_the_password(): void
    {
        $described = ConnectionManager::fromArray([
            'main' => ['dsn' => 'mysql:host=localhost;dbname=erp', 'username' => 'app', 'password' => 'hunter2'],
        ])->describe();

        self::assertSame(
            [['name' => 'main', 'driver' => 'mysql', 'dsn' => 'mysql:host=localhost;dbname=erp', 'username' => 'app']],
            $described,
        );

        self::assertStringNotContainsString('hunter2', \json_encode($described, \JSON_THROW_ON_ERROR));
    }

    public function test_a_connection_knows_its_driver_from_its_dsn(): void
    {
        self::assertSame('sqlite', ConnectionConfig::of('x', 'sqlite::memory:')->driver());
        self::assertSame('mysql', ConnectionConfig::of('x', 'mysql:host=h')->driver());
        self::assertSame('', ConnectionConfig::of('x', 'nonsense')->driver());
    }

    public function test_the_manager_hands_out_real_connections(): void
    {
        self::assertInstanceOf(Connection::class, $this->manager()->connection());
    }
}
