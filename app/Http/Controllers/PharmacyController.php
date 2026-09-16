<?php

namespace App\Http\Controllers;

use App\Http\Resources\PrescriptionOrderResource;
use App\Models\Medication;
use App\Models\PrescriptionOrder;
use App\Services\PharmacyReportService;
use App\Services\PharmacySearchService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pharmacy Management Foundation (Phase 6, Step 44), extended in Step 46
 * with a prescription detail page, and in Step 50 with the operational KPI
 * set (spec §5 asks to "create or improve /pharmacy/dashboard" — this
 * existing /pharmacy route already is that dashboard, so it is enriched in
 * place rather than duplicated at a second URL): a landing dashboard with
 * real counts (never fabricated statistics), the cross-encounter
 * prescriptions worklist, and a single prescription's detail. Kept thin:
 * every query lives in PharmacySearchService/PharmacyReportService, every
 * clinical fact is read live from the first-class PrescriptionOrder/
 * PrescriptionOrderItem/Medication/Stock tables.
 */
class PharmacyController extends Controller
{
    public function index(Request $request, PharmacySearchService $search, PharmacyReportService $reports): Response
    {
        abort_unless($request->user()->can('pharmacy.view'), 403);

        $hospitalId = $this->scopedHospitalId($request);

        $query = $search->prescriptionsQuery([]);
        $search->scopeToHospital($query, $hospitalId);

        $recentPrescriptions = (clone $query)->limit(5)->get();

        return Inertia::render('pharmacy/index', [
            'activePrescriptionCount' => (clone $query)->where('status', PrescriptionOrder::STATUS_ACTIVE)->count(),
            'activeMedicationCount' => Medication::query()->where('status', Medication::STATUS_ACTIVE)->count(),
            'totalMedicationCount' => Medication::query()->count(),
            'recentPrescriptions' => PrescriptionOrderResource::collection($recentPrescriptions)->resolve($request),
            // Phase 6, Step 50: only computed/sent when the user actually
            // holds the reports permission — a plain 'pharmacy.view'-only
            // user (if one is ever configured) never receives operational
            // analytics it has no permission to act on.
            'metrics' => $request->user()->can('pharmacy-reports.view') ? $reports->dashboardMetrics($hospitalId) : null,
        ]);
    }

    public function prescriptions(Request $request, PharmacySearchService $search): Response
    {
        abort_unless($request->user()->can('pharmacy-prescriptions.view'), 403);

        $filters = $request->only(['search', 'status', 'date_from', 'date_to']);

        $query = $search->prescriptionsQuery($filters);
        $search->scopeToHospital($query, $this->scopedHospitalId($request));

        $prescriptions = $query->paginate(20)->withQueryString()
            ->through(fn (PrescriptionOrder $order) => (new PrescriptionOrderResource($order))->resolve($request));

        return Inertia::render('pharmacy/prescriptions/index', [
            'prescriptions' => $prescriptions,
            'filters' => $filters,
            'statuses' => PrescriptionOrder::STATUSES,
        ]);
    }

    /**
     * A single prescription's detail — reached from the worklist, mirroring
     * RadiologyOrderController::show() exactly.
     */
    public function showPrescription(Request $request, PrescriptionOrder $prescriptionOrder): Response
    {
        $prescriptionOrder->load([
            'items',
            'prescriber:id,UserName',
            'updatedBy:id,UserName',
            'cancelledBy:id,UserName',
            'clinicalEncounter:id,encounter_number,patient_id,department_id',
            'clinicalEncounter.patient:id,patient_number,first_name,middle_name,last_name,date_of_birth,gender',
            // hospital_id is required by PrescriptionOrderPolicy::
            // belongsToUsersHospital(), not just display — omitting it left
            // the column null and caused every hospital-admin to be
            // incorrectly forbidden from their own hospital's prescriptions.
            'clinicalEncounter.department:id,name,hospital_id',
        ]);

        $this->authorize('view', $prescriptionOrder);

        return Inertia::render('pharmacy/prescriptions/show', [
            'prescription' => (new PrescriptionOrderResource($prescriptionOrder))->resolve($request),
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
