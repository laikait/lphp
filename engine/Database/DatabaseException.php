<?php

declare(strict_types=1);

namespace App\Engine\Database;

use App\Engine\Error\FrameworkException;

final class DatabaseException extends FrameworkException
{
    /**
     * Withheld: a driver's connection failure quotes the DSN back, and a DSN is
     * one keystroke away from carrying a password.
     */
    public static function cannotConnect(string $name, string $driver, \Throwable $previous): self
    {
        return (new self(
            \sprintf(
                'Could not open the "%s" connection (%s): %s',
                $name,
                $driver,
                $previous->getMessage(),
            ),
            0,
            $previous,
        ))->withheld();
    }

    /**
     * The SQL is in the message and the values are not.
     *
     * A failing statement is useless without the statement, and a bound value
     * is the one thing in a query that is likely to be somebody's password or
     * personal data. Naming the parameters without their values is the line
     * this draws.
     *
     * @param array<array-key, mixed> $bindings
     */
    public static function statementFailed(string $sql, array $bindings, \Throwable $previous): self
    {
        return (new self(
            \sprintf(
                '%s [SQL: %s] [%d bound value%s]',
                $previous->getMessage(),
                $sql,
                \count($bindings),
                \count($bindings) === 1 ? '' : 's',
            ),
            0,
            $previous,
        ))->withheld();
    }

    public static function unknownConnection(string $name, string $configured): self
    {
        return new self(\sprintf(
            'No connection named "%s" is configured. Configured: %s.',
            $name,
            $configured === '' ? '(none)' : $configured,
        ));
    }

    public static function noConnectionsConfigured(): self
    {
        return new self(
            'No database connections are configured. Set database.connections in the '
            . 'application configuration, or DB_DSN in the environment.',
        );
    }

    /**
     * An identifier that is not a plain name.
     *
     * Table and column names cannot be bound as parameters, so they are the one
     * part of a statement built by interpolation. Everything that reaches this
     * point comes from a repository declaration rather than from a request, and
     * refusing anything unusual keeps it that way even if one day something
     * slips through from higher up.
     */
    public static function unsafeIdentifier(string $identifier): self
    {
        return new self(\sprintf(
            'The identifier "%s" is not a plain name. Table and column names are written into the '
            . 'statement rather than bound, so only letters, digits and underscores are accepted. '
            . 'A name that comes from a request is never a column name.',
            $identifier,
        ));
    }

    public static function noRowsToUpdate(string $table): self
    {
        return new self(\sprintf('An update of "%s" was asked to change no columns.', $table));
    }

    public static function noColumnsToInsert(string $table): self
    {
        return new self(\sprintf('An insert into "%s" was given no columns.', $table));
    }

    public static function notInTransaction(string $operation): self
    {
        return new self(\sprintf(
            'There is no open transaction to %s. begin() and %s() must be balanced; '
            . 'prefer transaction(), which balances them for you.',
            $operation,
            $operation,
        ));
    }

    public static function driverLacksSavepoints(string $driver): self
    {
        return new self(\sprintf(
            'The %s driver does not support savepoints, so transactions cannot nest on it. '
            . 'Keep the transaction boundary at one level.',
            $driver,
        ));
    }
}
