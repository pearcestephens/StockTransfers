<?php
declare(strict_types=1);
/**
 * File: PickupHandler.php
 * Purpose: Plan pickup consignments driven by staff or third parties
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

final class PickupHandler extends AbstractHandler
{
    public function mode(): string
    {
        return Modes::PICKUP;
    }

    public function minInputs(): array
    {
        return ['pickup.by', 'pickup.time'];
    }

    protected function doPlan(PackSendRequest $request, ValidationResult $validation, ParcelPlannerDb $planner): HandlerPlan
    {
        $plan = $this->buildBasePlan($request, 'pickup', 'Pickup', Modes::PICKUP);
        $plan->meta['pickup'] = $request->modeData['pickup'] ?? [];
        $this->resolveParcels($request, $planner, Modes::PICKUP, $plan);
        return $plan;
    }
}
