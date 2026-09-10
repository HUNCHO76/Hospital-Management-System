<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\PatientInsurance;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PatientInsuranceService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Find existing insurance records that plausibly represent the same
     * membership being registered, so staff can be warned before creating
     * an accidental duplicate. Assistive warning only, never a hard block.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, PatientInsurance>
     */
    public function findPossibleDuplicates(int $patientId, array $data): Collection
    {
        $membershipNumber = $data['membership_number'] ?? null;
        $providerId = $data['insurance_provider_id'] ?? null;

        if (! $membershipNumber || ! $providerId) {
            return new Collection;
        }

        return PatientInsurance::query()
            ->with(['patient:id,patient_number,first_name,middle_name,last_name', 'insuranceProvider:id,name'])
            ->where('insurance_provider_id', $providerId)
            ->where('membership_number', $membershipNumber)
            ->limit(5)
            ->get();
    }

    /**
     * Create a patient insurance record, safely switching the primary flag
     * within a transaction when the new record is marked primary.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Patient $patient, array $data, Request $request): PatientInsurance
    {
        $insurance = DB::transaction(function () use ($patient, $data) {
            if ($data['is_primary'] ?? false) {
                $this->clearExistingPrimary($patient);
            }

            return $patient->insurances()->create($data);
        });

        $this->auditLog->record('patient_insurance_created', $insurance, null, $data, $request);

        return $insurance;
    }

    /**
     * Update a patient insurance record, safely switching the primary flag
     * within a transaction when it becomes primary.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(PatientInsurance $insurance, array $data, Request $request): PatientInsurance
    {
        $oldValues = $insurance->only(array_keys($data));

        DB::transaction(function () use ($insurance, $data) {
            if (($data['is_primary'] ?? false) && ! $insurance->is_primary) {
                $this->clearExistingPrimary($insurance->patient, exceptId: $insurance->id);
            }

            $insurance->update($data);
        });

        $this->auditLog->record('patient_insurance_updated', $insurance, $oldValues, $insurance->only(array_keys($data)), $request);

        return $insurance;
    }

    /**
     * Make this the patient's sole primary insurance record.
     */
    public function makePrimary(PatientInsurance $insurance, Request $request): PatientInsurance
    {
        $wasPrimary = $insurance->is_primary;

        DB::transaction(function () use ($insurance) {
            $this->clearExistingPrimary($insurance->patient, exceptId: $insurance->id);
            $insurance->update(['is_primary' => true]);
        });

        $this->auditLog->record('patient_insurance_updated', $insurance, ['is_primary' => $wasPrimary], ['is_primary' => true], $request);

        return $insurance;
    }

    /**
     * Set the internal verification status of a patient insurance record.
     * This never calls an external API — verification is purely internal
     * at this stage.
     */
    public function verify(PatientInsurance $insurance, string $verificationStatus, Request $request): PatientInsurance
    {
        $oldValues = $insurance->only(['verification_status', 'verified_at']);

        $insurance->update([
            'verification_status' => $verificationStatus,
            'verified_at' => now(),
        ]);

        $this->auditLog->record(
            'patient_insurance_verified',
            $insurance,
            $oldValues,
            $insurance->only(['verification_status', 'verified_at']),
            $request,
        );

        return $insurance;
    }

    /**
     * Deactivate a patient insurance record. Historical data is never
     * hard-deleted — this marks the record inactive and clears the primary
     * flag, since an inactive record should never remain the patient's
     * primary coverage.
     */
    public function deactivate(PatientInsurance $insurance, Request $request): void
    {
        $oldValues = $insurance->only(['status', 'is_primary']);

        $insurance->update([
            'status' => PatientInsurance::STATUS_INACTIVE,
            'is_primary' => false,
        ]);

        $this->auditLog->record(
            'patient_insurance_deleted',
            $insurance,
            $oldValues,
            $insurance->only(['status', 'is_primary']),
            $request,
        );
    }

    /**
     * Unmark any of the patient's other insurance records as primary.
     */
    protected function clearExistingPrimary(Patient $patient, ?int $exceptId = null): void
    {
        $patient->insurances()
            ->where('is_primary', true)
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->update(['is_primary' => false]);
    }
}
