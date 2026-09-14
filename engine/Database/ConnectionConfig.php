<?php

declare(strict_types=1);

namespace App\Engine\Database;

/**
 * What it takes to open one connection.
 *
 * A DSN string rather than a bag of host/port/database parts. Every PDO driver
 * documents its own DSN, including ones this framework has never heard of, so
 * taking the string works everywhere and inventing a per-driver builder would
 * only work for the drivers somebody remembered.
 *
 *     ConnectionConfig::of('main', 'mysql:host=localhost;dbname=erp;charset=utf8mb4', 'app', $secret)
 *     ConnectionConfig::of('reports', 'pgsql:host=db;dbname=warehouse', 'reader', $secret)
 *     ConnectionConfig::of('test', 'sqlite::memory:')
 */
final class ConnectionConfig
{
    /**
     * The attributes every connection gets unless it says otherwise.
     *
     * Exceptions rather than silent false returns, associative rows rather than
     * both, and real prepared statements rather than emulated ones -- which
     * matters twice over: emulation interpolates values into the SQL string,
     * and it hands every column back as a string.
     */
    public const DEFAULTS = [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES => false,
        \PDO::ATTR_STRINGIFY_FETCHES => false,
    ];

    /** @param array<int, mixed> $options */
    private function __construct(
        public readonly string $name,
        public readonly string $dsn,
        public readonly ?string $username = null,
        public readonly ?string $password = null,
        public readonly array $options = [],
    ) {}

    /** @param array<int, mixed> $options */
    public static function of(
        string $name,
        string $dsn,
        ?string $username = null,
        ?string $password = null,
        array $options = [],
    ): self {
        return new self($name, $dsn, $username, $password, $options);
    }

    /**
     * Build from a configuration block.
     *
     * @param array<string, mixed> $values
     */
    public static function fromArray(string $name, array $values): self
    {
        $dsn = $values['dsn'] ?? '';
        $username = $values['username'] ?? null;
        $password = $values['password'] ?? null;
        $options = $values['options'] ?? [];

        return new self(
            $name,
            \is_string($dsn) ? $dsn : '',
            \is_string($username) ? $username : null,
            \is_string($password) ? $password : null,
            \is_array($options) ? $options : [],
        );
    }

    /** The part of the DSN before the colon: mysql, pgsql, sqlite. */
    public function driver(): string
    {
        $colon = \strpos($this->dsn, ':');

        return $colon === false ? '' : \substr($this->dsn, 0, $colon);
    }

    /** @return array<int, mixed> */
    public function pdoOptions(): array
    {
        return $this->options + self::DEFAULTS;
    }

    /**
     * The connection as plain data, with the password withheld.
     *
     * Diagnostics print this. A credential that reaches a log, an error page or
     * a bug report is a credential that has to be rotated.
     *
     * @return array{name: string, driver: string, dsn: string, username: string|null}
     */
    public function describe(): array
    {
        return [
            'name' => $this->name,
            'driver' => $this->driver(),
            'dsn' => $this->dsn,
            'username' => $this->username,
        ];
    }
}
