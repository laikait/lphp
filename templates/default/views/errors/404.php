<?php

declare(strict_types=1);

/**
 * The page a lost visitor sees.
 *
 * A template named after the status wins over errors/error, which is the whole
 * of the rule: a 404 deserves a different sentence from a 500, because one is
 * the visitor's mistake and the other is ours.
 *
 * It goes through the layout like any other page -- two renders, not one with a
 * magic parent -- so a branded error page costs one file rather than a
 * subsystem. What it must not do is depend on the application being healthy. If
 * this throws, the framework falls back to its own built-in page rather than
 * turning a handled 404 into an unhandled 500.
 *
 * @var \App\Engine\Template\TemplateView $view
 * @var \App\Engine\Template\Escaper      $e
 * @var \App\Engine\Error\ErrorDocument   $error
 */

$content = '<h1>That page is not here</h1>'
    . '<p>The link may be out of date, or the address may have a typo in it.</p>';

echo $view->render('layout', ['title' => 'Not found', 'content' => $content]);
