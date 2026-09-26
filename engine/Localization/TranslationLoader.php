<?php

declare(strict_types=1);

namespace App\Engine\Localization;

/**
 * Reads a translation file once and keeps the array.
 *
 * A page with forty labels on it asks for forty translations and loads one
 * file. The arrays are kept for the life of this object -- a request, a
 * command, a job -- and never written anywhere, so an edited file shows on the
 * next request without anything to clear.
 */
final class TranslationLoader
{
    /** @var array<string, array<string, string>> keyed by file */
    private array $loaded = [];

    /**
     * @return array<string, string>
     *
     * @throws LocalizationException when the file is missing or does not return a flat array of strings
     */
    public function load(string $file): array
    {
        return $this->loaded[$file] ??= self::read($file);
    }

    public function isLoaded(string $file): bool
    {
        return isset($this->loaded[$file]);
    }

    public function flush(): void
    {
        $this->loaded = [];
    }

    /** @return array<string, string> */
    private static function read(string $file): array
    {
        if (!\is_file($file)) {
            throw LocalizationException::invalidFile($file, 'does not exist');
        }

        // In a closure of its own, so the file sees no variables from here.
        $messages = (static fn(string $__file): mixed => require $__file)($file);

        if (!\is_array($messages)) {
            throw LocalizationException::invalidFile($file, \sprintf('returns %s instead of an array', \get_debug_type($messages)));
        }

        foreach ($messages as $key => $message) {
            if (!\is_string($key)) {
                throw LocalizationException::invalidFile($file, \sprintf('has the non-string key %s', \var_export($key, true)));
            }

            if (!\is_string($message)) {
                throw LocalizationException::invalidFile($file, \sprintf('maps "%s" to %s instead of a string', $key, \get_debug_type($message)));
            }
        }

        /** @var array<string, string> $messages */
        return $messages;
    }
}
