<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Core\Application;

/**
 * Write the nginx server block for this application to nginx.conf.
 *
 * **It writes configuration, not source.** Nothing in the framework reads the
 * file, so it has no shape the framework quietly depends on -- which is what the
 * ban on generators is about. It is the nginx counterpart of .htaccess, and an
 * architecture test keeps its directory and metadata lists identical to that
 * file's.
 *
 * **Generated rather than shipped** because the three values that differ per
 * machine -- the host name, the directory and the PHP-FPM address -- are wrong
 * in any copy that is not edited, and a server block pointing at the wrong root
 * fails open: nginx serves whatever the wrong directory holds.
 *
 * **It refuses to overwrite** without --force. A file somebody has since edited
 * by hand -- a TLS listener, a larger upload limit -- is exactly the file that
 * must not be silently regenerated.
 */
final class NginxMakeCommand
{
    public const FILE = 'nginx.conf';

    public function __construct(private readonly Application $application) {}

    public function __invoke(
        Output $output,
        string $serverName = '_',
        string $root = '',
        string $listen = '80',
        string $php = 'unix:/run/php/php-fpm.sock',
        bool $force = false,
    ): int {
        $file = $this->application->basePath(self::FILE);

        if (\is_file($file) && !$force) {
            $output->error(\sprintf('%s already exists. Pass --force to replace it.', $file));

            return 1;
        }

        // nginx takes forward slashes on every platform, and a trailing one
        // would double up in the alias below.
        $root = \rtrim(\str_replace('\\', '/', $root === '' ? $this->application->basePath() : $root), '/');

        if (\file_put_contents($file, self::serverBlock($serverName, $root, $listen, $php), \LOCK_EX) === false) {
            $output->error(\sprintf('%s could not be written.', $file));

            return 1;
        }

        $output->success(\sprintf('Wrote %s', $file));
        $output->line();
        $output->pairs([
            'server_name' => $serverName,
            'root' => $root,
            'listen' => $listen,
            'fastcgi_pass' => $php,
        ]);
        $output->line();
        $output->line('Copy it into /etc/nginx/conf.d/ or sites-enabled/, then run nginx -t and reload.');
        $output->line('Check it: curl -i http://<host>/engine/Core/Application.php must be the application 404, not the file.');
        $output->line();

        return 0;
    }

    /**
     * The server block itself.
     *
     * The directory and metadata lists are .htaccess's, character for character;
     * the architecture test reads both files' patterns and compares them.
     */
    public static function serverBlock(string $serverName, string $root, string $listen, string $php): string
    {
        return <<<NGINX
            # nginx server block, written by `php bin/console nginx:make`.
            #
            # The counterpart of .htaccess, and just as load-bearing: index.php sits in
            # the same directory as engine/, modules/ and vendor/, and nginx serves
            # whatever it can reach. Verify after deploying:
            #
            #   curl -i http://<host>/engine/Core/Application.php   -> the application's 404, never the file

            server {
                listen {$listen};
                server_name {$serverName};
                root {$root};
                index index.php;

                # These directories and everything under them go to the front controller,
                # as they do in .htaccess: no file there is ever served, and the
                # application may have routes there -- /config/app is its to answer.
                location ~ ^/(engine|modules|templates|config|system|tests|bin|vendor)(/|$) { rewrite ^ /index.php last; }

                # The project's metadata, by name -- the list .htaccess refuses -- and
                # Markdown anywhere. Not by extension: a route or a published asset may end
                # in .json, and refusing the extension would 403 it here and nowhere else.
                location ~ ^/(composer\.(json|lock)|phpunit\.xml|phpstan\.neon|\.php-cs-fixer\..*|\.env.*|.*\.md|server|nginx\.conf)$ { deny all; }

                # Apache refuses .htaccess and .htpasswd by default; nginx has to be told.
                location ~ /\.ht { deny all; }

                # The application's own assets are served directly; /assets/core/<path> is
                # <root>/assets/<path>. Every other asset namespace lives inside the routed
                # modules/ tree and goes through the front controller.
                location ^~ /assets/core/ {
                    alias {$root}/assets/;
                    access_log off;
                }

                location / {
                    try_files \$uri /index.php\$is_args\$args;
                }

                # The front controller is the only PHP that runs. SCRIPT_NAME comes out as
                # /index.php, which is what the base path is derived from. nginx passes the
                # Authorization header on by itself, so the rewrite .htaccess needs for
                # bearer tokens has no equivalent here.
                location = /index.php {
                    include fastcgi_params;
                    fastcgi_param SCRIPT_FILENAME \$document_root/index.php;
                    fastcgi_pass {$php};
                }

                # Any other .php file is neither run nor handed over as source.
                location ~ \.php$ { return 404; }
            }

            NGINX;
    }
}
