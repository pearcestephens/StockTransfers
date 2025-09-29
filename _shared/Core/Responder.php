<?php
declare(strict_types=1);

namespace CIS\Shared\Core;

use CIS\Shared\Support\Http;

/**
 * Responder.php
 *
 * Convenience wrapper around HTTP envelope helpers.
 *
 * @package CIS\Shared\Core
 */
final class Responder
{
    /**
     * Build a success envelope.
     *
     * @param array<string, mixed> $data     Payload data.
     * @param array<int, string>   $warnings Optional warnings.
     *
     * @return array<string, mixed> Response envelope.
     */
    public static function ok(array $data, array $warnings = []): array
    {
        return Http::envelopeOk($data, $warnings);
    }

    /**
     * Build a failure envelope.
     *
     * @param string               $code    Error code.
     * @param string               $message Human-readable message.
     * @param array<string, mixed> $details Additional details.
     *
     * @return array<string, mixed> Response envelope.
     */
    public static function fail(string $code, string $message, array $details = []): array
    {
        return Http::envelopeFail($code, $message, $details);
    }
}
