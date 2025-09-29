<?php
declare(strict_types=1);

namespace CIS\Shared\Support;

/**
 * Uuid.php
 *
 * Lightweight UUID validation utilities.
 *
 * @package CIS\Shared\Support
 */
final class Uuid
{
    /**
     * Determine whether a string is a valid UUID v4 format.
     *
     * @param string|null $value Value to test.
     *
     * @return bool True when the value matches the canonical UUID format.
     */
    public static function isUuid(?string $value): bool
    {
        if (!$value) {
            return false;
        }

        return (bool) preg_match(
            '#^[0-9a-fA-F]{8}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{12}$#',
            $value
        );
    }
}
