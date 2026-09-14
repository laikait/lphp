<?php

declare(strict_types=1);

namespace App\Engine\Module;

/**
 * The three kinds of module, and the order they load in.
 *
 * The rank is what makes "shared" genuinely shared: it always registers before
 * anything that might depend on it. Gateways come last because they specialise
 * what plugins provide.
 */
enum ModuleKind: string
{
    case Shared = 'shared';
    case Plugin = 'plugins';
    case Gateway = 'gateways';

    public function rank(): int
    {
        return match ($this) {
            self::Shared => 0,
            self::Plugin => 1,
            self::Gateway => 2,
        };
    }

    /**
     * Whether the configured path holds many modules or is itself one module.
     *
     * modules/shared is a single module; modules/plugins and modules/gateways
     * are directories of them.
     */
    public function isContainer(): bool
    {
        return $this !== self::Shared;
    }
}
