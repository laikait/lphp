<?php

declare(strict_types=1);

namespace App\Engine\Template;

use App\Engine\Asset\AssetManager;
use App\Engine\Cache\Cache;
use App\Engine\Cache\Stores\NullStore;
use App\Engine\Support\Path;

/**
 * The template API application code actually uses.
 *
 *     template()->render('customer/profile', ['customer' => $customer]);
 *     template()->render('@plugin.Example/invoice', $data);
 *
 * **No extension is required**, and that is not a convenience: it is what lets
 * a template be migrated from PHP to Twig, or a Twig one be overridden by a PHP
 * one, without a single caller changing. The manager knows every extension any
 * registered engine claims and tries them all.
 *
 * **Resolution order**, first hit wins:
 *
 *   1. templates/ -- "customer/profile" is templates/customer/profile.twig
 *   2. for a namespaced name, templates/ under a subdirectory named after the
 *      namespace -- this is the override rule, see TemplateRegistry
 *   3. the module's own Templates/ directory
 *
 * so a site replaces a plugin's markup by adding a file to templates/, and the
 * plugin's copy is the fallback. templates/assets/ is never searched: it
 * holds static files.
 *
 * **This class renders; it never responds.** It cannot see Request or Response
 * and an architecture test keeps it that way. A handler wraps the string:
 * `new Response($templates->render(...))`. That is what lets the same template
 * be rendered into an email, a PDF pipeline or a test assertion.
 */
final class TemplateManager
{
    /** Namespaced names look like "@plugin.Example/invoice". */
    public const NAMESPACE_PREFIX = '@';

    /** @var array<string, TemplateEngine> keyed by extension, in the order they are tried */
    private array $engines = [];

    /** @var array<string, TemplateFile|null> resolved and missing, within this process */
    private array $resolved = [];

    /** How deep render() is currently nested, so a cycle is a message not a crash. */
    private int $depth = 0;

    private const MAX_DEPTH = 32;

    private const SEGMENT_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9._-]*$/';

    /** The one directory under templates/ that is never a view. */
    private const ASSETS = 'assets';

    public function __construct(
        private readonly TemplateRegistry $registry,
        private readonly AssetManager $assets,
        private readonly Escaper $escaper = new Escaper(),
        /**
         * Where a resolved name is kept between requests.
         *
         * A null object by default, so every existing caller and every test
         * builds one of these the way it always did and resolution behaves
         * exactly as it did before there was a cache.
         */
        private readonly Cache $cache = new Cache(new NullStore()),
    ) {}

    // ---- engines ----------------------------------------------------------

    /**
     * Claim a set of extensions.
     *
     * One extension, one engine. Two engines claiming ".twig" would make which
     * one rendered a file depend on registration order, which is exactly the
     * kind of thing that works on one machine.
     *
     * **Registration order is precedence**, and that one IS deliberate. Where a
     * directory holds both home.twig and home.php, the engine added first
     * renders; Bootstrap adds Twig and then PHP, so Twig is the default and PHP
     * the fallback. Within one engine its extensions are tried in the order it
     * lists them, which is why an engine lists "html.twig" before "twig": the
     * name "home.html" with ".twig" is the same file as "home" with
     * ".html.twig", and the longer claim should win.
     *
     * Precedence between directories still comes first -- see find().
     */
    public function addEngine(TemplateEngine $engine): void
    {
        foreach ($engine->extensions() as $extension) {
            $extension = \strtolower($extension);
            $existing = $this->engines[$extension] ?? null;

            if ($existing !== null && $existing::class !== $engine::class) {
                throw TemplateException::duplicateExtension($extension, $existing::class);
            }

            $this->engines[$extension] = $engine;
        }

        $this->resolved = [];
    }

    /** @return list<string> every extension that can be rendered, in match order */
    public function extensions(): array
    {
        return \array_keys($this->engines);
    }

    public function engineFor(string $extension): TemplateEngine
    {
        return $this->engines[\strtolower($extension)]
            ?? throw TemplateException::noEngineFor($extension);
    }

    public function registry(): TemplateRegistry
    {
        return $this->registry;
    }

    // ---- rendering --------------------------------------------------------

    /**
     * Find the template, render it, return the markup.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $name, array $data = []): string
    {
        $file = $this->locate($name);

        if (++$this->depth > self::MAX_DEPTH) {
            $this->depth = 0;

            throw TemplateException::renderFailed($name, new \RuntimeException(\sprintf(
                'templates are nested more than %d deep, which almost always means one includes itself',
                self::MAX_DEPTH,
            )));
        }

        try {
            return $this->engineFor($file->extension)->render(
                $file,
                $data,
                new TemplateView($this, $this->assets, $this->escaper, $data),
            );
        } finally {
            --$this->depth;
        }
    }

    public function exists(string $name): bool
    {
        try {
            return $this->find($name) !== null;
        } catch (TemplateException) {
            return false;
        }
    }

    /** @throws TemplateException when nothing matches */
    public function locate(string $name): TemplateFile
    {
        return $this->find($name) ?? throw TemplateException::notFound(
            $name,
            $this->registry->searchPath($this->split($name)[0]),
            $this->extensions(),
        );
    }

    /**
     * The search, or null.
     *
     * Directory-major rather than extension-major: every extension is tried in
     * the highest-precedence directory before dropping to the next one. That is
     * the order that makes overriding work -- a theme's customer.php has to beat
     * a module's customer.twig, and the other loop order would let the module
     * win by virtue of its file extension.
     */
    public function find(string $name): ?TemplateFile
    {
        if (\array_key_exists($name, $this->resolved)) {
            return $this->resolved[$name];
        }

        $remembered = $this->remembered($name);

        if ($remembered !== null) {
            return $this->resolved[$name] = $remembered;
        }

        [$namespace, $relative] = $this->split($name);

        if ($namespace !== null && !$this->registry->hasNamespace($namespace)) {
            throw TemplateException::unknownNamespace($namespace, $name);
        }

        $this->assertUsableName($name, $relative);

        // templates/assets/ holds the site's static files, served by the asset
        // layer. Resolving a view there would run a .php among them as code.
        if ($namespace === null && \explode('/', $relative, 2)[0] === self::ASSETS) {
            throw TemplateException::unacceptableName(
                $name,
                'templates/assets/ holds static files, not views; asset()->template() links to them',
            );
        }

        foreach ($this->registry->searchPath($namespace) as $directory) {
            foreach ($this->extensions() as $extension) {
                $candidate = Path::join($directory, $relative . '.' . $extension);

                if (!\is_file($candidate)) {
                    continue;
                }

                if (!Path::within($directory, $candidate)) {
                    // Every textual escape was refused above, so getting here
                    // means a link points out of the directory.
                    throw TemplateException::escapesSearchPath($name);
                }

                $real = \realpath($candidate);

                return $this->resolved[$name] = $this->remember(new TemplateFile(
                    name: $name,
                    root: $directory,
                    relativePath: $relative . '.' . $extension,
                    absolutePath: Path::normalize($real === false ? $candidate : $real),
                    extension: $extension,
                ));
            }
        }

        return $this->resolved[$name] = null;
    }

    /**
     * Split "@plugin.Example/invoice" into its namespace and its path.
     *
     * @return array{string|null, string}
     */
    public function split(string $name): array
    {
        if (!\str_starts_with($name, self::NAMESPACE_PREFIX)) {
            return [null, $name];
        }

        $rest = \substr($name, 1);
        $slash = \strpos($rest, '/');

        if ($slash === false || $slash === 0) {
            throw TemplateException::unacceptableName(
                $name,
                'a namespaced name is "@namespace/path" and this has no path after the namespace',
            );
        }

        return [\substr($rest, 0, $slash), \substr($rest, $slash + 1)];
    }

    /**
     * Names come from application code rather than from requests -- but "rather
     * than" is not "never", and a name assembled from a route parameter is one
     * refactor away in any application. The same segment rule as the asset
     * layer, for the same reason: ".." is unrepresentable rather than filtered,
     * and a leading dot keeps .env and friends out without naming them.
     */
    private function assertUsableName(string $name, string $relative): void
    {
        if ($relative === '') {
            throw TemplateException::unacceptableName($name, 'it is empty');
        }

        if (\str_contains($relative, "\0")) {
            throw TemplateException::unacceptableName('(binary)', 'it contains a null byte');
        }

        if (\str_contains($relative, '\\')) {
            throw TemplateException::unacceptableName(
                $name,
                'it contains a backslash; template names use "/" on every platform',
            );
        }

        if (\str_starts_with($relative, '/') || \preg_match('/^[A-Za-z]:/', $relative) === 1) {
            throw TemplateException::unacceptableName($name, 'it is absolute, and template names are relative');
        }

        foreach (\explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw TemplateException::unacceptableName(
                    $name,
                    'it tries to traverse out of the template directory',
                );
            }

            if (\preg_match(self::SEGMENT_PATTERN, $segment) !== 1) {
                throw TemplateException::unacceptableName(
                    $name,
                    \sprintf('the segment "%s" is not a plain file or directory name', $segment),
                );
            }
        }
    }

    /**
     * A resolution kept from an earlier request, if it still describes a file.
     *
     * The file is stat-ed before it is trusted, which is one stat instead of
     * the directories-times-extensions the search would do -- and it means a
     * cache that outlived the layout it describes degrades into a slow lookup
     * rather than a missing template.
     *
     * What it cannot notice is a NEW file that would have won: dropping an
     * override into a theme is a deployment, and a deployment runs cache:clear.
     */
    private function remembered(string $name): ?TemplateFile
    {
        $file = $this->cache->get($this->keyFor($name));

        if (!$file instanceof TemplateFile || !\is_file($file->absolutePath)) {
            return null;
        }

        return $file;
    }

    /**
     * Keep a resolution for the next request.
     *
     * Only hits. A miss is remembered within this process but never written to
     * the cache: a missing template is an exception rather than a slow path, so
     * caching one would turn "add the file" into "add the file and clear the
     * cache" -- and the failure it causes looks nothing like a stale cache.
     */
    private function remember(TemplateFile $file): TemplateFile
    {
        $this->cache->set($this->keyFor($file->name), $file);

        return $file;
    }

    /**
     * A template name hashed into something a cache key may contain.
     *
     * Names hold "@" and "/", which keys deliberately do not: a key becomes a
     * filename in one store and part of a protocol in another, and Cache
     * refuses rather than escaping per backend.
     */
    private function keyFor(string $name): string
    {
        return \hash('xxh128', $name);
    }

    /** Forget what has been resolved; a long-running worker between jobs. */
    public function flush(): void
    {
        $this->resolved = [];
    }
}
