<?php
declare(strict_types=1);

namespace CIS\Shared\Core;

use CIS\Shared\Config;
use CIS\Shared\Contracts\DestSnapshot;
use CIS\Shared\Contracts\VendResult;
use CIS\Shared\Contracts\VendServiceInterface;
use PDO;
use Throwable;

/**
 * VendService.php
 *
 * Placeholder Vend consignment integration.
 *
 * @package CIS\Shared\Core
 */
final class VendService implements VendServiceInterface
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
    public function upsertConsignment(
        string|int $transferId,
        string $fromUUID,
        string $toUUID,
        ?DestSnapshot $snapshot,
        array $options = []
    ): VendResult {
        $result = new VendResult();

        try {
            $result->ok = true;
        } catch (Throwable $exception) {
            $result->ok = false;
            $result->message = $exception->getMessage();
        }

        return $result;
    }
}
