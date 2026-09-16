<?php

namespace App\Http\Controllers;

use App\Http\Requests\RadiologyReport\ReturnRadiologyReportRequest;
use App\Http\Requests\RadiologyReport\VerifyRadiologyReportRequest;
use App\Http\Resources\RadiologyReportResource;
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
 * Radiology report verification & authorization (Phase 5, Step 43): the
 * independent-review worklist plus review/verify/return for one already-
 * SUBMITTED RadiologyReport. Kept as its own controller, distinct from
 * RadiologyReportController, mirroring Part 4's clinical separation between
 * authoring and verifying — the two surfaces have different audiences,
 * different eager-load shapes, and different eligibility rules, even though
 * both operate on the same RadiologyReport model and the same
 * RadiologyReportPolicy/RadiologyReportService (Part 18/29's own "extend
 * existing" instruction — no separate verification model/policy/service was
 * created). History reuses the existing radiology-results.history route
 * (Step 42) rather than a duplicate — any user with `radiology-results.view`
 * (which every role holding verification permissions also has) can already
 * reach it.
 */
class RadiologyVerificationController extends Controller
{
    protected function eagerLoads(): array
    {
        return [
            'radiologyAppointment:id,radiology_order_item_id,modality,resource_name,status,performed_by,started_at,completed_at,technical_notes,technical_details',
            'radiologyAppointment.performedBy:id,UserName',
            'radiologyAppointment.radiologyOrderItem:id,radiology_order_id,radiology_procedure_id,procedure_code,procedure_name,modality_snapshot,body_site_snapshot',
            'radiologyAppointment.radiologyOrderItem.radiologyProcedure:id,laterality,body_sites',
            'radiologyAppointment.radiologyOrderItem.radiologyOrder:id,order_number,priority,clinical_indication,clinical_encounter_id,ordered_by,ordered_at',
            'radiologyAppointment.radiologyOrderItem.radiologyOrder.orderedBy:id,UserName',
            // department_id must stay selected here even though the page
            // never displays it — RadiologyReportPolicy::belongsToUsersHospital()
            // resolves clinicalEncounter->department->hospital_id from this
            // very (already-loaded) relation for the canVerify/canReturn
            // checks below, and a column-restricted belongsTo with its FK
            // missing silently resolves to null rather than re-querying.
            'radiologyAppointment.radiologyOrderItem.radiologyOrder.clinicalEncounter:id,encounter_number,patient_id,department_id,started_at',
            'radiologyAppointment.radiologyOrderItem.radiologyOrder.clinicalEncounter.department:id,hospital_id',
            'radiologyAppointment.radiologyOrderItem.radiologyOrder.clinicalEncounter.patient:id,patient_number,first_name,middle_name,last_name,date_of_birth,gender',
            'reportingPractitioner:id,staff_id,specialization',
            'reportingPractitioner.staff:id,user_id',
            'reportingPractitioner.staff.user:id,UserName',
            'verifyingPractitioner:id,staff_id,specialization',
            'verifyingPractitioner.staff:id,user_id',
            'verifyingPractitioner.staff.user:id,UserName',
            'updatedBy:id,UserName',
            'submittedBy:id,UserName',
            'verifiedBy:id,UserName',
            'returnedBy:id,UserName',
        ];
    }

    public function index(Request $request, RadiologyReportSearchService $search): Response
    {
        $this->authorize('viewAny', RadiologyReport::class);

        $filters = $request->only(['search', 'status', 'modality', 'priority', 'reporting_practitioner', 'date', 'date_from', 'date_to']);

        $query = $search->verificationQuery($filters)->with($this->eagerLoads());
        $search->scopeReportsToHospital($query, $this->scopedHospitalId($request));

        $reports = $query->paginate(20)->withQueryString()
            ->through(fn (RadiologyReport $report) => (new RadiologyReportResource($report))->resolve($request));

        return Inertia::render('radiology/verification/index', [
            'reports' => $reports,
            'filters' => $filters,
            'reportStatuses' => RadiologyReport::STATUSES,
            'modalities' => RadiologyProcedure::MODALITIES,
            'priorities' => RadiologyOrder::PRIORITIES,
        ]);
    }

    /**
     * Open a submitted report for the verifier's review (Part 8/9). Fires
     * the RADIOLOGY_REPORT_REVIEW_STARTED audit event when the report is
     * actually pending a decision.
     */
    public function review(Request $request, RadiologyReport $radiologyReport, RadiologyReportService $service): Response
    {
        $this->authorize('review', $radiologyReport);

        $radiologyReport->load([...$this->eagerLoads(), 'radiologyAppointment.images', 'radiologyAppointment.images.uploadedBy:id,UserName']);

        $service->recordReviewStarted($radiologyReport, $request);

        return Inertia::render('radiology/verification/show', [
            'report' => (new RadiologyReportResource($radiologyReport))->resolve($request),
            'canVerify' => $request->user()->can('verify', $radiologyReport),
            'canReturn' => $request->user()->can('returnForCorrection', $radiologyReport),
        ]);
    }

    public function verify(VerifyRadiologyReportRequest $request, RadiologyReport $radiologyReport, RadiologyReportService $service): RedirectResponse
    {
        $this->authorize('verify', $radiologyReport);

        $service->verify($radiologyReport, $request->validated(), $request);

        return redirect()->route('radiology-verification.index')->with('success', 'Report verified.');
    }

    public function returnForCorrection(ReturnRadiologyReportRequest $request, RadiologyReport $radiologyReport, RadiologyReportService $service): RedirectResponse
    {
        $this->authorize('returnForCorrection', $radiologyReport);

        $service->returnForCorrection($radiologyReport, $request->validated(), $request);

        return redirect()->route('radiology-verification.index')->with('success', 'Report returned for correction.');
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
