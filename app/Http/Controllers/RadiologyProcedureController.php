<?php

namespace App\Http\Controllers;

use App\Http\Requests\Radiology\StoreRadiologyProcedureRequest;
use App\Http\Requests\Radiology\UpdateRadiologyProcedureRequest;
use App\Models\Department;
use App\Models\RadiologyProcedure;
use App\Services\RadiologyProcedureSearchService;
use App\Services\RadiologyProcedureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The authoritative radiology procedure catalog (Phase 5, Step 37) —
 * mirrors LaboratoryTestController exactly. authorizeResource() wires
 * index/create/store/show/edit/update to RadiologyProcedurePolicy
 * automatically (no destroy route exists — procedures are deactivated,
 * never deleted, Part 27). activate()/deactivate()/searchJson() are not
 * standard resource actions, so each authorizes itself explicitly.
 */
class RadiologyProcedureController extends Controller
{
    public function __construct(protected RadiologyProcedureSearchService $search)
    {
        $this->authorizeResource(RadiologyProcedure::class, 'radiologyProcedure');
    }

    /**
     * Lightweight server-side radiology procedure search consumed by the
     * RadiologyProcedureSearch combobox — mirrors
     * LaboratoryTestController::searchJson. Never returns the full
     * catalog: a query shorter than 2 characters yields no results, and
     * the result set is capped. Inactive procedures are never returned —
     * they must not be newly selectable for an order (Part 23).
     */
    public function searchJson(Request $request): JsonResponse
    {
        $this->authorize('viewAny', RadiologyProcedure::class);

        $search = $request->string('search')->trim()->toString();

        if (mb_strlen($search) < 2) {
            return response()->json(['data' => []]);
        }

        $procedures = $this->search->query(['search' => $search, 'status' => RadiologyProcedure::STATUS_ACTIVE])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'code', 'name', 'modality', 'preparation_type']);

        return response()->json(['data' => $procedures]);
    }

    /**
     * Display a paginated, searchable, filterable radiology procedure
     * catalog.
     */
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'modality', 'department_id', 'status']);

        $procedures = $this->search->query($filters)
            ->with(['department:id,name,code'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('radiology/catalog/index', [
            'procedures' => $procedures,
            'filters' => $filters,
            'modalities' => RadiologyProcedure::MODALITIES,
        ]);
    }

    /**
     * Show the form for creating a new radiology procedure.
     */
    public function create(): Response
    {
        return Inertia::render('radiology/catalog/create', $this->formOptions());
    }

    /**
     * Store a newly created radiology procedure.
     */
    public function store(StoreRadiologyProcedureRequest $request, RadiologyProcedureService $service): RedirectResponse
    {
        $procedure = $service->create($request->validated(), $request);

        return to_route('radiology-catalog.show', $procedure)->with('success', 'Radiology procedure created.');
    }

    /**
     * Display the specified radiology procedure.
     */
    public function show(RadiologyProcedure $radiologyProcedure): Response
    {
        $radiologyProcedure->load(['department:id,name,code', 'createdBy:id,UserName', 'updatedBy:id,UserName']);

        return Inertia::render('radiology/catalog/show', [
            'procedure' => $radiologyProcedure,
            'isReferenced' => $radiologyProcedure->isReferenced(),
        ]);
    }

    /**
     * Show the form for editing the specified radiology procedure.
     */
    public function edit(RadiologyProcedure $radiologyProcedure): Response
    {
        return Inertia::render('radiology/catalog/edit', [
            'procedure' => $radiologyProcedure,
            'isReferenced' => $radiologyProcedure->isReferenced(),
            ...$this->formOptions(),
        ]);
    }

    /**
     * Update the specified radiology procedure.
     */
    public function update(UpdateRadiologyProcedureRequest $request, RadiologyProcedure $radiologyProcedure, RadiologyProcedureService $service): RedirectResponse
    {
        $service->update($radiologyProcedure, $request->validated(), $request);

        return to_route('radiology-catalog.show', $radiologyProcedure)->with('success', 'Radiology procedure updated.');
    }

    /**
     * Activate the specified radiology procedure.
     */
    public function activate(Request $request, RadiologyProcedure $radiologyProcedure, RadiologyProcedureService $service): RedirectResponse
    {
        $this->authorize('activate', $radiologyProcedure);

        $service->activate($radiologyProcedure, $request);

        return back()->with('success', 'Radiology procedure activated.');
    }

    /**
     * Deactivate the specified radiology procedure.
     */
    public function deactivate(Request $request, RadiologyProcedure $radiologyProcedure, RadiologyProcedureService $service): RedirectResponse
    {
        $this->authorize('deactivate', $radiologyProcedure);

        $service->deactivate($radiologyProcedure, $request);

        return back()->with('success', 'Radiology procedure deactivated.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function formOptions(): array
    {
        return [
            'departments' => Department::active()->orderBy('name')->get(['id', 'name', 'code']),
            'modalities' => RadiologyProcedure::MODALITIES,
            'bodySites' => RadiologyProcedure::BODY_SITES,
            'lateralityOptions' => RadiologyProcedure::LATERALITY_OPTIONS,
            'contrastRequirements' => RadiologyProcedure::CONTRAST_REQUIREMENTS,
            'contrastTypes' => RadiologyProcedure::CONTRAST_TYPES,
            'preparationTypes' => RadiologyProcedure::PREPARATION_TYPES,
        ];
    }
}
