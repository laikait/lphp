<?php

declare(strict_types=1);

namespace App\Engine\Asset;

use App\Engine\Support\Path;

/**
 * One published asset namespace: a logical name bound to a directory.
 *
 * A source is the entire mapping between the public URL space and the disk. It
 * is deliberately a value rather than a service: registering one publishes a
 * directory, and that is an act somebody should be able to read in a diff.
 *
 *     core                 ->  <base>/public/assets
 *     template             ->  <base>/templates (web assets only)
 *     module  + "Billing"  ->  <base>/modules/Billing/assets
 *
 * A source may narrow which extensions it serves ($extensions, a subset of
 * MimeTypes). The template source does, so that a stylesheet can sit beside
 * the views that use it while templates/ never serves a view, a theme's
 * package.json, a README.txt or a data file.
 *
 * The root is stored normalised but NOT resolved: a source may legitimately be
 * registered for a directory that does not exist yet (a template that ships no
 * assets, a module mid-installation). Resolution is where existence matters,
 * and that is AssetResolver's job, not this one's.
 */
final class AssetSource
{
    /** Names appear in URLs and in paths, so they are checked, not trusted. */
    private const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]*$/';

    public readonly string $root;

    /**
     * @param ?list<string> $extensions what may be served from this root; null for every MimeTypes extension
     */
    public function __construct(
        public readonly AssetKind $kind,
        public readonly ?string $name,
        string $root,
        public readonly ?array $extensions = null,
    ) {
        if ($name !== null) {
            if (!$kind->allowsName()) {
                throw AssetException::sourceCannotBeNamed($kind);
            }

            if (\preg_match(self::NAME_PATTERN, $name) !== 1) {
                throw AssetException::invalidSourceName($name);
            }
        } elseif ($kind->requiresName()) {
            throw AssetException::sourceNeedsName($kind);
        }

        $this->root = Path::normalize($root);
    }

    /** The registry key: "core", "template", "module/Billing". */
    public function key(): string
    {
        return $this->name === null ? $this->kind->value : $this->kind->value . '/' . $this->name;
    }

    /**
     * The URL prefix, without slashes: "assets/core", "template",
     * "assets/module/Billing". AssetManager builds URLs with it and AssetServer
     * reads them back with it, so the two can never drift apart.
     */
    public function urlPrefix(): string
    {
        return $this->kind->urlPrefix() . ($this->name === null ? '' : '/' . $this->name);
    }

    /** Whether a file with this extension may be served from this root. */
    public function allows(string $extension): bool
    {
        $extension = \strtolower($extension);

        return MimeTypes::isServable($extension)
            && ($this->extensions === null || \in_array($extension, $this->extensions, true));
    }

    /** Whether the published directory is actually there. */
    public function exists(): bool
    {
        return \is_dir($this->root);
    }

    public function describe(): string
    {
        return $this->kind->describe($this->name);
    }
}
