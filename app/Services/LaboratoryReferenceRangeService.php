<?php

namespace App\Services;

use App\Models\LaboratoryReferenceRange;
use App\Models\LaboratoryTestParameter;
use App\Models\Patient;
use Illuminate\Http\Request;

/**
 * Selects the most-specific reference range for a parameter × patient combination,
 * and manages CRUD for reference range rows (Batch A).
 *
 * Selection rules (most-specific wins):
 *   1. Match patient's sex first; fall back to 'any' if no sex-specific row matches.
 *   2. Among matching-sex rows, prefer the row whose age band is narrower
 *      (measured by band width in days; unbounded side treated as very wide).
 *   3. A row matches age when patient_age_days ∈ [age_min_days, age_max_days]
 *      (null bounds are inclusive-unbounded).
 */
class LaboratoryReferenceRangeService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Find the best-matching reference range for a parameter, patient, and optional specimen source.
     * Returns null when no range is configured.
     */
    public function selectForPatient(
        LaboratoryTestParameter $parameter,
        Patient $patient,
        ?string $specimenSource = null
    ): ?LaboratoryReferenceRange {
        $ageInDays = $this->patientAgeInDays($patient);
        $sex = strtolower($patient->gender ?? 'unknown');
        $specimenSource = $specimenSource ? strtolower(trim($specimenSource)) : null;

        $candidates = LaboratoryReferenceRange::where('laboratory_test_parameter_id', $parameter->id)
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $matching = $candidates->filter(function (LaboratoryReferenceRange $range) use ($ageInDays, $sex, $specimenSource): bool {
            return $this->matchesSpecimenSource($range, $specimenSource)
                && $this->matchesSex($range, $sex)
                && $this->matchesAge($range, $ageInDays);
        });

        if ($matching->isEmpty()) {
            return null;
        }

        // 1. Exact specimen_source match ranked above 'any' / null
        // 2. Sex-specific rows ranked above 'any'
        // 3. Narrower age band wins
        return $matching->sortBy(function (LaboratoryReferenceRange $range) use ($sex, $specimenSource): array {
            $rangeSource = $range->specimen_source ? strtolower(trim($range->specimen_source)) : null;
            $sourcePriority = ($specimenSource && $rangeSource === $specimenSource) ? 0 : 1;
            $sexPriority = ($range->sex === $sex) ? 0 : 1;
            $bandWidth = $this->bandWidthDays($range);

            return [$sourcePriority, $sexPriority, $bandWidth];
        })->first();
    }

    protected function matchesSpecimenSource(LaboratoryReferenceRange $range, ?string $specimenSource): bool
    {
        $rangeSource = $range->specimen_source ? strtolower(trim($range->specimen_source)) : null;

        if ($rangeSource === null || $rangeSource === '' || $rangeSource === 'any') {
            return true;
        }

        if ($specimenSource === null || $specimenSource === '') {
            return true;
        }

        return $rangeSource === $specimenSource;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(LaboratoryTestParameter $parameter, array $data, Request $request): LaboratoryReferenceRange
    {
        $range = LaboratoryReferenceRange::create([
            'laboratory_test_parameter_id' => $parameter->id,
            ...$data,
        ]);

        $this->auditLog->record('laboratory_reference_range_created', $range, null, $range->only(array_keys($data)), $request);

        return $range;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(LaboratoryReferenceRange $range, array $data, Request $request): LaboratoryReferenceRange
    {
        $old = $range->only(array_keys($data));
        $range->update($data);

        $this->auditLog->record('laboratory_reference_range_updated', $range, $old, $range->only(array_keys($data)), $request);

        return $range;
    }

    public function delete(LaboratoryReferenceRange $range, Request $request): void
    {
        $this->auditLog->record('laboratory_reference_range_deleted', null, $range->toArray(), null, $request);
        $range->delete();
    }

    private function matchesSex(LaboratoryReferenceRange $range, string $patientSex): bool
    {
        return $range->sex === LaboratoryReferenceRange::SEX_ANY || $range->sex === $patientSex;
    }

    private function matchesAge(LaboratoryReferenceRange $range, ?int $ageInDays): bool
    {
        if ($ageInDays === null) {
            return $range->age_min_days === null && $range->age_max_days === null;
        }

        $minOk = $range->age_min_days === null || $ageInDays >= $range->age_min_days;
        $maxOk = $range->age_max_days === null || $ageInDays <= $range->age_max_days;

        return $minOk && $maxOk;
    }

    private function bandWidthDays(LaboratoryReferenceRange $range): int
    {
        $min = $range->age_min_days ?? 0;
        $max = $range->age_max_days ?? 999999;

        return $max - $min;
    }

    private function patientAgeInDays(Patient $patient): ?int
    {
        if (! $patient->date_of_birth) {
            return null;
        }

        return (int) $patient->date_of_birth->diffInDays(now());
    }
}
