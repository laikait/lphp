<?php

declare(strict_types=1);

namespace App\Engine\Error;

use App\Engine\Http\Request;
use App\Engine\Template\TemplateManager;

/**
 * An error, as a page for a person.
 *
 * Two renderings, in order of preference. An application that has a template
 * called errors/404 or errors/error gets its own page -- branded, in its own
 * layout, saying whatever the business wants a lost customer to read. An
 * application that has neither gets the built-in page below.
 *
 * The built-in one is deliberately plain and deliberately self-contained: no
 * stylesheet, no font, no asset URL. It has to work when the thing that broke
 * is the asset manager, and an error page that needs the application to be
 * healthy is an error page that vanishes exactly when it is needed.
 *
 * Rendering a template can itself throw -- a typo in errors/500 is discovered
 * the day the first 500 happens -- so a failure here falls back rather than
 * propagating. An error page that throws turns a handled 404 into an unhandled
 * 500, and the original error is lost along with it.
 */
final class ErrorPage
{
    public const DIRECTORY = 'errors';

    public function __construct(private readonly ?TemplateManager $templates = null) {}

    /**
     * The application's page when it has one, the built-in page otherwise.
     *
     * With one exception, and it is the important one: a document carrying
     * diagnostics -- which only happens in debug mode -- always gets the
     * built-in page. An application's error template is written for a visitor,
     * and it says what a visitor should read; if it won, the day somebody added
     * a branded 500 page would be the day stack traces stopped appearing, and
     * nothing about that failure would point at the template.
     *
     * The consequence is that error templates are previewed with debug off.
     * That is the right way round: a template is a production artefact, and the
     * trace is what development is for.
     */
    public function render(ErrorDocument $document, ?Request $request = null): string
    {
        if ($document->detail('trace') !== null) {
            return self::builtIn($document);
        }

        return $this->fromTemplate($document, $request) ?? self::builtIn($document);
    }

    /**
     * The template names this document would use, most specific first.
     *
     * @return list<string>
     */
    public function candidates(ErrorDocument $document): array
    {
        return [self::DIRECTORY . '/' . $document->status, self::DIRECTORY . '/error'];
    }

    /**
     * The data an error template is given: the document, and where home is.
     *
     * "Home" comes from the request because a lost visitor's way back is the
     * one link an error page must get right, and under Apache in a subdirectory
     * "/" is somebody else's site. With no request -- a fatal before one was
     * known -- the template falls back to "/" itself.
     *
     * @return array<string, mixed>
     */
    private function data(ErrorDocument $document, ?Request $request): array
    {
        return $request === null
            ? ['error' => $document]
            : ['error' => $document, 'home' => $request->basePath() . '/'];
    }

    private function fromTemplate(ErrorDocument $document, ?Request $request): ?string
    {
        if ($this->templates === null) {
            return null;
        }

        foreach ($this->candidates($document) as $name) {
            if (!$this->templates->exists($name)) {
                continue;
            }

            try {
                return $this->templates->render($name, $this->data($document, $request));
            } catch (\Throwable) {
                // Deliberately swallowed. Whatever went wrong in the error
                // template, the caller still needs a page, and the built-in one
                // cannot fail. The original error is the one worth reporting,
                // and it already has been.
                return null;
            }
        }

        return null;
    }

    /**
     * The page of last resort.
     *
     * Everything is escaped, including in debug mode: a stack trace routinely
     * contains a string argument that came from the request, and an error page
     * that renders it raw is a cross-site scripting hole reachable by causing
     * an error on purpose.
     */
    private static function builtIn(ErrorDocument $document): string
    {
        $escape = static fn(string $value): string => \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE);

        $heading = \sprintf('%d %s', $document->status, $document->title);

        $page = \sprintf(
            '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>%s</title><style>%s</style></head><body><main><h1>%s</h1><p>%s</p>',
            $escape($heading),
            self::STYLE,
            $escape($heading),
            $escape($document->message),
        );

        $trace = $document->detail('trace');

        if (\is_string($document->detail('exception')) && \is_array($trace)) {
            $page .= \sprintf(
                '<h2>%s</h2><p class="where">%s:%s</p><pre>%s</pre>',
                $escape((string) $document->detail('exception')),
                $escape((string) $document->detail('file')),
                $escape((string) $document->detail('line')),
                $escape(\implode("\n", \array_map(\strval(...), $trace))),
            );
        }

        return $page . '</main></body></html>';
    }

    private const STYLE = 'body{font:16px/1.5 system-ui,sans-serif;margin:0;padding:3rem 1rem;color:#111;'
        . 'background:#fafafa}main{max-width:44rem;margin:0 auto}h1{font-size:1.5rem;margin:0 0 .5rem}'
        . 'h2{font-size:1rem;margin:2rem 0 .25rem}p{margin:0 0 1rem}.where{color:#666;font-size:.875rem}'
        . 'pre{overflow-x:auto;background:#fff;border:1px solid #e5e5e5;padding:1rem;font-size:.8125rem}'
        . '@media(prefers-color-scheme:dark){body{background:#111;color:#eee}'
        . 'pre{background:#1a1a1a;border-color:#333}.where{color:#999}}';
}
