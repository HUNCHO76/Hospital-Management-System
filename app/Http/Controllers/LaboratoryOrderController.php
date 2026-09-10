<?php

namespace App\Http\Controllers;

use App\Http\Resources\LaboratoryOrderResource;
use App\Models\Department;
use App\Models\LaboratoryOrder;
use App\Models\Practitioner;
use App\Services\LaboratoryOrderSearchService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Laboratory dashboard/worklist/detail — the standalone, cross-encounter
 * surface laboratory staff use (spec §22-29). No store/update/cancel actions
 * here: order creation, editing, and cancellation all flow through
 * EncounterLaboratoryOrderController, since Laboratory is its own branch off
 * the ClinicalEncounter (Phase 4, Step 30), not a CarePlanItem. This step
 * implements read-only foundation only; sample/processing/result actions
 * are Steps 31-34.
 */
class LaboratoryOrderController extends Controller
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
     * The Laboratory dashboard: real status-distribution counts (never
     * fabricated categories) plus quick links and a short recent-orders
     * preview. The full filterable worklist lives on orders() (spec §33 —
     * Dashboard/Orders/Tests are three distinct nav items).
     */
    public function index(Request $request, LaboratoryOrderSearchService $search): Response
    {
        $this->authorize('viewAny', LaboratoryOrder::class);

        $query = $search->query([]);
        $search->scopeToHospital($query, $this->scopedHospitalId($request));

        $statusCounts = (clone $query)->selectRaw('status, count(*) as count')->groupBy('status')->pluck('count', 'status')->map(fn ($count) => (int) $count);

        $recentOrders = (clone $query)->with($this->eagerLoads())->latest('ordered_at')->limit(5)->get()
            ->map(fn (LaboratoryOrder $order) => (new LaboratoryOrderResource($order))->resolve($request));

        return Inertia::render('laboratory/index', [
            'statusCounts' => $statusCounts,
            'statuses' => LaboratoryOrder::STATUSES,
            'recentOrders' => $recentOrders,
        ]);
    }

    /**
     * The full paginated, filterable laboratory order worklist (spec
     * §28-29).
     */
    public function orders(Request $request, LaboratoryOrderSearchService $search): Response
    {
        $this->authorize('viewAny', LaboratoryOrder::class);

        $filters = $request->only(['search', 'status', 'priority', 'practitioner_id', 'department_id', 'date_from', 'date_to']);

        $query = $search->query($filters)->with($this->eagerLoads());
        $search->scopeToHospital($query, $this->scopedHospitalId($request));

        $orders = (clone $query)->latest('ordered_at')->paginate(15)->withQueryString()
            ->through(fn (LaboratoryOrder $order) => (new LaboratoryOrderResource($order))->resolve($request));

        return Inertia::render('laboratory/orders/index', [
            'orders' => $orders,
            'filters' => $filters,
            'statuses' => LaboratoryOrder::STATUSES,
            'priorities' => LaboratoryOrder::PRIORITIES,
            'departments' => Department::active()->orderBy('name')->get(['id', 'name', 'code']),
            'practitioners' => Practitioner::query()
                ->where('status', Practitioner::STATUS_ACTIVE)
                ->with('staff:id,first_name,last_name')
                ->get(['id', 'staff_id', 'practitioner_number']),
        ]);
    }

    /**
     * Laboratory order detail — the minimum context spec §25/39 asks for.
     */
    public function show(Request $request, LaboratoryOrder $laboratoryOrder): Response
    {
        $laboratoryOrder->load($this->eagerLoads());

        $this->authorize('view', $laboratoryOrder);

        return Inertia::render('laboratory/show', [
            'order' => (new LaboratoryOrderResource($laboratoryOrder))->resolve($request),
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
