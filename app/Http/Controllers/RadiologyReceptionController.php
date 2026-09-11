<?php

namespace App\Http\Controllers;

use App\Http\Requests\RadiologyAppointment\RadiologyConsentRequest;
use App\Http\Requests\RadiologyAppointment\RadiologyNotReadyRequest;
use App\Http\Requests\RadiologyAppointment\RadiologyPreparationRequest;
use App\Http\Requests\RadiologyAppointment\RadiologyPreparationWaiverRequest;
use App\Http\Requests\RadiologyAppointment\RadiologyScreeningRequest;
use App\Http\Resources\RadiologyAppointmentResource;
use App\Models\RadiologyAppointment;
use App\Services\RadiologyReceptionService;
use App\Services\RadiologySchedulingSearchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Radiology reception & preparation (Phase 5, Step 40): the dashboard plus
 * every arrival/verification/preparation/screening/consent/readiness
 * mutation. Ends at READY — the actual examination is Step 41.
 */
class RadiologyReceptionController extends Controller
{
    protected function eagerLoads(): array
    {
        return [
            'radiologyOrderItem:id,radiology_order_id,radiology_procedure_id,procedure_code,procedure_name,modality_snapshot',
            'radiologyOrderItem.radiologyProcedure:id,preparation_type,preparation_instructions,contrast_requirement,contrast_type',
            'radiologyOrderItem.radiologyOrder:id,order_number,priority,clinical_indication,clinical_encounter_id',
            'radiologyOrderItem.radiologyOrder.clinicalEncounter:id,encounter_number,patient_id',
            'radiologyOrderItem.radiologyOrder.clinicalEncounter.patient:id,patient_number,first_name,middle_name,last_name,date_of_birth,gender,phone',
            'arrivedBy:id,UserName',
            'verifiedBy:id,UserName',
            'readyBy:id,UserName',
        ];
    }

    public function index(Request $request, RadiologySchedulingSearchService $search, RadiologyReceptionService $service): Response
    {
        $this->authorize('viewReception', RadiologyAppointment::class);

        $filters = $request->only(['search', 'status', 'arrival_status', 'date', 'date_from', 'date_to']);

        $query = $search->receptionQuery($filters)->with($this->eagerLoads());
        $search->scopeWorklistToHospital($query, $this->scopedHospitalId($request));

        $appointments = $query->paginate(20)->withQueryString()
            ->through(fn (RadiologyAppointment $appointment) => [
                ...(new RadiologyAppointmentResource($appointment))->resolve($request),
                'readiness_blockers' => $service->readinessBlockers($appointment),
            ]);

        return Inertia::render('radiology/reception/index', [
            'appointments' => $appointments,
            'filters' => $filters,
            'statuses' => RadiologyAppointment::STATUSES,
            'notReadyReasons' => RadiologyAppointment::NOT_READY_REASONS,
            'consentStatuses' => RadiologyAppointment::CONSENT_STATUSES,
        ]);
    }

    public function arrive(Request $request, RadiologyAppointment $radiologyAppointment, RadiologyReceptionService $service): RedirectResponse
    {
        $this->authorize('manage', $radiologyAppointment);

        $service->markArrived($radiologyAppointment, $request);

        return back()->with('success', 'Patient marked as arrived.');
    }

    public function verify(Request $request, RadiologyAppointment $radiologyAppointment, RadiologyReceptionService $service): RedirectResponse
    {
        $this->authorize('manage', $radiologyAppointment);

        $service->verify($radiologyAppointment, $request);

        return back()->with('success', 'Patient identity and radiology order verified.');
    }

    public function startPreparation(Request $request, RadiologyAppointment $radiologyAppointment, RadiologyReceptionService $service): RedirectResponse
    {
        $this->authorize('manage', $radiologyAppointment);

        $service->startPreparation($radiologyAppointment, $request);

        return back()->with('success', 'Preparation started.');
    }

    public function completePreparation(RadiologyPreparationRequest $request, RadiologyAppointment $radiologyAppointment, RadiologyReceptionService $service): RedirectResponse
    {
        $this->authorize('manage', $radiologyAppointment);

        $service->completePreparation($radiologyAppointment, $request->validated(), $request);

        return back()->with('success', 'Preparation recorded.');
    }

    public function waivePreparation(RadiologyPreparationWaiverRequest $request, RadiologyAppointment $radiologyAppointment, RadiologyReceptionService $service): RedirectResponse
    {
        $this->authorize('manage', $radiologyAppointment);

        $service->waivePreparation($radiologyAppointment, $request->validated()['reason'], $request);

        return back()->with('success', 'Preparation waived.');
    }

    public function recordScreening(RadiologyScreeningRequest $request, RadiologyAppointment $radiologyAppointment, RadiologyReceptionService $service): RedirectResponse
    {
        $this->authorize('manage', $radiologyAppointment);

        $validated = $request->validated();
        $service->recordScreening($radiologyAppointment, (bool) $validated['flagged'], $validated['notes'] ?? null, $request);

        return back()->with('success', 'MRI safety screening recorded.');
    }

    public function reviewContrast(Request $request, RadiologyAppointment $radiologyAppointment, RadiologyReceptionService $service): RedirectResponse
    {
        $this->authorize('manage', $radiologyAppointment);

        $service->reviewContrast($radiologyAppointment, $request);

        return back()->with('success', 'Contrast requirement reviewed.');
    }

    public function recordConsent(RadiologyConsentRequest $request, RadiologyAppointment $radiologyAppointment, RadiologyReceptionService $service): RedirectResponse
    {
        $this->authorize('manage', $radiologyAppointment);

        $service->recordConsent($radiologyAppointment, $request->validated()['status'], $request);

        return back()->with('success', 'Consent status recorded.');
    }

    public function markReady(Request $request, RadiologyAppointment $radiologyAppointment, RadiologyReceptionService $service): RedirectResponse
    {
        $this->authorize('manage', $radiologyAppointment);

        $service->markReady($radiologyAppointment, $request);

        return back()->with('success', 'Patient marked ready for examination.');
    }

    public function markNotReady(RadiologyNotReadyRequest $request, RadiologyAppointment $radiologyAppointment, RadiologyReceptionService $service): RedirectResponse
    {
        $this->authorize('manage', $radiologyAppointment);

        $service->markNotReady($radiologyAppointment, $request->validated()['reason'], $request);

        return back()->with('success', 'Patient marked not ready.');
    }

    public function resume(Request $request, RadiologyAppointment $radiologyAppointment, RadiologyReceptionService $service): RedirectResponse
    {
        $this->authorize('manage', $radiologyAppointment);

        $service->resume($radiologyAppointment, $request);

        return back()->with('success', 'Patient returned to the active workflow.');
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
