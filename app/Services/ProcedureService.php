<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\BedsideProcedureEvent;
use App\Models\ObservationEscalation;
use App\Models\ProcedureOrder;
use App\Models\Service;
use App\Models\SurgicalCase;
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
}
