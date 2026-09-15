<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Database\ConnectionManager;
use App\Engine\Session\Stores\DatabaseStore;

/**
 * Print the CREATE TABLE the database session store needs.
 *
 * Like security:key, it prints and does not run. A store that creates its own
 * table needs CREATE rights at runtime -- rights an application account should
 * not have, granted permanently to remove one step from one deployment. And it
 * would do it on the first request after a deploy, which is the worst moment
 * available.
 *
 * Printing puts the statement wherever the team already keeps schema changes,
 * where it can be reviewed, replayed and rolled back.
 */
final class SessionTableCommand
{
    public function __invoke(
        ConnectionManager $connections,
        Output $output,
        string $driver = '',
        string $table = DatabaseStore::DEFAULT_TABLE,
        bool $bare = false,
    ): int {
        // The configured connection's driver, unless asked otherwise -- the
        // common case is "the database this application uses", and making
        // somebody type it invites getting it wrong.
        if ($driver === '') {
            $driver = $connections->isConfigured() ? $connections->connection()->driver() : 'sqlite';
        }

        $sql = DatabaseStore::ddl($driver, $table);

        if ($bare) {
            $output->write($sql . "\n");

            return 0;
        }

        $output->heading(\sprintf('Session table for %s', $driver));
        $output->line();

        foreach (\explode("\n", $sql) as $line) {
            $output->line('    ' . $line);
        }

        $output->line();
        $output->line('Run it where you keep schema changes, then set session.store to "database".');
        $output->line('The index is on touched_at because that is what session:gc deletes by.');
        $output->line();

        return 0;
    }
}
