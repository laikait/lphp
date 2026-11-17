<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Config\Config;
use App\Engine\Core\Application;
use App\Engine\System\Process\DevServer;

/**
 * Start the development server: `composer serve`, without Composer.
 *
 * It runs PHP's built-in server with public/ as the document root and the
 * `server` file as the router, which is exactly what the composer script does,
 * so the two behave the same and either can be used. It stays in the foreground
 * until Ctrl+C.
 *
 * **For development only.** The built-in server handles one request at a time
 * and has none of a web server's protections, so it says so when debug is off
 * and the real answer for production is nginx or Apache with public/ as the
 * root (nginx:make writes the server block).
 *
 * The process itself is started by engine/System, the one layer allowed to.
 */
final class ServeCommand
{
    public function __construct(
        private readonly Application $application,
        private readonly Config $config,
        private readonly DevServer $server,
    ) {}

    public function __invoke(
        Output $output,
        string $host = DevServer::DEFAULT_HOST,
        string $port = DevServer::DEFAULT_PORT,
    ): int {
        if (!DevServer::validHost($host)) {
            $output->error(\sprintf('"%s" is not a host name or address.', \addcslashes($host, "\0..\37")));

            return 2;
        }

        if (!DevServer::validPort($port)) {
            $output->error(\sprintf('"%s" is not a port. Use a number from 1 to 65535.', \addcslashes($port, "\0..\37")));

            return 2;
        }

        $base = $this->application->basePath();

        if (!\is_file($base . DIRECTORY_SEPARATOR . 'server') || !\is_dir($base . DIRECTORY_SEPARATOR . 'public')) {
            $output->error('This does not look like an application root: public/ and the server router file are needed.');

            return 1;
        }

        if (!$this->config->bool('app.debug', false)) {
            $output->warning('APP_DEBUG is off. The built-in server is for development; use nginx or Apache with public/ as the document root in production.');
        }

        $output->success(\sprintf('Serving http://%s:%s  (Ctrl+C to stop)', $host, $port));

        $status = $this->server->run(DevServer::arguments($host, (int) $port, $base), $base, [\STDIN, \STDOUT, \STDERR]);

        if ($status === null) {
            $output->error('The PHP built-in server could not be started.');

            return 1;
        }

        return $status === 0 ? 0 : 1;
    }
}
