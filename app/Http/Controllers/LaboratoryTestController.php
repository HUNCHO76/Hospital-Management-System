<?php

namespace App\Http\Controllers;

use App\Http\Requests\Laboratory\StoreLaboratoryTestRequest;
use App\Http\Requests\Laboratory\UpdateLaboratoryTestRequest;
use App\Models\ClinicalEncounter;
use App\Models\Department;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use App\Models\NhifSchemeService;
use App\Services\LaboratoryTestSearchService;
use App\Services\LaboratoryTestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * authorizeResource() wires index/create/store/show/edit/update to
 * LaboratoryTestPolicy automatically (no destroy route exists — tests are
 * deactivated, never deleted, spec §23). activate()/deactivate()/
 * searchJson() are not standard resource actions, so each authorizes
 * itself explicitly.
 */
class LaboratoryTestController extends Controller
{
    public function __construct(protected LaboratoryTestSearchService $search)
    {
        $this->authorizeResource(LaboratoryTest::class, 'laboratoryTest');
    }

    /**
     * Lightweight server-side laboratory test search consumed by the
     * LaboratoryTestSearch combobox — mirrors MedicationController::searchJson.
     * Never returns the full catalog: a query shorter than 2 characters
     * yields no results, and the result set is capped.
     */
    public function searchJson(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LaboratoryTest::class);

        $search = $request->string('search')->trim()->toString();

        if (mb_strlen($search) < 2) {
            return response()->json(['data' => [], 'nhif_catalog' => []]);
        }

        $nhifSchemeId = $this->resolveNhifSchemeId($request);

        $tests = $this->search->query(['search' => $search, 'status' => LaboratoryTest::STATUS_ACTIVE], $nhifSchemeId)
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'code', 'name', 'nhif_code']);

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

            $mappedTests = LaboratoryTest::where('status', LaboratoryTest::STATUS_ACTIVE)
                ->whereNotNull('nhif_code')
                ->whereIn('nhif_code', $catalogItems->pluck('item_code'))
                ->get(['id', 'code', 'name', 'nhif_code'])
                ->keyBy('nhif_code');

            $nhifCatalog = $catalogItems->map(function ($item) use ($mappedTests) {
                $localTest = $mappedTests->get($item->item_code);

                return [
                    'item_code' => $item->item_code,
                    'item_name' => $item->item_name,
                    'local_test' => $localTest ? [
                        'id' => $localTest->id,
                        'code' => $localTest->code,
                        'name' => $localTest->name,
                    ] : null,
                ];
            })->values();
        }

        return response()->json([
            'data' => $tests,
            'nhif_catalog' => $nhifCatalog,
            'nhif_filtered' => $nhifSchemeId !== null,
        ]);
    }

    /**
     * Resolve an NHIF catalog item_code to a local LaboratoryTest. If a
     * test with that nhif_code already exists it is returned; otherwise a
     * new active test is created from the catalog item's name and code.
     */
    public function resolveNhifItem(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LaboratoryTest::class);

        $request->validate([
            'item_code' => ['required', 'string', 'max:50'],
            'item_name' => ['required', 'string', 'max:255'],
        ]);

        $itemCode = $request->input('item_code');
        $itemName = $request->input('item_name');

        $test = LaboratoryTest::where('nhif_code', $itemCode)->first();

        if (! $test) {
            $test = LaboratoryTest::create([
                'code' => 'NHIF-'.$itemCode,
                'name' => $itemName,
                'nhif_code' => $itemCode,
                'status' => LaboratoryTest::STATUS_ACTIVE,
                'result_type' => 'text',
                'default_priority' => 'routine',
            ]);
        }

        return response()->json([
            'id' => $test->id,
            'code' => $test->code,
            'name' => $test->name,
        ]);
    }

    /**
     * Display a paginated, searchable, filterable laboratory test catalog.
     */
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'category_id', 'department_id', 'specimen_type', 'result_type', 'status']);

        $tests = $this->search->query($filters)
            ->with(['category:id,name,code', 'department:id,name,code'])
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('laboratory/tests/index', [
            'tests' => $tests,
            'filters' => $filters,
            'categories' => LaboratoryTestCategory::active()->orderBy('name')->get(['id', 'name', 'code']),
            'specimenTypes' => LaboratoryTest::SPECIMEN_TYPES,
            'resultTypes' => LaboratoryTest::RESULT_TYPES,
        ]);
    }

    /**
     * Show the form for creating a new laboratory test.
     */
    public function create(): Response
    {
        return Inertia::render('laboratory/tests/create', $this->formOptions());
    }

    /**
     * Store a newly created laboratory test.
     */
    public function store(StoreLaboratoryTestRequest $request, LaboratoryTestService $service): RedirectResponse
    {
        $test = $service->create($request->validated(), $request);

        return to_route('laboratory-tests.show', $test)->with('success', 'Laboratory test created.');
    }

    /**
     * Display the specified laboratory test.
     */
    public function show(LaboratoryTest $laboratoryTest): Response
    {
        $laboratoryTest->load([
            'category:id,name,code',
            'department:id,name,code',
            'createdBy:id,UserName',
            'updatedBy:id,UserName',
            'parameters' => fn ($q) => $q->orderBy('display_order'),
            'parameters.referenceRanges',
        ]);

        return Inertia::render('laboratory/tests/show', [
            'test' => $laboratoryTest,
            'isReferenced' => $laboratoryTest->isReferenced(),
        ]);
    }

    /**
     * Show the form for editing the specified laboratory test.
     */
    public function edit(LaboratoryTest $laboratoryTest): Response
    {
        return Inertia::render('laboratory/tests/edit', [
            'test' => $laboratoryTest,
            'isReferenced' => $laboratoryTest->isReferenced(),
            ...$this->formOptions(),
        ]);
    }

    /**
     * Update the specified laboratory test.
     */
    public function update(UpdateLaboratoryTestRequest $request, LaboratoryTest $laboratoryTest, LaboratoryTestService $service): RedirectResponse
    {
        $service->update($laboratoryTest, $request->validated(), $request);

        return to_route('laboratory-tests.show', $laboratoryTest)->with('success', 'Laboratory test updated.');
    }

    /**
     * Activate the specified laboratory test.
     */
    public function activate(Request $request, LaboratoryTest $laboratoryTest, LaboratoryTestService $service): RedirectResponse
    {
        $this->authorize('activate', $laboratoryTest);

        $service->activate($laboratoryTest, $request);

        return back()->with('success', 'Laboratory test activated.');
    }

    /**
     * Deactivate the specified laboratory test.
     */
    public function deactivate(Request $request, LaboratoryTest $laboratoryTest, LaboratoryTestService $service): RedirectResponse
    {
        $this->authorize('deactivate', $laboratoryTest);

        $service->deactivate($laboratoryTest, $request);

        return back()->with('success', 'Laboratory test deactivated.');
    }

    /**
     * Resolve the NHIF scheme ID from an optional clinical_encounter_id param.
     * Returns null if no encounter is provided or the patient has no active NHIF.
     * Only the server-side query param is trusted — never a raw scheme_id from
     * the client.
     */
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

        $insurance = $encounter->patient?->activeNhifInsurance();

        return $insurance?->nhif_scheme_id ? (int) $insurance->nhif_scheme_id : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function formOptions(): array
    {
        return [
            'categories' => LaboratoryTestCategory::active()->orderBy('name')->get(['id', 'name', 'code']),
            'departments' => Department::active()->orderBy('name')->get(['id', 'name', 'code']),
            'specimenTypes' => LaboratoryTest::SPECIMEN_TYPES,
            'specimenContainers' => LaboratoryTest::SPECIMEN_CONTAINERS,
            'resultTypes' => LaboratoryTest::RESULT_TYPES,
        ];
    }
}
