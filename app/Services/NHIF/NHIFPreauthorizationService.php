<?php

namespace App\Services\NHIF;

/**
 * Stub implementation of Phase 8 Step 69 NHIF preauthorization enforcement.
 * The real implementation requires the NHIF API client, NhifPreauthorization
 * lifecycle, and the full Phase 8 infrastructure. Until that phase is
 * implemented, all preauthorization gates pass through without throwing.
 */
class NHIFPreauthorizationService
{
    /**
     * Assert that every source in $sourceIds has a current, approved NHIF
     * preauthorization. Phase 8 stub — does not throw.
     *
     * @param  list<int>  $sourceIds
     */
    public function assertPreauthorizedForSources(string $sourceType, array $sourceIds): void
    {
        // Phase 8 (Step 69) implementation pending.
    }
}
