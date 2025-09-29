<?php
declare(strict_types=1);

namespace CIS\Shared\Support;

/**
 * Time.php
 *
 * Shared time utilities for CIS modules.
 *
 * @package CIS\Shared\Support
 */
final class Time
{
    /**
     * Retrieve the current UTC timestamp in ISO-8601 format.
     *
     * @return string Timestamp in UTC (e.g. 2025-09-29T10:15:00Z).
     */
    public static function nowUtc(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
