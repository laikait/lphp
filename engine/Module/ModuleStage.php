<?php

declare(strict_types=1);

namespace App\Engine\Module;

/**
 * Where a module is in its lifecycle.
 *
 * The stage is not decoration: ModuleContext checks it on every call, so an
 * illegal operation fails at the line that attempted it, naming the module,
 * rather than surfacing as a confusing error somewhere downstream.
 */
enum ModuleStage: string
{
    case Discovered = 'discovered';
    case Loading = 'loading';
    case Registering = 'registering';
    case Booting = 'booting';
    case Ready = 'ready';
}
