<?php
declare(strict_types=1);

namespace CIS\Shared\Contracts;

/**
 * Interfaces.php
 *
 * Interface definitions for core orchestration components.
 *
 * @package CIS\Shared\Contracts
 */
interface OrchestratorInterface
{
    /**
     * Process a Pack & Send request represented as an associative array.
     *
     * @param array<string, mixed> $json Request payload.
     *
     * @return array<string, mixed> Response envelope.
     */
    public function handle(array $json): array;
}

/**
 * Request validator contract.
 */
interface RequestValidatorInterface
{
    /**
     * Validate the incoming request object.
     *
     * @param PackSendRequest $req Fully hydrated request DTO.
     *
     * @return void
     */
    public function validate(PackSendRequest $req): void;
}

/**
 * Guardian service contract.
 */
interface GuardianServiceInterface
{
    /**
     * Retrieve the current guardian tier.
     *
     * @return string One of green, amber, or red.
     */
    public function tier(): string;
}

/**
 * Idempotency storage contract.
 */
interface IdempotencyStoreInterface
{
    /**
     * Fetch a previously stored envelope by key.
     *
     * @param string $key Idempotency key.
     *
     * @return array<string, mixed>|null Stored envelope or null.
     */
    public function get(string $key): ?array;

    /**
     * Persist a response envelope for future replay.
     *
     * @param string               $key      Idempotency key.
     * @param array<string, mixed> $envelope Response envelope.
     *
     * @return void
     */
    public function put(string $key, array $envelope): void;

    /**
     * Generate a hash representing the request payload.
     *
     * @param array<string, mixed> $json Normalised payload.
     *
     * @return string Hash digest.
     */
    public function hash(array $json): string;
}

/**
 * Handler contract for orchestration modes.
 */
interface HandlerInterface
{
    /**
     * Minimum required input paths for diagnostics.
     *
     * @return string[] Required fields.
     */
    public function minInputs(): array;

    /**
     * Generate a shipment plan for the provided request.
     *
     * @param PackSendRequest $req Request DTO.
     *
     * @return ShipmentPlan Normalised plan.
     */
    public function plan(PackSendRequest $req): ShipmentPlan;
}

/**
 * Handler factory contract.
 */
interface HandlerFactoryInterface
{
    /**
     * Resolve a handler for the requested mode.
     *
     * @param PackMode $mode Pack mode.
     *
     * @return HandlerInterface Handler instance.
     */
    public function forMode(PackMode $mode): HandlerInterface;
}

/**
 * Parcel planner contract.
 */
interface ParcelPlannerInterface
{
    /**
     * Estimate parcel specifications based on weight.
     *
     * @param int    $totalGrams Total payload weight in grams.
     * @param string $carrier    Carrier code (e.g. NZC, NZP).
     *
     * @return ParcelSpec[] Estimated parcel specs.
     */
    public function estimateByWeight(int $totalGrams, string $carrier): array;
}

/**
 * Persistence service contract.
 */
interface PersistenceServiceInterface
{
    /**
     * Commit a shipment plan transactionally.
     *
     * @param ShipmentPlan $plan Plan to persist.
     *
     * @return TxResult Persistence result DTO.
     */
    public function commit(ShipmentPlan $plan): TxResult;
}

/**
 * Vend service contract.
 */
interface VendServiceInterface
{
    /**
     * Upsert a manual consignment into Vend.
     *
     * @param string|int     $transferId   Transfer identifier.
     * @param string         $fromUUID     From outlet UUID.
     * @param string         $toUUID       To outlet UUID.
     * @param DestSnapshot|null $snapshot  Destination snapshot.
     * @param array<string, mixed> $options Additional options.
     *
     * @return VendResult Vend interaction result.
     */
    public function upsertConsignment(
        string|int $transferId,
        string $fromUUID,
        string $toUUID,
        ?DestSnapshot $snapshot,
        array $options = []
    ): VendResult;
}

/**
 * Audit logger contract.
 */
interface AuditLoggerInterface
{
    /**
     * Write an audit trail entry.
     *
     * @param string                    $action     Action identifier.
     * @param string                    $status     Status indicator.
     * @param array<string, mixed>      $before     Data before change.
     * @param array<string, mixed>      $after      Data after change.
     * @param array<string, mixed>      $meta       Additional metadata.
     * @param array<string, mixed>|null $apiResponse Optional downstream response.
     *
     * @return void
     */
    public function write(
        string $action,
        string $status,
        array $before,
        array $after,
        array $meta = [],
        ?array $apiResponse = null
    ): void;
}
