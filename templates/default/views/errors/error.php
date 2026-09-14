<?php

declare(strict_types=1);

/**
 * Every other status.
 *
 * Reached when no errors/<status> template exists, so it has to say something
 * true for a 403, a 405 and a 500 alike -- which is why it says the status back
 * rather than inventing a story about what went wrong.
 *
 * $error->message is already safe to print: outside debug mode an arbitrary
 * exception's message has been replaced with the status text long before it
 * reaches a template. Escaping it anyway is not belt and braces -- in debug
 * mode it is the real message, and a real message can contain anything that was
 * in the request.
 *
 * @var \App\Engine\Template\TemplateView $view
 * @var \App\Engine\Template\Escaper      $e
 * @var \App\Engine\Error\ErrorDocument   $error
 */

$heading = $error->status >= 500
    ? 'Something went wrong at our end'
    : 'That request could not be completed';

$content = '<h1>' . $e($heading) . '</h1>'
    . '<p>' . $e($error->message) . '</p>'
    . '<p class="status">' . $e($error->status . ' ' . $error->title) . '</p>';

echo $view->render('layout', [
    'title' => $error->status . ' ' . $error->title,
    'content' => $content,
]);
