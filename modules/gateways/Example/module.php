<?php

declare(strict_types=1);

use App\Engine\Module\ModuleContext;
use App\Modules\Gateways\Example\Hooks\AuditHooks;

/**
 * A gateway module.
 *
 * Gateways are specialised modules. They load last, which lets them observe and
 * adjust what plugins have already set up. Gateway-specific models stay inside
 * the gateway; generic concepts belong to the module that owns the capability.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Example Gateway')
        ->version('0.1.0')
        ->description('Shows a gateway reacting to another module\'s events.');

    $module->hook('customer.created', [AuditHooks::class, 'recordCustomer'], priority: 50);
};
