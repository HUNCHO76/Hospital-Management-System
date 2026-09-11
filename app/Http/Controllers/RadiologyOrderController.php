<?php

namespace App\Http\Controllers;

use App\Http\Resources\RadiologyOrderResource;
use App\Models\Department;
use App\Models\Practitioner;
use App\Models\RadiologyOrder;
use App\Models\RadiologyProcedure;
use App\Services\RadiologyOrderSearchService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Radiology dashboard/worklist/detail — the standalone, cross-encounter
 * surface radiology staff use (Phase 5, Step 36). No store/update/cancel
 * actions here: order creation, editing, and cancellation all flow through
 * EncounterRadiologyOrderController, since Radiology is its own branch off
 * the ClinicalEncounter, not a CarePlanItem. This step implements the order
 * foundation only; scheduling/examination/reporting/release actions are
 * Steps 38-44 (mirrors LaboratoryOrderController exactly).
 */
class RadiologyOrderController extends Controller
{
    protected function eagerLoads(): array
    {
        return [
            'items',
            'orderedBy:id,UserName',
            'cancelledBy:id,UserName',
            'clinicalEncounter:id,encounter_number,patient_id,department_id',
            'clinicalEncounter.patient:id,patient_number,first_name,middle_name,last_name',
        ];
    }

    /**
     * The Radiology dashboard: real status-distribution counts (never
     * fabricated categories) plus a short recent-orders preview (Part 30).
     */
    public function index(Request $request, RadiologyOrderSearchService $search): Response
    {
        $this->authorize('viewAny', RadiologyOrder::class);

        $query = $search->query([]);
        $search->scopeToHospital($query, $this->scopedHospitalId($request));

        $statusCounts = (clone $query)->selectRaw('status, count(*) as count')->groupBy('status')->pluck('count', 'status')->map(fn ($count) => (int) $count);

        $recentOrders = (clone $query)->with($this->eagerLoads())->latest('ordered_at')->limit(5)->get()
            ->map(fn (RadiologyOrder $order) => (new RadiologyOrderResource($order))->resolve($request));

        return Inertia::render('radiology/index', [
            'statusCounts' => $statusCounts,
            'statuses' => RadiologyOrder::STATUSES,
            'recentOrders' => $recentOrders,
        ]);
    }

    /**
     * The full paginated, filterable radiology order worklist (Part 31).
     */
    public function orders(Request $request, RadiologyOrderSearchService $search): Response
    {
        $this->authorize('viewAny', RadiologyOrder::class);

        $filters = $request->only(['search', 'status', 'priority', 'modality', 'practitioner_id', 'department_id', 'date_from', 'date_to']);

        $query = $search->query($filters)->with($this->eagerLoads());
        $search->scopeToHospital($query, $this->scopedHospitalId($request));

        $orders = (clone $query)->latest('ordered_at')->paginate(15)->withQueryString()
            ->through(fn (RadiologyOrder $order) => (new RadiologyOrderResource($order))->resolve($request));

        return Inertia::render('radiology/orders/index', [
            'orders' => $orders,
            'filters' => $filters,
            'statuses' => RadiologyOrder::STATUSES,
            'priorities' => RadiologyOrder::PRIORITIES,
            'modalities' => RadiologyProcedure::MODALITIES,
            'departments' => Department::active()->orderBy('name')->get(['id', 'name', 'code']),
            'practitioners' => Practitioner::query()
                ->where('status', Practitioner::STATUS_ACTIVE)
                ->with('staff:id,first_name,last_name')
                ->get(['id', 'staff_id', 'practitioner_number']),
        ]);
    }

    /**
     * Radiology order detail — the minimum context this step implements.
     * Examination/findings/report fields are deliberately absent (Part 32 —
     * "do not create fake result records"); they are Steps 41-43's job.
     */
    public function show(Request $request, RadiologyOrder $radiologyOrder): Response
    {
        $radiologyOrder->load($this->eagerLoads());

        $this->authorize('view', $radiologyOrder);

        return Inertia::render('radiology/show', [
            'order' => (new RadiologyOrderResource($radiologyOrder))->resolve($request),
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
