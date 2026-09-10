<?php

namespace App\Http\Controllers;

use App\Http\Requests\Laboratory\StoreLaboratoryTestCategoryRequest;
use App\Http\Requests\Laboratory\UpdateLaboratoryTestCategoryRequest;
use App\Models\LaboratoryTestCategory;
use App\Services\LaboratoryTestCategoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * No show/destroy: categories are simple enough to manage entirely from the
 * index page (mirrors how DiagnosisCode/Medication need no detail page
 * either), and are deactivated rather than deleted (spec §23/§36).
 */
class LaboratoryTestCategoryController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(LaboratoryTestCategory::class, 'laboratoryTestCategory', ['except' => ['show']]);
    }

    /**
     * Display a paginated, searchable, filterable list of categories.
     */
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();
        $status = $request->string('status')->toString();

        $categories = LaboratoryTestCategory::query()
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%");
            }))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('laboratory/categories/index', [
            'categories' => $categories,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    /**
     * Show the form for creating a new category.
     */
    public function create(): Response
    {
        return Inertia::render('laboratory/categories/create');
    }

    /**
     * Store a newly created category.
     */
    public function store(StoreLaboratoryTestCategoryRequest $request, LaboratoryTestCategoryService $service): RedirectResponse
    {
        $service->create($request->validated(), $request);

        return to_route('laboratory-categories.index')->with('success', 'Laboratory test category created.');
    }

    /**
     * Show the form for editing the specified category.
     */
    public function edit(LaboratoryTestCategory $laboratoryTestCategory): Response
    {
        return Inertia::render('laboratory/categories/edit', [
            'category' => $laboratoryTestCategory,
        ]);
    }

    /**
     * Update the specified category.
     */
    public function update(UpdateLaboratoryTestCategoryRequest $request, LaboratoryTestCategory $laboratoryTestCategory, LaboratoryTestCategoryService $service): RedirectResponse
    {
        $service->update($laboratoryTestCategory, $request->validated(), $request);

        return to_route('laboratory-categories.index')->with('success', 'Laboratory test category updated.');
    }

    /**
     * Activate the specified category.
     */
    public function activate(Request $request, LaboratoryTestCategory $laboratoryTestCategory, LaboratoryTestCategoryService $service): RedirectResponse
    {
        $this->authorize('activate', $laboratoryTestCategory);

        $service->activate($laboratoryTestCategory, $request);

        return back()->with('success', 'Category activated.');
    }

    /**
     * Deactivate the specified category.
     */
    public function deactivate(Request $request, LaboratoryTestCategory $laboratoryTestCategory, LaboratoryTestCategoryService $service): RedirectResponse
    {
        $this->authorize('deactivate', $laboratoryTestCategory);

        $service->deactivate($laboratoryTestCategory, $request);

        return back()->with('success', 'Category deactivated.');
    }
}
