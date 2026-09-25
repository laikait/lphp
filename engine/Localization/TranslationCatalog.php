<?php

declare(strict_types=1);

namespace App\Engine\Localization;

use App\Engine\Support\Path;

/**
 * Where translations live: the application's lang/ and every module's.
 *
 * The root directory has no namespace, so its keys are bare: 'updated'. A
 * module's directory is registered under the same name its templates are --
 * 'shared', 'plugin.Billing', 'gateway.Stripe' -- so its keys are qualified
 * the same way: 'plugin.Billing.invoice_created'. Names are unique because
 * module ids are, and a module that is removed or disabled is never
 * registered, so its keys simply stop resolving.
 *
 * Which locales a directory has is whatever <locale>.php files are in it,
 * read once per directory the first time it is asked about. There is no list
 * of supported languages to keep in step with the files: the files are the
 * list.
 */
final class TranslationCatalog
{
    /** The namespace of the application's own lang/ directory. */
    public const ROOT = '';

    /** @var array<string, string> namespace => directory */
    private array $directories = [];

    /** @var array<string, list<string>> namespace => locales, filled on first use */
    private array $locales = [];

    public function __construct(?string $root = null)
    {
        if ($root !== null) {
            $this->directories[self::ROOT] = Path::normalize($root);
        }
    }

    /** @throws LocalizationException when $namespace is already registered */
    public function add(string $namespace, string $directory): void
    {
        if ($namespace === self::ROOT || isset($this->directories[$namespace])) {
            throw LocalizationException::duplicateNamespace($namespace);
        }

        $this->directories[$namespace] = Path::normalize($directory);
        unset($this->locales[$namespace]);
    }

    public function has(string $namespace): bool
    {
        return isset($this->directories[$namespace]);
    }

    /** @return list<string> module namespaces, without the root */
    public function namespaces(): array
    {
        $namespaces = \array_keys($this->directories);

        return \array_values(\array_filter($namespaces, static fn(string $namespace): bool => $namespace !== self::ROOT));
    }

    public function directory(string $namespace = self::ROOT): ?string
    {
        return $this->directories[$namespace] ?? null;
    }

    /** @return list<string> */
    public function locales(string $namespace = self::ROOT): array
    {
        return $this->locales[$namespace] ??= $this->scan($this->directories[$namespace] ?? null);
    }

    public function hasLocale(string $locale, string $namespace = self::ROOT): bool
    {
        return \in_array($locale, $this->locales($namespace), true);
    }

    /**
     * The file for $locale in $namespace, or null when there is none.
     *
     * The name is built only from a locale this catalog found on disk, never
     * from the argument, so no input can make it point anywhere else.
     */
    public function file(string $namespace, string $locale): ?string
    {
        $directory = $this->directories[$namespace] ?? null;

        if ($directory === null || !$this->hasLocale($locale, $namespace)) {
            return null;
        }

        return Path::join($directory, $locale . '.php');
    }

    /**
     * Which namespace a key belongs to, and the key within it.
     *
     *     'plugin.Billing.invoice_created' → ['plugin.Billing', 'invoice_created']
     *     'shared.welcome'                 → ['shared', 'welcome']
     *     'updated'                        → ['', 'updated']
     *
     * A dotted key whose prefix is no registered module is a root key.
     *
     * @return array{string, string}
     */
    public function split(string $key): array
    {
        $segments = \explode('.', $key, 3);

        if (\count($segments) === 3 && $this->has($segments[0] . '.' . $segments[1])) {
            return [$segments[0] . '.' . $segments[1], $segments[2]];
        }

        if (\count($segments) >= 2 && $segments[0] !== self::ROOT && $this->has($segments[0])) {
            return [$segments[0], \substr($key, \strlen($segments[0]) + 1)];
        }

        return [self::ROOT, $key];
    }

    /** @return list<string> */
    private function scan(?string $directory): array
    {
        if ($directory === null || !\is_dir($directory)) {
            return [];
        }

        $locales = [];

        foreach (\scandir($directory) ?: [] as $entry) {
            if (!\str_ends_with($entry, '.php')) {
                continue;
            }

            $locale = \substr($entry, 0, -4);

            // Only a file named exactly as its canonical tag, so that
            // countries.php, a backup or pt_BR.php is never taken for one.
            if (Locale::normalize($locale) === $locale && \is_file(Path::join($directory, $entry))) {
                $locales[] = $locale;
            }
        }

        \sort($locales);

        return $locales;
    }
}
