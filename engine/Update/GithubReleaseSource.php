<?php

declare(strict_types=1);

namespace App\Engine\Update;

/**
 * Releases published on GitHub by the release workflow.
 *
 * Each release carries three assets: lphp-vX.Y.Z.zip, its .sha256, and the
 * framework.json inside it, published on its own so an application created
 * before manifests existed can fetch the one for its version without
 * downloading the whole release. The zip is checked against the checksum
 * before it is unpacked; a mismatch stops the update.
 *
 * Plain https through PHP's own stream wrapper -- no HTTP client, no
 * dependency -- with a timeout, and a User-Agent, which GitHub's API requires.
 */
final class GithubReleaseSource implements ReleaseSource
{
    public const REPOSITORY = 'laikait/lphp';

    private const API = 'https://api.github.com/repos/';

    /** @var array<string, array<string, string>> version => asset name => download URL */
    private array $assets = [];

    /**
     * @param ?\Closure(string): string $http GET a URL and return the body, or throw; for tests
     */
    public function __construct(
        private readonly string $repository = self::REPOSITORY,
        private readonly float $timeout = 30.0,
        private readonly ?\Closure $http = null,
    ) {}

    public function latest(): string
    {
        return $this->release('latest');
    }

    public function fetch(string $version, string $workDirectory): Release
    {
        $assets = $this->assetsOf($version);
        $zipName = 'lphp-v' . $version . '.zip';
        $zipUrl = $assets[$zipName] ?? throw UpdateException::download($this->describe() . ' v' . $version, 'it has no ' . $zipName);
        $sumUrl = $assets[$zipName . '.sha256'] ?? throw UpdateException::download($this->describe() . ' v' . $version, 'it has no checksum');

        if (!\is_dir($workDirectory) && !@\mkdir($workDirectory, 0o755, true) && !\is_dir($workDirectory)) {
            throw UpdateException::unwritable($workDirectory);
        }

        $zip = $workDirectory . '/' . $zipName;

        if (@\file_put_contents($zip, $this->get($zipUrl)) === false) {
            throw UpdateException::unwritable($zip);
        }

        ArchiveReleaseSource::verify($zip, $this->get($sumUrl));
        $release = Release::open(ArchiveReleaseSource::unpack($zip, $workDirectory . '/release'));

        if ($release->version() !== $version) {
            throw UpdateException::versionMismatch($version, $release->version());
        }

        return $release;
    }

    public function manifest(string $version): ?Manifest
    {
        try {
            $url = $this->assetsOf($version)[Manifest::FILE] ?? null;

            return $url === null ? null : Manifest::fromJson($this->get($url), $this->describe() . ' v' . $version);
        } catch (UpdateException) {
            return null;
        }
    }

    public function describe(): string
    {
        return 'github.com/' . $this->repository;
    }

    /** The version of the release at "latest" or "tags/vX.Y.Z", remembering its assets. */
    private function release(string $which): string
    {
        $url = self::API . $this->repository . '/releases/' . $which;

        try {
            $data = \json_decode($this->get($url), true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw UpdateException::download($url, 'the answer is not JSON');
        }

        $tag = \is_array($data) ? ($data['tag_name'] ?? null) : null;

        if (!\is_string($tag) || \preg_match('/^v?(\d+\.\d+\.\d+)$/D', $tag, $match) !== 1) {
            throw UpdateException::download($url, 'it names no version tag');
        }

        $assets = [];

        foreach (\is_array($data['assets'] ?? null) ? $data['assets'] : [] as $asset) {
            if (\is_array($asset) && \is_string($asset['name'] ?? null) && \is_string($asset['browser_download_url'] ?? null)) {
                $assets[$asset['name']] = $asset['browser_download_url'];
            }
        }

        $this->assets[$match[1]] = $assets;

        return $match[1];
    }

    /** @return array<string, string> */
    private function assetsOf(string $version): array
    {
        if (!isset($this->assets[$version])) {
            $this->release('tags/v' . $version);
        }

        return $this->assets[$version] ?? [];
    }

    private function get(string $url): string
    {
        if ($this->http !== null) {
            return ($this->http)($url);
        }

        if (!\str_starts_with($url, 'https://')) {
            throw UpdateException::download($url, 'only https is used');
        }

        $context = \stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeout,
                'follow_location' => 1,
                'ignore_errors' => true,
                'header' => "User-Agent: lphp-framework-update\r\nAccept: application/vnd.github+json, application/octet-stream\r\n",
            ],
        ]);

        $body = @\file_get_contents($url, false, $context);
        // http_get_last_response_headers() from PHP 8.4; before that the magic
        // variable, which a failed connection leaves undefined.
        $headers = \function_exists('http_get_last_response_headers')
            ? (\http_get_last_response_headers() ?? [])
            : (\get_defined_vars()['http_response_header'] ?? []);
        $status = 0;

        // After redirects the headers of every response are listed; the last status line is the answer.
        foreach ($headers as $header) {
            if (\preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        if ($body === false || $status !== 200) {
            throw UpdateException::download($url, $status === 0 ? 'no answer' : 'HTTP ' . $status);
        }

        return $body;
    }
}
