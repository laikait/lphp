<?php

declare(strict_types=1);

/**
 * The layout, which is a template like any other.
 *
 * There is no @extends, no @section and no @yield, because there does not need
 * to be: a page renders itself to a string and passes it here as $content. That
 * is a function call rather than a second control flow to learn, and it is the
 * line between a template engine and a reimplementation of Blade.
 *
 * In scope: every key of the data, plus $view and $e.
 *
 * @var \App\Engine\Template\TemplateView $view
 * @var \App\Engine\Template\Escaper      $e
 * @var string                            $title
 * @var string                            $content
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($title) ?></title>
<link rel="stylesheet" href="<?= $e->attr($view->asset()->core('css/app.css')) ?>">
<link rel="stylesheet" href="<?= $e->attr($view->asset()->template('css/theme.css')) ?>">
</head>
<body>
<header><a href="<?= $e->attr($view->get('home', '.')) ?>">App Framework</a></header>
<main><?= $content ?></main>
<footer>Rendered by the <?= $e($view->get('engine', 'php')) ?> template engine.</footer>
<script type="module" src="<?= $e->attr($view->asset()->core('js/app.js')) ?>"></script>
</body>
</html>
