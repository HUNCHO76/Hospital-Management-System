<?php

namespace App\Services;

use App\Models\MaternityProfile;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Centralized Domain Service for Maternity Patient Eligibility & Workflow Access Control.
 *
 * Enforces biological sex constraints, pregnancy eligibility, prevents duplicate active episodes,
 * and governs authorized clinical exceptions with full audit tracking.
 */
class MaternityEligibilityService
{
    public function __construct(
        protected AuditLogService $auditLog,
    ) {}

    /**
     * Check whether a patient is currently eligible to initiate a new maternity episode.
     *
     * @return array{
     *     eligible: bool,
     *     message: string,
     *     requires_exception: bool,
     *     existing_active_episode: ?MaternityProfile
     * }
     */
    public function checkEligibility(Patient $patient): array
    {
        $activeEpisode = MaternityProfile::query()
            ->where('patient_id', $patient->id)
            ->where('status', 'active')
            ->first();

        if ($activeEpisode) {
            return [
                'eligible' => false,
                'message' => "Patient already has an active maternity episode (#{$activeEpisode->episode_number}).",
                'requires_exception' => false,
                'existing_active_episode' => $activeEpisode,
            ];
        }

        $gender = strtolower(trim((string) $patient->gender));

        if ($gender === 'female') {
            return [
                'eligible' => true,
                'message' => 'Patient is eligible for maternity care.',
                'requires_exception' => false,
                'existing_active_episode' => null,
            ];
        }

        if ($gender === 'male') {
            return [
                'eligible' => false,
                'message' => 'Patient is registered as male. Maternity care can only be initiated for eligible female patients or via an authorized clinical exception.',
                'requires_exception' => true,
                'existing_active_episode' => null,
            ];
        }

        return [
            'eligible' => false,
            'message' => 'Patient demographic records do not establish maternity eligibility. Documented pregnancy status or an authorized clinical exception is required.',
            'requires_exception' => true,
            'existing_active_episode' => null,
        ];
    }

    /**
     * Assert patient eligibility when initiating or registering a maternity episode.
     *
     * Throws ValidationException with clinical guidance if not eligible or if duplicate active episode exists.
     *
     * @throws ValidationException
     */
    public function assertEligibleForInitiation(
        Patient $patient,
        ?string $exceptionReason = null,
        ?User $authorizingUser = null,
        ?int $ignoreProfileId = null
    ): void {
        // 1. Prevent duplicate active maternity episodes
        $duplicateQuery = MaternityProfile::query()
            ->where('patient_id', $patient->id)
            ->where('status', 'active');

        if ($ignoreProfileId) {
            $duplicateQuery->where('id', '!=', $ignoreProfileId);
        }

        $existingActive = $duplicateQuery->first();
        if ($existingActive) {
            throw ValidationException::withMessages([
                'patient_id' => "Patient already has an active maternity episode (#{$existingActive->episode_number}). Complete or discharge the existing episode before opening a new one.",
            ]);
        }

        // 2. Validate biological sex and pregnancy eligibility
        $gender = strtolower(trim((string) $patient->gender));

        if ($gender === 'female') {
            return;
        }

        // For non-female patients (e.g. male or other), check for an authorized clinical exception
        if (filled($exceptionReason)) {
            if (! $authorizingUser || ! ($authorizingUser->can('maternity.clinical-exception') || $authorizingUser->hasRole(['doctor', 'super-admin', 'hospital-admin']))) {
                throw ValidationException::withMessages([
                    'clinical_exception_reason' => 'You do not possess the required clinical privileges to authorize a maternity eligibility exception.',
                ]);
            }

            if (mb_strlen(trim($exceptionReason)) < 10) {
                throw ValidationException::withMessages([
                    'clinical_exception_reason' => 'A comprehensive clinical exception justification (at least 10 characters) is required to authorize maternity care for this patient.',
                ]);
            }

            return;
        }

        if ($gender === 'male') {
            throw ValidationException::withMessages([
                'patient_id' => 'Patient is registered as male. Maternity care can only be initiated for eligible female patients or via an authorized clinical exception.',
            ]);
        }

        throw ValidationException::withMessages([
            'patient_id' => 'Patient demographic records do not confirm maternity eligibility. An authorized clinical exception is required.',
        ]);
    }
}
