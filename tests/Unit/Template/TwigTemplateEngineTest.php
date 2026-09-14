<?php

declare(strict_types=1);

namespace App\Tests\Unit\Template;

use App\Engine\Asset\AssetManager;
use App\Engine\Asset\AssetRegistry;
use App\Engine\Asset\AssetResolver;
use App\Engine\Asset\AssetVersioning;
use App\Engine\Template\PhpTemplateEngine;
use App\Engine\Template\TemplateManager;
use App\Engine\Template\TemplateRegistry;
use App\Engine\Template\TemplateSource;
use App\Engine\Template\TwigTemplateEngine;
use App\Tests\Support\TestCase;

/**
 * Twig, when it is installed.
 *
 * It is a dev dependency rather than a real one, which is the whole claim:
 * production is not forced to carry it, and these tests are what make "optional"
 * a fact rather than a sentence in a README.
 */
final class TwigTemplateEngineTest extends TestCase
{
    private string $root;

    private TemplateRegistry $views;

    protected function setUp(): void
    {
        if (!TwigTemplateEngine::isAvailable()) {
            self::markTestSkipped('twig/twig is not installed');
        }

        $this->root = \sys_get_temp_dir() . '/framework-twig-' . \bin2hex(\random_bytes(6));

        \mkdir($this->root . '/theme', 0o777, true);
        \mkdir($this->root . '/theme/plugin.Example', 0o777, true);
        \mkdir($this->root . '/module', 0o777, true);

        $this->views = new TemplateRegistry();
        $this->views->add(null, $this->root . '/theme', TemplateSource::OVERRIDE);
        $this->views->add('plugin.Example', $this->root . '/module');
    }

    protected function tearDown(): void
    {
        foreach (['theme/plugin.Example', 'theme', 'module'] as $directory) {
            foreach (\glob($this->root . '/' . $directory . '/*') ?: [] as $file) {
                if (\is_file($file)) {
                    @\unlink($file);
                }
            }
        }

        foreach (['theme/plugin.Example', 'theme', 'module'] as $directory) {
            @\rmdir($this->root . '/' . $directory);
        }

        @\rmdir($this->root);

        parent::tearDown();
    }

    private function write(string $relative, string $contents): void
    {
        \file_put_contents($this->root . '/' . $relative, $contents);
    }

    private function manager(): TemplateManager
    {
        $templates = new TemplateManager($this->views, new AssetManager(
            new AssetRegistry(),
            new AssetResolver(),
            '',
            AssetVersioning::None,
        ));

        $templates->addEngine(new PhpTemplateEngine());
        $templates->addEngine(new TwigTemplateEngine($this->views));

        return $templates;
    }

    public function test_a_twig_template_renders(): void
    {
        $this->write('theme/hello.twig', 'Hello, {{ name }}');

        self::assertSame('Hello, Ada', $this->manager()->render('hello', ['name' => 'Ada']));
    }

    /** The reason to reach for Twig in the first place. */
    public function test_twig_escapes_without_being_asked(): void
    {
        $this->write('theme/xss.twig', '{{ payload }}');

        self::assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            $this->manager()->render('xss', ['payload' => '<script>alert(1)</script>']),
        );
    }

    /**
     * The loader mirrors the registry, so a parent named inside a Twig file
     * resolves exactly where the manager would have resolved it. If the two
     * disagreed, a template found by one would be missing to the other.
     */
    public function test_extends_resolves_across_the_same_search_path(): void
    {
        $this->write('theme/base.twig', '<main>{% block body %}{% endblock %}</main>');
        $this->write('module/child.twig', '{% extends "base.twig" %}{% block body %}from the module{% endblock %}');

        self::assertSame(
            '<main>from the module</main>',
            $this->manager()->render('@plugin.Example/child'),
        );
    }

    public function test_a_module_namespace_is_a_twig_namespace(): void
    {
        $this->write('module/row.twig', '[{{ label }}]');
        $this->write('theme/list.twig', '{% include "@plugin.Example/row.twig" with {label: "a"} %}');

        self::assertSame('[a]', $this->manager()->render('list'));
    }

    /** The override rule holds across engines, not only within one. */
    public function test_a_theme_overrides_a_modules_twig_template(): void
    {
        $this->write('module/panel.twig', 'the module');
        $this->write('theme/plugin.Example/panel.twig', 'the theme');

        self::assertSame('the theme', $this->manager()->render('@plugin.Example/panel'));
    }

    /** A PHP template in the theme beats a Twig one in the module. */
    public function test_precedence_beats_extension_across_engines(): void
    {
        $this->write('module/mixed.twig', 'module twig');
        $this->write('theme/plugin.Example/mixed.php', 'theme php');

        self::assertSame('theme php', $this->manager()->render('@plugin.Example/mixed'));
    }

    public function test_the_view_is_available_in_twig_too(): void
    {
        $this->write('theme/with-view.twig', '{{ view.get("title", "untitled") }}');

        self::assertSame('Customers', $this->manager()->render('with-view', ['title' => 'Customers']));
    }

    public function test_a_twig_error_is_wrapped_with_the_template_name(): void
    {
        $this->write('theme/bad.twig', '{% this is not twig %}');

        $this->expectException(\App\Engine\Template\TemplateException::class);
        $this->expectExceptionMessage('Rendering "bad" failed');

        $this->manager()->render('bad');
    }

    /**
     * The claim that makes Twig optional: with only the PHP engine registered,
     * everything still works and a .twig file is simply not a template.
     */
    public function test_without_the_twig_engine_a_twig_file_is_not_a_template(): void
    {
        $this->write('theme/only.twig', 'never rendered');

        $templates = new TemplateManager($this->views, new AssetManager(
            new AssetRegistry(),
            new AssetResolver(),
            '',
            AssetVersioning::None,
        ));
        $templates->addEngine(new PhpTemplateEngine());

        self::assertFalse($templates->exists('only'));
        // Longest first, which is the order the manager tries them in.
        self::assertSame(['phtml', 'php'], $templates->extensions());
    }

    public function test_the_environment_is_built_lazily(): void
    {
        $engine = new TwigTemplateEngine($this->views);

        // Modules register their directories after wiring, so an Environment
        // built in the constructor would have an empty loader.
        self::assertSame(['html.twig', 'twig'], $engine->extensions());

        $this->write('theme/late.twig', 'late');
        $this->views->add('late', $this->root . '/module');

        self::assertTrue($engine->twig()->getLoader()->exists('late.twig'));
    }
}
