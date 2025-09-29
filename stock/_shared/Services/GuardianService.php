<?php
declare(strict_types=1);
/**
 * File: GuardianService.php
 * Purpose: Evaluate operational guard rails before proceeding with pack/send
 * Author: GitHub Copilot
 * Last Modified: 2025-09-29
 * Dependencies: Payloads.php, RequestValidatorImpl.php
 */

namespace Modules\Transfers\Stock\Shared\Services;

final class GuardianService
{
    public function evaluate(PackSendRequest $request, ValidationResult $validation): GuardianResult
    {
        $result = new GuardianResult();

        if (!$validation->isValid()) {
            $result->tier = 'red';
            $result->messages[] = 'Validation failed; guardian short-circuited.';
            return $result;
        }

        if ($request->mode === Modes::PACKED_NOT_SENT && $request->sendNow) {
            $result->tier = 'red';
            $result->messages[] = 'PACKED_NOT_SENT cannot dispatch immediately.';
            return $result;
        }

        if ($request->mode === Modes::RECEIVE_ONLY) {
            $result->tier = 'amber';
            $result->messages[] = 'Receive-only mode is not yet implemented.';
        }

        if ($request->totals->totalWeightKg !== null && $request->totals->totalWeightKg > 400) {
            $result->tier = 'amber';
            $result->messages[] = 'Large consignment flagged for review (>400kg).';
        }

        return $result;
    }
}
