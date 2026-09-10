<?php

namespace App\Http\Controllers;

use App\Http\Requests\Laboratory\StoreLaboratoryBenchRequest;
use App\Http\Requests\Laboratory\UpdateLaboratoryBenchRequest;
use App\Models\LaboratoryBench;
use App\Services\LaboratoryBenchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * No show/destroy: benches are simple enough to manage entirely from the
 * index page (mirrors LaboratoryTestCategoryController exactly), and are
 * deactivated rather than deleted.
 */
class LaboratoryBenchController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(LaboratoryBench::class, 'laboratoryBench', ['except' => ['show']]);
    }

    /**
     * Display a paginated, searchable, filterable list of benches.
     */
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();
        $status = $request->string('status')->toString();

        $benches = LaboratoryBench::query()
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%");
            }))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('laboratory/benches/index', [
            'benches' => $benches,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    /**
     * Show the form for creating a new bench.
     */
    public function create(): Response
    {
        return Inertia::render('laboratory/benches/create');
    }

    /**
     * Store a newly created bench.
     */
    public function store(StoreLaboratoryBenchRequest $request, LaboratoryBenchService $service): RedirectResponse
    {
        $service->create($request->validated(), $request);

        return to_route('laboratory-benches.index')->with('success', 'Laboratory bench created.');
    }

    /**
     * Show the form for editing the specified bench.
     */
    public function edit(LaboratoryBench $laboratoryBench): Response
    {
        return Inertia::render('laboratory/benches/edit', [
            'bench' => $laboratoryBench,
        ]);
    }

    /**
     * Update the specified bench.
     */
    public function update(UpdateLaboratoryBenchRequest $request, LaboratoryBench $laboratoryBench, LaboratoryBenchService $service): RedirectResponse
    {
        $service->update($laboratoryBench, $request->validated(), $request);

        return to_route('laboratory-benches.index')->with('success', 'Laboratory bench updated.');
    }

    /**
     * Activate the specified bench.
     */
    public function activate(Request $request, LaboratoryBench $laboratoryBench, LaboratoryBenchService $service): RedirectResponse
    {
        $this->authorize('activate', $laboratoryBench);

        $service->activate($laboratoryBench, $request);

        return back()->with('success', 'Bench activated.');
    }

    /**
     * Deactivate the specified bench.
     */
    public function deactivate(Request $request, LaboratoryBench $laboratoryBench, LaboratoryBenchService $service): RedirectResponse
    {
        $this->authorize('deactivate', $laboratoryBench);

        $service->deactivate($laboratoryBench, $request);

        return back()->with('success', 'Bench deactivated.');
    }
}
