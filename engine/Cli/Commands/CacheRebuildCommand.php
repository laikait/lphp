<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;

/**
 * cache:clear, then cache:warm: the whole of a deployment's cache step.
 *
 * Nothing here is new behaviour. The two commands already say what they do
 * and why; this runs them in the order the deployment notes ask for, so the
 * step cannot be done half-way -- a cache cleared and never warmed makes the
 * next request pay for every scan, and a warm over a stale cache is the thing
 * clearing first exists to prevent.
 *
 * **If warming fails, the clearing stays done.** An application with no cache
 * is slower, not wrong, and putting the old cache back would be the one outcome
 * that is: stale files presented as fresh. The exit code is warm's, so a
 * deployment script stops, and its message says why -- usually APP_DEBUG, which
 * cache:warm refuses on purpose.
 */
final class CacheRebuildCommand
{
    public function __construct(
        private readonly CacheClearCommand $clear,
        private readonly CacheWarmCommand $warm,
    ) {}

    public function __invoke(Output $output): int
    {
        $output->heading('Clearing');

        $status = ($this->clear)($output);

        if ($status !== 0) {
            return $status;
        }

        $output->line();
        $output->heading('Warming');

        return ($this->warm)($output);
    }
}
