<?php
declare(strict_types=1);

namespace CIS\Shared\Support;

/**
 * Http.php
 *
 * Envelope helpers for HTTP responses and header utilities.
 *
 * @package CIS\Shared\Support
 */
final class Http
{
    /**
     * Build a standard success envelope.
     *
     * @param array<int|string, mixed> $data     Payload data.
     * @param array<int, string>       $warnings Optional warnings.
     * @param string|null              $requestId Optional request identifier.
     *
     * @return array<string, mixed> Normalised response envelope.
     */
    public static function envelopeOk(array $data, array $warnings = [], ?string $requestId = null): array
    {
        return [
            'ok' => true,
            'request_id' => $requestId ?? (self::header('X-Request-ID') ?: self::uuid()),
            'data' => $data,
            'error' => null,
            'warnings' => array_values($warnings),
        ];
    }

    /**
     * Build a standard failure envelope.
     *
     * @param string      $code      Error code identifier.
     * @param string      $message   Human-readable message.
     * @param array<mixed> $details  Additional error details.
     * @param string|null $requestId Optional request identifier.
     *
     * @return array<string, mixed> Normalised error envelope.
     */
    public static function envelopeFail(string $code, string $message, array $details = [], ?string $requestId = null): array
    {
        return [
            'ok' => false,
            'request_id' => $requestId ?? (self::header('X-Request-ID') ?: self::uuid()),
            'data' => null,
            'error' => ['code' => $code, 'message' => $message, 'details' => $details],
            'warnings' => [],
        ];
    }

    /**
     * Retrieve an HTTP header value from the current request.
     *
     * @param string $name Header name.
     *
     * @return string|null Header value when present.
     */
    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return $_SERVER[$key] ?? null;
    }

    /**
     * Generate a RFC-4122 compliant UUID v4 string.
     *
     * @return string Generated UUID.
     */
    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
