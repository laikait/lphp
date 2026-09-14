<?php

declare(strict_types=1);

namespace App\Engine\Template;

use App\Engine\Error\FrameworkException;

final class TemplateException extends FrameworkException
{
    /**
     * Nothing in the search path had that name.
     *
     * The message lists where it looked, in the order it looked, because "not
     * found" on its own is the least useful thing a template system can say --
     * the answer is almost always that the file is one directory over, or that
     * the namespace was spelled differently.
     *
     * @param list<string> $searched
     * @param list<string> $extensions
     */
    public static function notFound(string $name, array $searched, array $extensions): self
    {
        return new self(\sprintf(
            'No template named "%s" was found. Tried %s in: %s.',
            $name,
            \implode(', ', \array_map(static fn(string $e): string => '.' . $e, $extensions)),
            $searched === [] ? '(nothing is registered)' : \implode(', ', $searched),
        ));
    }

    public static function unknownNamespace(string $namespace, string $name): self
    {
        return new self(\sprintf(
            'The template "%s" asks for the "%s" namespace, which nothing has registered. '
            . 'A module publishes one by having a Templates/ directory.',
            $name,
            $namespace,
        ));
    }

    /**
     * The name could not become a path.
     *
     * Template names reach this from application code rather than from a
     * request, but "rather than" is not "never": a name assembled from a route
     * parameter is one refactor away in any application, so the check is here
     * rather than in a comment saying it is not needed.
     */
    public static function unacceptableName(string $name, string $reason): self
    {
        return new self(\sprintf('The template name "%s" was rejected: %s.', $name, $reason));
    }

    /**
     * The name resolved to a file outside every directory it was allowed to
     * look in -- in practice, a symlink out of a template directory.
     */
    public static function escapesSearchPath(string $name): self
    {
        return new self(\sprintf(
            'The template "%s" resolves to a location outside its template directory.',
            $name,
        ));
    }

    public static function noEngineFor(string $extension): self
    {
        return new self(\sprintf(
            'No template engine handles ".%s". Register one with TemplateManager::addEngine().',
            $extension,
        ));
    }

    public static function duplicateExtension(string $extension, string $engine): self
    {
        return new self(\sprintf(
            'The ".%s" extension is already handled by %s. One extension, one engine.',
            $extension,
            $engine,
        ));
    }

    public static function duplicateNamespace(TemplateSource $existing): self
    {
        return new self(\sprintf(
            'Templates for %s are already registered from "%s". '
            . 'Two directories cannot share one namespace at the same precedence.',
            $existing->describe(),
            $existing->root,
        ));
    }

    /**
     * Withheld: whatever the template threw is quoted here, and a template is
     * application code that can throw anything at all.
     */
    public static function renderFailed(string $name, \Throwable $previous): self
    {
        return (new self(
            \sprintf('Rendering "%s" failed: %s', $name, $previous->getMessage()),
            0,
            $previous,
        ))->withheld();
    }

    public static function twigIsNotInstalled(): self
    {
        return new self(
            'Twig templates were requested but twig/twig is not installed. '
            . 'Run "composer require twig/twig". PHP templates work without it, which is the point of it being optional.',
        );
    }
}
