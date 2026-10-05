<?php

namespace App\Http\Controllers;

use App\Http\Requests\Medication\StoreMedicationRequest;
use App\Http\Requests\Medication\UpdateMedicationRequest;
use App\Models\ClinicalEncounter;
use App\Models\Medication;
use App\Models\NhifSchemeService;
use App\Services\MedicationSearchService;
use App\Services\MedicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The authoritative medication catalog (Phase 6, Step 45) — mirrors
 * LaboratoryTestController/RadiologyProcedureController exactly.
 * authorizeResource() wires index/create/store/show/edit/update to
 * MedicationPolicy automatically (no destroy route exists — medications are
 * deactivated, never deleted, spec §11/§26). activate()/deactivate()/
 * searchJson() are not standard resource actions, so each authorizes itself
 * explicitly.
 */
class MedicationController extends Controller
{
    public function __construct(protected MedicationSearchService $search)
    {
        $this->authorizeResource(Medication::class, 'medication');
    }

    /**
     * Lightweight server-side medication search consumed by the
     * MedicationSearch combobox — mirrors LaboratoryTestController::
     * searchJson. Never returns the full catalog: a query shorter than 2
     * characters yields no results, and the result set is capped. Inactive
     * medications are never returned — they must not be newly selectable
     * for a prescription (spec §11/§17).
     */
    public function searchJson(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Medication::class);

        $search = $request->string('search')->trim()->toString();

        if (mb_strlen($search) < 2) {
            return response()->json(['data' => [], 'nhif_catalog' => []]);
        }

        $nhifSchemeId = $this->resolveNhifSchemeId($request);

        $medications = $this->search->query(['search' => $search, 'status' => Medication::STATUS_ACTIVE], $nhifSchemeId)
            ->orderBy('generic_name')
            ->limit(10)
            ->get(['id', 'code', 'generic_name', 'brand_name', 'strength', 'form', 'nhif_code']);

        $nhifCatalog = [];

        if ($nhifSchemeId !== null) {
            $catalogItems = NhifSchemeService::catalog()
                ->where('type_name', 'Medicine and Consumables')
                ->where('scheme_id', $nhifSchemeId)
                ->where(function ($q) use ($search) {
                    $q->where('item_name', 'like', "%{$search}%")
                        ->orWhere('item_code', 'like', "%{$search}%");
                })
                ->selectRaw('DISTINCT item_code, item_name')
                ->orderBy('item_name')
                ->limit(15)
                ->get();

            $mappedMedications = Medication::where('status', Medication::STATUS_ACTIVE)
                ->whereNotNull('nhif_code')
                ->whereIn('nhif_code', $catalogItems->pluck('item_code'))
                ->get(['id', 'code', 'generic_name', 'nhif_code'])
                ->keyBy('nhif_code');

            $nhifCatalog = $catalogItems->map(function ($item) use ($mappedMedications) {
                $localMed = $mappedMedications->get($item->item_code);

                return [
                    'item_code' => $item->item_code,
                    'item_name' => $item->item_name,
                    'local_medication' => $localMed ? [
                        'id' => $localMed->id,
                        'code' => $localMed->code,
                        'generic_name' => $localMed->generic_name,
                    ] : null,
                ];
            })->values();
        }

        return response()->json([
            'data' => $medications,
            'nhif_catalog' => $nhifCatalog,
            'nhif_filtered' => $nhifSchemeId !== null,
        ]);
    }

    /**
     * Display a paginated, searchable, filterable medication catalog.
     */
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'category', 'form', 'route', 'status']);

        $medications = $this->search->query($filters)
            ->orderBy('generic_name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('pharmacy/medications/index', [
            'medications' => $medications,
            'filters' => $filters,
            'categories' => Medication::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'forms' => Medication::FORMS,
            'routes' => Medication::ROUTES,
        ]);
    }

    /**
     * Show the form for creating a new medication.
     */
    public function create(): Response
    {
        return Inertia::render('pharmacy/medications/create', $this->formOptions());
    }

    /**
     * Store a newly created medication.
     */
    public function store(StoreMedicationRequest $request, MedicationService $service): RedirectResponse
    {
        $medication = $service->create($request->validated(), $request);

        return to_route('medications.show', $medication)->with('success', 'Medication created.');
    }

    /**
     * Display the specified medication.
     */
    public function show(Medication $medication): Response
    {
        $medication->load(['createdBy:id,UserName', 'updatedBy:id,UserName']);

        return Inertia::render('pharmacy/medications/show', [
            'medication' => $medication,
            'isReferenced' => $medication->isReferenced(),
        ]);
    }

    /**
     * Show the form for editing the specified medication.
     */
    public function edit(Medication $medication): Response
    {
        return Inertia::render('pharmacy/medications/edit', [
            'medication' => $medication,
            'isReferenced' => $medication->isReferenced(),
            ...$this->formOptions(),
        ]);
    }

    /**
     * Update the specified medication.
     */
    public function update(UpdateMedicationRequest $request, Medication $medication, MedicationService $service): RedirectResponse
    {
        $service->update($medication, $request->validated(), $request);

        return to_route('medications.show', $medication)->with('success', 'Medication updated.');
    }

    /**
     * Activate the specified medication.
     */
    public function activate(Request $request, Medication $medication, MedicationService $service): RedirectResponse
    {
        $this->authorize('activate', $medication);

        $service->activate($medication, $request);

        return back()->with('success', 'Medication activated.');
    }

    /**
     * Deactivate the specified medication.
     */
    public function deactivate(Request $request, Medication $medication, MedicationService $service): RedirectResponse
    {
        $this->authorize('deactivate', $medication);

        $service->deactivate($medication, $request);

        return back()->with('success', 'Medication deactivated.');
    }

    /**
     * Resolve an NHIF catalog item_code to a local Medication. If a
     * medication with that nhif_code already exists it is returned; otherwise
     * a new active medication is created from the catalog item's name and code.
     */
    public function resolveNhifItem(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Medication::class);

        $request->validate([
            'item_code' => ['required', 'string', 'max:50'],
            'item_name' => ['required', 'string', 'max:255'],
        ]);

        $itemCode = $request->input('item_code');
        $itemName = $request->input('item_name');

        $medication = Medication::where('nhif_code', $itemCode)->first();

        if (! $medication) {
            $medication = Medication::create([
                'code' => 'NHIF-'.$itemCode,
                'generic_name' => $itemName,
                'nhif_code' => $itemCode,
                'status' => Medication::STATUS_ACTIVE,
            ]);
        }

        return response()->json([
            'id' => $medication->id,
            'code' => $medication->code,
            'generic_name' => $medication->generic_name,
            'brand_name' => $medication->brand_name,
            'strength' => $medication->strength,
            'form' => $medication->form,
        ]);
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
            'forms' => Medication::FORMS,
            'routes' => Medication::ROUTES,
        ];
    }
}
