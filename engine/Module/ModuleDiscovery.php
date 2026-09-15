<?php

declare(strict_types=1);

namespace App\Engine\Module;

use App\Engine\Config\Config;
use App\Engine\Support\Path;

/**
 * The Discover stage: a filesystem walk, and nothing else.
 *
 * No module code runs here. The configured roots are scanned for module.php
 * files, and each definition records what a later stage would otherwise have to
 * ask the filesystem again -- whether the module has an assets/ directory and
 * whether it has a Templates/ one. That is what lets a boot from the discovery
 * cache touch no module directory at all: the cache holds these answers, and
 * nothing after this stage asks the questions.
 *
 * It is its own class rather than a few methods on the manager for two reasons.
 * One is that building the cache must scan afresh -- a process that booted from
 * an old cache and wrote its registry back would make the old cache permanent --
 * and that needs discovery without a manager that may already have run. The
 * other is that "only discovery probes module directories" is a rule worth being
 * able to check, and a rule about one file is checkable.
 */
final class ModuleDiscovery
{
    /** @param array<string, string> $roots kind => directory, relative to the base path or absolute */
    public function __construct(
        private readonly string $basePath,
        private readonly array $roots,
    ) {}

    public static function fromConfig(Config $config, string $basePath): self
    {
        /** @var mixed $paths */
        $paths = $config->get('modules.paths', []);
        $roots = [];

        if (\is_array($paths)) {
            foreach ($paths as $kind => $path) {
                if (\is_string($kind) && \is_string($path)) {
                    $roots[$kind] = $path;
                }
            }
        }

        return new self($basePath, $roots);
    }

    /**
     * The roots this discovery walks, as absolute paths, keyed by kind.
     *
     * The discovery cache stores these and is ignored when they differ, which
     * covers the two ways a cache goes wrong without anybody editing a module:
     * modules.paths changed, or the application now lives in another directory.
     * The second is not exotic -- a test suite pointing at fixture modules, in a
     * checkout where somebody once ran cache:warm, is exactly that.
     *
     * @return array<string, string>
     */
    public function roots(): array
    {
        $resolved = [];

        foreach ($this->roots as $kind => $relative) {
            $resolved[$kind] = Path::normalize(
                Path::isAbsolute($relative) ? $relative : Path::join($this->basePath, $relative),
            );
        }

        return $resolved;
    }

    /**
     * Every module under the configured roots, in no particular order.
     *
     * Order is the registry's job, and deliberately not filesystem order; see
     * ModuleRegistry.
     *
     * @return list<ModuleDefinition>
     */
    public function scan(): array
    {
        $found = [];

        foreach ($this->roots() as $kindValue => $root) {
            $kind = ModuleKind::tryFrom($kindValue);

            if ($kind === null) {
                continue;
            }

            if (!$kind->isContainer()) {
                $definition = $this->module($kind, $root, $kind->value);

                if ($definition !== null) {
                    $found[] = $definition;
                }

                continue;
            }

            foreach ($this->entries($root) as $entry) {
                $definition = $this->module($kind, Path::join($root, $entry), $entry);

                if ($definition !== null) {
                    $found[] = $definition;
                }
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function entries(string $root): array
    {
        if (!\is_dir($root)) {
            return [];
        }

        $entries = \scandir($root);

        if ($entries === false) {
            return [];
        }

        return \array_values(\array_filter(
            $entries,
            static fn(string $entry): bool => $entry !== '.' && $entry !== '..',
        ));
    }

    private function module(ModuleKind $kind, string $path, string $directory): ?ModuleDefinition
    {
        if (!\is_dir($path) || !\is_file(Path::join($path, 'module.php'))) {
            return null;
        }

        return ModuleDefinition::create(
            $kind,
            $path,
            $directory,
            hasAssets: \is_dir(Path::join($path, ModuleDefinition::ASSETS)),
            hasTemplates: \is_dir(Path::join($path, ModuleDefinition::TEMPLATES)),
        );
    }
}
