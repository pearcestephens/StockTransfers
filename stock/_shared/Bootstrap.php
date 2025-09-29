<?php
declare(strict_types=1);
/**
 * File: Bootstrap.php
 * Purpose: Bootstrap helpers for pack/send orchestrator wiring
 * Author: GitHub Copilot
 * Last Modified: 2025-09-29
 * Dependencies: Autoload.php, orchestrator services
 */

namespace Modules\Transfers\Stock\Shared;

use Modules\Transfers\Stock\Shared\Handlers\HandlerFactoryImpl;
use Modules\Transfers\Stock\Shared\Services\AuditLoggerDb;
use Modules\Transfers\Stock\Shared\Services\GuardianService;
use Modules\Transfers\Stock\Shared\Services\IdempotencyStoreDb;
use Modules\Transfers\Stock\Shared\Services\OrchestratorImpl;
use Modules\Transfers\Stock\Shared\Services\ParcelPlannerDb;
use Modules\Transfers\Stock\Shared\Services\PersistenceStock;
use Modules\Transfers\Stock\Shared\Services\RequestValidatorImpl;
use Modules\Transfers\Stock\Shared\Services\VendServiceImpl;

require_once __DIR__ . '/Autoload.php';

function pack_send_bootstrap(): void
{
    // Autoload registration already performed via require above.
}

function pack_send_orchestrator(): OrchestratorImpl
{
    static $instance = null;
    if ($instance instanceof OrchestratorImpl) {
        return $instance;
    }

    $validator = new RequestValidatorImpl();
    $guardian = new GuardianService();
    $idempotency = new IdempotencyStoreDb();
    $handlerFactory = new HandlerFactoryImpl();
    $planner = new ParcelPlannerDb();
    $persistence = new PersistenceStock();
    $vend = new VendServiceImpl();
    $audit = new AuditLoggerDb();

    $instance = new OrchestratorImpl(
        $validator,
        $guardian,
        $idempotency,
        $handlerFactory,
        $planner,
        $persistence,
        $vend,
        $audit
    );

    return $instance;
}
