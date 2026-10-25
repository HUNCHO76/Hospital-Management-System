<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\ClinicalEncounter;
use App\Models\MaternityProfile;
use App\Models\NhifAdmission;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Centralized Domain Service for Maternity Patient Eligibility & Workflow Access Control.
 *
 * Enforces biological sex constraints, pregnancy eligibility, prevents duplicate active episodes,
 * governs authorized clinical exceptions, and evaluates NHIF financial authorization states.
 */
class MaternityEligibilityService
{
    public function __construct(
        protected AuditLogService $auditLog,
        protected ?NursingAccessService $accessService = null,
    ) {
        $this->accessService = $this->accessService ?? app(NursingAccessService::class);
    }

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

    /**
     * Evaluate the NHIF financial authorization status for maternity care.
     *
     * Distinguishes:
     * - Insurance/coverage status (cash_self_pay, active, inactive, expired, suspended).
     * - Authorization requirement and state (not_required, authorized, pending, rejected, expired, unverified).
     * - Service permission (whether routine covered services may proceed).
     * - Reference numbers and human-readable explanation.
     *
     * @return array{
     *     is_nhif: bool,
     *     insurance_status: string,
     *     authorization_required: bool,
     *     authorization_status: string,
     *     service_permitted: bool,
     *     is_valid: bool,
     *     emergency_override_available: bool,
     *     reason: string,
     *     reference_no: ?string,
     *     expires_at: ?string
     * }
     */
    public function checkNhifClearance(
        ?Admission $admission = null,
        ?Patient $patient = null,
        ?ClinicalEncounter $encounter = null,
        ?string $serviceType = 'maternity'
    ): array {
        $patient = $patient ?? $admission?->patient ?? $encounter?->patient;

        $isUsingNhif = false;
        $insurance = null;

        if ($admission) {
            $admission->load(['nhifAdmission', 'patientInsurance.insuranceProvider', 'hospital', 'patient']);
            if ($admission->nhifAdmission !== null || ! empty($admission->patient_insurance_id)) {
                $isUsingNhif = true;
                $insurance = $admission->patientInsurance;
            }
        } elseif ($encounter) {
            $encounter->load(['patientInsurance.insuranceProvider', 'patient']);
            if (! empty($encounter->patient_insurance_id)) {
                $isUsingNhif = true;
                $insurance = $encounter->patientInsurance;
            }
        }

        // If not explicitly set on admission/encounter, check if outpatient patient has active NHIF
        if (! $isUsingNhif && ! $admission && $patient) {
            $activeNhif = $patient->currentNhifInsurance();
            if ($activeNhif) {
                $isUsingNhif = true;
                $insurance = $activeNhif;
            }
        }

        // 1. Cash / Self-Pay Patients: Never subjected to NHIF restrictions
        if (! $isUsingNhif) {
            return [
                'is_nhif' => false,
                'insurance_status' => 'cash_self_pay',
                'authorization_required' => false,
                'authorization_status' => 'not_required',
                'service_permitted' => true,
                'is_valid' => true,
                'emergency_override_available' => false,
                'reason' => 'Patient is self-pay/cash. No NHIF authorization required.',
                'reference_no' => null,
                'expires_at' => null,
            ];
        }

        // 2. Resolve patient insurance if not directly eager-loaded
        if (! $insurance && $patient) {
            $insurance = $patient->currentNhifInsurance() ?? $patient->latestNhifInsurance();
        }

        $insuranceStatus = $insurance?->status ?? 'unknown';

        if (in_array($insuranceStatus, [PatientInsurance::STATUS_INACTIVE, PatientInsurance::STATUS_EXPIRED, PatientInsurance::STATUS_SUSPENDED], true)) {
            return [
                'is_nhif' => true,
                'insurance_status' => $insuranceStatus,
                'authorization_required' => true,
                'authorization_status' => 'rejected',
                'service_permitted' => false,
                'is_valid' => false,
                'emergency_override_available' => true,
                'reason' => "NHIF membership is {$insuranceStatus}. Services cannot be covered under this policy.",
                'reference_no' => $insurance?->card_number,
                'expires_at' => null,
            ];
        }

        // 3. Inpatient Admission Pathway
        if ($admission) {
            // Patient verification check (Step 8)
            if ($patient && (int) $admission->patient_id !== (int) $patient->id) {
                return [
                    'is_nhif' => true,
                    'insurance_status' => 'active',
                    'authorization_required' => true,
                    'authorization_status' => 'rejected',
                    'service_permitted' => false,
                    'is_valid' => false,
                    'emergency_override_available' => true,
                    'reason' => 'Authorization record patient mismatch.',
                    'reference_no' => null,
                    'expires_at' => null,
                ];
            }

            $nhifAdmission = $admission->nhifAdmission;

            if (! $nhifAdmission) {
                return [
                    'is_nhif' => true,
                    'insurance_status' => 'active',
                    'authorization_required' => true,
                    'authorization_status' => 'pending',
                    'service_permitted' => false,
                    'is_valid' => false,
                    'emergency_override_available' => true,
                    'reason' => 'NHIF inpatient admission request has not been submitted or registered.',
                    'reference_no' => $insurance?->nhif_authorization_no,
                    'expires_at' => null,
                ];
            }

            // Facility verification check (Step 8)
            $hospitalFacilityCode = $admission->hospital?->nhif_facility_code ?? $admission->hospital?->code;
            if (filled($nhifAdmission->nhif_facility_code) && filled($hospitalFacilityCode) && $nhifAdmission->nhif_facility_code !== $hospitalFacilityCode) {
                return [
                    'is_nhif' => true,
                    'insurance_status' => 'active',
                    'authorization_required' => true,
                    'authorization_status' => 'rejected',
                    'service_permitted' => false,
                    'is_valid' => false,
                    'emergency_override_available' => true,
                    'reason' => "NHIF authorization was issued for facility '{$nhifAdmission->nhif_facility_code}' instead of current facility '{$hospitalFacilityCode}'.",
                    'reference_no' => $nhifAdmission->nhif_authorization_no,
                    'expires_at' => null,
                ];
            }

            // Rejection check
            if (in_array($nhifAdmission->nhif_status, [NhifAdmission::STATUS_REJECTED, NhifAdmission::STATUS_FAILED], true)) {
                return [
                    'is_nhif' => true,
                    'insurance_status' => 'active',
                    'authorization_required' => true,
                    'authorization_status' => 'rejected',
                    'service_permitted' => false,
                    'is_valid' => false,
                    'emergency_override_available' => true,
                    'reason' => 'NHIF admission authorization request was rejected: '.($nhifAdmission->error_message ?? 'Authorization declined by NHIF.'),
                    'reference_no' => $nhifAdmission->nhif_admission_no ?? $nhifAdmission->nhif_authorization_no,
                    'expires_at' => null,
                ];
            }

            // Pending check
            if (in_array($nhifAdmission->nhif_status, [NhifAdmission::STATUS_PENDING, NhifAdmission::STATUS_SUBMITTED], true)) {
                return [
                    'is_nhif' => true,
                    'insurance_status' => 'active',
                    'authorization_required' => true,
                    'authorization_status' => 'pending',
                    'service_permitted' => false,
                    'is_valid' => false,
                    'emergency_override_available' => true,
                    'reason' => 'NHIF admission authorization has been submitted and is currently pending approval.',
                    'reference_no' => $nhifAdmission->nhif_authorization_no,
                    'expires_at' => null,
                ];
            }

            // Accepted status check
            if ($nhifAdmission->nhif_status === NhifAdmission::STATUS_ACCEPTED) {
                // An admission number alone does not bypass actual authorization expiry rules (Step 7)
                if ($insurance && $insurance->isAuthorizationExpired()) {
                    return [
                        'is_nhif' => true,
                        'insurance_status' => 'active',
                        'authorization_required' => true,
                        'authorization_status' => 'expired',
                        'service_permitted' => false,
                        'is_valid' => false,
                        'emergency_override_available' => true,
                        'reason' => 'NHIF 24-hour authorization has expired. Re-authorization required.',
                        'reference_no' => $nhifAdmission->nhif_admission_no ?? $nhifAdmission->nhif_authorization_no,
                        'expires_at' => $insurance->authorizationExpiresAt()?->toISOString(),
                    ];
                }

                return [
                    'is_nhif' => true,
                    'insurance_status' => 'active',
                    'authorization_required' => true,
                    'authorization_status' => 'authorized',
                    'service_permitted' => true,
                    'is_valid' => true,
                    'emergency_override_available' => false,
                    'reason' => 'NHIF inpatient admission authorization is verified and accepted.',
                    'reference_no' => $nhifAdmission->nhif_admission_no ?? $nhifAdmission->nhif_authorization_no,
                    'expires_at' => $insurance?->authorizationExpiresAt()?->toISOString(),
                ];
            }

            return [
                'is_nhif' => true,
                'insurance_status' => 'active',
                'authorization_required' => true,
                'authorization_status' => 'unverified',
                'service_permitted' => false,
                'is_valid' => false,
                'emergency_override_available' => true,
                'reason' => "NHIF admission authorization status is {$nhifAdmission->nhif_status}.",
                'reference_no' => $nhifAdmission->nhif_authorization_no,
                'expires_at' => null,
            ];
        }

        // 4. Outpatient / Emergency Care Pathway (no inpatient admission)
        if ($insurance) {
            if ($insurance->hasValidAuthorization()) {
                return [
                    'is_nhif' => true,
                    'insurance_status' => 'active',
                    'authorization_required' => true,
                    'authorization_status' => 'authorized',
                    'service_permitted' => true,
                    'is_valid' => true,
                    'emergency_override_available' => false,
                    'reason' => 'NHIF 24-hour authorization is verified and active.',
                    'reference_no' => $insurance->nhif_authorization_no,
                    'expires_at' => $insurance->authorizationExpiresAt()?->toISOString(),
                ];
            }

            if ($insurance->isAuthorizationExpired()) {
                return [
                    'is_nhif' => true,
                    'insurance_status' => 'active',
                    'authorization_required' => true,
                    'authorization_status' => 'expired',
                    'service_permitted' => false,
                    'is_valid' => false,
                    'emergency_override_available' => true,
                    'reason' => 'NHIF 24-hour authorization has expired. Re-verification required.',
                    'reference_no' => $insurance->nhif_authorization_no,
                    'expires_at' => $insurance->authorizationExpiresAt()?->toISOString(),
                ];
            }

            if ($insurance->verification_status === PatientInsurance::VERIFICATION_FAILED) {
                return [
                    'is_nhif' => true,
                    'insurance_status' => 'active',
                    'authorization_required' => true,
                    'authorization_status' => 'rejected',
                    'service_permitted' => false,
                    'is_valid' => false,
                    'emergency_override_available' => true,
                    'reason' => 'NHIF member card verification failed.',
                    'reference_no' => $insurance->card_number,
                    'expires_at' => null,
                ];
            }

            return [
                'is_nhif' => true,
                'insurance_status' => 'active',
                'authorization_required' => true,
                'authorization_status' => 'unverified',
                'service_permitted' => false,
                'is_valid' => false,
                'emergency_override_available' => true,
                'reason' => 'NHIF member card verification is pending or unverified.',
                'reference_no' => $insurance->card_number,
                'expires_at' => null,
            ];
        }

        return [
            'is_nhif' => true,
            'insurance_status' => 'active',
            'authorization_required' => true,
            'authorization_status' => 'unverified',
            'service_permitted' => false,
            'is_valid' => false,
            'emergency_override_available' => true,
            'reason' => 'No active NHIF authorization record found.',
            'reference_no' => null,
            'expires_at' => null,
        ];
    }

    /**
     * Enforce maternity registration gate and NHIF financial clearance.
     *
     * In urgent/emergency clinical circumstances, records the emergency override justification
     * and audit trail without blocking patient care.
     *
     * @throws ValidationException
     */
    public function assertActivityAllowed(
        ?Admission $admission,
        ?MaternityProfile $profile,
        bool $isEmergency = false,
        ?string $emergencyReason = null,
        ?User $user = null,
        ?Request $request = null
    ): void {
        // Emergency Override Pathway: life-saving care is NEVER blocked
        if ($isEmergency && filled($emergencyReason)) {
            if ($user && $profile) {
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'maternity_emergency_override_documented',
                    'auditable_type' => MaternityProfile::class,
                    'auditable_id' => $profile->id,
                    'old_values' => null,
                    'new_values' => [
                        'emergency_reason' => $emergencyReason,
                        'authorized_by' => $user->id,
                        'episode_number' => $profile->episode_number,
                        'patient_id' => $profile->patient_id,
                    ],
                    'ip_address' => $request?->ip(),
                    'user_agent' => $request?->userAgent(),
                    'url' => $request?->fullUrl(),
                ]);
            }

            return;
        }

        // 1. Maternity Registration Gate
        if ($profile === null) {
            throw ValidationException::withMessages([
                'maternity_profile_id' => 'Patient must be registered for maternity care before routine labour monitoring or delivery documentation can be recorded.',
            ]);
        }

        // 2. NHIF Financial Clearance Gate
        $clearance = $this->checkNhifClearance(
            $admission ?? $profile->admission,
            $profile->patient,
            $profile->clinicalEncounter
        );

        if (! $clearance['service_permitted']) {
            throw ValidationException::withMessages([
                'nhif_clearance' => "NHIF Clearance Required: {$clearance['reason']}",
            ]);
        }
    }

    /**
     * Check whether an admission and user are authorized and eligible to access the maternity workspace.
     *
     * Validates:
     * - Multi-facility scoping and admission access via NursingAccessService.
     * - Patient ID integrity between admission and patient record.
     * - Staff maternity permissions/privileges.
     * - Existing active maternity profile on admission, OR biological pregnancy eligibility when initiating.
     *
     * @return array{
     *     allowed: bool,
     *     reason: string,
     *     has_profile: bool,
     *     is_eligible: bool,
     *     profile: ?MaternityProfile
     * }
     */
    public function checkAdmissionAccess(User $user, Admission $admission): array
    {
        $admission->loadMissing(['patient', 'department', 'hospital', 'maternityProfile']);

        // 1. Patient integrity
        if ($admission->patient === null || (int) $admission->patient_id !== (int) $admission->patient->id) {
            return [
                'allowed' => false,
                'reason' => 'Patient record mismatch or patient not found for this admission.',
                'has_profile' => false,
                'is_eligible' => false,
                'profile' => null,
            ];
        }

        // 2. Multi-facility and ward scoping
        if ($this->accessService && ! $this->accessService->canAccess($user, $admission)) {
            return [
                'allowed' => false,
                'reason' => 'User is not authorized to access admissions in this facility or ward.',
                'has_profile' => false,
                'is_eligible' => false,
                'profile' => null,
            ];
        }

        // 3. Staff maternity privilege check
        $hasMaternityPermission = $user->hasRole('super-admin')
            || $user->can('maternity.view')
            || $user->can('maternity.manage')
            || $user->can('inpatient-nursing.view')
            || $user->hasAnyRole(['midwife', 'nurse', 'doctor']);

        if (! $hasMaternityPermission) {
            return [
                'allowed' => false,
                'reason' => 'User does not possess maternity clinical privileges.',
                'has_profile' => false,
                'is_eligible' => false,
                'profile' => null,
            ];
        }

        // 4. Existing Maternity Profile check
        $profile = $admission->maternityProfile ?? MaternityProfile::where('admission_id', $admission->id)->first();
        if ($profile !== null) {
            return [
                'allowed' => true,
                'reason' => 'Active maternity profile exists for this admission.',
                'has_profile' => true,
                'is_eligible' => true,
                'profile' => $profile,
            ];
        }

        // 5. Patient Maternity Eligibility when initiating a new profile
        $eligibility = $this->checkEligibility($admission->patient);

        if (! $eligibility['eligible']) {
            return [
                'allowed' => false,
                'reason' => $eligibility['message'],
                'has_profile' => false,
                'is_eligible' => false,
                'profile' => null,
            ];
        }

        return [
            'allowed' => true,
            'reason' => 'Patient is eligible for maternity care.',
            'has_profile' => false,
            'is_eligible' => true,
            'profile' => null,
        ];
    }
}
