<?php
declare(strict_types=1);
/**
 * File: VendServiceImpl.php
 * Purpose: Placeholder Vend integration for manual consignments
 * Author: GitHub Copilot
 * Last Modified: 2025-09-29
 * Dependencies: Payloads.php
 */

namespace Modules\Transfers\Stock\Shared\Services;

final class VendServiceImpl
{
    /**
     * @return array{ok:bool,warning?:string,vend_transfer_id?:string,vend_number?:string,vend_url?:string}
     */
    public function upsertManualConsignment(PackSendRequest $request, TxResult $txResult, HandlerPlan $plan): array
    {
        // Placeholder implementation; real integration to be wired later.
        return [
            'ok' => true,
            'vend_transfer_id' => null,
            'vend_number' => null,
            'vend_url' => null,
        ];
    }
}
