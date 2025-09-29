<?php
declare(strict_types=1);
/**
 * File: AbstractHandler.php
 * Purpose: Shared utilities for handler implementations
 * Author: GitHub Copilot
 * Last Modified: 2025-09-29
 * Dependencies: Payloads.php, ParcelPlannerDb
 */

namespace Modules\Transfers\Stock\Shared\Handlers;

use Modules\Transfers\Stock\Shared\Services\HandlerPlan;
use Modules\Transfers\Stock\Shared\Services\PackSendRequest;
use Modules\Transfers\Stock\Shared\Services\ParcelPlannerDb;
use Modules\Transfers\Stock\Shared\Services\ParcelSpec;
use Modules\Transfers\Stock\Shared\Services\PlanTotals;
use Modules\Transfers\Stock\Shared\Services\ValidationResult;

abstract class AbstractHandler implements HandlerInterface
{
    protected function buildBasePlan(PackSendRequest $request, string $deliveryMode, string $carrierLabel, string $carrierLane): HandlerPlan
    {
        $plan = new HandlerPlan();
        $plan->mode = $request->mode;
        $plan->deliveryMode = $deliveryMode;
        $plan->carrierLabel = $carrierLabel;
        $plan->carrierLane = $carrierLane;
        $plan->sendNow = $request->sendNow;
        $plan->shouldDispatch = $request->sendNow;
        return $plan;
    }

    protected function resolveParcels(PackSendRequest $request, ParcelPlannerDb $planner, string $carrierLane, HandlerPlan $plan): void
    {
        $parcels = [];
        $totalWeightG = 0;
        $boxCount = 0;

        if ($request->parcels !== []) {
            foreach ($request->parcels as $index => $parcelInput) {
                $spec = new ParcelSpec();
                $spec->boxNumber = $parcelInput->sequence > 0 ? $parcelInput->sequence : ($index + 1);
                $spec->weightKg = $parcelInput->weightKg;
                $spec->lengthMm = $parcelInput->lengthMm;
                $spec->widthMm = $parcelInput->widthMm;
                $spec->heightMm = $parcelInput->heightMm;
                $spec->estimated = $parcelInput->estimated;
                $spec->notes = $parcelInput->notes;
                $parcels[] = $spec;

                if ($spec->weightKg !== null) {
                    $totalWeightG += (int)round($spec->weightKg * 1000);
                }
                $boxCount++;
            }
        } elseif ($request->totals->totalWeightKg !== null && $request->totals->totalWeightKg > 0) {
            $estimate = $planner->estimateByWeight((float)$request->totals->totalWeightKg, $carrierLane, $request->totals->boxCount);
            if ($estimate === []) {
                $plan->warnings[] = 'PLANNER_UNAVAILABLE: No capacity data available; parcels require manual entry.';
            }
            foreach ($estimate as $spec) {
                $parcels[] = $spec;
            }
            $boxCount = count($estimate);
            $totalWeightG = (int)round($request->totals->totalWeightKg * 1000);
        } else {
            $plan->warnings[] = 'PARCELS_UNKNOWN: No parcels or totals supplied; using zero placeholders.';
        }

        if ($request->totals->boxCount !== null && $request->totals->boxCount > 0) {
            $boxCount = $request->totals->boxCount;
        }

        $plan->parcels = $parcels;
        $plan->totals = new PlanTotals();
        $plan->totals->boxCount = max($boxCount, count($parcels));
        if ($totalWeightG <= 0 && $request->totals->totalWeightKg !== null) {
            $totalWeightG = (int)round($request->totals->totalWeightKg * 1000);
        }
        $plan->totals->totalWeightG = max(0, $totalWeightG);
    }

    protected function ensureShipmentStatus(HandlerPlan $plan): void
    {
        if (!$plan->sendNow) {
            $plan->shouldDispatch = false;
        }
    }

    public function minInputs(): array
    {
        return [];
    }

    public function plan(PackSendRequest $request, ValidationResult $validation, ParcelPlannerDb $planner): HandlerPlan
    {
        $plan = $this->doPlan($request, $validation, $planner);
        $this->ensureShipmentStatus($plan);
        return $plan;
    }

    abstract protected function doPlan(PackSendRequest $request, ValidationResult $validation, ParcelPlannerDb $planner): HandlerPlan;
}
