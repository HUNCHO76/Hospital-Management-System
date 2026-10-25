<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\BedsideProcedureEvent;
use App\Models\ClinicalEncounter;
use App\Models\Hospital;
use App\Models\NhifPreApprovalSessionItem;
use App\Models\NhifService;
use App\Models\NhifServiceMapping;
use App\Models\ObservationEscalation;
use App\Models\ProcedureOrder;
use App\Models\Service;
use App\Models\SurgicalCase;
use App\Models\User;
use App\Services\NHIF\NhifEncounterPreApprovalService;
use App\Services\NHIF\NhifSchemeEligibilityService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Domain service for Doctor-Ordered Procedures and Operating Theatre Surgical Operations.
 */
class ProcedureService
{
    public function __construct(
        protected AuditLogService $auditLog,
        protected ChargeCaptureService $chargeCapture,
        protected FinancialAuthorizationService $financialAuth,
        protected NhifSchemeEligibilityService $schemeEligibility,
        protected NhifEncounterPreApprovalService $nhifPreApproval,
    ) {}

    /**
     * Create a doctor-prescribed procedure order.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function createOrder(Admission $admission, array $data, Request $request): ProcedureOrder
    {
        if ($admission->status !== Admission::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'admission_id' => 'Procedures can only be ordered for an active admission.',
            ]);
        }

        $serviceId = $data['service_id'] ?? null;
        $procedureName = $data['procedure_name'] ?? null;

        if ($serviceId && empty($procedureName)) {
            $service = Service::find($serviceId);
            if ($service) {
                $procedureName = $service->name;
            }
        }

        if (empty($procedureName)) {
            throw ValidationException::withMessages([
                'procedure_name' => 'The procedure name is required.',
            ]);
        }

        $order = ProcedureOrder::create([
            'admission_id' => $admission->id,
            'patient_id' => $admission->patient_id,
            'clinical_encounter_id' => $data['clinical_encounter_id'] ?? null,
            'service_id' => $serviceId,
            'procedure_name' => $procedureName,
            'procedure_type' => $data['procedure_type'] ?? ProcedureOrder::TYPE_BEDSIDE,
            'urgency' => $data['urgency'] ?? 'elective',
            'clinical_indication' => $data['clinical_indication'],
            'prescribed_by' => $request->user()->id,
            'scheduled_at' => isset($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : null,
            'status' => isset($data['scheduled_at']) ? ProcedureOrder::STATUS_SCHEDULED : ProcedureOrder::STATUS_ORDERED,
            'notes' => $data['notes'] ?? null,
        ]);

        $this->auditLog->record(
            'procedure_ordered',
            $order,
            null,
            ['admission_id' => $admission->id, 'procedure' => $order->procedure_name, 'urgency' => $order->urgency],
            $request,
        );

        return $order;
    }

    /**
     * Schedule an operating theatre surgical case for a procedure order.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function scheduleSurgicalCase(ProcedureOrder $order, array $data, Request $request): SurgicalCase
    {
        if (in_array($order->status, [ProcedureOrder::STATUS_COMPLETED, ProcedureOrder::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages([
                'procedure_order_id' => "Cannot schedule a theatre case for an order with status \"{$order->status}\".",
            ]);
        }

        $case = SurgicalCase::create([
            'procedure_order_id' => $order->id,
            'admission_id' => $order->admission_id,
            'patient_id' => $order->patient_id,
            'theatre_room' => $data['theatre_room'],
            'lead_surgeon_id' => $data['lead_surgeon_id'],
            'assistant_surgeon_id' => $data['assistant_surgeon_id'] ?? null,
            'anesthetist_id' => $data['anesthetist_id'] ?? null,
            'scrub_nurse_id' => $data['scrub_nurse_id'] ?? null,
            'circulating_nurse_id' => $data['circulating_nurse_id'] ?? null,
            'anesthesia_type' => $data['anesthesia_type'] ?? 'general',
            'started_at' => isset($data['started_at']) ? Carbon::parse($data['started_at']) : null,
            'status' => SurgicalCase::STATUS_BOOKED,
            'recorded_by' => $request->user()->id,
        ]);

        $order->update([
            'status' => ProcedureOrder::STATUS_SCHEDULED,
            'scheduled_at' => $case->started_at ?? now(),
        ]);

        $this->auditLog->record(
            'surgical_case_scheduled',
            $case,
            null,
            ['case_number' => $case->case_number, 'theatre' => $case->theatre_room],
            $request,
        );

        return $case;
    }

    /**
     * Update the WHO Surgical Safety Checklist for a surgical case.
     *
     * @param  array<string, mixed>  $checklist
     */
    public function updateChecklist(SurgicalCase $case, array $checklist, Request $request): SurgicalCase
    {
        $case->update([
            'identity_confirmed' => (bool) ($checklist['identity_confirmed'] ?? $case->identity_confirmed),
            'site_marked' => (bool) ($checklist['site_marked'] ?? $case->site_marked),
            'consent_confirmed' => (bool) ($checklist['consent_confirmed'] ?? $case->consent_confirmed),
            'anesthesia_safety_check_completed' => (bool) ($checklist['anesthesia_safety_check_completed'] ?? $case->anesthesia_safety_check_completed),
            'pulse_oximeter_functioning' => (bool) ($checklist['pulse_oximeter_functioning'] ?? $case->pulse_oximeter_functioning),
            'allergy_screened' => (bool) ($checklist['allergy_screened'] ?? $case->allergy_screened),
            'difficult_airway_risk_assessed' => (bool) ($checklist['difficult_airway_risk_assessed'] ?? $case->difficult_airway_risk_assessed),
            'blood_loss_risk_assessed' => (bool) ($checklist['blood_loss_risk_assessed'] ?? $case->blood_loss_risk_assessed),
            'fasting_npo_hours_confirmed' => (bool) ($checklist['fasting_npo_hours_confirmed'] ?? $case->fasting_npo_hours_confirmed),
            'checklist_completed_by' => $request->user()->id,
        ]);

        $this->auditLog->record(
            'surgical_checklist_updated',
            $case,
            null,
            ['case_number' => $case->case_number],
            $request,
        );

        return $case;
    }

    /**
     * Record intra-operative surgical documentation and update case status.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordIntraOp(SurgicalCase $case, array $data, Request $request): SurgicalCase
    {
        $ebl = isset($data['estimated_blood_loss_ml']) ? (int) $data['estimated_blood_loss_ml'] : 0;
        $swabCountCorrect = array_key_exists('swab_instrument_count_correct', $data)
            ? (bool) $data['swab_instrument_count_correct']
            : true;

        $case->update([
            'started_at' => isset($data['started_at']) ? Carbon::parse($data['started_at']) : ($case->started_at ?? now()),
            'ended_at' => isset($data['ended_at']) ? Carbon::parse($data['ended_at']) : now(),
            'pre_operative_diagnosis' => $data['pre_operative_diagnosis'] ?? $case->pre_operative_diagnosis,
            'post_operative_diagnosis' => $data['post_operative_diagnosis'] ?? null,
            'surgical_technique' => $data['surgical_technique'],
            'intraoperative_findings' => $data['intraoperative_findings'] ?? null,
            'implants_used' => $data['implants_used'] ?? null,
            'drains_placed' => $data['drains_placed'] ?? null,
            'swab_instrument_count_correct' => $swabCountCorrect,
            'specimens_sent_to_lab' => $data['specimens_sent_to_lab'] ?? null,
            'estimated_blood_loss_ml' => $ebl,
            'complications' => $data['complications'] ?? null,
            'status' => SurgicalCase::STATUS_RECOVERY,
        ]);

        $case->procedureOrder()->update([
            'status' => ProcedureOrder::STATUS_IN_PROGRESS,
        ]);

        // Auto-escalate if swab/instrument count is incorrect or critical blood loss
        $alerts = [];
        if (! $swabCountCorrect) {
            $alerts[] = 'Swab / instrument count discrepancy flagged';
        }
        if ($ebl >= 1000) {
            $alerts[] = "Major intra-operative hemorrhage (EBL: {$ebl} ml)";
        }

        if (! empty($alerts)) {
            ObservationEscalation::create([
                'admission_id' => $case->admission_id,
                'patient_id' => $case->patient_id,
                'source' => ObservationEscalation::SOURCE_AUTO,
                'reason' => 'Surgical Safety Alert: '.implode('; ', $alerts),
                'flags' => $alerts,
                'raised_by' => $request->user()->id,
                'raised_at' => now(),
                'status' => ObservationEscalation::STATUS_OPEN,
            ]);
        }

        $this->auditLog->record(
            'intra_operative_recorded',
            $case,
            null,
            ['case_number' => $case->case_number, 'ebl' => $ebl],
            $request,
        );

        return $case;
    }

    /**
     * Document PACU / Recovery room monitoring and discharge readiness (Aldrete score).
     *
     * @param  array<string, mixed>  $data
     */
    public function recordPacuRecovery(SurgicalCase $case, array $data, Request $request): SurgicalCase
    {
        $aldreteScore = isset($data['aldrete_score']) ? (int) $data['aldrete_score'] : null;

        $case->update([
            'recovery_started_at' => isset($data['recovery_started_at']) ? Carbon::parse($data['recovery_started_at']) : ($case->recovery_started_at ?? now()),
            'recovery_ended_at' => isset($data['recovery_ended_at']) ? Carbon::parse($data['recovery_ended_at']) : now(),
            'aldrete_score' => $aldreteScore,
            'post_operative_orders' => $data['post_operative_orders'] ?? null,
            'transferred_to_ward_at' => isset($data['transferred_to_ward_at']) ? Carbon::parse($data['transferred_to_ward_at']) : now(),
            'status' => SurgicalCase::STATUS_COMPLETED,
        ]);

        $case->procedureOrder()->update([
            'status' => ProcedureOrder::STATUS_COMPLETED,
        ]);

        $this->auditLog->record(
            'pacu_recovery_completed',
            $case,
            null,
            ['case_number' => $case->case_number, 'aldrete_score' => $aldreteScore],
            $request,
        );

        return $case;
    }

    /**
     * Record a ward-based bedside procedure directly.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordBedsideProcedure(ProcedureOrder $order, array $data, Request $request): BedsideProcedureEvent
    {
        $event = BedsideProcedureEvent::create([
            'procedure_order_id' => $order->id,
            'admission_id' => $order->admission_id,
            'patient_id' => $order->patient_id,
            'performed_at' => isset($data['performed_at']) ? Carbon::parse($data['performed_at']) : now(),
            'performed_by' => $request->user()->id,
            'procedure_notes' => $data['procedure_notes'],
            'patient_tolerance' => $data['patient_tolerance'] ?? 'tolerated_well',
            'consumables_used' => $data['consumables_used'] ?? null,
            'complications' => $data['complications'] ?? null,
        ]);

        $order->update([
            'status' => ProcedureOrder::STATUS_COMPLETED,
        ]);

        $this->auditLog->record(
            'bedside_procedure_completed',
            $event,
            null,
            ['order_number' => $order->order_number, 'procedure' => $order->procedure_name],
            $request,
        );

        return $event;
    }

    /**
     * Create a procedure order from a Doctor Clinical Encounter.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function createForEncounter(ClinicalEncounter $encounter, array $data, Request|User $request): ProcedureOrder
    {
        $user = $request instanceof User ? $request : $request->user();
        if ($request instanceof Request) {
            $httpRequest = $request;
        } else {
            $httpRequest = new Request();
            $httpRequest->setUserResolver(fn () => $user);
        }

        if ($encounter->status === ClinicalEncounter::STATUS_CANCELLED || $encounter->status === ClinicalEncounter::STATUS_COMPLETED) {
            throw ValidationException::withMessages([
                'encounter' => 'Procedures cannot be ordered for a completed or cancelled encounter.',
            ]);
        }

        $serviceId = $data['service_id'] ?? null;
        $service = null;
        if ($serviceId) {
            $service = Service::find($serviceId);
        }

        if (! $service && empty($data['procedure_name'])) {
            throw ValidationException::withMessages([
                'service_id' => 'Please select a procedure from the available service catalogue.',
            ]);
        }

        $procedureName = $service?->name ?? $data['procedure_name'];
        $quantity = max(1, (int) ($data['quantity'] ?? 1));

        // Idempotency / Duplicate Submission Prevention:
        $existingOrder = ProcedureOrder::query()
            ->where('clinical_encounter_id', $encounter->id)
            ->where(function ($q) use ($serviceId, $procedureName) {
                if ($serviceId) {
                    $q->where('service_id', $serviceId);
                } else {
                    $q->where('procedure_name', $procedureName);
                }
            })
            ->whereIn('status', [
                ProcedureOrder::STATUS_ORDERED,
                ProcedureOrder::STATUS_SCHEDULED,
                ProcedureOrder::STATUS_IN_PROGRESS,
            ])
            ->first();

        if ($existingOrder) {
            return $existingOrder;
        }

        $hospitalId = $encounter->department?->hospital_id
            ?? $encounter->hospital_id
            ?? Hospital::first()?->id
            ?? 1;

        $patientInsurance = $encounter->patient?->currentNhifInsurance();
        $isNhifPatient = $patientInsurance !== null && $patientInsurance->hasValidAuthorization();

        $requestedPaymentMode = $data['payment_mode'] ?? null;
        $isEmergencyExemption = (bool) ($data['is_emergency_exemption'] ?? $data['emergency_exemption'] ?? false);

        $paymentMode = ProcedureOrder::PAYMENT_MODE_CASH;
        $billingStatus = ProcedureOrder::BILLING_STATUS_PENDING_PAYMENT;
        $nhifCode = $service?->code;
        $nhifName = $service?->name;
        $unitPrice = 0.00;
        $requiresPreauth = false;

        if ($isEmergencyExemption) {
            $paymentMode = ProcedureOrder::PAYMENT_MODE_CASH;
            $billingStatus = ProcedureOrder::BILLING_STATUS_EXEMPTED;
        } elseif ($requestedPaymentMode === ProcedureOrder::PAYMENT_MODE_EXEMPT) {
            $paymentMode = ProcedureOrder::PAYMENT_MODE_EXEMPT;
            $billingStatus = ProcedureOrder::BILLING_STATUS_EXEMPTED;
        } elseif ($isNhifPatient && $requestedPaymentMode !== ProcedureOrder::PAYMENT_MODE_CASH) {
            // Patient is NHIF insured
            if ($service) {
                $mapping = NhifServiceMapping::where('service_id', $service->id)
                    ->where('hospital_id', $hospitalId)
                    ->where('is_active', true)
                    ->first();
                if ($mapping) {
                    $nhifCode = $mapping->nhif_item_code;
                    $nhifName = $mapping->nhif_item_name ?? $service->name;
                    $requiresPreauth = (bool) $mapping->requires_preauth;
                    if ($mapping->isExcluded()) {
                        throw ValidationException::withMessages([
                            'service_id' => "Procedure \"{$service->name}\" is excluded from NHIF coverage at this facility.",
                        ]);
                    }
                }
            }

            // Check scheme eligibility
            $schemeId = $patientInsurance->nhif_scheme_id;
            $isEligible = true;
            if ($schemeId && $nhifCode) {
                $isEligible = $this->schemeEligibility->isEligible($schemeId, $nhifCode);
            }

            if (! $isEligible) {
                // If patient specifically agreed to self-pay/cash
                if (! empty($data['patient_consent_self_pay']) || ! empty($data['consent_obtained']) || $requestedPaymentMode === ProcedureOrder::PAYMENT_MODE_CASH) {
                    $paymentMode = ProcedureOrder::PAYMENT_MODE_CASH;
                    $billingStatus = ProcedureOrder::BILLING_STATUS_PENDING_PAYMENT;
                } else {
                    throw ValidationException::withMessages([
                        'service_id' => "Procedure \"{$procedureName}\" (NHIF code: {$nhifCode}) is not covered under scheme {$schemeId}. Patient payment consent is required to proceed with cash billing.",
                        'consent_obtained' => 'Patient payment consent is required to proceed with cash billing.',
                        'patient_consent_self_pay' => 'Patient payment consent is required to proceed with cash billing.',
                    ]);
                }
            } else {
                $paymentMode = ProcedureOrder::PAYMENT_MODE_NHIF;

                // Check restriction
                $nhifService = NhifService::where('nhif_item_code', $nhifCode)->first();
                if ($nhifService && ($nhifService->is_restricted || ! empty($nhifService->is_restricted))) {
                    $requiresPreauth = true;
                }

                if ($requiresPreauth || ! empty($data['requires_preauth'])) {
                    $billingStatus = ProcedureOrder::BILLING_STATUS_PENDING_AUTHORIZATION;
                } else {
                    $billingStatus = ProcedureOrder::BILLING_STATUS_AUTHORIZED;
                }
            }
        }

        // Calculate unit price from ServicePrice if available
        if ($service) {
            $hospitalPrice = $service->prices()
                ->where('hospital_id', $hospitalId)
                ->where('status', 'active')
                ->first();
            $unitPrice = (float) ($hospitalPrice?->amount ?? 0.00);
        }

        $totalPrice = $unitPrice * $quantity;

        $order = ProcedureOrder::create([
            'patient_id' => $encounter->patient_id,
            'clinical_encounter_id' => $encounter->id,
            'admission_id' => $encounter->admission_id ?? $encounter->linkedAdmission?->id ?? null,
            'service_id' => $service?->id,
            'procedure_name' => $procedureName,
            'procedure_type' => $data['procedure_type'] ?? ProcedureOrder::TYPE_BEDSIDE,
            'urgency' => $data['urgency'] ?? $data['priority'] ?? 'elective',
            'clinical_indication' => $data['clinical_indication'] ?? 'Clinical procedure prescribed during consultation',
            'prescribed_by' => $user->id,
            'scheduled_at' => isset($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : null,
            'status' => isset($data['scheduled_at']) ? ProcedureOrder::STATUS_SCHEDULED : ProcedureOrder::STATUS_ORDERED,
            'notes' => $data['notes'] ?? null,
            'payment_mode' => $paymentMode,
            'billing_status' => $billingStatus,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_price' => $totalPrice,
            'nhif_item_code' => $nhifCode,
            'nhif_item_name' => $nhifName,
            'is_emergency_exemption' => $isEmergencyExemption,
            'exemption_reason' => $data['exemption_reason'] ?? null,
            'exempted_by' => $isEmergencyExemption ? $user->id : null,
        ]);

        // If Cash: capture charge and invoice immediately (unless exempted)
        if ($paymentMode === ProcedureOrder::PAYMENT_MODE_CASH && $service && ! $isEmergencyExemption) {
            $this->chargeCapture->captureFromProcedureOrder($order, $encounter, $httpRequest);
            $order->refresh();
        }

        // If NHIF requiring pre-authorization and auto preapproval requested
        if ($paymentMode === ProcedureOrder::PAYMENT_MODE_NHIF && $billingStatus === ProcedureOrder::BILLING_STATUS_PENDING_AUTHORIZATION) {
            if ($httpRequest->boolean('send_preapproval', true) && $patientInsurance) {
                try {
                    $this->nhifPreApproval->createAndSubmitFromSelection(
                        $patientInsurance,
                        $encounter,
                        [],
                        [],
                        $httpRequest,
                        $order->clinical_indication,
                        $encounter->practitioner?->nhif_practitioner_no,
                        [],
                        [$order->id],
                    );
                    $order->refresh();
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Automatic NHIF pre-approval failed for procedure order', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->auditLog->record(
            'procedure_ordered',
            $order,
            null,
            [
                'encounter_id' => $encounter->id,
                'procedure' => $order->procedure_name,
                'payment_mode' => $order->payment_mode,
                'billing_status' => $order->billing_status,
                'urgency' => $order->urgency,
            ],
            $httpRequest,
        );

        return $order;
    }

    /**
     * Record execution and clinical findings for a procedure order.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function recordExecution(ProcedureOrder $order, array $data, Request|User $request): ProcedureOrder
    {
        $user = $request instanceof User ? $request : $request->user();
        if ($request instanceof Request) {
            $httpRequest = $request;
        } else {
            $httpRequest = new Request();
            $httpRequest->setUserResolver(fn () => $user);
        }

        if ($order->status === ProcedureOrder::STATUS_COMPLETED) {
            throw ValidationException::withMessages([
                'status' => 'This procedure order has already been completed.',
            ]);
        }

        if ($order->status === ProcedureOrder::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'status' => 'Cannot execute a cancelled procedure order.',
            ]);
        }

        $isEmergency = (bool) ($data['is_emergency_exemption'] ?? $order->is_emergency_exemption);

        if ($isEmergency) {
            $order->update([
                'is_emergency_exemption' => true,
                'exemption_reason' => $data['exemption_reason'] ?? $order->exemption_reason ?? 'Clinical Emergency',
                'exempted_by' => $user->id,
                'billing_status' => ProcedureOrder::BILLING_STATUS_EXEMPTED,
            ]);
        } else {
            // Financial & authorization checks:
            if ($order->payment_mode === ProcedureOrder::PAYMENT_MODE_CASH) {
                if (! $order->isPaid()) {
                    throw ValidationException::withMessages([
                        'payment' => 'Payment is required before procedure execution. Confirmed cashier payment is required.',
                    ]);
                }
            } elseif ($order->payment_mode === ProcedureOrder::PAYMENT_MODE_NHIF) {
                if ($order->billing_status === ProcedureOrder::BILLING_STATUS_REJECTED || $order->nhif_approval_status === ProcedureOrder::NHIF_APPROVAL_REJECTED) {
                    throw ValidationException::withMessages([
                        'nhif' => 'NHIF pre-authorization was rejected: ' . ($order->nhif_rejection_reason ?: 'Cannot perform procedure under NHIF.'),
                    ]);
                }

                if ($order->billing_status === ProcedureOrder::BILLING_STATUS_PENDING_AUTHORIZATION || $order->nhif_approval_status === ProcedureOrder::NHIF_APPROVAL_PENDING) {
                    $sessionItem = NhifPreApprovalSessionItem::where('source_type', NhifPreApprovalSessionItem::SOURCE_PROCEDURE)
                        ->where('source_id', $order->id)
                        ->first();
                    if ($sessionItem && $sessionItem->isIssuable()) {
                        $order->update([
                            'billing_status' => ProcedureOrder::BILLING_STATUS_AUTHORIZED,
                            'nhif_approval_status' => ProcedureOrder::NHIF_APPROVAL_APPROVED,
                        ]);
                    } else {
                        throw ValidationException::withMessages([
                            'nhif' => 'NHIF pre-authorization has not been approved. Execution is blocked until approved.',
                        ]);
                    }
                }
            }
        }

        $order->update([
            'status' => ProcedureOrder::STATUS_COMPLETED,
            'performer_id' => $data['performer_id'] ?? $user->id,
            'performed_at' => isset($data['performed_at']) ? Carbon::parse($data['performed_at']) : now(),
            'findings' => $data['findings'] ?? null,
            'results' => $data['results'] ?? null,
            'procedure_notes' => $data['procedure_notes'] ?? $data['notes'] ?? null,
            'complications' => $data['complications'] ?? null,
        ]);

        $this->auditLog->record(
            'procedure_executed',
            $order,
            null,
            [
                'order_number' => $order->order_number,
                'procedure' => $order->procedure_name,
                'performer' => $order->performer_id,
            ],
            $httpRequest,
        );

        return $order->fresh();
    }

    /**
     * Cancel an existing procedure order.
     *
     * @throws ValidationException
     */
    public function cancelOrder(ProcedureOrder $order, string $reason, Request|User $request): ProcedureOrder
    {
        $user = $request instanceof User ? $request : $request->user();
        if ($request instanceof Request) {
            $httpRequest = $request;
        } else {
            $httpRequest = new Request();
            $httpRequest->setUserResolver(fn () => $user);
        }

        if (in_array($order->status, [ProcedureOrder::STATUS_COMPLETED, ProcedureOrder::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages([
                'status' => "Cannot cancel an order with status \"{$order->status}\".",
            ]);
        }

        if ($order->charge_id) {
            $this->chargeCapture->voidForSource(ProcedureOrder::class, $order->id, $reason, $httpRequest);
        }

        $order->update([
            'status' => ProcedureOrder::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        $this->auditLog->record(
            'procedure_cancelled',
            $order,
            null,
            [
                'order_number' => $order->order_number,
                'reason' => $reason,
            ],
            $httpRequest,
        );

        return $order->fresh();
    }

    /**
     * Retrieve available procedures with real-time pricing and NHIF coverage status.
     *
     * @return list<array<string, mixed>>
     */
    public function getAvailableProcedures(ClinicalEncounter $encounter, ?string $search = null): array
    {
        $hospitalId = $encounter->department?->hospital_id
            ?? $encounter->hospital_id
            ?? Hospital::first()?->id
            ?? 1;

        $insurance = $encounter->patient?->currentNhifInsurance();
        $schemeId = $insurance?->nhif_scheme_id;

        $query = Service::query()
            ->where('category', 'procedure')
            ->where('status', Service::STATUS_ACTIVE)
            ->where('is_billable', true)
            ->with(['prices' => fn ($q) => $q->where('hospital_id', $hospitalId)->where('status', 'active')]);

        if (filled($search)) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
        }

        return $query->take(50)->get()->map(function (Service $service) use ($hospitalId, $schemeId) {
            $cashPrice = (float) ($service->prices->first()?->amount ?? 0.00);
            $mapping = NhifServiceMapping::where('service_id', $service->id)
                ->where('hospital_id', $hospitalId)
                ->where('is_active', true)
                ->first();

            $nhifCode = $mapping?->nhif_item_code ?? $service->code;
            $isCovered = false;
            $requiresPreauth = false;

            if ($schemeId && $nhifCode) {
                $isCovered = $this->schemeEligibility->isEligible($schemeId, $nhifCode);
                $isRestricted = NhifService::where('nhif_item_code', $nhifCode)->value('is_restricted');
                $requiresPreauth = (bool) ($mapping?->requires_preauth || $isRestricted);
            }

            return [
                'id' => $service->id,
                'code' => $service->code,
                'name' => $service->name,
                'category' => $service->category,
                'cash_price' => $cashPrice,
                'nhif_code' => $nhifCode,
                'is_covered_under_scheme' => $isCovered,
                'requires_preauth' => $requiresPreauth,
            ];
        })->values()->all();
    }
}
