<?php

namespace App\Http\Controllers;

use App\Http\Requests\Billing\CreateServiceRequest;
use App\Http\Requests\Billing\UpdateServiceRequest;
use App\Http\Resources\ServicePriceResource;
use App\Http\Resources\ServiceResource;
use App\Models\Hospital;
use App\Models\Practitioner;
use App\Models\Service;
use App\Models\ServicePrice;
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

        $prices = $service->prices()
            ->with([
                'hospital:id,name',
                'createdBy:id,UserName',
                'practitioner:id,staff_id,practitioner_number',
                'practitioner.staff:id,first_name,last_name',
            ])
            ->orderByDesc('effective_from')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (ServicePrice $price) => (new ServicePriceResource($price))->resolve($request));

        return Inertia::render('billing/services/show', [
            'service' => (new ServiceResource($service))->resolve($request),
            'prices' => $prices,
            'canCreatePrice' => $request->user()->can('billing-pricing.create'),
        ]);
    }

    public function edit(Request $request, Service $service): Response
    {
        $service->loadCount('prices');
        $service->load(['prices' => fn ($query) => $query
            ->where('status', ServicePrice::STATUS_ACTIVE)
            ->with(['hospital:id,name', 'practitioner:id,staff_id,practitioner_number', 'practitioner.staff:id,first_name,last_name'])
            ->orderByDesc('effective_from'),
        ]);

        $hospitals = $request->user()->hasRole('hospital-admin')
            ? Hospital::query()->whereKey($this->scopedHospitalId($request))->select('id', 'name')->get()
            : Hospital::query()->where('status', Hospital::STATUS_ACTIVE)->select('id', 'name')->orderBy('name')->get();

        $practitioners = Practitioner::query()->where('status', Practitioner::STATUS_ACTIVE)
            ->with('staff:id,first_name,last_name')
            ->orderBy('practitioner_number')
            ->get(['id', 'practitioner_number', 'staff_id']);

        return Inertia::render('billing/services/edit', [
            'service' => (new ServiceResource($service))->resolve($request),
            'categories' => Service::CATEGORIES,
            'currentPrices' => ServicePriceResource::collection($service->prices)->resolve($request),
            'hospitals' => $hospitals,
            'practitioners' => $practitioners,
            'canCreatePrice' => $request->user()->can('billing-pricing.create'),
            'canUpdatePrice' => $request->user()->can('billing-pricing.update'),
            'canCancelPrice' => $request->user()->can('billing-pricing.deactivate'),
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
