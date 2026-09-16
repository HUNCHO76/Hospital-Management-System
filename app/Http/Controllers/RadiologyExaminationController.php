<?php

namespace App\Http\Controllers;

use App\Http\Requests\RadiologyExamination\AbortRadiologyExaminationRequest;
use App\Http\Requests\RadiologyExamination\CompleteRadiologyExaminationRequest;
use App\Http\Requests\RadiologyExamination\StartRadiologyExaminationRequest;
use App\Http\Requests\RadiologyExamination\UpdateRadiologyExaminationRequest;
use App\Http\Resources\RadiologyAppointmentResource;
use App\Models\RadiologyAppointment;
use App\Models\RadiologyOrder;
use App\Models\RadiologyProcedure;
use App\Services\RadiologyExaminationService;
use App\Services\RadiologySchedulingSearchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Radiology examination (Phase 5, Step 41): the worklist plus the actual
 * start/technical-data/complete/abort workflow for one already-READY
 * RadiologyAppointment. Kept thin — every rule/transition/audit lives in
 * RadiologyExaminationService. Ends at COMPLETED (the handoff point to Step
 * 42, Findings & Results) or ABORTED.
 */
class RadiologyExaminationController extends Controller
{
    protected function eagerLoads(): array
    {
        return [
            'radiologyOrderItem:id,radiology_order_id,radiology_procedure_id,procedure_code,procedure_name,modality_snapshot,body_site_snapshot',
            'radiologyOrderItem.radiologyProcedure:id,preparation_type,contrast_requirement,contrast_type',
            'radiologyOrderItem.radiologyOrder:id,order_number,priority,clinical_indication,clinical_encounter_id',
            'radiologyOrderItem.radiologyOrder.clinicalEncounter:id,encounter_number,patient_id',
            'radiologyOrderItem.radiologyOrder.clinicalEncounter.patient:id,patient_number,first_name,middle_name,last_name,date_of_birth,gender',
            'scheduledBy:id,UserName',
            'verifiedBy:id,UserName',
            'readyBy:id,UserName',
            'performedBy:id,UserName',
            'completedBy:id,UserName',
            'abortedBy:id,UserName',
        ];
    }

    public function index(Request $request, RadiologySchedulingSearchService $search): Response
    {
        $this->authorize('viewExaminationWorklist', RadiologyAppointment::class);

        $filters = $request->only(['search', 'status', 'modality', 'resource_name', 'priority', 'date', 'date_from', 'date_to']);

        $query = $search->examinationWorklistQuery($filters)->with($this->eagerLoads());
        $search->scopeWorklistToHospital($query, $this->scopedHospitalId($request));

        $appointments = $query->paginate(20)->withQueryString()
            ->through(fn (RadiologyAppointment $appointment) => (new RadiologyAppointmentResource($appointment))->resolve($request));

        return Inertia::render('radiology/examinations/index', [
            'appointments' => $appointments,
            'filters' => $filters,
            'statuses' => [
                RadiologyAppointment::STATUS_READY,
                RadiologyAppointment::STATUS_IN_PROGRESS,
                RadiologyAppointment::STATUS_COMPLETED,
                RadiologyAppointment::STATUS_ABORTED,
            ],
            'modalities' => RadiologyProcedure::MODALITIES,
            'priorities' => RadiologyOrder::PRIORITIES,
        ]);
    }

    public function show(Request $request, RadiologyAppointment $radiologyAppointment): Response
    {
        $this->authorize('viewExamination', $radiologyAppointment);

        $radiologyAppointment->load([...$this->eagerLoads(), 'images', 'images.uploadedBy:id,UserName']);

        return Inertia::render('radiology/examinations/show', [
            'appointment' => (new RadiologyAppointmentResource($radiologyAppointment))->resolve($request),
            'abortReasons' => RadiologyAppointment::ABORT_REASONS,
            'technicalFields' => RadiologyAppointment::TECHNICAL_FIELDS_BY_MODALITY,
            'canManageImages' => $request->user()->can('manageImages', $radiologyAppointment),
        ]);
    }

    public function start(StartRadiologyExaminationRequest $request, RadiologyAppointment $radiologyAppointment, RadiologyExaminationService $service): RedirectResponse
    {
        $this->authorize('start', $radiologyAppointment);

        $service->start($radiologyAppointment, $request->validated(), $request);

        return back()->with('success', 'Examination started.');
    }

    public function update(UpdateRadiologyExaminationRequest $request, RadiologyAppointment $radiologyAppointment, RadiologyExaminationService $service): RedirectResponse
    {
        $this->authorize('updateExamination', $radiologyAppointment);

        $service->recordTechnicalData($radiologyAppointment, $request->validated(), $request);

        return back()->with('success', 'Technical information recorded.');
    }

    public function complete(CompleteRadiologyExaminationRequest $request, RadiologyAppointment $radiologyAppointment, RadiologyExaminationService $service): RedirectResponse
    {
        $this->authorize('completeExamination', $radiologyAppointment);

        $service->complete($radiologyAppointment, $request->validated(), $request);

        return back()->with('success', 'Examination completed.');
    }

    public function abort(AbortRadiologyExaminationRequest $request, RadiologyAppointment $radiologyAppointment, RadiologyExaminationService $service): RedirectResponse
    {
        $this->authorize('abortExamination', $radiologyAppointment);

        $service->abort($radiologyAppointment, $request->validated()['reason'], $request);

        return back()->with('success', 'Examination aborted.');
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
