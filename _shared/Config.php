<?php
declare(strict_types=1);

namespace CIS\Shared;

/**
 * Config.php
 *
 * Centralised configuration helpers for shared CIS modules.
 *
 * @package CIS\Shared
 */
final class Config
{
    /**
     * Retrieve a singleton PDO instance using environment configuration.
     *
     * @return \PDO Active PDO connection.
     */
    public static function pdo(): \PDO
    {
        static $pdo = null;
        if ($pdo instanceof \PDO) {
            return $pdo;
        }

        $dsn = getenv('DB_DSN') ?: '';
        $user = getenv('DB_USER') ?: '';
        $pass = getenv('DB_PASS') ?: '';
        $pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        return $pdo;
    }

    /**
     * Provide the fallback tare weight (grams) for outer cartons.
     *
     * @return int Tare weight in grams.
     */
    public static function defaultTareG(): int
    {
        $value = getenv('CIS_OUTER_TARE_G');

        return ($value !== false && $value !== '') ? (int) $value : 500;
    }

    /**
     * Determine whether the guardian (TrafficGuardian) feature is enabled.
     *
     * @return bool True when guardian is enabled.
     */
    public static function guardianEnabled(): bool
    {
        return (getenv('CIS_GUARDIAN') ?: '0') === '1';
    }

    /**
     * Resolve the URL that Pack & Send flows should redirect to after completion.
     *
     * @return string Fully-qualified or relative redirect URL.
     */
    public static function afterPackUrl(): string
    {
        return getenv('AFTER_PACK_URL') ?: '/transfers';
    }
}
