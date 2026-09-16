<?php

namespace App\Http\Controllers;

use App\Http\Requests\RadiologyReport\CreateRadiologyReportRequest;
use App\Http\Requests\RadiologyReport\SubmitRadiologyReportRequest;
use App\Http\Requests\RadiologyReport\UpdateRadiologyReportRequest;
use App\Http\Resources\RadiologyAppointmentResource;
use App\Http\Resources\RadiologyReportResource;
use App\Models\AuditLog;
use App\Models\RadiologyAppointment;
use App\Models\RadiologyOrder;
use App\Models\RadiologyProcedure;
use App\Models\RadiologyReport;
use App\Services\RadiologyReportSearchService;
use App\Services\RadiologyReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Radiology findings & results (Phase 5, Step 42): the reporting worklist
 * plus create/show/update/submit for one report against an already-
 * COMPLETED RadiologyAppointment. Kept thin — every rule/transition/audit
 * lives in RadiologyReportService. Ends at SUBMITTED (the handoff point to
 * Step 43, Verification & Reporting).
 */
class RadiologyReportController extends Controller
{
    protected function reportEagerLoads(): array
    {
        return [
            'radiologyAppointment:id,radiology_order_item_id,modality,resource_name,status,performed_by,started_at,completed_at,technical_notes,technical_details',
            'radiologyAppointment.performedBy:id,UserName',
            'radiologyAppointment.images',
            'radiologyAppointment.images.uploadedBy:id,UserName',
            'radiologyAppointment.radiologyOrderItem:id,radiology_order_id,radiology_procedure_id,procedure_code,procedure_name,modality_snapshot,body_site_snapshot',
            'radiologyAppointment.radiologyOrderItem.radiologyProcedure:id,laterality,body_sites',
            'radiologyAppointment.radiologyOrderItem.radiologyOrder:id,order_number,priority,clinical_indication,clinical_encounter_id,ordered_by,ordered_at',
            'radiologyAppointment.radiologyOrderItem.radiologyOrder.orderedBy:id,UserName',
            'radiologyAppointment.radiologyOrderItem.radiologyOrder.clinicalEncounter:id,encounter_number,patient_id,started_at',
            'radiologyAppointment.radiologyOrderItem.radiologyOrder.clinicalEncounter.patient:id,patient_number,first_name,middle_name,last_name,date_of_birth,gender',
            'reportingPractitioner:id,staff_id,specialization',
            'reportingPractitioner.staff:id,user_id',
            'reportingPractitioner.staff.user:id,UserName',
            'updatedBy:id,UserName',
            'submittedBy:id,UserName',
        ];
    }

    public function index(Request $request, RadiologyReportSearchService $search): Response
    {
        $this->authorize('viewAny', RadiologyReport::class);

        $filters = $request->only(['search', 'report_status', 'modality', 'priority', 'date', 'date_from', 'date_to']);

        $query = $search->worklistQuery($filters)->with([
            'radiologyOrderItem:id,radiology_order_id,procedure_code,procedure_name,modality_snapshot',
            'radiologyOrderItem.radiologyOrder:id,order_number,priority,clinical_indication,clinical_encounter_id',
            'radiologyOrderItem.radiologyOrder.clinicalEncounter:id,encounter_number,patient_id',
            'radiologyOrderItem.radiologyOrder.clinicalEncounter.patient:id,patient_number,first_name,middle_name,last_name,date_of_birth,gender',
            'performedBy:id,UserName',
            'report:id,radiology_appointment_id,report_number,status,reporting_practitioner_id',
            'report.reportingPractitioner:id,staff_id',
            'report.reportingPractitioner.staff:id,user_id',
            'report.reportingPractitioner.staff.user:id,UserName',
        ]);
        $search->scopeToHospital($query, $this->scopedHospitalId($request));

        $appointments = $query->paginate(20)->withQueryString()
            ->through(fn (RadiologyAppointment $appointment) => [
                ...(new RadiologyAppointmentResource($appointment))->resolve($request),
                'report' => $appointment->report ? [
                    'id' => $appointment->report->id,
                    'report_number' => $appointment->report->report_number,
                    'status' => $appointment->report->status,
                    'reporting_practitioner' => $appointment->report->reportingPractitioner ? [
                        'id' => $appointment->report->reportingPractitioner->id,
                        'name' => $appointment->report->reportingPractitioner->staff?->user?->UserName,
                    ] : null,
                ] : null,
            ]);

        return Inertia::render('radiology/results/index', [
            'appointments' => $appointments,
            'filters' => $filters,
            'reportStatuses' => RadiologyReport::STATUSES,
            'modalities' => RadiologyProcedure::MODALITIES,
            'priorities' => RadiologyOrder::PRIORITIES,
        ]);
    }

    /**
     * A read-only preview of the examination before a report is started —
     * the patient-safety-style confirmation before creating the draft.
     */
    public function create(Request $request, RadiologyAppointment $radiologyAppointment): Response
    {
        $this->authorize('create', [RadiologyReport::class, $radiologyAppointment]);

        $radiologyAppointment->load([
            'radiologyOrderItem:id,radiology_order_id,procedure_code,procedure_name,modality_snapshot',
            'radiologyOrderItem.radiologyOrder:id,order_number,priority,clinical_indication,clinical_encounter_id',
            'radiologyOrderItem.radiologyOrder.clinicalEncounter:id,encounter_number,patient_id',
            'radiologyOrderItem.radiologyOrder.clinicalEncounter.patient:id,patient_number,first_name,middle_name,last_name,date_of_birth,gender',
            'performedBy:id,UserName',
            'report:id,radiology_appointment_id',
        ]);

        return Inertia::render('radiology/results/create', [
            'appointment' => (new RadiologyAppointmentResource($radiologyAppointment))->resolve($request),
            'existingReportId' => $radiologyAppointment->report?->id,
        ]);
    }

    public function store(CreateRadiologyReportRequest $request, RadiologyReportService $service): RedirectResponse
    {
        $appointment = RadiologyAppointment::query()->findOrFail($request->validated('radiology_appointment_id'));

        $this->authorize('create', [RadiologyReport::class, $appointment]);

        $report = $service->createDraft($appointment, $request->validated(), $request);

        return redirect()->route('radiology-results.show', $report)->with('success', 'Report draft created.');
    }

    public function show(Request $request, RadiologyReport $radiologyReport): Response
    {
        $this->authorize('view', $radiologyReport);

        $radiologyReport->load($this->reportEagerLoads());

        return Inertia::render('radiology/results/show', [
            'report' => (new RadiologyReportResource($radiologyReport))->resolve($request),
            'canUpdate' => $request->user()->can('update', $radiologyReport),
            'canSubmit' => $request->user()->can('submit', $radiologyReport),
        ]);
    }

    public function update(UpdateRadiologyReportRequest $request, RadiologyReport $radiologyReport, RadiologyReportService $service): RedirectResponse
    {
        $this->authorize('update', $radiologyReport);

        $service->updateDraft($radiologyReport, $request->validated(), $request);

        return back()->with('success', 'Report draft saved.');
    }

    public function submit(SubmitRadiologyReportRequest $request, RadiologyReport $radiologyReport, RadiologyReportService $service): RedirectResponse
    {
        $this->authorize('submit', $radiologyReport);

        $service->submit($radiologyReport, $request);

        return back()->with('success', 'Report submitted for verification.');
    }

    /**
     * The report's own change history (Part 18) — reuses the existing
     * AuditLogService/AuditLog table directly rather than a second
     * versioning system, mirroring this step's own migration doc-comment.
     */
    public function history(Request $request, RadiologyReport $radiologyReport): Response
    {
        $this->authorize('view', $radiologyReport);

        $logs = AuditLog::query()
            ->where('auditable_type', $radiologyReport->getMorphClass())
            ->where('auditable_id', $radiologyReport->id)
            ->with('user:id,UserName')
            ->latest('created_at')
            ->latest('id')
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'user' => $log->user?->only(['id', 'UserName']),
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return Inertia::render('radiology/results/history', [
            'report' => [
                'id' => $radiologyReport->id,
                'report_number' => $radiologyReport->report_number,
                'status' => $radiologyReport->status,
            ],
            'logs' => $logs,
        ]);
    }

    /**
     * The hospital a hospital-admin is restricted to, or null when the user
     * is unrestricted (super-admin and any other unscoped role).
     */
    protected function scopedHospitalId(Request $request): ?int
    {
        if (! $request->user()->hasRole('hospital-admin')) {
            return null;
        }

        return $request->user()->staff?->hospital_id;
    }
}
