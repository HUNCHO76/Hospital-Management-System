<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\ClinicalEncounter;
use App\Models\MedicationAdministration;
use App\Models\PrescriptionOrderItem;
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
     * Record a medication administration event (administer / hold / refuse / miss).
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
     *     reason?: string|null,
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

            $item = PrescriptionOrderItem::with('prescriptionOrder')->findOrFail($data['prescription_order_item_id']);

            $this->assertItemBelongsToAdmission($item, $admission);

            $encounter = ClinicalEncounter::findOrFail($data['clinical_encounter_id']);

            if ((int) $encounter->admission_id !== (int) $admission->id) {
                throw ValidationException::withMessages([
                    'clinical_encounter_id' => 'The encounter does not belong to this admission.',
                ]);
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
                'dose' => $data['dose'],
                'dose_unit' => $data['dose_unit'],
                'route' => $data['route'],
                'is_prn' => $data['is_prn'] ?? false,
                'administered_by' => $request->user()->id,
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->auditLog->record(
                'medication_administration.recorded',
                $mar,
                null,
                ['status' => $mar->status, 'admission_id' => $admission->id],
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

        $encounter = ClinicalEncounter::find($order->clinical_encounter_id);

        if (! $encounter || (int) $encounter->admission_id !== (int) $admission->id) {
            throw ValidationException::withMessages([
                'prescription_order_item_id' => 'This prescription item does not belong to the given admission.',
            ]);
        }
    }
}
