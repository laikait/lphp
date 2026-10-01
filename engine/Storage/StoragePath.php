<?php

declare(strict_types=1);

namespace App\Engine\Storage;

/**
 * The one rule for what a storage path may be, shared by every disk.
 *
 * Refused rather than repaired: "../x" normalised to "x" would make a bug
 * that tried to escape look like a request that succeeded.
 */
final class StoragePath
{
    /** @throws StorageException */
    public static function file(string $path): string
    {
        if ($path === '') {
            throw StorageException::invalidPath($path, 'it is empty.');
        }

        return self::check($path);
    }

    /**
     * A prefix for files(): "" for everything, otherwise a path whose
     * trailing slash is optional.
     *
     * @throws StorageException
     */
    public static function prefix(string $prefix): string
    {
        $prefix = \rtrim($prefix, '/');

        return $prefix === '' ? '' : self::check($prefix);
    }

    private static function check(string $path): string
    {
        if (\preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw StorageException::invalidPath($path, 'it contains a control character.');
        }

        if (\str_contains($path, '\\')) {
            throw StorageException::invalidPath($path, 'it contains a backslash.');
        }

        if (\str_starts_with($path, '/') || \preg_match('/^[A-Za-z]:/', $path) === 1) {
            throw StorageException::invalidPath($path, 'it is absolute.');
        }

        foreach (\explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw StorageException::invalidPath($path, 'it has an empty, "." or ".." segment.');
            }
        }

        return $path;
    }
}
