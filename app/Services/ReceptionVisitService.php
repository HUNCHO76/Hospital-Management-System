<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Charge;
use App\Models\ClinicalEncounter;
use App\Models\Department;
use App\Models\NhifPreApprovalSession;
use App\Models\NhifPreApprovalSessionItem;
use App\Models\NhifServiceMapping;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\PatientInsurance;
use App\Models\Practitioner;
use App\Models\ReceptionVisit;
use App\Models\Service;
use App\Services\NHIF\NhifConsultationAuthorizationService;
use App\Services\NHIF\NhifHttpClient;
use App\Services\NHIF\NhifSchemeEligibilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceptionVisitService
{
    public function __construct(
        protected AuditLogService $auditLog,
        protected AppointmentService $appointments,
        protected ChargeCaptureService $chargeCapture,
        protected ChargeService $charges,
        protected InvoiceService $invoices,
        protected FinancialAuthorizationService $financialAuth,
        protected NhifConsultationAuthorizationService $nhifConsultationAuth,
        protected NhifSchemeEligibilityService $nhifSchemeEligibility,
    ) {}

    /**
     * Check in a scheduled appointment at the front desk, creating its
     * reception visit. Reuses AppointmentService::checkIn() so Step 18's own
     * transition rules (rejects cancelled/no-show/completed/already
     * checked-in) are the single source of truth — never re-implemented
     * here.
     *
     * @throws ValidationException
     */
    public function checkInAppointment(Appointment $appointment, int $departmentId, Request $request): ReceptionVisit
    {
        $visit = DB::transaction(function () use ($appointment, $departmentId, $request) {
            // Locking both rows is what makes this reception-side action
            // concurrency-safe: two simultaneous check-in attempts for the
            // same appointment/patient will serialize on these locks, even
            // though AppointmentService::checkIn() itself doesn't lock.
            Patient::query()->whereKey($appointment->patient_id)->lockForUpdate()->firstOrFail();
            $appointment = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            $this->assertNoActiveVisit($appointment->patient_id);

            $this->appointments->checkIn($appointment, $request);

            $practitioner = $appointment->practitioner_id
                ? Practitioner::query()->find($appointment->practitioner_id)
                : null;

            $visit = ReceptionVisit::create([
                'patient_id' => $appointment->patient_id,
                'appointment_id' => $appointment->id,
                'department_id' => $departmentId,
                'practitioner_id' => $practitioner?->id,
                'visit_type' => 'appointment',
                'status' => ReceptionVisit::STATUS_CHECKED_IN,
            ]);

            if ($practitioner) {
                $visit->load('department');
                $charge = $this->chargeCapture->captureFromReceptionVisit($visit, $practitioner, $request);
                $this->postAndInvoiceReceptionCharge($charge, $request);

                if ($charge === null) {
                    $visit->billing_warning = 'No consultation price is configured for this practitioner or facility — the visit was created without a billing charge. Notify billing/admin to configure pricing.';
                }
            }

            return $visit;
        });

        if ($visit->practitioner_id) {
            $this->tryNhifConsultationAuth($visit, $request);
        }

        $this->auditLog->record('reception_visit_checked_in', $visit, null, $visit->only(['patient_id', 'appointment_id', 'department_id', 'practitioner_id', 'status']), $request);

        return $visit;
    }

    /**
     * Register a walk-in patient's visit at the front desk.
     *
     * For NHIF patients (nhif_item_code supplied): the NHIF preapproval is
     * submitted BEFORE the visit is created. If NHIF rejects/pends/errors,
     * the visit is NOT created — a ValidationException is thrown. Only an
     * accepted NHIF authorization allows the visit to be registered.
     *
     * For CASH patients: the visit is created immediately and the standard
     * financial gate at sendToService() handles payment verification.
     *
     * @param  array{patient_id: int, department_id: int, visit_type: string, practitioner_id?: int|null, nhif_item_code?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function createWalkIn(array $data, Request $request): ReceptionVisit
    {
        $nhifItemCode = $data['nhif_item_code'] ?? null;
        $nhifPreAuthResult = null;
        $resolvedServiceId = null;

        // ── NHIF gate: pre-authorize BEFORE creating the visit ──────────
        if ($nhifItemCode) {
            $patient = Patient::find($data['patient_id']);
            $insurance = $patient?->activeNhifInsurance();
            if (! $insurance?->nhif_scheme_id) {
                throw ValidationException::withMessages([
                    'nhif_item_code' => 'Patient has no active verified NHIF insurance with a captured scheme.',
                ]);
            }
            $this->nhifSchemeEligibility->assertEligible((int) $insurance->nhif_scheme_id, $nhifItemCode);

            $department = Department::find($data['department_id']);
            $hospitalId = $department?->hospital_id ?? 0;

            $mapping = NhifServiceMapping::query()
                ->where('hospital_id', $hospitalId)
                ->where('nhif_item_code', $nhifItemCode)
                ->where('coverage_status', NhifServiceMapping::COVERAGE_COVERED)
                ->first();
            $resolvedServiceId = $mapping?->service_id;

            if (! $resolvedServiceId) {
                $resolvedServiceId = Service::where('code', $nhifItemCode)->value('id')
                    ?? Service::where('code', 'CONSULT-GP')->value('id');
            }

            if (! empty($data['practitioner_id'])) {
                $practitioner = Practitioner::query()->with('consultationService')->find($data['practitioner_id']);
                if ($practitioner && ! $practitioner->isNhifConfigured()) {
                    throw ValidationException::withMessages([
                        'practitioner_id' => 'The selected practitioner does not have an NHIF practitioner number registered. Please assign an NHIF practitioner number in the practitioner profile before creating an NHIF-billed visit.',
                    ]);
                }

                if ($practitioner && $practitioner->consultation_service_id) {
                    $matchesService = ($practitioner->consultation_service_id === $resolvedServiceId)
                        || ($practitioner->consultationService?->code === $nhifItemCode);
                    if (! $matchesService) {
                        throw ValidationException::withMessages([
                            'practitioner_id' => 'The selected practitioner is not assigned to the selected consultation service.',
                        ]);
                    }
                }
            }

            $nhifPreAuthResult = $this->nhifConsultationAuth->preAuthorize(
                (int) $data['patient_id'],
                $hospitalId,
                $resolvedServiceId ?? 0,
                ! empty($data['practitioner_id']) ? (int) $data['practitioner_id'] : null,
                $nhifItemCode,
                $request,
            );

            $reqNo = $nhifPreAuthResult['nhif_request_no'] ?? null;
            $reqLabel = $reqNo ? " (Request No: {$reqNo})" : '';

            if ($nhifPreAuthResult['status'] === 'rejected') {
                $reason = $nhifPreAuthResult['rejection_reason'];
                $message = $reason
                    ? "NHIF rejected the service: {$reason}{$reqLabel}"
                    : "NHIF rejected the service without specifying a reason.{$reqLabel} Contact NHIF for details.";

                throw ValidationException::withMessages(['nhif' => $message]);
            }

            if ($nhifPreAuthResult['status'] === 'pending') {
                throw ValidationException::withMessages([
                    'nhif' => "NHIF authorization is pending approval{$reqLabel}. Registration cannot proceed until NHIF approves the service.",
                ]);
            }

            if ($nhifPreAuthResult['status'] !== 'authorized') {
                $detail = $nhifPreAuthResult['error'] ?? $nhifPreAuthResult['rejection_reason'] ?? null;
                $message = $detail
                    ? "NHIF authorization failed: {$detail}"
                    : 'NHIF authorization could not be completed. Please check the NHIF service mapping and try again.';

                throw ValidationException::withMessages(['nhif' => $message]);
            }
        }

        // ── Create the visit (only reached if NHIF accepted or CASH) ────
        $visitCharge = DB::transaction(function () use ($data, $request, $nhifItemCode, $resolvedServiceId) {
            Patient::query()->whereKey($data['patient_id'])->lockForUpdate()->firstOrFail();

            $this->assertNoActiveVisit($data['patient_id']);

            $visit = ReceptionVisit::create([
                'patient_id' => $data['patient_id'],
                'appointment_id' => null,
                'department_id' => $data['department_id'],
                'practitioner_id' => $data['practitioner_id'] ?? null,
                'visit_type' => $data['visit_type'],
                'status' => ReceptionVisit::STATUS_CHECKED_IN,
            ]);

            $charge = null;
            $practitioner = ! empty($data['practitioner_id'])
                ? Practitioner::query()->find($data['practitioner_id'])
                : null;
            $selectedService = ! empty($data['service_id'])
                ? Service::query()->find($data['service_id'])
                : null;

            $visit->load('department');

            if ($practitioner && $nhifItemCode && $resolvedServiceId) {
                $nhifService = Service::find($resolvedServiceId);
                $nhifPrice = $this->fetchNhifItemPrice($data['patient_id'], $nhifItemCode);

                if ($nhifService && $nhifPrice !== null) {
                    $charge = $this->chargeCapture->captureNhifReceptionVisit(
                        $visit, $practitioner, $nhifService, (string) $nhifPrice, $request
                    );
                }
            }

            if ($charge === null && ! $nhifItemCode) {
                if ($selectedService) {
                    $charge = $this->chargeCapture->captureFromSelectedService(
                        $visit, $practitioner, $selectedService, $request
                    );
                }

                if ($charge === null && $practitioner) {
                    $charge = $this->chargeCapture->captureFromReceptionVisit($visit, $practitioner, $request);
                }
            }

            if ($charge !== null) {
                $this->postAndInvoiceReceptionCharge($charge, $request);
            } elseif (! $nhifItemCode && ($practitioner || $selectedService)) {
                $visit->billing_warning = 'No consultation price is configured for this practitioner or facility — the visit was created without a billing charge. Notify billing/admin to configure pricing.';
            }

            return ['visit' => $visit, 'charge' => $charge];
        });

        $visit = $visitCharge['visit'];
        $charge = $visitCharge['charge'];

        // ── Post-transaction: link NHIF authorization to the visit ───────
        if ($nhifPreAuthResult && $nhifPreAuthResult['status'] === 'authorized' && $nhifPreAuthResult['session_item_id'] && $charge) {
            $this->nhifConsultationAuth->attachToVisit(
                $nhifPreAuthResult['session_item_id'],
                $visit,
                $charge,
                $nhifPreAuthResult['percent_covered'] ?? 100.0,
                $nhifPreAuthResult['insurance_id'],
                $request,
            );
        } elseif (! $nhifItemCode && ! empty($data['practitioner_id'])) {
            $this->tryNhifConsultationAuth($visit, $request);
        }

        $this->auditLog->record('reception_visit_walkin_created', $visit, null, $visit->only(['patient_id', 'department_id', 'practitioner_id', 'visit_type', 'status']), $request);

        return $visit;
    }

    /**
     * Move a checked-in visit to waiting.
     */
    public function markWaiting(ReceptionVisit $visit, Request $request): ReceptionVisit
    {
        return $this->transition($visit, ReceptionVisit::STATUS_WAITING, 'reception_visit_waiting', $request);
    }

    /**
     * Mark a waiting visit ready for service.
     */
    public function markReadyForService(ReceptionVisit $visit, Request $request): ReceptionVisit
    {
        return $this->transition($visit, ReceptionVisit::STATUS_READY_FOR_SERVICE, 'reception_visit_ready', $request);
    }

    /**
     * Send a ready visit to its destination service/department — the final
     * reception step before the patient is admitted to the clinical queue.
     * The financial gate blocks patients whose consultation charge has not been
     * cleared. NHIF-authorized visits (accepted/approved pre-approval) pass
     * the gate without cash payment. The no-charge rule still applies for
     * free consultations (no charge captured).
     *
     * @throws ValidationException
     */
    public function sendToService(ReceptionVisit $visit, Request $request): ReceptionVisit
    {
        $status = $this->consultationAccessStatus($visit->id);

        if ($status === FinancialAuthorizationService::STATUS_AUTHORIZED) {
            return $this->transition($visit, ReceptionVisit::STATUS_SENT_TO_SERVICE, 'reception_visit_sent_to_service', $request);
        }

        $message = match ($status) {
            'nhif_pending' => 'NHIF authorization is still pending — patient cannot be sent to service until NHIF approves.',
            'nhif_rejected' => 'NHIF authorization was rejected — patient must pay as cash or the request must be resubmitted.',
            default => 'Payment is required before this patient can be sent to service.',
        };

        throw ValidationException::withMessages(['financial_authorization' => $message]);
    }

    /**
     * Compute the NHIF-aware service access status for a reception visit.
     *
     * For NHIF visits (those with a pre-approval session item):
     *   - accepted/approved → authorized (patient proceeds regardless of copayment)
     *   - pending_approval  → nhif_pending (blocked until NHIF responds)
     *   - rejected          → nhif_rejected (blocked, must convert to cash)
     *   - unknown/error     → nhif_pending (blocked, awaiting resolution)
     *
     * For cash visits (no pre-approval item): delegates to FinancialAuthorizationService.
     */
    public function consultationAccessStatus(int $visitId): string
    {
        $nhifItem = NhifPreApprovalSessionItem::query()
            ->where('source_type', ReceptionVisit::class)
            ->where('source_id', $visitId)
            ->whereHas('session', fn ($q) => $q->whereNotIn('status', [
                NhifPreApprovalSession::STATUS_CANCELLED,
            ]))
            ->latest()
            ->first();

        if ($nhifItem) {
            if ($nhifItem->isAccepted() || $nhifItem->isApproved()) {
                return FinancialAuthorizationService::STATUS_AUTHORIZED;
            }

            if ($nhifItem->isRejected()) {
                return 'nhif_rejected';
            }

            return 'nhif_pending';
        }

        return $this->financialAuth->statusForSource(
            ReceptionVisit::class,
            $visitId,
            FinancialAuthorizationService::STATUS_AUTHORIZED,
        );
    }

    /**
     * Batch-compute NHIF-aware service access statuses for multiple visits.
     *
     * @param  list<int>  $visitIds
     * @return array<int, string>
     */
    public function consultationAccessStatuses(array $visitIds): array
    {
        if (empty($visitIds)) {
            return [];
        }

        $nhifItems = NhifPreApprovalSessionItem::query()
            ->where('source_type', ReceptionVisit::class)
            ->whereIn('source_id', $visitIds)
            ->whereHas('session', fn ($q) => $q->whereNotIn('status', [
                NhifPreApprovalSession::STATUS_CANCELLED,
            ]))
            ->get()
            ->groupBy('source_id')
            ->map(fn ($items) => $items->sortByDesc('id')->first());

        $cashVisitIds = collect($visitIds)->diff($nhifItems->keys())->values()->all();
        $cashStatuses = $this->financialAuth->statusesForSources(
            ReceptionVisit::class,
            $cashVisitIds,
            FinancialAuthorizationService::STATUS_AUTHORIZED,
        );

        $result = [];

        foreach ($visitIds as $visitId) {
            $nhifItem = $nhifItems->get($visitId);

            if ($nhifItem) {
                if ($nhifItem->isAccepted() || $nhifItem->isApproved()) {
                    $result[$visitId] = FinancialAuthorizationService::STATUS_AUTHORIZED;
                } elseif ($nhifItem->isRejected()) {
                    $result[$visitId] = 'nhif_rejected';
                } else {
                    $result[$visitId] = 'nhif_pending';
                }
            } else {
                $result[$visitId] = $cashStatuses[$visitId] ?? FinancialAuthorizationService::STATUS_AUTHORIZED;
            }
        }

        return $result;
    }

    /**
     * Complete a visit.
     */
    public function complete(ReceptionVisit $visit, Request $request): ReceptionVisit
    {
        $oldValues = $visit->only(['status', 'completed_at']);

        $this->assertTransitionAllowed($visit, ReceptionVisit::STATUS_COMPLETED);

        $visit->update(['status' => ReceptionVisit::STATUS_COMPLETED, 'completed_at' => now()]);

        $this->auditLog->record('reception_visit_completed', $visit, $oldValues, $visit->only(['status', 'completed_at']), $request);

        return $visit;
    }

    /**
     * Cancel a visit, recording the reason.
     */
    public function cancel(ReceptionVisit $visit, string $reason, Request $request): ReceptionVisit
    {
        $oldValues = $visit->only(['status', 'cancelled_at', 'cancellation_reason']);

        $this->assertTransitionAllowed($visit, ReceptionVisit::STATUS_CANCELLED);

        $visit->update([
            'status' => ReceptionVisit::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        $this->auditLog->record('reception_visit_cancelled', $visit, $oldValues, $visit->only(['status', 'cancelled_at', 'cancellation_reason']), $request);

        return $visit;
    }

    /**
     * The patient's current active visit, if any — used both to block a
     * second concurrent visit and to show the existing one to staff instead
     * of a raw error.
     */
    public function findActiveVisit(int $patientId): ?ReceptionVisit
    {
        $visit = ReceptionVisit::query()
            ->where('patient_id', $patientId)
            ->whereIn('status', ReceptionVisit::ACTIVE_STATUSES)
            ->first();

        if (! $visit) {
            return null;
        }

        // If the visit already has a completed clinical encounter (either directly linked or created during/after this visit),
        // auto-complete the visit so it does not block subsequent visits.
        $hasCompletedEncounter = ClinicalEncounter::query()
            ->where('patient_id', $patientId)
            ->where(function ($q) use ($visit) {
                $q->where('reception_visit_id', $visit->id)
                    ->orWhere('created_at', '>=', $visit->created_at);
            })
            ->where('status', ClinicalEncounter::STATUS_COMPLETED)
            ->exists();

        if ($hasCompletedEncounter) {
            $visit->update([
                'status' => ReceptionVisit::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            return null;
        }

        return $visit;
    }

    /**
     * The reception dashboard's data: today's appointment/visit summary
     * counts, today's appointments (with reception + insurance status), and
     * today's walk-ins. Hospital-scoped exactly like AppointmentController's
     * own index/calendar actions — $hospitalId is null for an unrestricted
     * user (super-admin or any role without hospital scoping).
     *
     * @return array{summary: array<string, int>, todaysAppointments: Collection, todaysWalkIns: \Illuminate\Database\Eloquent\Collection}
     */
    public function dashboardData(?int $hospitalId): array
    {
        $appointmentStatusCounts = Appointment::query()
            ->whereDate('scheduled_start', today())
            ->when($hospitalId, fn ($query, $id) => $query->whereHas('department', fn ($query) => $query->where('hospital_id', $id)))
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $visitStatusCounts = ReceptionVisit::query()
            ->whereDate('created_at', today())
            ->when($hospitalId, fn ($query, $id) => $query->whereHas('department', fn ($query) => $query->where('hospital_id', $id)))
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $summary = [
            'total_appointments' => (int) $appointmentStatusCounts->sum(),
            'no_show' => (int) ($appointmentStatusCounts['no_show'] ?? 0),
            'checked_in' => (int) ($visitStatusCounts['checked_in'] ?? 0),
            'waiting' => (int) ($visitStatusCounts['waiting'] ?? 0),
            'ready_for_service' => (int) ($visitStatusCounts['ready_for_service'] ?? 0),
            'sent_to_service' => (int) ($visitStatusCounts['sent_to_service'] ?? 0),
            'completed' => (int) ($visitStatusCounts['completed'] ?? 0),
            'cancelled' => (int) (($appointmentStatusCounts['cancelled'] ?? 0) + ($visitStatusCounts['cancelled'] ?? 0)),
        ];

        $todaysAppointments = Appointment::query()
            ->whereDate('scheduled_start', today())
            ->when($hospitalId, fn ($query, $id) => $query->whereHas('department', fn ($query) => $query->where('hospital_id', $id)))
            ->with([
                'patient:id,patient_number,first_name,middle_name,last_name,phone',
                'patient.insurances' => fn ($query) => $query->orderByDesc('is_primary'),
                'department:id,name,code,hospital_id',
                'practitioner:id,practitioner_number,staff_id',
                'practitioner.staff:id,first_name,last_name',
                'receptionVisit:id,appointment_id,visit_number,status',
            ])
            ->orderBy('scheduled_start')
            ->get();

        $appointmentVisitIds = $todaysAppointments->pluck('receptionVisit.id')->filter()->values()->all();
        $appointmentFinancialStatuses = $this->financialAuth->statusesForSources(
            ReceptionVisit::class,
            $appointmentVisitIds,
            FinancialAuthorizationService::STATUS_AUTHORIZED,
        );

        $todaysWalkIns = ReceptionVisit::query()
            ->where('visit_type', 'walk_in')
            ->whereDate('created_at', today())
            ->when($hospitalId, fn ($query, $id) => $query->whereHas('department', fn ($query) => $query->where('hospital_id', $id)))
            ->with(['patient:id,patient_number,first_name,middle_name,last_name', 'department:id,name,hospital_id'])
            ->latest('created_at')
            ->get();

        $walkInFinancialStatuses = $this->financialAuth->statusesForSources(
            ReceptionVisit::class,
            $todaysWalkIns->pluck('id')->all(),
            FinancialAuthorizationService::STATUS_AUTHORIZED,
        );

        $accountIds = $this->patientAccountIdsFor([
            ...$todaysAppointments->map(fn (Appointment $a) => [$a->patient_id, $a->department?->hospital_id]),
            ...$todaysWalkIns->map(fn (ReceptionVisit $v) => [$v->patient_id, $v->department?->hospital_id]),
        ]);

        $todaysAppointments = $todaysAppointments->map(fn (Appointment $appointment) => [
            'id' => $appointment->id,
            'appointment_number' => $appointment->appointment_number,
            'appointment_type' => $appointment->appointment_type,
            'scheduled_start' => $appointment->scheduled_start,
            'status' => $appointment->status,
            'patient' => $appointment->patient?->only(['id', 'patient_number', 'first_name', 'middle_name', 'last_name']),
            'department' => $appointment->department?->only(['id', 'name']),
            'practitioner' => $appointment->practitioner?->staff?->only(['first_name', 'last_name']),
            'reception_visit' => $appointment->receptionVisit?->only(['id', 'visit_number', 'status']),
            'insurance_indicator' => $appointment->patient ? $this->insuranceIndicator($appointment->patient) : 'none',
            'financial_status' => $appointment->receptionVisit
                ? ($appointmentFinancialStatuses[$appointment->receptionVisit->id] ?? null)
                : null,
            'patient_account_id' => $accountIds["{$appointment->patient_id}:{$appointment->department?->hospital_id}"] ?? null,
        ]);

        $todaysWalkIns->each(function (ReceptionVisit $visit) use ($walkInFinancialStatuses, $accountIds) {
            $visit->financial_status = $walkInFinancialStatuses[$visit->id] ?? null;
            $visit->patient_account_id = $accountIds["{$visit->patient_id}:{$visit->department?->hospital_id}"] ?? null;
        });

        return compact('summary', 'todaysAppointments', 'todaysWalkIns');
    }

    /**
     * Batch-resolve PatientAccount ids for many (patient_id, hospital_id)
     * pairs in one query — avoids an N+1 lookup per dashboard row. Returns a
     * map keyed by "{patient_id}:{hospital_id}" => account id.
     *
     * @param  iterable<array{0: int|null, 1: int|null}>  $pairs
     * @return array<string, int>
     */
    protected function patientAccountIdsFor(iterable $pairs): array
    {
        $patientIds = [];
        $hospitalIds = [];

        foreach ($pairs as [$patientId, $hospitalId]) {
            if ($patientId !== null && $hospitalId !== null) {
                $patientIds[] = $patientId;
                $hospitalIds[] = $hospitalId;
            }
        }

        if (empty($patientIds)) {
            return [];
        }

        return PatientAccount::query()
            ->whereIn('patient_id', array_unique($patientIds))
            ->whereIn('hospital_id', array_unique($hospitalIds))
            ->get(['id', 'patient_id', 'hospital_id'])
            ->mapWithKeys(fn (PatientAccount $account) => ["{$account->patient_id}:{$account->hospital_id}" => $account->id])
            ->all();
    }

    /**
     * A concise, front-desk-appropriate coverage indicator derived from the
     * patient's primary insurance record — reuses PatientInsurance's own
     * status/verification fields rather than duplicating insurance logic.
     */
    public function insuranceIndicator(Patient $patient): string
    {
        $insurance = $patient->relationLoaded('insurances')
            ? $patient->insurances->firstWhere('is_primary', true) ?? $patient->insurances->first()
            : $patient->insurances()->orderByDesc('is_primary')->first();

        if (! $insurance) {
            return 'none';
        }

        if ($insurance->status !== PatientInsurance::STATUS_ACTIVE) {
            return $insurance->status === PatientInsurance::STATUS_EXPIRED ? 'expired' : 'none';
        }

        if ($insurance->effective_to && $insurance->effective_to->isPast()) {
            return 'expired';
        }

        return match ($insurance->verification_status) {
            PatientInsurance::VERIFICATION_VERIFIED => 'active',
            PatientInsurance::VERIFICATION_FAILED, PatientInsurance::VERIFICATION_EXPIRED => 'not_verified',
            default => 'pending_verification',
        };
    }

    /**
     * Attempt NHIF consultation authorization for the freshly-created visit.
     * Looks up the posted charge by source and calls NhifConsultationAuthorizationService.
     * Never throws — NHIF failures degrade gracefully to the cash payment path.
     */
    protected function tryNhifConsultationAuth(ReceptionVisit $visit, Request $request): void
    {
        try {
            $charge = Charge::query()
                ->where('source_type', ReceptionVisit::class)
                ->where('source_id', $visit->id)
                ->where('status', Charge::STATUS_POSTED)
                ->first();

            if ($charge === null || $charge->service_id === null) {
                return;
            }

            $this->nhifConsultationAuth->authorizeConsultation($visit, $charge, $request);
        } catch (\Throwable) {
            // NHIF failures must never block visit creation.
        }
    }

    /**
     * Post a freshly-captured reception-visit charge and immediately raise a
     * posted invoice for it — the cashier then only needs to collect payment.
     * No-op when $charge is null (the no-charge rule: no consultation service
     * configured on the practitioner).
     */
    protected function postAndInvoiceReceptionCharge(?Charge $charge, Request $request): void
    {
        if ($charge === null) {
            return;
        }

        $posted = $this->charges->post($charge, $request);
        $account = $posted->patientAccount;
        $invoice = $this->invoices->createDraft($account, [$posted->id], null, $request);
        $this->invoices->post($invoice, $request);
    }

    private function fetchNhifItemPrice(int $patientId, string $itemCode): ?float
    {
        $insurance = PatientInsurance::where('patient_id', $patientId)
            ->where('status', PatientInsurance::STATUS_ACTIVE)
            ->where('verification_status', PatientInsurance::VERIFICATION_VERIFIED)
            ->whereNotNull('nhif_facility_code')
            ->first(['nhif_facility_code']);

        $facilityCode = $insurance?->nhif_facility_code;
        if (! $facilityCode) {
            return null;
        }

        $result = app(NhifHttpClient::class)->getPricePackageByItemCode($facilityCode, $itemCode);
        if (! $result['success']) {
            return null;
        }

        $data = $result['data'];
        if (isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }

        return isset($data['UnitPrice']) ? (float) $data['UnitPrice'] : null;
    }

    /**
     * @throws ValidationException
     */
    protected function assertNoActiveVisit(int $patientId): void
    {
        $existing = $this->findActiveVisit($patientId);

        if ($existing) {
            throw ValidationException::withMessages([
                'patient_id' => "This patient already has an active visit ({$existing->visit_number}). Complete or cancel it before starting another.",
            ]);
        }
    }

    /**
     * Apply a validated status transition and audit it.
     *
     * @throws ValidationException
     */
    protected function transition(ReceptionVisit $visit, string $newStatus, string $auditAction, Request $request): ReceptionVisit
    {
        $oldStatus = $visit->status;

        $this->assertTransitionAllowed($visit, $newStatus);

        $visit->update(['status' => $newStatus]);

        $this->auditLog->record($auditAction, $visit, ['status' => $oldStatus], ['status' => $newStatus], $request);

        return $visit;
    }

    /**
     * @throws ValidationException
     */
    protected function assertTransitionAllowed(ReceptionVisit $visit, string $newStatus): void
    {
        if (! $visit->canTransitionTo($newStatus)) {
            throw ValidationException::withMessages([
                'status' => "Cannot change visit status from \"{$visit->status}\" to \"{$newStatus}\".",
            ]);
        }
    }
}
