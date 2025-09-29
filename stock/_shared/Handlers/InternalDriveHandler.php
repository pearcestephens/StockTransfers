<?php
declare(strict_types=1);
/**
 * File: InternalDriveHandler.php
 * Purpose: Plan internal store-to-store drives
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

final class InternalDriveHandler extends AbstractHandler
{
    public function mode(): string
    {
        return Modes::INTERNAL_DRIVE;
    }

    public function minInputs(): array
    {
        return ['internal.driver'];
    }

    protected function doPlan(PackSendRequest $request, ValidationResult $validation, ParcelPlannerDb $planner): HandlerPlan
    {
        $plan = $this->buildBasePlan($request, 'internal', 'Internal Drive', Modes::INTERNAL_DRIVE);
        $plan->meta['internal'] = $request->modeData['internal'] ?? [];
        $this->resolveParcels($request, $planner, Modes::INTERNAL_DRIVE, $plan);
        return $plan;
    }
}
