<?php

declare(strict_types=1);

namespace App\Engine\Module;

/**
 * One module saying it needs another.
 *
 * **By id, not by name.** `plugins/Billing`, never `Billing`: the
 * specification's own tree has a plugin and a gateway both called `Example`, so
 * a bare name cannot say which one is meant, and module ids are already
 * kind-qualified for exactly that reason.
 *
 * **Optional is not the same as absent.** An optional dependency that is
 * installed and enabled is checked exactly like a required one -- its version
 * must fit, and the module registers after it. What changes is only what
 * happens when it is not there: nothing, rather than a refusal to boot. That is
 * what makes an optional integration safe to write: the ordering guarantee holds
 * whenever the other module exists.
 */
final class Dependency
{
    /**
     * `shared`, or a kind and a PHP identifier.
     *
     * The identifier rule is not arbitrary: a module's directory name is a
     * namespace segment (the `Billing` in `...\Plugins\Billing`), so a name PHP could
     * not use as one is not a module that can exist.
     */
    public const ID_PATTERN = '/^(shared|(plugins|gateways)\/[A-Za-z_][A-Za-z0-9_]*)$/';

    public function __construct(
        public readonly string $id,
        public readonly VersionConstraint $constraint,
        public readonly bool $optional = false,
    ) {}

    public static function isValidId(string $id): bool
    {
        return \preg_match(self::ID_PATTERN, $id) === 1;
    }

    /** The kind of module this points at, read from the id itself. */
    public function kind(): ModuleKind
    {
        return $this->id === ModuleKind::Shared->value
            ? ModuleKind::Shared
            : ModuleKind::from(\strstr($this->id, '/', true) ?: $this->id);
    }

    /** `plugins/Billing ^1.0`, `plugins/Crm ^2.0 (optional)`, or just the id when any version will do. */
    public function describe(): string
    {
        return $this->id
            . ($this->constraint->isAny() ? '' : ' ' . $this->constraint)
            . ($this->optional ? ' (optional)' : '');
    }
}
