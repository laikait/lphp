<?php

declare(strict_types=1);

namespace App\Engine\Template;

/**
 * Templates written in Twig: the default engine.
 *
 * What Twig buys is automatic escaping -- a value printed without thinking
 * about it is escaped, so a forgotten escape is a visible double-encoding
 * rather than a hole -- and a syntax a designer can be handed without also
 * being handed PHP. What it costs is a dependency and, optionally, a
 * compilation cache. Bootstrap registers it ahead of the PHP engine, so where a
 * directory holds both page.twig and page.php, this one renders.
 *
 * Nothing else in engine/Template/ mentions Twig, and an architecture test
 * keeps it that way: the manager speaks to engines through TemplateEngine, so
 * replacing Twig later is one class and one line in Bootstrap, not a search
 * through the template layer.
 *
 * **The loader mirrors the registry.** Every search path the manager would
 * look in is given to Twig in the same order, and module namespaces become Twig
 * namespaces, so `{% extends "layout.twig" %}` and
 * `{% include "@plugin.Example/row.twig" %}` resolve exactly where the manager
 * would have resolved them — including the override rule. If they did not
 * agree, a template found by one would be missing to the other, which is the
 * sort of bug that takes a day.
 */
final class TwigTemplateEngine implements TemplateEngine
{
    private ?\Twig\Environment $twig = null;

    /** @param string|null $cacheDirectory null disables Twig's compilation cache */
    public function __construct(
        private readonly TemplateRegistry $registry,
        private readonly ?string $cacheDirectory = null,
        private readonly bool $debug = false,
    ) {}

    public function extensions(): array
    {
        // Longest first: profile.html.twig is a Twig template, not a file named
        // "profile.html" that happens to end in something.
        return ['html.twig', 'twig'];
    }

    public function render(TemplateFile $file, array $data, TemplateView $view): string
    {
        try {
            return $this->twig()->render($this->twigName($file), $data + ['view' => $view]);
        } catch (\Throwable $e) {
            throw TemplateException::renderFailed($file->name, $e);
        }
    }

    /**
     * The name Twig should resolve, rather than the path the manager found.
     *
     * Handing Twig the absolute path would work for this one file and break
     * every {% extends %} inside it, because the parent would be looked up
     * against a loader that had never been told where to look.
     */
    private function twigName(TemplateFile $file): string
    {
        return $file->name . '.' . $file->extension;
    }

    /**
     * Built on first render, not in the constructor.
     *
     * Modules register their template directories during the Register stage,
     * so an Environment built at wiring time would have an empty loader. Being
     * lazy also means a request that renders no Twig template -- an API call,
     * an asset -- never builds an Environment at all.
     */
    public function twig(): \Twig\Environment
    {
        if ($this->twig instanceof \Twig\Environment) {
            return $this->twig;
        }

        $loader = new \Twig\Loader\FilesystemLoader();

        foreach ($this->registry->searchPath(null) as $path) {
            if (\is_dir($path)) {
                $loader->addPath($path);
            }
        }

        foreach ($this->registry->namespaces() as $namespace) {
            foreach ($this->registry->searchPath($namespace) as $path) {
                if (\is_dir($path)) {
                    $loader->addPath($path, $namespace);
                }
            }
        }

        return $this->twig = new \Twig\Environment($loader, [
            // Twig's default already, stated because it is the reason to
            // choose Twig and should not be switched off by accident.
            'autoescape' => 'html',
            'cache' => $this->cacheDirectory ?? false,
            'debug' => $this->debug,
            // A missing variable is a mistake in the handler, and silently
            // rendering nothing is how it reaches production.
            'strict_variables' => $this->debug,
        ]);
    }

    /** Forget the Environment, so a later registration is picked up. */
    public function flush(): void
    {
        $this->twig = null;
    }
}
