<?php
declare(strict_types=1);
/**
 * File: HandlerInterface.php
 * Purpose: Contract for pack/send handler implementations by mode
 * Author: GitHub Copilot
 * Last Modified: 2025-09-29
 * Dependencies: Payloads.php, RequestValidatorImpl.php
 */

namespace Modules\Transfers\Stock\Shared\Handlers;

use Modules\Transfers\Stock\Shared\Services\HandlerPlan;
use Modules\Transfers\Stock\Shared\Services\PackSendRequest;
use Modules\Transfers\Stock\Shared\Services\ParcelPlannerDb;
use Modules\Transfers\Stock\Shared\Services\ValidationResult;

interface HandlerInterface
{
    public function mode(): string;

    /**
     * @return list<string>
     */
    public function minInputs(): array;

    public function plan(PackSendRequest $request, ValidationResult $validation, ParcelPlannerDb $planner): HandlerPlan;
}
