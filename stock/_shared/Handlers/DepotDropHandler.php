<?php
declare(strict_types=1);
/**
 * File: DepotDropHandler.php
 * Purpose: Plan depot drop consignments
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

final class DepotDropHandler extends AbstractHandler
{
    public function mode(): string
    {
        return Modes::DEPOT_DROP;
    }

    public function minInputs(): array
    {
        return [];
    }

    protected function doPlan(PackSendRequest $request, ValidationResult $validation, ParcelPlannerDb $planner): HandlerPlan
    {
        $plan = $this->buildBasePlan($request, 'dropoff', 'Depot Drop', Modes::DEPOT_DROP);
        $plan->meta['depot'] = $request->modeData['depot'] ?? [];
        $this->resolveParcels($request, $planner, Modes::DEPOT_DROP, $plan);
        return $plan;
    }
}
