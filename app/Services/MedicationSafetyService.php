<?php

namespace App\Services;

use App\Models\DiagnosisMedicationRule;
use App\Models\EncounterDiagnosis;

/**
 * Checks prescription items against the patient's active diagnoses to surface
 * contraindication and caution rules (Phase 3/6 safety check, spec §12).
 *
 * Design constraints:
 * - Never hard-blocks a prescription — an urgent prescription must not be
 *   blocked by a safety rule when the prescriber has clinical justification.
 * - When a rule match is found and the item carries no justification, a
 *   validation error is returned for that item; the prescriber must supply
 *   one and resubmit.
 * - When a justification is present the rule passes through and is stored
 *   on the item so the pharmacist can see the clinical reasoning.
 * - No allergy or drug-interaction framework exists yet — these are captured
 *   as known gaps in the audit report. This service only covers
 *   DiagnosisMedicationRule rows explicitly entered by an administrator.
 */
class MedicationSafetyService
{
    /**
     * Check a list of prescription items against the given active diagnosis
     * code IDs. Returns Laravel validation error messages keyed by the item's
     * dotted form path (e.g. "items.0.safety_override_justification") for
     * every item that matches a rule but lacks a justification.
     *
     * @param  list<int>  $activeDiagnosisCodeIds
     * @param  list<array<string, mixed>>  $items
     * @return array<string, string>
     */
    public function checkItems(array $activeDiagnosisCodeIds, array $items): array
    {
        if (empty($activeDiagnosisCodeIds) || empty($items)) {
            return [];
        }

        $medicationIds = array_values(array_unique(
            array_map(fn (array $item) => (int) $item['medication_id'], $items),
        ));

        $rulesByMedication = DiagnosisMedicationRule::query()
            ->whereIn('diagnosis_code_id', $activeDiagnosisCodeIds)
            ->whereIn('medication_id', $medicationIds)
            ->where('is_active', true)
            ->with('diagnosisCode:id,code,description')
            ->get()
            ->groupBy('medication_id');

        if ($rulesByMedication->isEmpty()) {
            return [];
        }

        $errors = [];

        foreach ($items as $index => $item) {
            $medicationRules = $rulesByMedication->get((int) $item['medication_id']);

            if (! $medicationRules || $medicationRules->isEmpty()) {
                continue;
            }

            $justification = trim((string) ($item['safety_override_justification'] ?? ''));

            if ($justification !== '') {
                continue;
            }

            // Pick the most severe matching rule for the error message.
            $severityOrder = [
                DiagnosisMedicationRule::SEVERITY_HIGH => 3,
                DiagnosisMedicationRule::SEVERITY_MEDIUM => 2,
                DiagnosisMedicationRule::SEVERITY_LOW => 1,
            ];

            $topRule = $medicationRules
                ->sortByDesc(fn ($r) => $severityOrder[$r->severity] ?? 0)
                ->first();

            $diagnosisRef = $topRule->diagnosisCode
                ? " ({$topRule->diagnosisCode->code})"
                : '';

            $errors["items.{$index}.safety_override_justification"] =
                ucfirst($topRule->rule_type).$diagnosisRef.': '.$topRule->description
                .' Please provide a clinical justification to prescribe this medication.';
        }

        return $errors;
    }

    /**
     * Pre-compute safety warnings for the given active diagnosis code IDs so
     * the encounter page can show them to the prescriber before form
     * submission. Returns a map of medication_id => list<warning> containing
     * only active rules for the patient's current diagnoses.
     *
     * @param  list<int>  $activeDiagnosisCodeIds
     * @return array<int, list<array{rule_type: string, severity: string, description: string, diagnosis_code: string, diagnosis_description: string}>>
     */
    public function warningsForDiagnoses(array $activeDiagnosisCodeIds): array
    {
        if (empty($activeDiagnosisCodeIds)) {
            return [];
        }

        $rules = DiagnosisMedicationRule::query()
            ->whereIn('diagnosis_code_id', $activeDiagnosisCodeIds)
            ->where('is_active', true)
            ->with('diagnosisCode:id,code,description')
            ->get();

        $result = [];

        foreach ($rules as $rule) {
            $result[$rule->medication_id][] = [
                'rule_type' => $rule->rule_type,
                'severity' => $rule->severity,
                'description' => $rule->description,
                'diagnosis_code' => $rule->diagnosisCode?->code ?? '',
                'diagnosis_description' => $rule->diagnosisCode?->description ?? '',
            ];
        }

        return $result;
    }

    /**
     * Collect the active diagnosis code IDs for an encounter.
     *
     * @return list<int>
     */
    public function activeDiagnosisCodeIds(int $clinicalEncounterId): array
    {
        return EncounterDiagnosis::query()
            ->where('clinical_encounter_id', $clinicalEncounterId)
            ->whereIn('status', EncounterDiagnosis::ACTIVE_STATUSES)
            ->pluck('diagnosis_code_id')
            ->all();
    }
}
