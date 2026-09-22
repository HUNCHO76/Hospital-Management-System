<?php

namespace App\Services\NHIF;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;

/**
 * Stub implementation of Phase 8 Step 71 NHIF clinical utilization monitoring.
 * Returns empty summaries until the Phase 8 infrastructure is in place.
 */
class NHIFClinicalUtilizationService
{
    /**
     * Return NHIF utilization summaries for the given sources. Phase 8 stub
     * — always returns an empty array so existing UI sections render cleanly
     * with no utilization data.
     *
     * @param  list<int>  $sourceIds
     * @return array<int, array<string, mixed>>
     */
    public function summariesFor(User $user, string $sourceType, array $sourceIds, Request $request): array
    {
        return [];
    }
}
