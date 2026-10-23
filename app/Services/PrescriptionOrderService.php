<?php

namespace App\Services;

use App\Models\ClinicalEncounter;
use App\Models\Consultation;
use App\Models\Medication;
use App\Models\NhifSchemeService;
use App\Models\PrescriptionOrder;
use App\Models\PrescriptionOrderItem;
use App\Models\PrescriptionVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Prescription is its own sibling branch off the clinical encounter (Phase 6,
 * Step 46) — never nested under a CarePlanItem. Mirrors RadiologyOrderService's
 * shape deliberately (encounter-lock, consultation-eligibility guard,
 * create/update/cancel + audit), with no diagnosis requirement for the same
 * reason: an urgent prescription must not be blocked simply because a final
 * diagnosis has not yet been entered.
 */
class PrescriptionOrderService
{
    public function __construct(
        protected AuditLogService $auditLog,
        protected PrescriptionVerificationService $verification,
        protected ChargeCaptureService $chargeCapture,
        protected MedicationSafetyService $safety,
    ) {}

    /**
     * Record a new prescription order with one or more medications.
     *
     * @param  array{items: list<array<string, mixed>>, clinical_notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function create(ClinicalEncounter $encounter, array $data, Request $request): PrescriptionOrder
    {
        $order = DB::transaction(function () use ($encounter, $data, $request) {
            // Locking the encounter row serializes concurrent order creation
            // the same way RadiologyOrderService/LaboratoryOrderService
            // serialize their own create-time invariants.
            $encounter = ClinicalEncounter::query()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();

            $this->assertEncounterEligible($encounter);
            $this->assertConsultationEligible($encounter);
            $this->assertPrescriberAuthorized($request->user());

            $medications = $this->resolveActiveMedications($this->assertNoDuplicateMedications($data['items']));

            // Safety check: if any item's medication has a diagnosis-based
            // contraindication/caution rule and no override justification was
            // supplied, return per-item validation errors so the prescriber
            // can review the flag and provide a clinical reason.
            $activeDiagnosisCodes = $this->safety->activeDiagnosisCodeIds($encounter->id);
            $safetyErrors = $this->safety->checkItems($activeDiagnosisCodes, $data['items']);
            if (! empty($safetyErrors)) {
                throw ValidationException::withMessages($safetyErrors);
            }

            $this->autoMapNhifCodes($encounter, collect($medications));

            $order = PrescriptionOrder::create([
                'clinical_encounter_id' => $encounter->id,
                'clinical_notes' => $data['clinical_notes'] ?? null,
                'status' => PrescriptionOrder::STATUS_ACTIVE,
                'prescriber_id' => $request->user()->id,
                'prescribed_at' => now(),
            ]);

            foreach ($data['items'] as $itemData) {
                $this->addItem($order, $medications[$itemData['medication_id']], $itemData, $request);
            }

            // Phase 6, Step 47: every order enters the pharmacy verification
            // queue the moment it is written — there is no separate "submit"
            // action in this architecture (STATUS_ACTIVE's own docblock), so
            // creation and submission are the same moment.
            $this->verification->initiate($order, $request);

            return $order;
        });

        $this->auditLog->record(
            'prescription_order_created',
            $order,
            null,
            $order->only(['clinical_encounter_id', 'status']),
            $request,
        );

        return $order->fresh('items');
    }

    /**
     * Update an order's editable fields and optionally add more medications.
     * Only allowed while still active — once verification (Step 47) begins,
     * this becomes that step's concern.
     *
     * @param  array{clinical_notes?: string|null, items?: list<array<string, mixed>>}  $data
     *
     * @throws ValidationException
     */
    public function update(PrescriptionOrder $order, array $data, Request $request): PrescriptionOrder
    {
        $this->assertMutable($order);

        $newItems = $data['items'] ?? null;
        unset($data['items']);

        if ($data !== []) {
            $oldValues = $order->only(array_keys($data));
            $order->update([...$data, 'updated_by' => $request->user()->id]);

            $this->auditLog->record('prescription_order_updated', $order, $oldValues, $order->only(array_keys($data)), $request);
        }

        if ($newItems !== null) {
            $this->addNewItems($order, $newItems, $request);
        }

        return $order->fresh('items');
    }

    /**
     * Cancel the order, recording who/when/why, and cascade the same
     * cancellation to every non-cancelled item. Never deletes — a cancelled
     * clinical order remains historically traceable.
     *
     * @throws ValidationException
     */
    public function cancel(PrescriptionOrder $order, string $reason, Request $request): PrescriptionOrder
    {
        if (! $order->canTransitionTo(PrescriptionOrder::STATUS_CANCELLED)) {
            throw ValidationException::withMessages([
                'status' => "Cannot cancel a prescription order with status \"{$order->status}\".",
            ]);
        }

        $oldValues = $order->only(['status', 'cancelled_at', 'cancellation_reason']);

        DB::transaction(function () use ($order, $reason, $request) {
            $order->update([
                'status' => PrescriptionOrder::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $request->user()->id,
                'cancellation_reason' => $reason,
            ]);

            $order->items()->where('status', '!=', PrescriptionOrderItem::STATUS_CANCELLED)->get()->each(function (PrescriptionOrderItem $item) use ($reason, $request) {
                $item->update([
                    'status' => PrescriptionOrderItem::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'cancelled_by' => $request->user()->id,
                    'cancellation_reason' => $reason,
                ]);

                // Step 62: void the pre-captured charge (if any) so it cannot be invoiced.
                $this->chargeCapture->voidForSource(PrescriptionOrderItem::class, $item->id, $reason, $request);
            });
        });

        $this->auditLog->record(
            'prescription_order_cancelled',
            $order,
            $oldValues,
            $order->only(['status', 'cancelled_at', 'cancellation_reason']),
            $request,
        );

        return $order->fresh('items');
    }

    /**
     * Remove a single medication from an order, only while both the parent
     * order and the item itself are still active.
     *
     * @throws ValidationException
     */
    public function cancelItem(PrescriptionOrderItem $item, string $reason, Request $request): PrescriptionOrderItem
    {
        $order = $item->prescriptionOrder;

        $this->assertMutable($order);

        if (! $item->canTransitionTo(PrescriptionOrderItem::STATUS_CANCELLED)) {
            throw ValidationException::withMessages([
                'status' => "Cannot remove a prescription order item with status \"{$item->status}\".",
            ]);
        }

        $oldValues = $item->only(['status', 'cancelled_at', 'cancellation_reason']);

        $item->update([
            'status' => PrescriptionOrderItem::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $request->user()->id,
            'cancellation_reason' => $reason,
        ]);

        // Step 62: void the pre-captured charge (if any) so it cannot be invoiced.
        $this->chargeCapture->voidForSource(PrescriptionOrderItem::class, $item->id, $reason, $request);

        $this->auditLog->record(
            'prescription_order_item_removed',
            $item,
            $oldValues,
            $item->only(['status', 'cancelled_at', 'cancellation_reason']),
            $request,
        );

        return $item;
    }

    /**
     * Create and audit a single order item, snapshotting the medication's
     * code/generic name/strength/form/route from the live catalog row at
     * this moment (historical integrity — a later catalog rename/
     * deactivation must never make an existing prescription ambiguous).
     *
     * @param  array<string, mixed>  $itemData
     */
    protected function addItem(PrescriptionOrder $order, Medication $medication, array $itemData, Request $request): PrescriptionOrderItem
    {
        $item = PrescriptionOrderItem::create([
            'prescription_order_id' => $order->id,
            'medication_id' => $medication->id,
            'medication_code_snapshot' => $medication->code,
            'medication_name_snapshot' => $medication->generic_name,
            'strength_snapshot' => $medication->strength,
            'form_snapshot' => $medication->form,
            'route_snapshot' => $medication->route,
            'dose' => $itemData['dose'],
            'dose_unit' => $itemData['dose_unit'] ?? null,
            'frequency' => $itemData['frequency'],
            'route' => $itemData['route'],
            'duration' => $itemData['duration'] ?? null,
            'duration_unit' => $itemData['duration_unit'] ?? null,
            'quantity' => $itemData['quantity'],
            'quantity_unit' => $itemData['quantity_unit'] ?? null,
            'instructions' => $itemData['instructions'] ?? null,
            'safety_override_justification' => isset($itemData['safety_override_justification'])
                ? (trim($itemData['safety_override_justification']) ?: null)
                : null,
            'status' => PrescriptionOrderItem::STATUS_ACTIVE,
        ]);

        $this->auditLog->record(
            'prescription_order_item_added',
            $item,
            null,
            $item->only(['prescription_order_id', 'medication_id', 'medication_code_snapshot', 'dose', 'frequency', 'route', 'quantity', 'status']),
            $request,
        );

        // Step 62: capture charge at ORDER time so the patient can pay at the
        // cashier before dispensing starts — mirrors captureFromLaboratoryOrderItem().
        // A missing Medication.price is audited but never blocks the order itself.
        $this->chargeCapture->captureFromPrescriptionOrderItem($item, $order->clinicalEncounter, $request);

        return $item;
    }

    /**
     * Add medications to an already-existing order via update(). Existing
     * items are never touched here (removal is cancelItem()'s job) —
     * duplicates against the currently-active items are rejected, not
     * silently skipped, mirroring RadiologyOrderService's own duplicate
     * handling.
     *
     * @param  list<array<string, mixed>>  $items
     *
     * @throws ValidationException
     */
    protected function addNewItems(PrescriptionOrder $order, array $items, Request $request): void
    {
        $medications = $this->resolveActiveMedications($this->assertNoDuplicateMedications($items));

        $existingMedicationIds = $order->items()->where('status', '!=', PrescriptionOrderItem::STATUS_CANCELLED)->pluck('medication_id')->all();

        foreach ($items as $itemData) {
            if (in_array($itemData['medication_id'], $existingMedicationIds, true)) {
                throw ValidationException::withMessages([
                    'items' => 'This medication is already on the prescription.',
                ]);
            }
        }

        foreach ($items as $itemData) {
            $this->addItem($order, $medications[$itemData['medication_id']], $itemData, $request);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    protected function assertNoDuplicateMedications(array $items): array
    {
        $medicationIds = array_map(fn (array $item) => $item['medication_id'], $items);

        if (count($medicationIds) !== count(array_unique($medicationIds))) {
            throw ValidationException::withMessages([
                'items' => 'Duplicate medications cannot be added to the same prescription.',
            ]);
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<int, Medication>
     *
     * @throws ValidationException
     */
    protected function resolveActiveMedications(array $items): array
    {
        $medicationIds = array_map(fn (array $item) => $item['medication_id'], $items);

        $medications = Medication::query()->whereIn('id', $medicationIds)->where('status', Medication::STATUS_ACTIVE)->get()->keyBy('id');

        if ($medications->count() !== count($medicationIds)) {
            throw ValidationException::withMessages([
                'items' => 'One or more selected medications are not available in the catalog.',
            ]);
        }

        return $medications->all();
    }

    /**
     * For NHIF encounters, auto-set nhif_code on medications that lack one
     * by matching against the schemes_services catalog
     * (type_name = 'Medicine and Consumables').
     *
     * @param  Collection<int, Medication>  $medications
     */
    protected function autoMapNhifCodes(ClinicalEncounter $encounter, $medications): void
    {
        $insurance = $encounter->patient?->currentNhifInsurance();
        if (! $insurance?->nhif_scheme_id || ! Schema::hasTable('schemes_services')) {
            return;
        }

        $unmapped = $medications->filter(fn (Medication $m) => blank($m->nhif_code));
        if ($unmapped->isEmpty()) {
            return;
        }

        foreach ($unmapped as $medication) {
            $name = $medication->generic_name;

            $match = NhifSchemeService::catalog()
                ->where('type_name', 'Medicine and Consumables')
                ->where('scheme_id', $insurance->nhif_scheme_id)
                ->where('item_name', 'like', "{$name}%")
                ->selectRaw('DISTINCT item_code, item_name')
                ->first();

            if (! $match) {
                $firstWord = explode(' ', $name)[0] ?? '';
                if (mb_strlen($firstWord) >= 3) {
                    $match = NhifSchemeService::catalog()
                        ->where('type_name', 'Medicine and Consumables')
                        ->where('scheme_id', $insurance->nhif_scheme_id)
                        ->where('item_name', 'like', "{$firstWord}%")
                        ->selectRaw('DISTINCT item_code, item_name')
                        ->first();
                }
            }

            if ($match) {
                $medication->update(['nhif_code' => $match->item_code]);
            }
        }
    }

    /**
     * @throws ValidationException
     */
    protected function assertEncounterEligible(ClinicalEncounter $encounter): void
    {
        if (! in_array($encounter->status, ClinicalEncounter::ACTIVE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'clinical_encounter_id' => "Cannot record a prescription on an encounter with status \"{$encounter->status}\".",
            ]);
        }
    }

    /**
     * Confirm the encounter has a consultation the doctor has actually
     * engaged with (mirrors RadiologyOrderService::assertConsultationEligible()
     * exactly). Deliberately does NOT require a diagnosis — an urgent
     * prescription must not be blocked on a final diagnosis not yet being
     * entered.
     *
     * Inpatient encounters (Step 74) have no consultation context — the
     * clinician opens the encounter directly against the admission during a
     * ward round and is not required to complete a triage/consultation flow
     * before prescribing.
     *
     * @throws ValidationException
     */
    protected function assertConsultationEligible(ClinicalEncounter $encounter): ?Consultation
    {
        if ($encounter->encounter_type === 'inpatient') {
            return null;
        }

        $consultation = $encounter->consultation;

        if (! $consultation || ! in_array($consultation->status, [Consultation::STATUS_IN_PROGRESS, Consultation::STATUS_COMPLETED], true)) {
            throw ValidationException::withMessages([
                'clinical_encounter_id' => 'A consultation must be started before a prescription can be written for this encounter.',
            ]);
        }

        return $consultation;
    }

    /**
     * Phase 6, Step 47: once a pharmacist has verified the order, it becomes
     * immutable through this ordinary edit route — mirrors
     * RadiologyReportPolicy's "VERIFIED is terminal" rule exactly. A
     * RETURNED order is still editable here (that is precisely how a
     * prescriber corrects it before calling PrescriptionVerificationService::
     * resubmit()); the resubmit step itself re-checks the order is ACTIVE.
     *
     * @throws ValidationException
     */
    protected function assertMutable(PrescriptionOrder $order): void
    {
        if ($order->status !== PrescriptionOrder::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'status' => "Cannot modify a prescription order with status \"{$order->status}\".",
            ]);
        }

        if ($order->verification?->status === PrescriptionVerification::STATUS_VERIFIED) {
            throw ValidationException::withMessages([
                'status' => 'Cannot modify a prescription that has already been verified.',
            ]);
    }

    /**
     * Enforce professional scope restrictions: nurses cannot prescribe unless explicitly granted the nurse-prescribe privilege.
     *
     * @throws ValidationException
     */
    protected function assertPrescriberAuthorized(\App\Models\User $user): void
    {
        if ($user->hasRole('nurse') && ! $user->can('prescriptions.nurse-prescribe')) {
            throw ValidationException::withMessages([
                'prescriber' => 'Nurses are not authorized to prescribe medications without explicit prescribing privileges.',
            ]);
        }
    }
}
