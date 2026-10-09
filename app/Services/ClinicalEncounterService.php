<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\ClinicalEncounter;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Models\ReceptionVisit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClinicalEncounterService
{
    public function __construct(
        protected AuditLogService $auditLog,
        protected ChargeCaptureService $chargeCapture,
        protected ChargeService $charges,
        protected InvoiceService $invoices,
        protected FinancialAuthorizationService $financialAuth,
    ) {}

    /**
     * Create a clinical encounter, safely serialized against a concurrent
     * duplicate for the same patient.
     *
     * @param  array{patient_id: int, reception_visit_id?: int|null, appointment_id?: int|null, department_id: int, practitioner_id?: int|null, encounter_type: string}  $data
     *
     * @throws ValidationException
     */
    public function create(array $data, Request $request): ClinicalEncounter
    {
        $encounter = DB::transaction(function () use ($data, $request) {
            // Locking the patient row is what makes this concurrency-safe:
            // two simultaneous attempts to open an encounter for the same
            // patient serialize on this lock, so the active-encounter check
            // below and the insert that follows it are never interleaved
            // with another request's.
            Patient::query()->whereKey($data['patient_id'])->lockForUpdate()->firstOrFail();

            $this->assertNoActiveEncounter($data['patient_id']);

            if (empty($data['reception_visit_id'])) {
                $activeVisit = ReceptionVisit::query()
                    ->where('patient_id', $data['patient_id'])
                    ->whereIn('status', ReceptionVisit::ACTIVE_STATUSES)
                    ->latest()
                    ->first();
                if ($activeVisit) {
                    $data['reception_visit_id'] = $activeVisit->id;
                }
            }

            // Step 62 fix: when the encounter is being created from a reception
            // visit, enforce the financial gate — defense-in-depth for direct
            // URL bypass. Uses the same no-charge rule as sendToService(): if
            // no charge was captured at reception (practitioner has no
            // consultation service), the gate passes automatically.
            if (! empty($data['reception_visit_id'])) {
                $paymentStatus = $this->financialAuth->statusForSource(
                    ReceptionVisit::class,
                    (int) $data['reception_visit_id'],
                    FinancialAuthorizationService::STATUS_AUTHORIZED,
                );

                if ($paymentStatus !== FinancialAuthorizationService::STATUS_AUTHORIZED) {
                    throw ValidationException::withMessages([
                        'financial_authorization' => 'Payment is required before this patient can proceed to the clinical queue.',
                    ]);
                }
            }

            $data['status'] = ClinicalEncounter::STATUS_OPEN;
            $data['created_by'] = $request->user()->id;

            $encounter = ClinicalEncounter::create($data);

            if (! empty($data['reception_visit_id'])) {
                // Transfer the reception-visit consultation charge to this
                // encounter so the ConsultationService gate checks the correct
                // source. If no charge was captured at reception (no-charge
                // rule), this is a no-op and the gate will pass automatically.
                $visit = ReceptionVisit::query()->find($data['reception_visit_id']);

                if ($visit) {
                    $this->chargeCapture->releaseReceptionVisitChargeToEncounter($visit, $encounter);
                    if (in_array($visit->status, [ReceptionVisit::STATUS_WAITING, ReceptionVisit::STATUS_READY_FOR_SERVICE], true)) {
                        $visit->update(['status' => ReceptionVisit::STATUS_SENT_TO_SERVICE]);
                    }
                }
                // Skip direct re-capture — the charge (now on the encounter)
                // was already captured at reception time.
            } else {
                // Step 62: capture a consultation charge at encounter creation
                // time when a practitioner is known and no reception visit is
                // involved (direct encounter creation path). The practitioner's
                // own consultation service wins, else the facility default —
                // see ChargeCaptureService::resolveConsultationService().
                if (! empty($data['practitioner_id'])) {
                    $practitioner = Practitioner::query()->find($data['practitioner_id']);

                    if ($practitioner) {
                        $encounter->load('department');
                        $charge = $this->chargeCapture->captureFromClinicalEncounter($encounter, $practitioner, $request);
                        $this->postAndInvoiceEncounterCharge($charge, $request);
                    }
                }
            }

            return $encounter;
        });

        $this->auditLog->record(
            'clinical_encounter_created',
            $encounter,
            null,
            $encounter->only(['patient_id', 'reception_visit_id', 'appointment_id', 'department_id', 'practitioner_id', 'encounter_type', 'status']),
            $request,
        );

        return $encounter;
    }

    /**
     * Start a clinical encounter (OPEN -> IN_PROGRESS), recording the actual
     * start time of clinical service.
     *
     * @throws ValidationException
     */
    public function start(ClinicalEncounter $encounter, Request $request): ClinicalEncounter
    {
        $oldValues = $encounter->only(['status', 'started_at']);

        $this->assertTransitionAllowed($encounter, ClinicalEncounter::STATUS_IN_PROGRESS);

        $encounter->update([
            'status' => ClinicalEncounter::STATUS_IN_PROGRESS,
            'started_at' => $encounter->started_at ?? now(),
        ]);

        $this->auditLog->record('clinical_encounter_started', $encounter, $oldValues, $encounter->only(['status', 'started_at']), $request);

        return $encounter;
    }

    /**
     * Complete a clinical encounter (IN_PROGRESS -> COMPLETED), recording its
     * clinical outcome. `disposition` is optional and defaults to COMPLETED
     * — a simple encounter is never forced to declare an outcome it doesn't
     * have (spec §15's "do not require unnecessary steps for every encounter
     * type"). Each disposition is a handoff boundary only: FOLLOW_UP points
     * staff at the existing Appointment module, ADMITTED/REFERRED/
     * TRANSFERRED are recorded for a future module to read — none of those
     * workflows are implemented here.
     *
     * @param  array{disposition?: string|null, disposition_notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function complete(ClinicalEncounter $encounter, array $data, Request $request): ClinicalEncounter
    {
        $oldValues = $encounter->only(['status', 'ended_at', 'closed_by', 'disposition', 'disposition_notes']);

        $this->assertTransitionAllowed($encounter, ClinicalEncounter::STATUS_COMPLETED);

        $encounter->update([
            'status' => ClinicalEncounter::STATUS_COMPLETED,
            'ended_at' => now(),
            'closed_by' => $request->user()->id,
            'disposition' => $data['disposition'] ?? ClinicalEncounter::DISPOSITION_COMPLETED,
            'disposition_notes' => $data['disposition_notes'] ?? null,
        ]);

        $this->auditLog->record(
            'clinical_encounter_completed',
            $encounter,
            $oldValues,
            $encounter->only(['status', 'ended_at', 'closed_by', 'disposition', 'disposition_notes']),
            $request,
        );

        // Auto-complete any active/linked reception visit for this patient
        $activeVisit = $encounter->reception_visit_id
            ? ReceptionVisit::find($encounter->reception_visit_id)
            : ReceptionVisit::query()
                ->where('patient_id', $encounter->patient_id)
                ->whereIn('status', ReceptionVisit::ACTIVE_STATUSES)
                ->latest()
                ->first();

        if ($activeVisit && in_array($activeVisit->status, ReceptionVisit::ACTIVE_STATUSES, true)) {
            $activeVisitOld = $activeVisit->only(['status', 'completed_at']);
            $activeVisit->update([
                'status' => ReceptionVisit::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
            $this->auditLog->record(
                'reception_visit_completed',
                $activeVisit,
                $activeVisitOld,
                $activeVisit->only(['status', 'completed_at']),
                $request,
            );
        }

        return $encounter;
    }

    /**
     * Cancel a clinical encounter, recording who/when/why.
     *
     * @throws ValidationException
     */
    public function cancel(ClinicalEncounter $encounter, string $reason, Request $request): ClinicalEncounter
    {
        $oldValues = $encounter->only(['status', 'ended_at', 'closed_by', 'cancellation_reason']);

        $this->assertTransitionAllowed($encounter, ClinicalEncounter::STATUS_CANCELLED);

        $encounter->update([
            'status' => ClinicalEncounter::STATUS_CANCELLED,
            'ended_at' => now(),
            'closed_by' => $request->user()->id,
            'cancellation_reason' => $reason,
        ]);

        $this->auditLog->record(
            'clinical_encounter_cancelled',
            $encounter,
            $oldValues,
            $encounter->only(['status', 'ended_at', 'closed_by', 'cancellation_reason']),
            $request,
        );

        return $encounter;
    }

    /**
     * Reassign the practitioner on an open encounter, recalculating the
     * consultation charge when the new practitioner's billing configuration
     * differs (Step 62, spec §7). Safe to call before the consultation has
     * started; blocked after the consultation is in progress or completed.
     *
     * - PENDING consultation charge → voided and recaptured for the new
     *   practitioner's service.
     * - POSTED consultation charge → rejected: use the billing correction
     *   workflow before reassigning.
     *
     * @throws ValidationException
     */
    public function changePractitioner(ClinicalEncounter $encounter, int $practitionerId, string $reason, Request $request): ClinicalEncounter
    {
        $encounter = DB::transaction(function () use ($encounter, $practitionerId, $reason, $request) {
            $encounter = ClinicalEncounter::query()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();

            if (! in_array($encounter->status, ClinicalEncounter::ACTIVE_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'practitioner_id' => "Cannot change practitioner on an encounter with status \"{$encounter->status}\".",
                ]);
            }

            $consultation = Consultation::query()->where('clinical_encounter_id', $encounter->id)->first();

            if ($consultation && ! in_array($consultation->status, [Consultation::STATUS_PENDING, Consultation::STATUS_CANCELLED], true)) {
                throw ValidationException::withMessages([
                    'practitioner_id' => 'Cannot change practitioner once a consultation has started.',
                ]);
            }

            $newPractitioner = Practitioner::query()->find($practitionerId);

            if (! $newPractitioner || $newPractitioner->status !== Practitioner::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'practitioner_id' => 'The selected practitioner is not active.',
                ]);
            }

            // Handle existing consultation charge before changing the practitioner.
            $existingCharge = Charge::query()
                ->where('source_type', ClinicalEncounter::class)
                ->where('source_id', $encounter->id)
                ->whereNotIn('status', [Charge::STATUS_VOIDED, Charge::STATUS_CANCELLED])
                ->first();

            if ($existingCharge) {
                if ($existingCharge->status !== Charge::STATUS_PENDING) {
                    throw ValidationException::withMessages([
                        'practitioner_id' => 'A posted consultation charge exists for this encounter. Use the billing correction workflow (reversal or refund) before reassigning the practitioner.',
                    ]);
                }

                $this->chargeCapture->voidForSource(ClinicalEncounter::class, $encounter->id, $reason, $request);

                // Release (source_type, source_id) on the just-voided charge so
                // the UNIQUE constraint allows a new charge for the same encounter
                // when the incoming practitioner also has a billable service.
                // A PENDING→VOIDED charge was never posted, so nulling its source
                // pointer is safe and preserves financial immutability.
                Charge::query()
                    ->where('source_type', ClinicalEncounter::class)
                    ->where('source_id', $encounter->id)
                    ->whereIn('status', [Charge::STATUS_VOIDED, Charge::STATUS_CANCELLED])
                    ->update(['source_type' => null, 'source_id' => null]);
            }

            $oldValues = $encounter->only(['practitioner_id']);

            $encounter->update(['practitioner_id' => $practitionerId]);

            // Capture new charge for the incoming practitioner — their own
            // consultation service wins, else the facility default (see
            // ChargeCaptureService::resolveConsultationService()); $newPractitioner
            // is already guaranteed active/non-null by the check above.
            $encounter->load('department');
            $charge = $this->chargeCapture->captureFromClinicalEncounter($encounter, $newPractitioner, $request);
            $this->postAndInvoiceEncounterCharge($charge, $request);

            $this->auditLog->record(
                'clinical_encounter_practitioner_changed',
                $encounter,
                $oldValues,
                $encounter->only(['practitioner_id']),
                $request,
            );

            return $encounter;
        });

        return $encounter;
    }

    /**
     * Post a freshly-captured encounter charge and raise a posted invoice so
     * the financial gate in TriageService and ConsultationService can verify
     * payment. No-op when $charge is null (the no-charge safety net: no
     * consultation service configured for the practitioner or facility).
     */
    protected function postAndInvoiceEncounterCharge(?Charge $charge, Request $request): void
    {
        if ($charge === null) {
            return;
        }

        $posted = $this->charges->post($charge, $request);
        $account = $posted->patientAccount;
        $invoice = $this->invoices->createDraft($account, [$posted->id], null, $request);
        $this->invoices->post($invoice, $request);
    }

    /**
     * The patient's current active non-inpatient encounter, if any — used
     * both to block a second concurrent OPD encounter and to surface the
     * existing one to authorized staff instead of a raw error. Inpatient
     * encounters (encounter_type = 'inpatient') are excluded deliberately:
     * a patient may have one active inpatient encounter and one active OPD
     * encounter concurrently (e.g. an inpatient attending outpatient
     * imaging) without blocking either creation.
     */
    public function findActiveEncounter(int $patientId): ?ClinicalEncounter
    {
        return ClinicalEncounter::query()
            ->where('patient_id', $patientId)
            ->whereIn('status', ClinicalEncounter::ACTIVE_STATUSES)
            ->where('encounter_type', '!=', 'inpatient')
            ->first();
    }

    /**
     * @throws ValidationException
     */
    protected function assertNoActiveEncounter(int $patientId): void
    {
        $existing = $this->findActiveEncounter($patientId);

        if ($existing) {
            throw ValidationException::withMessages([
                'patient_id' => "This patient already has an active clinical encounter ({$existing->encounter_number}). Complete or cancel it before starting another.",
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function assertTransitionAllowed(ClinicalEncounter $encounter, string $newStatus): void
    {
        if (! $encounter->canTransitionTo($newStatus)) {
            throw ValidationException::withMessages([
                'status' => "Cannot change encounter status from \"{$encounter->status}\" to \"{$newStatus}\".",
            ]);
        }
    }
}
