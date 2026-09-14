<?php

declare(strict_types=1);

namespace App\Engine\Module;

use App\Engine\Support\Path;

/**
 * Holds what discovery found, in a deterministic order.
 *
 * Order is by kind rank first (shared, then plugins, then gateways) and by
 * directory name second, case-sensitively. It is never filesystem order:
 * readdir() ordering varies between filesystems and platforms, and a framework
 * whose behaviour depends on it is a framework that behaves differently in
 * production than on a developer's laptop.
 *
 * That "shared" always comes first is what makes it genuinely shared.
 */
final class ModuleRegistry
{
    /** @var array<string, ModuleDefinition> */
    private array $definitions = [];

    /** @var array<string, ModuleContext> */
    private array $contexts = [];

    private bool $sorted = true;

    public function add(ModuleDefinition $definition): void
    {
        $existing = $this->definitions[$definition->id] ?? null;

        if ($existing !== null && $existing->path !== $definition->path) {
            throw ModuleException::duplicateId($definition->id, $existing->path, $definition->path);
        }

        $this->definitions[$definition->id] = $definition;
        $this->sorted = false;
    }

    public function has(string $id): bool
    {
        return isset($this->definitions[$id]);
    }

    public function definition(string $id): ?ModuleDefinition
    {
        return $this->definitions[$id] ?? null;
    }

    /** @return list<ModuleDefinition> */
    public function definitions(): array
    {
        $this->sort();

        return \array_values($this->definitions);
    }

    /** @return list<string> */
    public function ids(): array
    {
        $this->sort();

        return \array_keys($this->definitions);
    }

    public function setContext(ModuleContext $context): void
    {
        $this->contexts[$context->id()] = $context;
    }

    public function context(string $id): ?ModuleContext
    {
        return $this->contexts[$id] ?? null;
    }

    /**
     * Contexts in module order.
     *
     * @return list<ModuleContext>
     */
    public function contexts(): array
    {
        $ordered = [];

        foreach ($this->ids() as $id) {
            if (isset($this->contexts[$id])) {
                $ordered[] = $this->contexts[$id];
            }
        }

        return $ordered;
    }

    public function count(): int
    {
        return \count($this->definitions);
    }

    public function clear(): void
    {
        $this->definitions = [];
        $this->contexts = [];
        $this->sorted = true;
    }

    // ---- discovery cache --------------------------------------------------

    /**
     * The cacheable artefact: plain scalars only.
     *
     * @return list<array{id: string, kind: string, path: string, entryFile: string, directory: string}>
     */
    public function toArray(): array
    {
        return \array_map(
            static fn(ModuleDefinition $definition): array => $definition->toArray(),
            $this->definitions(),
        );
    }

    /**
     * @param list<array{id: string, kind: string, path: string, entryFile: string, directory: string}> $data
     */
    public function loadArray(array $data): void
    {
        $this->clear();

        foreach ($data as $entry) {
            $this->add(ModuleDefinition::fromArray($entry));
        }
    }

    /**
     * Write the discovery cache.
     *
     * var_export() rather than serialize() so the file is opcache-friendly and
     * readable when something goes wrong.
     */
    public function writeCache(string $file): bool
    {
        $directory = \dirname($file);

        if (!\is_dir($directory) && !\mkdir($directory, 0o775, true) && !\is_dir($directory)) {
            return false;
        }

        $contents = "<?php\n\n// Generated module discovery cache. Delete this file to force a rescan.\n\nreturn "
            . \var_export($this->toArray(), true)
            . ";\n";

        // Write and rename so a concurrent reader never sees a half-written file.
        $temporary = $file . '.' . \getmypid() . '.tmp';

        if (\file_put_contents($temporary, $contents, \LOCK_EX) === false) {
            return false;
        }

        if (!\rename($temporary, $file)) {
            @\unlink($temporary);

            return false;
        }

        return true;
    }

    /**
     * Read the discovery cache.
     *
     * There is deliberately no automatic invalidation. Stat-ing every module
     * directory to decide whether the cache is stale would undo the saving the
     * cache exists to provide, and mtime is unreliable on Windows and on
     * network shares. Clearing the cache is deleting the file.
     */
    public function readCache(string $file): bool
    {
        if (!\is_file($file)) {
            return false;
        }

        /** @var mixed $data */
        $data = require $file;

        if (!\is_array($data)) {
            return false;
        }

        /** @var list<array{id: string, kind: string, path: string, entryFile: string, directory: string}> $data */
        $this->loadArray($data);

        return true;
    }

    public static function cacheFile(string $basePath): string
    {
        return Path::join($basePath, 'system/Cache/modules.php');
    }

    private function sort(): void
    {
        if ($this->sorted) {
            return;
        }

        \uasort(
            $this->definitions,
            static fn(ModuleDefinition $a, ModuleDefinition $b): int => $a->sortKey() <=> $b->sortKey(),
        );

        $this->sorted = true;
    }
}
