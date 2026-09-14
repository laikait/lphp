<?php

declare(strict_types=1);

namespace App\Engine\Module;

use App\Engine\Error\FrameworkException;

final class ModuleException extends FrameworkException
{
    public static function entryMustReturnClosure(string $id, string $path, string $actual): self
    {
        return new self(\sprintf(
            'Module "%s" is invalid: %s returned %s. A module.php must return a closure taking a ModuleContext:'
            . "\n\n    return static function (ModuleContext \$module): void { ... };\n",
            $id,
            $path,
            $actual,
        ));
    }

    public static function duplicateId(string $id, string $existingPath, string $newPath): self
    {
        return new self(\sprintf(
            'Two modules claim the id "%s": %s and %s. Module ids are derived from the directory name '
            . 'and must be unique within their kind.',
            $id,
            $existingPath,
            $newPath,
        ));
    }

    /**
     * The message names the module, the method and the stage, because "you
     * cannot do that here" is useless without all three.
     */
    public static function wrongStage(string $id, string $method, ModuleStage $stage, string $allowed): self
    {
        return new self(\sprintf(
            'Module "%s" called %s() during the %s stage, which is not allowed. %s',
            $id,
            $method,
            $stage->value,
            $allowed,
        ));
    }

    public static function pathEscapesRoot(string $path, string $root): self
    {
        return new self(\sprintf(
            'Refusing to load "%s": it resolves outside the configured module root "%s".',
            $path,
            $root,
        ));
    }
}
