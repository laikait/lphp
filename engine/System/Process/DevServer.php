<?php

declare(strict_types=1);

namespace App\Engine\System\Process;

/**
 * PHP's built-in web server, run in the foreground for `php laika serve`.
 *
 * **Outside the command policy on purpose.** ProcessManager and CommandExecutor
 * exist so that an application can start a named program with a timeout, an
 * audit record and a concurrency slot. This is none of that: it is one fixed
 * program, started by a person at a terminal, that is meant to run until they
 * press Ctrl+C. A timeout would end it, and nothing here takes a program name
 * from anywhere -- the only inputs are a validated host, a validated port and
 * the application's own base path.
 *
 * **No shell.** The arguments go to proc_open() as an array, the same rule as
 * the rest of this layer, so a host with a semicolon in it is an invalid host
 * and never a second command.
 *
 * The child inherits this process's input and output, so its request log appears
 * in the terminal and Ctrl+C reaches it directly.
 */
final class DevServer
{
    public const DEFAULT_HOST = '127.0.0.1';

    public const DEFAULT_PORT = '8080';

    public static function validHost(string $host): bool
    {
        return \preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?|\[[0-9A-Fa-f:]+\])$/', $host) === 1;
    }

    public static function validPort(string $port): bool
    {
        return \preg_match('/^[0-9]{1,5}$/', $port) === 1 && (int) $port >= 1 && (int) $port <= 65535;
    }

    /**
     * What to run: php -S <host>:<port> -t public server, from the base path.
     *
     * @return list<string>
     */
    public static function arguments(string $host, int $port, string $base, string $php = PHP_BINARY): array
    {
        return [$php, '-S', $host . ':' . $port, '-t', $base . DIRECTORY_SEPARATOR . 'public', $base . DIRECTORY_SEPARATOR . 'server'];
    }

    /**
     * Run it until it exits.
     *
     * @param list<string>      $argv    from arguments()
     * @param array<int, mixed> $streams what the child reads and writes: the terminal's input, output and error,
     *                                   handed in because this layer does not know which interface called it
     *
     * @return int|null the server's exit status, or null when it could not be started
     */
    public function run(array $argv, string $base, array $streams): ?int
    {
        $process = @\proc_open($argv, $streams, $pipes, $base);

        if (!\is_resource($process)) {
            return null;
        }

        return \proc_close($process);
    }
}
