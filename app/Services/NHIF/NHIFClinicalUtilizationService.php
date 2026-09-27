<?php

namespace App\Services\NHIF;

use App\Models\DispensingItem;
use App\Models\LaboratoryResult;
use App\Models\RadiologyAppointment;
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

    /**
     * Phase 8, Step 71 stub — report a dispensed item to NHIF utilization.
     * Never throws; never blocks the dispensing flow.
     */
    public function captureDispensingItem(DispensingItem $item, Request $request): void
    {
        // Phase 8 implementation pending.
    }

    /**
     * Phase 8, Step 71 stub — report a released laboratory result to NHIF
     * utilization. Never throws; never blocks or reverses the release.
     */
    public function captureLaboratoryResult(LaboratoryResult $result, Request $request): void
    {
        // Phase 8 implementation pending.
    }

    /**
     * Phase 8, Step 71 stub — report a completed radiology examination to
     * NHIF utilization. Never throws; never blocks or reverses the
     * completion.
     */
    public function captureRadiologyExamination(RadiologyAppointment $appointment, Request $request): void
    {
        // Phase 8 implementation pending.
    }
}
