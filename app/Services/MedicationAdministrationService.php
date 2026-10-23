<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\ClinicalEncounter;
use App\Models\MedicationAdministration;
use App\Models\PatientAllergy;
use App\Models\PrescriptionOrder;
use App\Models\PrescriptionOrderItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Medication Administration Record (MAR) service (Phase 9, Step 75).
 *
 * Sits AFTER pharmacy dispensing — stock is already deducted. This service
 * only records the bedside clinical execution event.
 *
 * Concurrency: `lockForUpdate()` on the admission row serializes concurrent
 * administration events for the same patient, identical to how
 * PrescriptionOrderService locks the ClinicalEncounter.
 */
class MedicationAdministrationService
{
    public function __construct(
        protected AuditLogService $auditLog,
    ) {}

    /**
     * Record a medication administration event (administer / hold / refuse / miss / delay / not_applicable).
     *
     * @param  array{
     *     prescription_order_item_id: int,
     *     clinical_encounter_id: int,
     *     status: string,
     *     administered_at: string,
     *     dose: string,
     *     dose_unit: string,
     *     route: string,
     *     is_prn?: bool,
     *     dispensing_item_id?: int|null,
     *     scheduled_at?: string|null,
     *     slot_key?: string|null,
     *     reason?: string|null,
     *     allergy_override_reason?: string|null,
     *     notes?: string|null,
     * }  $data
     *
     * @throws ValidationException
     */
    public function record(Admission $admission, array $data, Request $request): MedicationAdministration
    {
        return DB::transaction(function () use ($admission, $data, $request) {
            // Lock the admission row to serialize concurrent MAR writes
            $admission = Admission::query()->whereKey($admission->id)->lockForUpdate()->firstOrFail();

            $this->assertAdmissionActive($admission);

            $item = PrescriptionOrderItem::with(['prescriptionOrder', 'medication'])->findOrFail($data['prescription_order_item_id']);

            $this->assertItemBelongsToAdmission($item, $admission);

            $encounter = ClinicalEncounter::findOrFail($data['clinical_encounter_id']);

            $belongsToAdmission = (int) $encounter->admission_id === (int) $admission->id
                || (int) $encounter->id === (int) $admission->clinical_encounter_id;

            if (! $belongsToAdmission) {
                $admissionWindowStart = ($admission->admitted_at ?? $admission->created_at)->copy()->subHours(24);
                $belongsToAdmission = (int) $encounter->patient_id === (int) $admission->patient_id
                    && $encounter->created_at >= $admissionWindowStart
                    && (! $admission->discharged_at || $encounter->created_at <= $admission->discharged_at);
            }

            if (! $belongsToAdmission) {
                throw ValidationException::withMessages([
                    'clinical_encounter_id' => 'The encounter does not belong to this admission.',
                ]);
            }

            if (empty($encounter->admission_id)) {
                $encounter->update(['admission_id' => $admission->id]);
            }

            // Reason check
            if (in_array($data['status'], MedicationAdministration::REASON_REQUIRED_STATUSES, true) && empty(trim((string) ($data['reason'] ?? '')))) {
                throw ValidationException::withMessages([
                    'reason' => "A documented reason is required when a dose is {$data['status']}.",
                ]);
            }

            // Allergy safety check for given doses
            if (in_array($data['status'], MedicationAdministration::GIVEN_STATUSES, true)) {
                $allergies = PatientAllergy::query()
                    ->where('patient_id', $admission->patient_id)
                    ->where('status', PatientAllergy::STATUS_ACTIVE)
                    ->get();

                $matchingAllergy = null;
                foreach ($allergies as $allergy) {
                    if ($allergy->medication_id && (int) $allergy->medication_id === (int) $item->medication_id) {
                        $matchingAllergy = $allergy;
                        break;
                    }
                    $generic = strtolower((string) ($item->medication?->generic_name ?? $item->medication_name_snapshot));
                    $brand = strtolower((string) ($item->medication?->brand_name ?? ''));
                    $allergen = strtolower((string) $allergy->allergen);
                    if ($allergen !== '' && (str_contains($generic, $allergen) || ($brand !== '' && str_contains($brand, $allergen)))) {
                        $matchingAllergy = $allergy;
                        break;
                    }
                }

                if ($matchingAllergy && empty(trim((string) ($data['allergy_override_reason'] ?? '')))) {
                    throw ValidationException::withMessages([
                        'allergy_override_reason' => "Patient has a documented allergy to {$matchingAllergy->allergen} ({$matchingAllergy->severity}). An explicit clinical override reason is required to administer.",
                    ]);
                }
            }

            // Timing checks
            $adminTime = Carbon::parse($data['administered_at']);
            $futureTolerance = config('mar.future_tolerance_minutes', 5);
            if ($adminTime->isAfter(now()->addMinutes($futureTolerance))) {
                throw ValidationException::withMessages([
                    'administered_at' => 'Medication administration time cannot be in the future.',
                ]);
            }

            // Duplicate dose check via slot_key
            $slotKey = $data['slot_key'] ?? null;
            if (! $slotKey && ! empty($data['scheduled_at']) && empty($data['is_prn'])) {
                $slotKey = "item:{$item->id}:".Carbon::parse($data['scheduled_at'])->format('YmdHi');
            }

            if ($slotKey) {
                $duplicate = MedicationAdministration::query()
                    ->where('slot_key', $slotKey)
                    ->whereNull('corrects_id')
                    ->first();

                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'scheduled_at' => 'This scheduled dose has already been recorded. Use the correction workflow if an amendment is required.',
                    ]);
                }
            }

            $mar = MedicationAdministration::create([
                'admission_id' => $admission->id,
                'clinical_encounter_id' => $encounter->id,
                'prescription_order_id' => $item->prescription_order_id,
                'prescription_order_item_id' => $item->id,
                'dispensing_item_id' => $data['dispensing_item_id'] ?? null,
                'status' => $data['status'],
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'administered_at' => $data['administered_at'],
                'slot_key' => $slotKey,
                'dose' => $data['dose'],
                'dose_unit' => $data['dose_unit'],
                'route' => $data['route'],
                'is_prn' => $data['is_prn'] ?? false,
                'administered_by' => $request->user()->id,
                'reason' => $data['reason'] ?? null,
                'allergy_override_reason' => $data['allergy_override_reason'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->auditLog->record(
                'medication_administration.recorded',
                $mar,
                null,
                ['status' => $mar->status, 'admission_id' => $admission->id, 'slot_key' => $slotKey],
                $request,
            );

            return $mar;
        });
    }

    /**
     * Amend an existing MAR record.
     *
     * The original row is never modified — a new row is written pointing back
     * to the row it supersedes via `corrects_id`. Mirrors ClinicalNote's own
     * amendment-chain pattern exactly.
     *
     * @param  array{
     *     correction_reason: string,
     *     status: string,
     *     administered_at: string,
     *     dose: string,
     *     dose_unit: string,
     *     route: string,
     *     reason?: string|null,
     *     notes?: string|null,
     * }  $data
     *
     * @throws ValidationException
     */
    public function correct(MedicationAdministration $original, array $data, Request $request): MedicationAdministration
    {
        return DB::transaction(function () use ($original, $data, $request) {
            $admission = Admission::query()->whereKey($original->admission_id)->lockForUpdate()->firstOrFail();

            $this->assertAdmissionActive($admission);

            if ($original->corrects_id !== null) {
                throw ValidationException::withMessages([
                    'corrects_id' => 'A correction record cannot itself be corrected. Correct the original record instead.',
                ]);
            }

            $correction = MedicationAdministration::create([
                'admission_id' => $original->admission_id,
                'clinical_encounter_id' => $original->clinical_encounter_id,
                'prescription_order_id' => $original->prescription_order_id,
                'prescription_order_item_id' => $original->prescription_order_item_id,
                'dispensing_item_id' => $original->dispensing_item_id,
                'status' => $data['status'],
                'scheduled_at' => $original->scheduled_at,
                'administered_at' => $data['administered_at'],
                'dose' => $data['dose'],
                'dose_unit' => $data['dose_unit'],
                'route' => $data['route'],
                'is_prn' => $original->is_prn,
                'administered_by' => $request->user()->id,
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'corrects_id' => $original->id,
                'correction_reason' => $data['correction_reason'],
            ]);

            $this->auditLog->record(
                'medication_administration.corrected',
                $correction,
                ['corrected_record_id' => $original->id],
                ['status' => $correction->status, 'correction_reason' => $correction->correction_reason],
                $request,
            );

            return $correction;
        });
    }

    /**
     * @throws ValidationException
     */
    protected function assertAdmissionActive(Admission $admission): void
    {
        if ($admission->status !== Admission::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'admission_id' => "Cannot record medication administration for a {$admission->status} admission.",
            ]);
        }
    }

    /**
     * Confirm the prescription order item belongs to this admission by tracing
     * through its order → encounter → admission FK chain.
     *
     * @throws ValidationException
     */
    protected function assertItemBelongsToAdmission(PrescriptionOrderItem $item, Admission $admission): void
    {
        $order = $item->prescriptionOrder;

        if (! $order) {
            throw ValidationException::withMessages([
                'prescription_order_item_id' => 'Prescription order item references a missing order.',
            ]);
        }

        if ($order->status !== PrescriptionOrder::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'prescription_order_item_id' => 'Cannot record administration for an inactive prescription order.',
            ]);
        }

        if ($item->status !== PrescriptionOrderItem::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'prescription_order_item_id' => 'Cannot record administration for a cancelled prescription item.',
            ]);
        }

        $encounter = ClinicalEncounter::find($order->clinical_encounter_id);

        if (! $encounter) {
            throw ValidationException::withMessages([
                'prescription_order_item_id' => 'This prescription item does not belong to the given admission.',
            ]);
        }

        $belongsToAdmission = (int) $encounter->admission_id === (int) $admission->id
            || (int) $encounter->id === (int) $admission->clinical_encounter_id;

        if (! $belongsToAdmission) {
            $admissionWindowStart = ($admission->admitted_at ?? $admission->created_at)->copy()->subHours(24);
            $belongsToAdmission = (int) $encounter->patient_id === (int) $admission->patient_id
                && $encounter->created_at >= $admissionWindowStart
                && (! $admission->discharged_at || $encounter->created_at <= $admission->discharged_at);
        }

        if (! $belongsToAdmission) {
            throw ValidationException::withMessages([
                'prescription_order_item_id' => 'This prescription item does not belong to the given admission.',
            ]);
        }

        if (empty($encounter->admission_id)) {
            $encounter->update(['admission_id' => $admission->id]);
        }
    }
}
