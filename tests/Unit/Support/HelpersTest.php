<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Support\Extensions;
use App\Tests\Support\TestCase;

final class HelpersTest extends TestCase
{
    private HookEngine $hooks;

    private FilterEngine $filters;

    protected function setUp(): void
    {
        $this->hooks = new HookEngine();
        $this->filters = new FilterEngine();

        Extensions::init($this->hooks, $this->filters);
    }

    protected function tearDown(): void
    {
        Extensions::reset();
    }

    // ---- dump and dd ---------------------------------------------------------

    public function test_dump_prints_the_value_and_where_it_was_called(): void
    {
        Extensions::init($this->hooks, $this->filters, debug: true);

        \ob_start();
        $line = __LINE__ + 1;
        dump(['id' => 3]);
        $out = (string) \ob_get_clean();

        self::assertStringContainsString('HelpersTest.php:' . $line, $out);
        self::assertStringContainsString('"id" => int(3)', $out);
    }

    public function test_dump_with_debug_off_prints_nothing_and_logs_where_it_is(): void
    {
        $writer = new class implements \App\Engine\Logging\LogWriter {
            /** @var list<\App\Engine\Logging\LogRecord> */
            public array $records = [];

            public function describe(): string
            {
                return 'memory';
            }

            public function accepts(\App\Engine\Logging\LogRecord $record): bool
            {
                return true;
            }

            public function write(\App\Engine\Logging\LogRecord $record): void
            {
                $this->records[] = $record;
            }
        };
        $logs = new \App\Engine\Logging\LogManager();
        $logs->add($writer);

        Extensions::init($this->hooks, $this->filters, debug: false, log: $logs->channel());

        \ob_start();
        dump('secret order data');
        $out = (string) \ob_get_clean();

        self::assertSame('', $out);
        self::assertCount(1, $writer->records);
        self::assertSame('dump() left in code', $writer->records[0]->message);
        self::assertStringContainsString('HelpersTest.php:', (string) $writer->records[0]->context['at']);
    }

    public function test_dd_with_debug_off_throws_instead_of_printing(): void
    {
        Extensions::init($this->hooks, $this->filters, debug: false);

        \ob_start();

        try {
            dd('secret order data');
        } catch (\App\Engine\Support\DebugException $e) {
            self::assertStringContainsString('dd() was called at', $e->getMessage());
            self::assertStringContainsString('HelpersTest.php:', $e->getMessage());
            self::assertFalse($e->disclosesMessage(), 'the location is for the log, not the visitor');
        } finally {
            self::assertSame('', (string) \ob_get_clean());
        }
    }

    public function test_dd_prints_and_exits_with_status_one(): void
    {
        $script = \sprintf(
            'require %s; dd(["ok" => true]); echo "not reached";',
            \var_export($this->basePath('vendor/autoload.php'), true),
        );

        \exec(\escapeshellarg(\PHP_BINARY) . ' -r ' . \escapeshellarg($script) . ' 2>&1', $output, $status);
        $out = \implode("\n", $output);

        self::assertSame(1, $status);
        self::assertStringContainsString('"ok" => bool(true)', $out);
        self::assertStringNotContainsString('not reached', $out);
    }

    // ---- hooks ------------------------------------------------------------

    public function test_add_hook_registers_on_the_wired_engine(): void
    {
        $handle = add_hook('customer.created', static fn(): null => null, 20);

        self::assertTrue($this->hooks->has('customer.created'));
        self::assertSame(20, $this->hooks->listeners('customer.created')[0]['priority']);
        self::assertSame($handle, $this->hooks->listeners('customer.created')[0]['handle']);
    }

    public function test_do_hook_fires_on_the_wired_engine(): void
    {
        $seen = [];

        $this->hooks->add('customer.created', static function (string $name) use (&$seen): void {
            $seen[] = $name;
        });

        do_hook('customer.created', 'Ada');

        self::assertSame(['Ada'], $seen);
    }

    public function test_has_hook_and_remove_hook_delegate(): void
    {
        $handle = add_hook('x', static fn(): null => null);

        self::assertTrue(has_hook('x'));
        self::assertTrue(remove_hook('x', $handle));
        self::assertFalse(has_hook('x'));
    }

    public function test_add_hook_accepts_an_accepted_args_limit(): void
    {
        $seen = null;

        add_hook('x', static function (...$args) use (&$seen): void {
            $seen = $args;
        }, 10, 1);

        do_hook('x', 'a', 'b');

        self::assertSame(['a'], $seen);
    }

    // ---- filters ----------------------------------------------------------

    public function test_add_filter_and_apply_filter_delegate(): void
    {
        add_filter('invoice.total', static fn(int $t): int => $t * 2);

        self::assertSame(20, apply_filter('invoice.total', 10));
    }

    public function test_apply_filter_passes_context_arguments(): void
    {
        add_filter('customer.name', static fn(string $n, string $suffix): string => $n . $suffix);

        self::assertSame('Ada!', apply_filter('customer.name', 'Ada', '!'));
    }

    public function test_has_filter_and_remove_filter_delegate(): void
    {
        $handle = add_filter('x', static fn(mixed $v): mixed => $v);

        self::assertTrue(has_filter('x'));
        self::assertTrue(remove_filter('x', $handle));
        self::assertFalse(has_filter('x'));
    }

    public function test_apply_filter_on_an_unregistered_name_returns_the_value(): void
    {
        self::assertSame('untouched', apply_filter('nothing.listens', 'untouched'));
    }

    // ---- the wiring itself ------------------------------------------------

    /**
     * The helpers must be a bridge to injected engines, not ambient global
     * state. If the engines are gone, calling one is a clear error rather than
     * a silent no-op against a hidden singleton.
     */
    public function test_a_helper_used_before_bootstrap_fails_loudly(): void
    {
        Extensions::reset();

        self::assertFalse(Extensions::isInitialised());

        try {
            add_hook('x', static fn(): null => null);
            self::fail('expected a LogicException');
        } catch (\LogicException $e) {
            self::assertStringContainsString('before the application was bootstrapped', $e->getMessage());
        }
    }

    public function test_the_filter_helpers_fail_loudly_too(): void
    {
        Extensions::reset();

        $this->expectException(\LogicException::class);

        apply_filter('x', 'value');
    }

    public function test_init_rebinds_the_helpers_to_new_engines(): void
    {
        add_hook('x', static fn(): null => null);
        self::assertTrue(has_hook('x'));

        Extensions::init(new HookEngine(), new FilterEngine());

        self::assertFalse(has_hook('x'));
    }

    public function test_every_helper_is_guarded_against_redeclaration(): void
    {
        $source = \file_get_contents($this->basePath('engine/Support/helpers.php'));
        self::assertIsString($source);

        // helpers.php lives in the global namespace, so function_exists may
        // legitimately appear there with or without a leading separator.
        $normalised = \str_replace('\\', '', $source);

        foreach (self::PERMITTED as $function) {
            self::assertStringContainsString(
                \sprintf("!function_exists('%s')", $function),
                $normalised,
                \sprintf('%s() is not guarded; loading the framework would fatal an app that defines it.', $function),
            );
        }
    }

    /**
     * The closed set, and the gate on growing it.
     *
     * A name belongs here only when the specification says application authors
     * reach that subsystem globally -- the hook and filter helpers, asset() and
     * template(). Everything else in the framework is injected.
     *
     * dump() and dd() are the one addition beyond the specification, and the
     * justification is the same shape: a debugging aid has to work wherever
     * code can be written -- a handler, a template, module.php, a script --
     * and most of those have nothing to inject into. They print only with
     * debug on, so one left in code cannot show a visitor anything.
     *
     * Changing this array is a design decision with a written justification,
     * not a line in somebody's commit.
     */
    public const PERMITTED = [
        'add_hook',
        'do_hook',
        'remove_hook',
        'has_hook',
        'add_filter',
        'apply_filter',
        'remove_filter',
        'has_filter',
        'asset',
        'template',
        'dump',
        'dd',
    ];

    public function test_every_permitted_helper_exists(): void
    {
        foreach (self::PERMITTED as $function) {
            self::assertTrue(\function_exists($function), $function . '() is missing');
        }
    }
}
