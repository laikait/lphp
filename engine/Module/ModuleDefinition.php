<?php

declare(strict_types=1);

namespace App\Engine\Module;

use App\Engine\Support\Path;

/**
 * What discovery learns about a module without running any of its code.
 *
 * Everything here is a plain scalar, which is the whole point: this is the
 * artefact a discovery cache stores, so it must survive var_export() and come
 * back identical.
 *
 * Two of the fields are answers to filesystem questions -- does the module have
 * an assets/ directory, does it have a Templates/ one -- asked once, here,
 * rather than by registration on every request. They are facts about the
 * directory in the same way the path is, which is why they belong in the cache
 * and why nothing resolved or declared does.
 */
final class ModuleDefinition
{
    /** The directory whose presence publishes a module's assets. */
    public const ASSETS = 'assets';

    /** The directory whose presence registers a module's templates. */
    public const TEMPLATES = 'Templates';

    public function __construct(
        public readonly string $id,
        public readonly ModuleKind $kind,
        public readonly string $path,
        public readonly string $entryFile,
        public readonly string $directory,
        public readonly bool $hasAssets = false,
        public readonly bool $hasTemplates = false,
    ) {}

    /**
     * The id is qualified by kind because the specification's own example has a
     * plugin and a gateway both called "Example". Bare directory names would
     * collide in config namespacing and hook attribution.
     */
    public static function create(
        ModuleKind $kind,
        string $path,
        string $directory,
        bool $hasAssets = false,
        bool $hasTemplates = false,
    ): self {
        $path = Path::normalize($path);

        return new self(
            id: $kind === ModuleKind::Shared ? $kind->value : $kind->value . '/' . $directory,
            kind: $kind,
            path: $path,
            entryFile: $path . '/module.php',
            directory: $directory,
            hasAssets: $hasAssets,
            hasTemplates: $hasTemplates,
        );
    }

    public function file(string $relative = ''): string
    {
        return $relative === '' ? $this->path : Path::join($this->path, $relative);
    }

    /** The sort key that makes module order a deterministic total order. */
    public function sortKey(): string
    {
        return \sprintf('%d:%s', $this->kind->rank(), $this->directory);
    }

    /** @return array{id: string, kind: string, path: string, entryFile: string, directory: string, assets: bool, templates: bool} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'path' => $this->path,
            'entryFile' => $this->entryFile,
            'directory' => $this->directory,
            'assets' => $this->hasAssets,
            'templates' => $this->hasTemplates,
        ];
    }

    /** @param array{id: string, kind: string, path: string, entryFile: string, directory: string, assets: bool, templates: bool} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            kind: ModuleKind::from($data['kind']),
            path: $data['path'],
            entryFile: $data['entryFile'],
            directory: $data['directory'],
            hasAssets: $data['assets'],
            hasTemplates: $data['templates'],
        );
    }

    /**
     * Whether a value read back from a cache file is a definition this version
     * can use.
     *
     * A cache written by an older version lacks fields, and one edited by hand
     * may have anything. Either way the answer is "scan instead", never a
     * TypeError from inside a generated file nobody is looking at.
     */
    public static function isCachedShape(mixed $data): bool
    {
        if (!\is_array($data)) {
            return false;
        }

        foreach (['id', 'kind', 'path', 'entryFile', 'directory'] as $field) {
            if (!isset($data[$field]) || !\is_string($data[$field])) {
                return false;
            }
        }

        return ModuleKind::tryFrom($data['kind']) !== null
            && \is_bool($data['assets'] ?? null)
            && \is_bool($data['templates'] ?? null);
    }
}
