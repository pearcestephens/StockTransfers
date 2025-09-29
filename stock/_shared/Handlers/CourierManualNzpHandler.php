<?php
declare(strict_types=1);
/**
 * File: CourierManualNzpHandler.php
 * Purpose: Plan manual NZ Post consignments
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

final class CourierManualNzpHandler extends AbstractHandler
{
    public function mode(): string
    {
        return Modes::NZP_MANUAL;
    }

    public function minInputs(): array
    {
        return ['transfer.id'];
    }

    protected function doPlan(PackSendRequest $request, ValidationResult $validation, ParcelPlannerDb $planner): HandlerPlan
    {
        $plan = $this->buildBasePlan($request, 'courier', 'NZ Post Manual', Modes::NZP_MANUAL);
        $this->resolveParcels($request, $planner, Modes::NZP_MANUAL, $plan);
        return $plan;
    }
}
