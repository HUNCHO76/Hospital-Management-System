<?php

namespace App\Http\Controllers;

use App\Http\Requests\InsuranceProvider\StoreInsuranceProviderRequest;
use App\Http\Requests\InsuranceProvider\UpdateInsuranceProviderRequest;
use App\Models\InsuranceProvider;
use App\Services\InsuranceProviderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InsuranceProviderController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(InsuranceProvider::class, 'provider');
    }

    /**
     * Display a paginated, searchable, filterable list of insurance providers.
     */
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();

        $providers = InsuranceProvider::query()
            ->withCount('schemes')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('registration_number', 'like', "%{$search}%");
                });
            })
            ->when($request->string('type')->toString(), fn ($query, $type) => $query->where('type', $type))
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('insurance/providers/index', [
            'providers' => $providers,
            'filters' => $request->only(['search', 'type', 'status']),
            'types' => InsuranceProvider::TYPES,
        ]);
    }

    /**
     * Show the form for creating a new insurance provider.
     */
    public function create(): Response
    {
        return Inertia::render('insurance/providers/create', [
            'types' => InsuranceProvider::TYPES,
        ]);
    }

    /**
     * Store a newly created insurance provider.
     */
    public function store(StoreInsuranceProviderRequest $request, InsuranceProviderService $providers): RedirectResponse
    {
        $providers->create($request->validated(), $request);

        return to_route('insurance.providers.index')->with('success', 'Insurance provider created successfully.');
    }

    /**
     * Display the specified insurance provider, including its schemes.
     */
    public function show(InsuranceProvider $provider): Response
    {
        $provider->load(['schemes' => fn ($query) => $query->orderBy('name')]);

        return Inertia::render('insurance/providers/show', [
            'provider' => $provider,
        ]);
    }

    /**
     * Show the form for editing the specified insurance provider.
     */
    public function edit(InsuranceProvider $provider): Response
    {
        return Inertia::render('insurance/providers/edit', [
            'provider' => $provider,
            'types' => InsuranceProvider::TYPES,
        ]);
    }

    /**
     * Update the specified insurance provider.
     */
    public function update(UpdateInsuranceProviderRequest $request, InsuranceProvider $provider, InsuranceProviderService $providers): RedirectResponse
    {
        $providers->update($provider, $request->validated(), $request);

        return to_route('insurance.providers.index')->with('success', 'Insurance provider updated successfully.');
    }

    /**
     * Soft-delete the specified insurance provider.
     */
    public function destroy(Request $request, InsuranceProvider $provider, InsuranceProviderService $providers): RedirectResponse
    {
        try {
            $providers->delete($provider, $request);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return to_route('insurance.providers.index')->with('success', 'Insurance provider deleted successfully.');
    }
}
