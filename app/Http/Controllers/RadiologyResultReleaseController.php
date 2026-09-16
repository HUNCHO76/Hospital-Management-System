<?php

namespace App\Http\Controllers;

use App\Http\Requests\RadiologyReport\ReleaseRadiologyResultRequest;
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
 * Radiology result release (Phase 5, Step 44): the release worklist plus
 * show/release for one already-VERIFIED RadiologyReport. Kept as its own
 * controller — continuing this Radiology module's own established
 * convention (a dedicated controller per distinct worklist stage, one each
 * for scheduling/reception/examination/reporting/verification since Step
 * 39) rather than folding release into RadiologyVerificationController,
 * even though Laboratory's own precedent keeps its entire post-submission
 * lifecycle (review/verify/authorize/return/release) on one controller.
 * Reuses the same RadiologyReportPolicy/RadiologyReportService (Part
 * 24/29's own "extend existing" instruction) — no separate release
 * model/policy/service was created.
 */
class RadiologyResultReleaseController extends Controller
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
            // department_id/department must stay selected even though the
            // page never displays them — RadiologyReportPolicy::
            // belongsToUsersHospital() resolves clinicalEncounter->
            // department->hospital_id from this already-loaded relation for
            // the release() authorization check, and a column-restricted
            // belongsTo with its FK missing silently resolves to null
            // rather than re-querying (see RadiologyVerificationController's
            // identical comment for the bug this avoids).
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
            'releasedBy:id,UserName',
        ];
    }

    public function index(Request $request, RadiologyReportSearchService $search): Response
    {
        $this->authorize('viewAny', RadiologyReport::class);

        $filters = $request->only(['search', 'status', 'modality', 'priority', 'reporting_practitioner', 'verifier', 'date', 'date_from', 'date_to']);

        $query = $search->releaseQuery($filters)->with($this->eagerLoads());
        $search->scopeReportsToHospital($query, $this->scopedHospitalId($request));

        $reports = $query->paginate(20)->withQueryString()
            ->through(fn (RadiologyReport $report) => (new RadiologyReportResource($report))->resolve($request));

        return Inertia::render('radiology/release/index', [
            'reports' => $reports,
            'filters' => $filters,
            'modalities' => RadiologyProcedure::MODALITIES,
            'priorities' => RadiologyOrder::PRIORITIES,
        ]);
    }

    public function show(Request $request, RadiologyReport $radiologyReport): Response
    {
        $this->authorize('view', $radiologyReport);

        $radiologyReport->load([...$this->eagerLoads(), 'radiologyAppointment.images', 'radiologyAppointment.images.uploadedBy:id,UserName']);

        return Inertia::render('radiology/release/show', [
            'report' => (new RadiologyReportResource($radiologyReport))->resolve($request),
            'canRelease' => $request->user()->can('release', $radiologyReport),
        ]);
    }

    public function release(ReleaseRadiologyResultRequest $request, RadiologyReport $radiologyReport, RadiologyReportService $service): RedirectResponse
    {
        $this->authorize('release', $radiologyReport);

        $service->release($radiologyReport, $request->validated(), $request);

        return redirect()->route('radiology-release.index')->with('success', 'Result released.');
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
