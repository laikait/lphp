<?php

declare(strict_types=1);

namespace App\Engine\Support;

use App\Engine\Error\FrameworkException;

/**
 * dd() reached with debug mode off.
 *
 * A dd() left in code must not print to a visitor, and must not quietly do
 * nothing either -- the code after it was never meant to run. So it fails the
 * way any error does: the visitor gets the ordinary error page, and the log
 * says where the dd() is.
 */
final class DebugException extends FrameworkException
{
    public static function leftInCode(string $where): self
    {
        return (new self(\sprintf(
            'dd() was called at %s with debug mode off. It prints only with APP_DEBUG=true; remove it before deploying.',
            $where,
        )))->withheld();
    }
}
