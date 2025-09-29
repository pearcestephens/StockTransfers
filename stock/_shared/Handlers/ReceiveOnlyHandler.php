<?php
declare(strict_types=1);
/**
 * File: ReceiveOnlyHandler.php
 * Purpose: Placeholder handler for receive-only workflow (no ship)
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

final class ReceiveOnlyHandler extends AbstractHandler
{
    public function mode(): string
    {
        return Modes::RECEIVE_ONLY;
    }

    protected function doPlan(PackSendRequest $request, ValidationResult $validation, ParcelPlannerDb $planner): HandlerPlan
    {
        $plan = $this->buildBasePlan($request, 'receive', 'Receive Only', Modes::RECEIVE_ONLY);
        $plan->sendNow = false;
        $plan->shouldDispatch = false;
        $plan->warnings[] = 'MODE_UNSUPPORTED: Receive-only handler is a placeholder.';
        return $plan;
    }
}
