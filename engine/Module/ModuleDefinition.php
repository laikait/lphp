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
 */
final class ModuleDefinition
{
    public function __construct(
        public readonly string $id,
        public readonly ModuleKind $kind,
        public readonly string $path,
        public readonly string $entryFile,
        public readonly string $directory,
    ) {}

    /**
     * The id is qualified by kind because the specification's own example has a
     * plugin and a gateway both called "Example". Bare directory names would
     * collide in config namespacing and hook attribution.
     */
    public static function create(ModuleKind $kind, string $path, string $directory): self
    {
        $path = Path::normalize($path);

        return new self(
            id: $kind === ModuleKind::Shared ? $kind->value : $kind->value . '/' . $directory,
            kind: $kind,
            path: $path,
            entryFile: $path . '/module.php',
            directory: $directory,
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

    /** @return array{id: string, kind: string, path: string, entryFile: string, directory: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'path' => $this->path,
            'entryFile' => $this->entryFile,
            'directory' => $this->directory,
        ];
    }

    /** @param array{id: string, kind: string, path: string, entryFile: string, directory: string} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            kind: ModuleKind::from($data['kind']),
            path: $data['path'],
            entryFile: $data['entryFile'],
            directory: $data['directory'],
        );
    }
}
