<?php
declare(strict_types=1);

namespace CIS\Shared\Core;

use CIS\Shared\Config;
use CIS\Shared\Contracts\ParcelPlannerInterface;
use CIS\Shared\Contracts\ParcelSpec;
use PDO;

/**
 * ParcelPlanner.php
 *
 * DB-backed parcel planning utilities for manual shipments.
 *
 * @package CIS\Shared\Core
 */
final class ParcelPlanner implements ParcelPlannerInterface
{
    /** @var PDO */
    private PDO $pdo;

    /**
     * @param PDO|null $pdo Optional PDO instance.
     */
    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Config::pdo();
    }

    /**
     * {@inheritDoc}
     */
    public function estimateByWeight(int $totalGrams, string $carrier): array
    {
        if ($totalGrams <= 0) {
            return [];
        }

        $rows = $this->fetchCaps($carrier);
        if (!$rows) {
            return [];
        }

        $first = $rows[0];
        $contentCap = max(1, (int) $first['cap_g'] - (int) $first['tare_g']);
        $boxCount = (int) ceil(max(0, $totalGrams) / $contentCap);

        $specs = [];
        for ($i = 1; $i <= $boxCount; $i++) {
            $parcel = new ParcelSpec();
            $parcel->box_number = $i;
            $parcel->weight_kg = null;
            $parcel->estimated = true;
            $parcel->notes = 'estimated';
            $specs[] = $parcel;
        }

        return $specs;
    }

    /**
     * Fetch carrier container capacity information.
     *
     * @param string $carrier Carrier code.
     *
     * @return array<int, array<string, mixed>> Capacity rows.
     */
    private function fetchCaps(string $carrier): array
    {
        $tareDefault = Config::defaultTareG();

        if (strcasecmp($carrier, 'NZC') === 0) {
            $sql = "
              SELECT c.container_id, caps.container_code, c.name AS container_name,
                     CAST(caps.container_cap_g AS UNSIGNED) AS cap_g
                FROM v_carrier_caps caps
                JOIN containers c ON c.code = caps.container_code
                JOIN carriers car ON car.carrier_id = c.carrier_id
               WHERE car.name = 'NZ Couriers'
                 AND caps.container_code IN ('E20','E40','E60')
               ORDER BY FIELD(caps.container_code,'E20','E40','E60')";
        } else {
            $sql = "
              SELECT c.container_id, caps.container_code, c.name AS container_name,
                     CAST(caps.container_cap_g AS UNSIGNED) AS cap_g
                FROM v_carrier_caps caps
                JOIN containers c ON c.code = caps.container_code
                JOIN carriers car ON car.carrier_id = c.carrier_id
               WHERE car.name = 'NZ Post'
               ORDER BY caps.container_code";
        }

        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['tare_g'] = $tareDefault;
        }

        return $rows;
    }
}
