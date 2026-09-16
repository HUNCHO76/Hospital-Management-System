<?php

namespace App\Http\Controllers;

use App\Http\Requests\Billing\CreateServiceRequest;
use App\Http\Requests\Billing\UpdateServiceRequest;
use App\Http\Resources\ServicePriceResource;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use App\Services\ServiceCatalogSearchService;
use App\Services\ServiceCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The central billing service catalog (Phase 7, Step 53) — mirrors
 * MedicationController exactly: `authorizeResource()` wires index/create/
 * store/show/edit/update to ServicePolicy automatically (no destroy route —
 * a service is deactivated, never deleted). activate()/deactivate()
 * authorize themselves explicitly, the same precedent.
 */
class ServiceCatalogController extends Controller
{
    public function __construct(protected ServiceCatalogSearchService $search)
    {
        $this->authorizeResource(Service::class, 'service');
    }

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'category', 'status', 'is_billable']);

        $services = $this->search->servicesQuery($filters)
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Service $service) => (new ServiceResource($service))->resolve($request));

        return Inertia::render('billing/services/index', [
            'services' => $services,
            'filters' => $filters,
            'categories' => Service::CATEGORIES,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('billing/services/create', [
            'categories' => Service::CATEGORIES,
        ]);
    }

    public function store(CreateServiceRequest $request, ServiceCatalogService $catalog): RedirectResponse
    {
        $service = $catalog->create($request->validated(), $request);

        return to_route('billing-services.show', $service)->with('success', 'Service created.');
    }

    public function show(Request $request, Service $service): Response
    {
        $service->load('createdBy:id,UserName');
        $service->loadCount('prices');

        $prices = $service->prices()->with(['hospital:id,name', 'createdBy:id,UserName'])->orderByDesc('effective_from')->get();

        return Inertia::render('billing/services/show', [
            'service' => (new ServiceResource($service))->resolve($request),
            'prices' => ServicePriceResource::collection($prices)->resolve($request),
            'canCreatePrice' => $request->user()->can('billing-pricing.create'),
        ]);
    }

    public function edit(Service $service): Response
    {
        $service->loadCount('prices');

        return Inertia::render('billing/services/edit', [
            'service' => (new ServiceResource($service))->resolve(request()),
            'categories' => Service::CATEGORIES,
        ]);
    }

    public function update(UpdateServiceRequest $request, Service $service, ServiceCatalogService $catalog): RedirectResponse
    {
        $catalog->update($service, $request->validated(), $request);

        return to_route('billing-services.show', $service)->with('success', 'Service updated.');
    }

    public function activate(Request $request, Service $service, ServiceCatalogService $catalog): RedirectResponse
    {
        $this->authorize('activate', $service);

        $catalog->activate($service, $request);

        return back()->with('success', 'Service activated.');
    }

    public function deactivate(Request $request, Service $service, ServiceCatalogService $catalog): RedirectResponse
    {
        $this->authorize('deactivate', $service);

        $catalog->deactivate($service, $request);

        return back()->with('success', 'Service deactivated.');
    }
}
