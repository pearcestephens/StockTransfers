<?php
declare(strict_types=1);
/**
 * File: ParcelPlannerDb.php
 * Purpose: Estimate parcel splits using DB carrier capacity metadata
 * Author: GitHub Copilot
 * Last Modified: 2025-09-29
 * Dependencies: Util\Db, Services\Payloads
 */

namespace Modules\Transfers\Stock\Shared\Services;

use Modules\Transfers\Stock\Shared\Util\Db;
use PDO;
use PDOException;

final class ParcelPlannerDb
{
    private PDO $pdo;
    private int $defaultTareG;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::pdo();
        $this->defaultTareG = (int)(getenv('CIS_OUTER_TARE_G') ?: 500);
    }

    /**
     * @return list<ParcelSpec>
     */
    public function estimateByWeight(float $totalWeightKg, string $carrierLane, ?int $preferredBoxes = null): array
    {
        $grams = (int)round($totalWeightKg * 1000);
        if ($grams <= 0) {
            return [];
        }

        $caps = $this->loadCaps($carrierLane);
        if ($caps === []) {
            return [];
        }

        $selected = $caps[0];
        $contentCap = max(1, (int)$selected['cap_g'] - (int)$selected['tare_g']);
        $boxes = $preferredBoxes !== null && $preferredBoxes > 0 ? $preferredBoxes : (int)ceil($grams / $contentCap);
        $boxes = max(1, $boxes);

        $perBoxWeight = $grams / $boxes;

        $specs = [];
        for ($i = 1; $i <= $boxes; $i++) {
            $spec = new ParcelSpec();
            $spec->boxNumber = $i;
            $spec->weightKg = round($perBoxWeight / 1000, 3);
            $spec->estimated = true;
            $spec->notes = 'estimated';
            $specs[] = $spec;
        }

        return $specs;
    }

    /**
     * @return list<array{container_code:string,cap_g:int,tare_g:int}>
     */
    private function loadCaps(string $carrierLane): array
    {
        $carrierLane = strtoupper($carrierLane);

        $sql = '';
        $params = [];
        if ($carrierLane === 'COURIER_MANUAL_NZC' || $carrierLane === 'NZC_MANUAL') {
            $sql = "
                SELECT caps.container_code, CAST(caps.container_cap_g AS UNSIGNED) AS cap_g,
                       COALESCE(c.tare_grams, :tare) AS tare_g
                  FROM v_carrier_caps caps
                  JOIN containers c ON c.code = caps.container_code
                  JOIN carriers car ON car.carrier_id = c.carrier_id
                 WHERE car.name = 'NZ Couriers'
                   AND caps.container_code IN ('E20','E40','E60')
                 ORDER BY FIELD(caps.container_code,'E20','E40','E60')";
        } else {
            $sql = "
                SELECT caps.container_code, CAST(caps.container_cap_g AS UNSIGNED) AS cap_g,
                       COALESCE(c.tare_grams, :tare) AS tare_g
                  FROM v_carrier_caps caps
                  JOIN containers c ON c.code = caps.container_code
                  JOIN carriers car ON car.carrier_id = c.carrier_id
                 WHERE car.name = 'NZ Post'
                 ORDER BY caps.container_code";
        }

        $params[':tare'] = $this->defaultTareG;

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException) {
            return [];
        }

        return array_map(static function(array $row): array {
            return [
                'container_code' => (string)$row['container_code'],
                'cap_g' => (int)$row['cap_g'],
                'tare_g' => (int)$row['tare_g'],
            ];
        }, $rows);
    }
}
