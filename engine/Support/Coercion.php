<?php

declare(strict_types=1);

namespace App\Engine\Support;

/**
 * The framework's one definition of an unambiguous conversion.
 *
 * Three places need to turn a loosely typed value into a declared type: route
 * parameters, which arrive as strings; storage rows, which a driver may hand
 * back entirely as strings; and schema input, which comes from a form or a JSON
 * body. Each reports failure differently -- a 400, a mapping exception, a
 * validation error -- so each keeps its own error handling and only the
 * question "is there exactly one reading of this value?" lives here.
 *
 * The rule throughout: convert when precisely one interpretation exists, and
 * refuse otherwise. "42" is one integer. "42.5" is not, and neither is true.
 * Guessing is how a typo in a query string becomes a silent 0 three layers
 * down.
 *
 * Every method returns null to mean "no unambiguous conversion". Callers deal
 * with a genuine null before calling, because null is a value question and this
 * is a type question.
 */
final class Coercion
{
    /** @param mixed $value never null; the caller settles nullability first */
    public static function toInt(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }

        // Deliberately not is_numeric: that accepts "42.5", " 42" and "4e2",
        // none of which is one integer.
        if (\is_string($value) && \preg_match('/^-?[0-9]+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /** @param mixed $value never null; the caller settles nullability first */
    public static function toFloat(mixed $value): ?float
    {
        if (\is_float($value) || \is_int($value)) {
            return (float) $value;
        }

        if (\is_string($value) && \is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /** @param mixed $value never null; the caller settles nullability first */
    public static function toString(mixed $value): ?string
    {
        if (\is_string($value)) {
            return $value;
        }

        // A number has one string form. A bool does not: "1" or "true"?
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    /** @param mixed $value never null; the caller settles nullability first */
    public static function toBool(mixed $value): ?bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if ($value === 0 || $value === 1) {
            return $value === 1;
        }

        if (\is_string($value)) {
            return match (\strtolower($value)) {
                '1', 'true', 'yes', 'on' => true,
                '0', 'false', 'no', 'off' => false,
                default => null,
            };
        }

        return null;
    }
}
