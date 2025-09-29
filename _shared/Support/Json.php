<?php
declare(strict_types=1);

namespace CIS\Shared\Support;

use JsonException;

/**
 * Json.php
 *
 * JSON encoding/decoding helpers with consistent flags and exceptions.
 *
 * @package CIS\Shared\Support
 */
final class Json
{
    /**
     * Decode a JSON payload into an associative array.
     *
     * @param string $raw Raw JSON string.
     *
     * @throws JsonException When decoding fails.
     *
     * @return array<string, mixed> Decoded payload.
     */
    public static function decode(string $raw): array
    {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Encode a payload into JSON using shared flags.
     *
     * @param mixed $value Value to encode.
     *
     * @return string JSON representation.
     */
    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
