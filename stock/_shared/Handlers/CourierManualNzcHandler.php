<?php
declare(strict_types=1);
/**
 * File: CourierManualNzcHandler.php
 * Purpose: Plan manual NZ Couriers consignments
 * Author: GitHub Copilot
 * Last Modified: 2025-09-29
 * Dependencies: AbstractHandler, Modes, ParcelPlannerDb
 */

namespace Modules\Transfers\Stock\Shared\Handlers;

use Modules\Transfers\Stock\Shared\Services\HandlerPlan;
use Modules\Transfers\Stock\Shared\Services\Modes;
use Modules\Transfers\Stock\Shared\Services\PackSendRequest;
use Modules\Transfers\Stock\Shared\Services\ParcelPlannerDb;
use Modules\Transfers\Stock\Shared\Services\ValidationResult;

final class CourierManualNzcHandler extends AbstractHandler
{
    public function mode(): string
    {
        return Modes::NZC_MANUAL;
    }

    public function minInputs(): array
    {
        return ['transfer.id', 'totals.total_weight_kg'];
    }

    protected function doPlan(PackSendRequest $request, ValidationResult $validation, ParcelPlannerDb $planner): HandlerPlan
    {
        $plan = $this->buildBasePlan($request, 'courier', 'NZ Couriers Manual', Modes::NZC_MANUAL);
        $this->resolveParcels($request, $planner, Modes::NZC_MANUAL, $plan);
        return $plan;
    }
}
