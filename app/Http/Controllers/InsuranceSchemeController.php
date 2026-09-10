<?php

namespace App\Http\Controllers;

use App\Http\Requests\InsuranceScheme\StoreInsuranceSchemeRequest;
use App\Http\Requests\InsuranceScheme\UpdateInsuranceSchemeRequest;
use App\Models\InsuranceProvider;
use App\Models\InsuranceScheme;
use App\Services\InsuranceSchemeService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InsuranceSchemeController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(InsuranceScheme::class, 'scheme');
    }

    /**
     * Display a paginated, searchable, filterable list of insurance schemes.
     */
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();

        $schemes = InsuranceScheme::query()
            ->with('insuranceProvider:id,name')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhereHas('insuranceProvider', fn ($query) => $query->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->integer('insurance_provider_id'), fn ($query, $id) => $query->where('insurance_provider_id', $id))
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('insurance/schemes/index', [
            'schemes' => $schemes,
            'filters' => $request->only(['search', 'insurance_provider_id', 'status']),
            'providers' => InsuranceProvider::query()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    /**
     * Show the form for creating a new insurance scheme.
     */
    public function create(): Response
    {
        return Inertia::render('insurance/schemes/create', [
            'providers' => InsuranceProvider::active()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    /**
     * Store a newly created insurance scheme.
     */
    public function store(StoreInsuranceSchemeRequest $request, InsuranceSchemeService $schemes): RedirectResponse
    {
        $schemes->create($request->validated(), $request);

        return to_route('insurance.schemes.index')->with('success', 'Insurance scheme created successfully.');
    }

    /**
     * Display the specified insurance scheme.
     */
    public function show(InsuranceScheme $scheme): Response
    {
        $scheme->load('insuranceProvider:id,name,code');

        return Inertia::render('insurance/schemes/show', [
            'scheme' => $scheme,
        ]);
    }

    /**
     * Show the form for editing the specified insurance scheme.
     */
    public function edit(InsuranceScheme $scheme): Response
    {
        return Inertia::render('insurance/schemes/edit', [
            'scheme' => $scheme,
            'providers' => $this->selectableProviders($scheme->insurance_provider_id),
        ]);
    }

    /**
     * Update the specified insurance scheme.
     */
    public function update(UpdateInsuranceSchemeRequest $request, InsuranceScheme $scheme, InsuranceSchemeService $schemes): RedirectResponse
    {
        $schemes->update($scheme, $request->validated(), $request);

        return to_route('insurance.schemes.index')->with('success', 'Insurance scheme updated successfully.');
    }

    /**
     * Soft-delete the specified insurance scheme.
     */
    public function destroy(Request $request, InsuranceScheme $scheme, InsuranceSchemeService $schemes): RedirectResponse
    {
        try {
            $schemes->delete($scheme, $request);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return to_route('insurance.schemes.index')->with('success', 'Insurance scheme deleted successfully.');
    }

    /**
     * Providers selectable in the scheme form: active providers, plus the
     * scheme's current provider even if it has since become inactive.
     *
     * @return Collection<int, InsuranceProvider>
     */
    protected function selectableProviders(?int $includeProviderId = null): Collection
    {
        return InsuranceProvider::query()
            ->where(function ($query) use ($includeProviderId) {
                $query->where('status', InsuranceProvider::STATUS_ACTIVE);

                if ($includeProviderId) {
                    $query->orWhere('id', $includeProviderId);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }
}
