<?php

declare(strict_types=1);

namespace App\Tests\Unit\Localization;

use App\Tests\Support\TestCase;

/**
 * Translation directories written for one test and removed after it.
 */
abstract class LocalizationTestCase extends TestCase
{
    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            foreach (\glob($directory . '/*') ?: [] as $file) {
                \unlink($file);
            }

            \rmdir($directory);
        }

        $this->directories = [];
    }

    /**
     * A directory holding one file per entry: an array is written as a file
     * returning it, a string is written as it is.
     *
     * @param array<string, array<array-key, mixed>|string> $files file name => contents
     */
    protected function directory(array $files): string
    {
        $directory = \sys_get_temp_dir() . '/lang-' . \bin2hex(\random_bytes(6));
        \mkdir($directory);
        $this->directories[] = $directory;

        foreach ($files as $name => $contents) {
            \file_put_contents(
                $directory . '/' . $name,
                \is_array($contents) ? "<?php\n\nreturn " . \var_export($contents, true) . ";\n" : $contents,
            );
        }

        return $directory;
    }
}
