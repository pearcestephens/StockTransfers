<?php
declare(strict_types=1);
/**
 * File: Time.php
 * Purpose: Time helpers centralised for pack/send orchestration
 * Author: GitHub Copilot
 * Last Modified: 2025-09-29
 * Dependencies: PHP 8.1+
 */

namespace Modules\Transfers\Shared\Util;

use DateTimeImmutable;
use DateTimeZone;

final class Time
{
    private function __construct()
    {
    }

    public static function nowUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function nowUtcString(): string
    {
        return self::nowUtc()->format('Y-m-d H:i:s');
    }

    public static function iso8601(): string
    {
        return self::nowUtc()->format(DateTimeImmutable::ATOM);
    }

    public static function toSqlDateTime(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        try {
            $dateTime = new DateTimeImmutable($trimmed);
        } catch (\Exception $exception) {
            return null;
        }

        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
