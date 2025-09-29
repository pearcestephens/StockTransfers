<?php
declare(strict_types=1);

namespace CIS\Shared\Support;

/**
 * Types.php
 *
 * Scalar coercion helpers used throughout shared modules.
 *
 * @package CIS\Shared\Support
 */
final class Types
{
    /**
     * Coerce a value to a non-empty string when possible.
     *
     * @param mixed       $value   Value to coerce.
     * @param string|null $default Default when coercion fails.
     *
     * @return string|null Normalised string or the default value.
     */
    public static function str(mixed $value, ?string $default = null): ?string
    {
        if ($value === null) {
            return $default;
        }

        $scalar = is_string($value) ? $value : (is_scalar($value) ? (string) $value : null);

        return ($scalar === null || $scalar === '') ? $default : $scalar;
    }

    /**
     * Coerce a value to an integer when possible.
     *
     * @param mixed    $value   Value to coerce.
     * @param int|null $default Default when coercion fails.
     *
     * @return int|null Normalised integer or the default value.
     */
    public static function int(mixed $value, ?int $default = null): ?int
    {
        if ($value === null) {
            return $default;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * Coerce a value to a floating-point number when possible.
     *
     * @param mixed     $value   Value to coerce.
     * @param float|null $default Default when coercion fails.
     *
     * @return float|null Normalised float or the default value.
     */
    public static function float(mixed $value, ?float $default = null): ?float
    {
        if ($value === null) {
            return $default;
        }

        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return $default;
    }

    /**
     * Coerce a value to a boolean when possible.
     *
     * @param mixed     $value   Value to coerce.
     * @param bool|null $default Default when coercion fails.
     *
     * @return bool|null Normalised boolean or the default value.
     */
    public static function bool(mixed $value, ?bool $default = null): ?bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $token = strtolower((string) $value);
        if (in_array($token, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($token, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $default;
    }
}
