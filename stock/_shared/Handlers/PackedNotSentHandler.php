<?php
declare(strict_types=1);
/**
 * File: PackedNotSentHandler.php
 * Purpose: Handler for packing without dispatching
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

final class PackedNotSentHandler extends AbstractHandler
{
    public function mode(): string
    {
        return Modes::PACKED_NOT_SENT;
    }

    public function minInputs(): array
    {
        return ['transfer.id'];
    }

    protected function doPlan(PackSendRequest $request, ValidationResult $validation, ParcelPlannerDb $planner): HandlerPlan
    {
        $plan = $this->buildBasePlan($request, 'courier', 'Manual Pack', 'MANUAL');
        $plan->sendNow = false;
        $plan->shouldDispatch = false;
        $this->resolveParcels($request, $planner, $plan->carrierLane, $plan);
        return $plan;
    }
}
