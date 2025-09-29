<?php
declare(strict_types=1);
/**
 * File: Uuid.php
 * Purpose: UUID validation and generation helpers for pack/send orchestration
 * Author: GitHub Copilot
 * Last Modified: 2025-09-29
 * Dependencies: ext-random, PHP 8.1+
 */

namespace Modules\Transfers\Stock\Shared\Util;

final class Uuid
{
    private const REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    private function __construct()
    {
    }

    public static function v4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function isValid(?string $uuid): bool
    {
        if ($uuid === null) {
            return false;
        }

        return $uuid !== '' && preg_match(self::REGEX, $uuid) === 1;
    }

    public static function normalize(?string $uuid): string
    {
        $uuid = trim((string)($uuid ?? ''));
        if ($uuid === '') {
            return '';
        }

        return strtolower($uuid);
    }
}
