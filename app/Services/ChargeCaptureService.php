<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\ClinicalEncounter;
use App\Models\DispensingItem;
use App\Models\LaboratoryOrderItem;
use App\Models\LaboratoryTest;
use App\Models\Medication;
use App\Models\Practitioner;
use App\Models\PrescriptionOrderItem;
use App\Models\RadiologyOrderItem;
use App\Models\RadiologyProcedure;
use App\Models\ReceptionVisit;
use App\Models\Service;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The centralized entry point every charge — automated or manual — is
 * meant to pass through (Phase 7, Step 54, spec §23) — no controller or
 * clinical service computes a price or builds a `Charge` array by hand
 * anywhere else. Wraps `ServicePriceResolver` (Step 53) and
 * `ChargeService`'s own create/lifecycle primitives (Step 52) rather than
 * duplicating either.
 *
 * **Clinical events are never blocked by a billing configuration gap.**
 * `captureFromLaboratoryOrderItem()`/`captureFromRadiologyOrderItem()`/
 * `captureFromDispensingItem()` each catch their own pricing failure (no
 * mapped `Service`, no applicable `ServicePrice`, no `Medication.price`)
 * and return `null` instead of throwing — a missing price is a *billing*
 * problem to fix and reconcile later (audited as `charge_capture_skipped`,
 * never silent), not a reason to refuse a lab test, an X-ray, or a
 * medication a patient clinically needs right now. This mirrors the
 * existing "urgent care is never blocked" precedent
 * `LaboratoryOrderService`/`RadiologyOrderService` themselves already
 * establish for the diagnosis requirement. `captureManual()` is the one
 * exception — a human directly initiating a billing action gets the real
 * error immediately, synchronously (spec §7's own "return a clear business
 * error").
 *
 * **Pharmacy boundary** (spec §12, reaffirmed from Step 53 §26):
 * `Medication` is deliberately never mapped into the `Service` catalog —
 * `captureFromDispensingItem()` prices itself from `Medication.price`
 * directly, `service_id` stays null on the resulting charge, and
 * `service_code_snapshot`/`description` reuse the *already-snapshotted*
 * `PrescriptionOrderItem.medication_code_snapshot`/`medication_name_snapshot`
 * (Step 46) rather than re-reading the live catalog row.
 *
 * **Duplicate protection** (spec §16/§25): every source-based capture first
 * looks for an existing charge for that exact `(source_type, source_id)`
 * under a row lock and returns it unchanged if found — idempotent by
 * design, not merely by accident — with the table's own UNIQUE constraint
 * as the real, race-proof guarantee (a losing concurrent insert's
 * constraint-violation is caught and treated as success, never surfaced as
 * an error).
 */
class ChargeCaptureService
{
    public function __construct(
        protected ChargeService $charges,
        protected ServicePriceResolver $prices,
        protected AuditLogService $auditLog,
    ) {}

    /**
     * Capture a charge for a single ordered laboratory test, at order time
     * (spec §10 — this codebase's own domain rule: a `LaboratoryOrderItem`
     * is created ACTIVE/ORDERED synchronously with order placement, the
     * same moment reagents/lab resources are committed; if the order is
     * later cancelled, `voidForSource()` reverses it). Returns null (never
     * throws) when no billing service is mapped to this test yet, or no
     * price is configured — the order itself still succeeds.
     */
    public function captureFromLaboratoryOrderItem(LaboratoryOrderItem $item, ClinicalEncounter $encounter, Request $request): ?Charge
    {
        try {
            $service = $this->resolveMappedService(LaboratoryTest::class, $item->laboratory_test_id, 'laboratory test');

            return $this->createFromCatalogService(
                $service,
                $encounter->department->hospital_id,
                $encounter->patient_id,
                $encounter->id,
                LaboratoryOrderItem::class,
                $item->id,
                1,
                $request,
            );
        } catch (ValidationException $e) {
            $this->recordCaptureSkipped(LaboratoryOrderItem::class, $item->id, $e, $request);

            return null;
        }
    }

    /**
     * Capture a charge for a single ordered radiology procedure, at order
     * time — mirrors captureFromLaboratoryOrderItem() exactly, the same
     * domain-rule reasoning.
     */
    public function captureFromRadiologyOrderItem(RadiologyOrderItem $item, ClinicalEncounter $encounter, Request $request): ?Charge
    {
        try {
            $service = $this->resolveMappedService(RadiologyProcedure::class, $item->radiology_procedure_id, 'radiology procedure');

            return $this->createFromCatalogService(
                $service,
                $encounter->department->hospital_id,
                $encounter->patient_id,
                $encounter->id,
                RadiologyOrderItem::class,
                $item->id,
                1,
                $request,
            );
        } catch (ValidationException $e) {
            $this->recordCaptureSkipped(RadiologyOrderItem::class, $item->id, $e, $request);

            return null;
        }
    }

    /**
     * Capture a consultation charge for a clinical encounter, using the
     * assigned practitioner's configured consultation service (Step 62,
     * spec §2/§4). Source = ClinicalEncounter so the financial gate in
     * ConsultationService::create() can query by encounter ID. Returns null
     * (never throws) when no consultation service is mapped to the
     * practitioner — the no-charge rule applies and the gate passes.
     */
    public function captureFromClinicalEncounter(ClinicalEncounter $encounter, Practitioner $practitioner, Request $request): ?Charge
    {
        try {
            if (! $practitioner->consultation_service_id) {
                throw ValidationException::withMessages([
                    'service' => 'No consultation service is configured for this practitioner.',
                ]);
            }

            $service = Service::query()->find($practitioner->consultation_service_id);

            if (! $service) {
                throw ValidationException::withMessages([
                    'service' => 'The consultation service configured for this practitioner no longer exists.',
                ]);
            }

            return $this->createFromCatalogService(
                $service,
                $encounter->department->hospital_id,
                $encounter->patient_id,
                $encounter->id,
                ClinicalEncounter::class,
                $encounter->id,
                1,
                $request,
            );
        } catch (ValidationException $e) {
            $this->recordCaptureSkipped(ClinicalEncounter::class, $encounter->id, $e, $request);

            return null;
        }
    }

    /**
     * Capture a consultation charge at RECEPTION time (Step 62 fix) — sourced
     * to the ReceptionVisit so the financial gate in
     * ReceptionVisitService::sendToService() can check payment status before
     * the patient is admitted to the clinical queue. Source =
     * ReceptionVisit::class + visit->id; encounter_id is null until
     * releaseReceptionVisitChargeToEncounter() later transfers it. Returns
     * null (never throws) — same no-charge rule as captureFromClinicalEncounter.
     */
    public function captureFromReceptionVisit(ReceptionVisit $visit, Practitioner $practitioner, Request $request): ?Charge
    {
        try {
            if (! $practitioner->consultation_service_id) {
                throw ValidationException::withMessages([
                    'service' => 'No consultation service is configured for this practitioner.',
                ]);
            }

            $service = Service::query()->find($practitioner->consultation_service_id);

            if (! $service) {
                throw ValidationException::withMessages([
                    'service' => 'The consultation service configured for this practitioner no longer exists.',
                ]);
            }

            $visit->loadMissing('department');

            return $this->createFromCatalogService(
                $service,
                $visit->department->hospital_id,
                $visit->patient_id,
                null,
                ReceptionVisit::class,
                $visit->id,
                1,
                $request,
            );
        } catch (ValidationException $e) {
            $this->recordCaptureSkipped(ReceptionVisit::class, $visit->id, $e, $request);

            return null;
        }
    }

    /**
     * Transfer a reception-visit consultation charge to its newly-created
     * clinical encounter (Step 62 fix) — updates source_type/source_id and
     * encounter_id so the financial gate in ConsultationService::create() can
     * find the charge by encounter ID. Call only after the encounter exists
     * and inside the same transaction. No-op when no reception-visit charge
     * exists (no-charge rule then applies at ConsultationService).
     */
    public function releaseReceptionVisitChargeToEncounter(ReceptionVisit $visit, ClinicalEncounter $encounter): void
    {
        Charge::query()
            ->where('source_type', ReceptionVisit::class)
            ->where('source_id', $visit->id)
            ->whereNotIn('status', [Charge::STATUS_VOIDED, Charge::STATUS_CANCELLED])
            ->update([
                'source_type' => ClinicalEncounter::class,
                'source_id' => $encounter->id,
                'encounter_id' => $encounter->id,
            ]);
    }

    /**
     * Capture a charge for a prescription order item at ORDER time (Phase 7,
     * Step 62) — mirrors captureFromLaboratoryOrderItem()'s own at-order-time
     * pattern so the charge exists before dispensing starts, enabling the
     * financial authorization gate. Quantity = prescribed quantity (the
     * financial commitment the patient pays at the cashier before dispensing).
     * Prices from `Medication.price` directly (spec §12, same rule as the
     * older captureFromDispensingItem). Returns null (never throws) when no
     * price is configured — billing gap, not a clinical blocker.
     */
    public function captureFromPrescriptionOrderItem(PrescriptionOrderItem $item, ClinicalEncounter $encounter, Request $request): ?Charge
    {
        try {
            $medication = $item->medication;

            if (! $medication || $medication->price === null) {
                throw ValidationException::withMessages([
                    'price' => 'No price is configured for this medication.',
                ]);
            }

            return $this->createFromResolvedPrice(
                hospitalId: $encounter->department->hospital_id,
                patientId: $encounter->patient_id,
                encounterId: $encounter->id,
                serviceId: null,
                sourceType: PrescriptionOrderItem::class,
                sourceId: $item->id,
                serviceCodeSnapshot: $item->medication_code_snapshot ?? $medication->code,
                description: $item->medication_name_snapshot ?? $medication->generic_name,
                quantity: $item->quantity,
                unitPrice: (string) $medication->price,
                request: $request,
            );
        } catch (ValidationException $e) {
            $this->recordCaptureSkipped(PrescriptionOrderItem::class, $item->id, $e, $request);

            return null;
        }
    }

    /**
     * Capture a charge for one dispensing fulfillment event (spec §12/§17)
     * — one `DispensingItem` row is always exactly one dispensing event
     * (Step 48's own architecture: a new event, a new row, never a running
     * total), so charging per `DispensingItem` is inherently the correct
     * per-event granularity for partial dispensing — no separate
     * "remaining quantity" bookkeeping is needed here. Prices from
     * `Medication.price` directly (see this class's own docblock for why),
     * never `ServicePriceResolver`.
     *
     * @deprecated Step 62: charge is now captured at prescription ORDER time
     *             via captureFromPrescriptionOrderItem(). This method is kept
     *             for backward compatibility but is no longer called by
     *             DispensingService::complete().
     */
    public function captureFromDispensingItem(DispensingItem $item, PrescriptionOrderItem $orderItem, ClinicalEncounter $encounter, Request $request): ?Charge
    {
        try {
            $medication = $orderItem->medication;

            if (! $medication || $medication->price === null) {
                throw ValidationException::withMessages([
                    'price' => 'No price is configured for this medication.',
                ]);
            }

            return $this->createFromResolvedPrice(
                hospitalId: $encounter->department->hospital_id,
                patientId: $encounter->patient_id,
                encounterId: $encounter->id,
                serviceId: null,
                sourceType: DispensingItem::class,
                sourceId: $item->id,
                serviceCodeSnapshot: $orderItem->medication_code_snapshot ?? $medication->code,
                description: $orderItem->medication_name_snapshot ?? $medication->generic_name,
                quantity: $item->dispensed_quantity,
                unitPrice: (string) $medication->price,
                request: $request,
            );
        } catch (ValidationException $e) {
            $this->recordCaptureSkipped(DispensingItem::class, $item->id, $e, $request);

            return null;
        }
    }

    /**
     * Manually capture a charge against any active, billable catalog
     * service (spec §26-27 — administrative/ad-hoc billing, e.g. a
     * consultation, with no automated source). The caller (a human,
     * directly) gets a real, synchronous error if pricing cannot be
     * resolved — never swallowed the way the automated capture paths
     * above are. No `source_type`/`source_id` is ever recorded — a manual
     * charge has no source event to be idempotent against.
     *
     * @param  array{hospital_id: int, patient_id: int, service_id: int, quantity?: int, encounter_id?: int|null, notes: string}  $data
     *
     * @throws ValidationException
     */
    public function captureManual(array $data, Request $request): Charge
    {
        if (trim($data['notes'] ?? '') === '') {
            throw ValidationException::withMessages(['notes' => 'A reason/reference is required for a manual charge.']);
        }

        $service = Service::query()->findOrFail($data['service_id']);

        if (($data['encounter_id'] ?? null) !== null) {
            $encounter = ClinicalEncounter::query()->findOrFail($data['encounter_id']);

            if ((int) $encounter->patient_id !== (int) $data['patient_id']) {
                throw ValidationException::withMessages([
                    'encounter_id' => 'The selected encounter does not belong to this patient.',
                ]);
            }
        }

        return $this->createFromCatalogService(
            $service,
            (int) $data['hospital_id'],
            (int) $data['patient_id'],
            $data['encounter_id'] ?? null,
            null,
            null,
            (int) ($data['quantity'] ?? 1),
            $request,
            notes: $data['notes'],
        );
    }

    /**
     * Reverse the charge (if any) tied to a source event that has since
     * been cancelled/removed (spec §11/§19) — a PENDING charge is voided, a
     * POSTED one is cancelled; if none exists yet, or it is already in a
     * terminal state, this is a silent no-op (the cancellation it is called
     * from must never fail because of this). Never called for pharmacy:
     * a completed dispensing event is never itself reversed by this
     * mechanism (spec §12's "do not duplicate stock transactions" — Step 49
     * already owns dispensing reversal, if any).
     */
    public function voidForSource(string $sourceType, int $sourceId, string $reason, Request $request): void
    {
        $charge = Charge::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->first();

        if (! $charge) {
            return;
        }

        if ($charge->canTransitionTo(Charge::STATUS_VOIDED)) {
            $this->charges->void($charge, $reason, $request);
        } elseif ($charge->canTransitionTo(Charge::STATUS_CANCELLED)) {
            $this->charges->cancel($charge, $reason, $request);
        }
    }

    /**
     * Resolve the catalog service mapped to a source module row via
     * `serviceable_type`/`serviceable_id` (Step 53).
     *
     * @throws ValidationException
     */
    protected function resolveMappedService(string $serviceableType, int $serviceableId, string $label): Service
    {
        $service = Service::query()
            ->where('serviceable_type', $serviceableType)
            ->where('serviceable_id', $serviceableId)
            ->first();

        if (! $service) {
            throw ValidationException::withMessages([
                'service' => "No billing service is mapped for this {$label} yet.",
            ]);
        }

        return $service;
    }

    /**
     * Resolve the applicable price for a catalog service and create the
     * charge from it.
     *
     * @throws ValidationException
     */
    protected function createFromCatalogService(
        Service $service,
        int $hospitalId,
        int $patientId,
        ?int $encounterId,
        ?string $sourceType,
        ?int $sourceId,
        int $quantity,
        Request $request,
        ?string $notes = null,
    ): Charge {
        $price = $this->prices->resolvePrice($service, $hospitalId);

        return $this->createFromResolvedPrice(
            hospitalId: $hospitalId,
            patientId: $patientId,
            encounterId: $encounterId,
            serviceId: $service->id,
            sourceType: $sourceType,
            sourceId: $sourceId,
            serviceCodeSnapshot: $service->code,
            description: $service->name,
            quantity: $quantity,
            unitPrice: (string) $price->amount,
            request: $request,
            notes: $notes,
        );
    }

    /**
     * The single low-level write path every capture method above funnels
     * through — duplicate-checked, transactional, delegates the actual
     * ledger write to `ChargeService::create()` (never re-implements it).
     *
     * @throws ValidationException
     */
    protected function createFromResolvedPrice(
        int $hospitalId,
        int $patientId,
        ?int $encounterId,
        ?int $serviceId,
        ?string $sourceType,
        ?int $sourceId,
        ?string $serviceCodeSnapshot,
        string $description,
        int $quantity,
        string $unitPrice,
        Request $request,
        ?string $notes = null,
    ): Charge {
        return DB::transaction(function () use ($hospitalId, $patientId, $encounterId, $serviceId, $sourceType, $sourceId, $serviceCodeSnapshot, $description, $quantity, $unitPrice, $request, $notes) {
            if ($sourceType !== null && $sourceId !== null) {
                $existing = Charge::query()
                    ->where('source_type', $sourceType)
                    ->where('source_id', $sourceId)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            try {
                return $this->charges->create([
                    'hospital_id' => $hospitalId,
                    'patient_id' => $patientId,
                    'encounter_id' => $encounterId,
                    'service_id' => $serviceId,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'service_code_snapshot' => $serviceCodeSnapshot,
                    'description' => $description,
                    'notes' => $notes,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                ], $request);
            } catch (QueryException $e) {
                if (! $this->isDuplicateKeyViolation($e) || $sourceType === null || $sourceId === null) {
                    throw $e;
                }

                // Lost a genuine concurrent race to another request — its
                // insert won, ours didn't; that IS the one successful
                // charge (spec §25), so return it rather than surfacing an
                // error for what is, from the caller's perspective, success.
                return Charge::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->firstOrFail();
            }
        });
    }

    /**
     * The SQLSTATE for an integrity constraint violation — portable across
     * MySQL and SQLite (this project's test driver), unlike matching on a
     * driver-specific error message string.
     */
    protected function isDuplicateKeyViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }

    /**
     * A billing-configuration gap is never silent (spec §7) even though it
     * never blocks the clinical event that triggered it — this is the audit
     * trail a billing officer follows up on.
     */
    protected function recordCaptureSkipped(string $sourceType, int $sourceId, ValidationException $e, Request $request): void
    {
        $this->auditLog->record(
            'charge_capture_skipped',
            null,
            null,
            [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'reason' => collect($e->errors())->flatten()->implode(' '),
            ],
            $request,
        );
    }
}
