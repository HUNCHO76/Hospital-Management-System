<?php

namespace App\Http\Controllers;

use App\Http\Requests\Radiology\StoreRadiologyProcedureRequest;
use App\Http\Requests\Radiology\UpdateRadiologyProcedureRequest;
use App\Models\ClinicalEncounter;
use App\Models\Department;
use App\Models\NhifSchemeService;
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
            return response()->json(['data' => [], 'nhif_catalog' => []]);
        }

        $nhifSchemeId = $this->resolveNhifSchemeId($request);

        $procedures = $this->search->query(['search' => $search, 'status' => RadiologyProcedure::STATUS_ACTIVE])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'code', 'name', 'modality', 'preparation_type', 'nhif_code']);

        $nhifCatalog = [];

        if ($nhifSchemeId !== null) {
            $catalogItems = NhifSchemeService::catalog()
                ->where('item_type_id', 5)
                ->where('scheme_id', $nhifSchemeId)
                ->where(function ($q) use ($search) {
                    $q->where('item_name', 'like', "%{$search}%")
                        ->orWhere('item_code', 'like', "%{$search}%");
                })
                ->selectRaw('DISTINCT item_code, item_name')
                ->orderBy('item_name')
                ->limit(15)
                ->get();

            $mappedProcedures = RadiologyProcedure::where('status', RadiologyProcedure::STATUS_ACTIVE)
                ->whereNotNull('nhif_code')
                ->whereIn('nhif_code', $catalogItems->pluck('item_code'))
                ->get(['id', 'code', 'name', 'modality', 'nhif_code'])
                ->keyBy('nhif_code');

            $nhifCatalog = $catalogItems->map(function ($item) use ($mappedProcedures) {
                $localProc = $mappedProcedures->get($item->item_code);

                return [
                    'item_code' => $item->item_code,
                    'item_name' => $item->item_name,
                    'local_procedure' => $localProc ? [
                        'id' => $localProc->id,
                        'code' => $localProc->code,
                        'name' => $localProc->name,
                        'modality' => $localProc->modality,
                    ] : null,
                ];
            })->values();
        }

        return response()->json([
            'data' => $procedures,
            'nhif_catalog' => $nhifCatalog,
            'nhif_filtered' => $nhifSchemeId !== null,
        ]);
    }

    /**
     * Resolve an NHIF catalog item_code to a local RadiologyProcedure. If a
     * procedure with that nhif_code already exists it is returned; otherwise a
     * new active procedure is created from the catalog item's name and code.
     */
    public function resolveNhifItem(Request $request): JsonResponse
    {
        $this->authorize('viewAny', RadiologyProcedure::class);

        $request->validate([
            'item_code' => ['required', 'string', 'max:50'],
            'item_name' => ['required', 'string', 'max:255'],
        ]);

        $itemCode = $request->input('item_code');
        $itemName = $request->input('item_name');

        $procedure = RadiologyProcedure::where('nhif_code', $itemCode)->first();

        if (! $procedure) {
            $modality = 'xray';
            $upperName = strtoupper($itemName);
            if (str_contains($upperName, 'USS') || str_contains($upperName, 'ULTRASOUND') || str_contains($upperName, 'SONO')) {
                $modality = 'ultrasound';
            } elseif (str_contains($upperName, 'CT') || str_contains($upperName, 'CAT')) {
                $modality = 'ct';
            } elseif (str_contains($upperName, 'MRI') || str_contains($upperName, 'RESONANCE')) {
                $modality = 'mri';
            }

            $procedure = RadiologyProcedure::create([
                'code' => 'NHIF-'.$itemCode,
                'name' => $itemName,
                'nhif_code' => $itemCode,
                'status' => RadiologyProcedure::STATUS_ACTIVE,
                'modality' => $modality,
                'preparation_type' => 'none',
                'standard_duration_minutes' => 30,
            ]);
        }

        return response()->json([
            'id' => $procedure->id,
            'code' => $procedure->code,
            'name' => $procedure->name,
            'modality' => $procedure->modality,
        ]);
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

    private function resolveNhifSchemeId(Request $request): ?int
    {
        $encounterId = $request->query('clinical_encounter_id');
        if (! $encounterId) {
            return null;
        }

        $encounter = ClinicalEncounter::find((int) $encounterId);
        if (! $encounter) {
            return null;
        }

        $insurance = $encounter->patient?->currentNhifInsurance();

        return $insurance?->nhif_scheme_id ? (int) $insurance->nhif_scheme_id : null;
    }
}
