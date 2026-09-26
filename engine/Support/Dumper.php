<?php

declare(strict_types=1);

namespace App\Engine\Support;

/**
 * A value, written out for a person debugging: what dump() and dd() print.
 *
 * Types are shown, not guessed at: int(3) and string(1) "3" are different
 * things, and the difference is usually the bug. Objects show every property,
 * private ones included, unless the class defines __debugInfo() -- which is
 * how a Security\Secret stays "[redacted]" even here.
 *
 * Bounded, because the thing being dumped is often the thing that is too big:
 * nesting stops at MAX_DEPTH, arrays and objects at MAX_ITEMS entries, strings
 * at MAX_STRING characters, and an object met again inside itself is marked
 * rather than followed.
 *
 * In HTML every character is escaped. A dump of request input that rendered it
 * raw would be a way to inject markup by getting somebody to debug a request.
 */
final class Dumper
{
    public const MAX_DEPTH = 8;

    public const MAX_ITEMS = 200;

    public const MAX_STRING = 1000;

    /** @var array<int, true> objects currently being written, by id */
    private array $open = [];

    public static function render(mixed $value): string
    {
        return (new self())->write($value, 0);
    }

    /** The same, as a self-contained HTML block: no stylesheet, no script, everything escaped. */
    public static function html(mixed $value, string $where): string
    {
        return '<pre style="background:#18171b;color:#e0e0e0;padding:.75rem 1rem;margin:.5rem 0;'
            . 'font:12px/1.5 ui-monospace,monospace;white-space:pre-wrap;overflow-x:auto;text-align:left">'
            . '<span style="color:#888">' . self::escape($where) . "</span>\n"
            . self::escape(self::render($value))
            . '</pre>';
    }

    /**
     * Print $values for whoever is looking: plain text on the command line,
     * HTML in a browser.
     *
     * In a browser the dump comes before the response is sent, so output is
     * buffered first: without that, the first byte would send the headers and
     * the response's own headers would fail on the way out. The buffer is
     * flushed with everything else when the request ends.
     *
     * @param list<mixed> $values
     */
    public static function emit(array $values, string $where): void
    {
        if (\PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg') {
            foreach ($values as $value) {
                echo $where, "\n", self::render($value), "\n\n";
            }

            return;
        }

        if (\ob_get_level() === 0) {
            \ob_start();
        }

        foreach ($values as $value) {
            echo self::html($value, $where);
        }
    }

    /**
     * "modules/Billing/Http/Show.php:14": where the call that asked for the dump is.
     *
     * @param list<array<string, mixed>> $trace from debug_backtrace()
     */
    public static function caller(array $trace, string $function, string $basePath = ''): string
    {
        foreach ($trace as $frame) {
            if (($frame['function'] ?? null) === $function && isset($frame['file'], $frame['line'])) {
                $file = (string) $frame['file'];

                if ($basePath !== '' && \str_starts_with($file, $basePath . '/')) {
                    $file = \substr($file, \strlen($basePath) + 1);
                }

                return $file . ':' . (int) $frame['line'];
            }
        }

        return 'unknown';
    }

    private function write(mixed $value, int $depth): string
    {
        return match (true) {
            $value === null => 'null',
            \is_bool($value) => 'bool(' . ($value ? 'true' : 'false') . ')',
            \is_int($value) => 'int(' . $value . ')',
            \is_float($value) => 'float(' . \var_export($value, true) . ')',
            \is_string($value) => $this->string($value),
            \is_array($value) => $this->array($value, $depth),
            \is_object($value) => $this->object($value, $depth),
            \is_resource($value) => 'resource(' . \get_resource_type($value) . ')',
            default => \get_debug_type($value),
        };
    }

    private function string(string $value): string
    {
        $length = \strlen($value);
        $shown = $length > self::MAX_STRING ? \substr($value, 0, self::MAX_STRING) . '…' : $value;

        return \sprintf('string(%d) "%s"', $length, $shown);
    }

    /** @param array<array-key, mixed> $value */
    private function array(array $value, int $depth): string
    {
        $count = \count($value);

        if ($count === 0) {
            return 'array(0) []';
        }

        if ($depth >= self::MAX_DEPTH) {
            return \sprintf('array(%d) [ …too deep ]', $count);
        }

        $lines = [];

        foreach (\array_slice($value, 0, self::MAX_ITEMS, true) as $key => $item) {
            $lines[] = \sprintf('%s => %s', \is_int($key) ? $key : '"' . $key . '"', $this->write($item, $depth + 1));
        }

        return \sprintf('array(%d) [', $count) . $this->block($lines, $count, $depth) . ']';
    }

    private function object(object $value, int $depth): string
    {
        $id = \spl_object_id($value);
        // get_debug_type(), not ::class: an anonymous class's name carries a
        // NUL byte and its file path, which is not something to print.
        $head = \get_debug_type($value) . ' {#' . $id;

        if (isset($this->open[$id])) {
            return $head . ' *RECURSION*}';
        }

        if ($value instanceof \Closure) {
            return $head . '}';
        }

        $properties = $this->properties($value);

        if ($properties === []) {
            return $head . '}';
        }

        if ($depth >= self::MAX_DEPTH) {
            return $head . ' …too deep}';
        }

        $this->open[$id] = true;
        $lines = [];

        foreach (\array_slice($properties, 0, self::MAX_ITEMS, true) as $name => $item) {
            $lines[] = \sprintf('%s: %s', $name, $this->write($item, $depth + 1));
        }

        unset($this->open[$id]);

        return $head . $this->block($lines, \count($properties), $depth) . '}';
    }

    /**
     * "+public", "#protected", "-private", or what __debugInfo() says.
     *
     * @return array<string, mixed>
     */
    private function properties(object $value): array
    {
        if (\method_exists($value, '__debugInfo')) {
            $properties = [];

            foreach ((array) $value->__debugInfo() as $name => $item) {
                $properties['+' . $name] = $item;
            }

            return $properties;
        }

        $properties = [];

        // An (array) cast is the one view that includes private and protected
        // properties without Reflection, and it marks which is which.
        foreach ((array) $value as $name => $item) {
            $name = (string) $name;

            if (\str_starts_with($name, "\0*\0")) {
                $properties['#' . \substr($name, 3)] = $item;
            } elseif (\str_starts_with($name, "\0")) {
                // "\0Class\0name"; the last NUL, because an anonymous class's
                // own name contains one.
                $properties['-' . \substr($name, (int) \strrpos($name, "\0") + 1)] = $item;
            } else {
                $properties['+' . $name] = $item;
            }
        }

        return $properties;
    }

    /** @param list<string> $lines */
    private function block(array $lines, int $count, int $depth): string
    {
        $indent = \str_repeat('  ', $depth + 1);

        if ($count > self::MAX_ITEMS) {
            $lines[] = \sprintf('…%d more', $count - self::MAX_ITEMS);
        }

        return "\n" . $indent . \implode("\n" . $indent, $lines) . "\n" . \str_repeat('  ', $depth);
    }

    private static function escape(string $text): string
    {
        return \htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
