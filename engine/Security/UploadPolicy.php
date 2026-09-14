<?php

declare(strict_types=1);

namespace App\Engine\Security;

use App\Engine\Http\UploadedFile;
use App\Engine\Support\Path;

/**
 * What an uploaded file has to be before anything is done with it.
 *
 *     $policy = UploadPolicy::images();
 *     $problems = $policy->check($request->file('avatar'));
 *
 *     if ($problems === []) {
 *         $stored = $policy->store($request->file('avatar'), $directory);
 *     }
 *
 * **Nothing here is automatic**, and that is the one design decision worth
 * defending. A framework cannot know that this endpoint takes avatars and that
 * one takes CSVs, so a global upload policy is either wrong for one of them or
 * so permissive it is not a policy. What the framework can do is make the right
 * check short to write and hard to write incompletely -- which is why check()
 * returns every problem rather than the first, and why there is no "is this
 * file ok" boolean anywhere on it.
 *
 * The checks, in the order they matter:
 *
 * **The extension is on an allowlist.** Never a blocklist. A blocklist has to
 * enumerate .php, .phtml, .php5, .phar, .htaccess, .cgi, .pl and whatever the
 * next server module adds; an allowlist has to enumerate .jpg and .png. One of
 * those lists stops being correct when the world changes.
 *
 * **The name is a name, not a path.** "../../index.php" is a filename as far as
 * a browser is concerned.
 *
 * **There is exactly one extension.** "avatar.php.jpg" passes an extension
 * check and is still executed by an Apache with an old AddHandler line, because
 * that configuration matches on any extension in the name rather than the last.
 *
 * **The contents match the extension.** The client's Content-Type is whatever
 * the client said, so it is evidence of nothing; finfo reads the file's own
 * bytes. A .jpg that is really a PHP script fails here.
 *
 * **The size is within the limit.** Checked against the real file rather than
 * anything the request claimed.
 */
final class UploadPolicy
{
    /** 4 MiB, and an explicit argument away from being anything else. */
    public const DEFAULT_BYTES = 4194304;

    /**
     * Extensions that are dangerous wherever they appear in a name.
     *
     * Not the policy -- the policy is the allowlist. This is the second line
     * for the double-extension case, and the list is short because it only has
     * to cover what a web server executes.
     */
    public const EXECUTABLE = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'exe', 'com', 'bat', 'cmd', 'jsp', 'asp', 'aspx',
        'htaccess', 'htpasswd',
    ];

    /**
     * @param list<string>              $extensions allowed, lowercase, without dots
     * @param array<string, list<string>> $types     extension => media types its bytes may be
     */
    public function __construct(
        private readonly array $extensions,
        private readonly int $maxBytes = self::DEFAULT_BYTES,
        private readonly array $types = [],
    ) {}

    /** The common case, with the type mapping already right. */
    public static function images(int $maxBytes = self::DEFAULT_BYTES): self
    {
        return new self(['jpg', 'jpeg', 'png', 'gif', 'webp'], $maxBytes, [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
        ]);
    }

    public static function documents(int $maxBytes = self::DEFAULT_BYTES): self
    {
        return new self(['pdf', 'csv', 'txt'], $maxBytes, [
            'pdf' => ['application/pdf'],
            // A CSV is a text file, and finfo says so; it has no type of its
            // own that can be relied on across platforms.
            'csv' => ['text/plain', 'text/csv', 'application/csv'],
            'txt' => ['text/plain'],
        ]);
    }

    /** @return list<string> */
    public function extensions(): array
    {
        return $this->extensions;
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /**
     * Everything wrong with this file, in the order somebody should fix it.
     *
     * All the problems, not the first one. A user who fixes the size and is
     * then told about the type has been made to upload twice for one answer
     * the server already had.
     *
     * @return list<string> empty means acceptable
     */
    public function check(?UploadedFile $file): array
    {
        if ($file === null) {
            return ['No file was uploaded.'];
        }

        if (!$file->isValid()) {
            return [$file->errorMessage()];
        }

        $problems = [];
        $name = $file->clientName();

        if ($name !== \basename(\str_replace('\\', '/', $name))) {
            $problems[] = 'The file name contains a path. A name is a name.';
        }

        $extension = $file->clientExtension();

        if ($extension === '' || !\in_array($extension, $this->extensions, true)) {
            $problems[] = \sprintf(
                'A .%s file is not accepted here. Allowed: %s.',
                $extension === '' ? '(none)' : $extension,
                \implode(', ', $this->extensions),
            );
        }

        if ($this->hasExecutableExtension($name)) {
            $problems[] = 'The file name contains an executable extension. '
                . '"avatar.php.jpg" is a .jpg to this check and a script to some web servers.';
        }

        if ($file->size() > $this->maxBytes) {
            $problems[] = \sprintf(
                'The file is %s; the limit is %s.',
                RequestLimits::format($file->size()),
                RequestLimits::format($this->maxBytes),
            );
        }

        $mismatch = $this->contentMismatch($file, $extension);

        if ($mismatch !== null) {
            $problems[] = $mismatch;
        }

        return $problems;
    }

    /**
     * Move the file under a name this application chose.
     *
     * The stored name is generated, never the client's. A client name is
     * attacker-controlled text that becomes a path, and every rule for making
     * one safe is a rule that can be got subtly wrong; generating one removes
     * the question. The original is the caller's to keep in a database column
     * beside the row, where it is data rather than a filename.
     *
     * @return string the path written to
     */
    public function store(UploadedFile $file, string $directory, ?string $name = null): string
    {
        if (!\is_dir($directory) && !@\mkdir($directory, 0o775, true) && !\is_dir($directory)) {
            throw SecurityException::unwritableDestination($directory);
        }

        $extension = $file->clientExtension();
        $stored = ($name ?? \bin2hex(\random_bytes(16)))
            . (\in_array($extension, $this->extensions, true) ? '.' . $extension : '');

        $destination = Path::join($directory, $stored);

        // Belt and braces: a caller-supplied name is still checked against the
        // directory it is meant to land in, so that a name of "../x" cannot
        // write outside it even though it never came from the client.
        if (!Path::within($directory, $destination)) {
            throw SecurityException::unwritableDestination($destination);
        }

        $file->moveTo($destination);

        return $destination;
    }

    private function hasExecutableExtension(string $name): bool
    {
        foreach (\explode('.', \strtolower($name)) as $index => $part) {
            if ($index > 0 && \in_array($part, self::EXECUTABLE, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the bytes say, against what the name claims.
     *
     * Skipped when fileinfo is unavailable rather than failing: the extension
     * allowlist is still enforced, and refusing every upload because an
     * extension is missing would be a worse default than the one weaker check.
     * `security:check` reports when this is not running.
     */
    private function contentMismatch(UploadedFile $file, string $extension): ?string
    {
        $expected = $this->types[$extension] ?? null;

        if ($expected === null || !\function_exists('finfo_open')) {
            return null;
        }

        $finfo = @\finfo_open(\FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        $detected = @\finfo_file($finfo, $file->temporaryPath());
        @\finfo_close($finfo);

        if (!\is_string($detected) || \in_array($detected, $expected, true)) {
            return null;
        }

        return \sprintf(
            'The file is named .%s but its contents are %s. The name is not evidence of anything.',
            $extension,
            $detected,
        );
    }
}
