<?php
declare(strict_types=1);
/**
 * File: Json.php
 * Purpose: Safe JSON encode/decode helpers to avoid silent failures
 * Author: GitHub Copilot
 * Last Modified: 2025-09-29
 * Dependencies: PHP 8.1+
 */

namespace Modules\Transfers\Shared\Util;

use JsonException;

final class Json
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new JsonException('JSON encode failure: ' . $exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * @return array<mixed>
     */
    public static function decode(string $json): array
    {
        if ($json === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new JsonException('JSON decode failure: ' . $exception->getMessage(), $exception->getCode(), $exception);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
