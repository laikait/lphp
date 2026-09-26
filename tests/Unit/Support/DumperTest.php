<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Engine\Security\Secret;
use App\Engine\Support\Dumper;
use App\Tests\Support\TestCase;

final class DumperTest extends TestCase
{
    public function test_scalars_show_their_type(): void
    {
        self::assertSame('null', Dumper::render(null));
        self::assertSame('bool(true)', Dumper::render(true));
        self::assertSame('int(3)', Dumper::render(3));
        self::assertSame('float(1.5)', Dumper::render(1.5));
        self::assertSame('string(1) "3"', Dumper::render('3'), 'a numeric string is not an int');
    }

    public function test_arrays_show_keys_and_nesting(): void
    {
        self::assertSame(
            "array(2) [\n  0 => int(1)\n  \"k\" => array(1) [\n    0 => string(1) \"v\"\n  ]\n]",
            Dumper::render([1, 'k' => ['v']]),
        );
        self::assertSame('array(0) []', Dumper::render([]));
    }

    public function test_objects_show_every_property_and_its_visibility(): void
    {
        $object = new class {
            public int $id = 3;

            protected string $name = 'Ada';

            private bool $active = true;

            public function active(): bool
            {
                return $this->active;
            }
        };

        $out = Dumper::render($object);

        self::assertStringContainsString('+id: int(3)', $out);
        self::assertStringContainsString('#name: string(3) "Ada"', $out);
        self::assertStringContainsString('-active: bool(true)', $out);
    }

    public function test_an_object_inside_itself_is_marked_not_followed(): void
    {
        $object = new \stdClass();
        $object->self = $object;

        self::assertStringContainsString('*RECURSION*', Dumper::render($object));
    }

    public function test_a_secret_stays_redacted(): void
    {
        $out = Dumper::render(['password' => new Secret('hunter2')]);

        self::assertStringContainsString('[redacted]', $out);
        self::assertStringNotContainsString('hunter2', $out);
    }

    public function test_it_is_bounded(): void
    {
        $long = Dumper::render(\str_repeat('x', 5000));
        self::assertStringContainsString('string(5000)', $long);
        self::assertLessThan(1100, \strlen($long));

        self::assertStringContainsString('…50 more', Dumper::render(\range(1, Dumper::MAX_ITEMS + 50)));

        $deep = [];
        $cursor = &$deep;
        for ($i = 0; $i < 20; ++$i) {
            $cursor['next'] = [];
            $cursor = &$cursor['next'];
        }
        unset($cursor);

        self::assertStringContainsString('…too deep', Dumper::render($deep));
    }

    public function test_html_is_escaped(): void
    {
        $html = Dumper::html('<script>alert(1)</script>', 'app.php:1');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringStartsWith('<pre', $html);
    }

    public function test_the_caller_is_the_line_that_called(): void
    {
        $line = __LINE__ + 1;
        $trace = [['function' => 'dump', 'file' => '/srv/app/modules/Billing/Show.php', 'line' => $line]];

        self::assertSame('modules/Billing/Show.php:' . $line, Dumper::caller($trace, 'dump', '/srv/app'));
        self::assertSame('unknown', Dumper::caller([], 'dump'));
    }
}
