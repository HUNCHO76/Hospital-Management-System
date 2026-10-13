<?php

namespace App\Http\Controllers;

use App\Http\Requests\Billing\CancelServicePriceRequest;
use App\Http\Requests\Billing\CreateServicePriceRequest;
use App\Http\Requests\Billing\UpdateServicePriceRequest;
use App\Http\Resources\ServicePriceResource;
use App\Models\Hospital;
use App\Models\Practitioner;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Services\ServiceCatalogSearchService;
use App\Services\ServicePricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Facility-specific, effective-dated service pricing (Phase 7, Step 53) —
 * kept thin: every query lives in ServiceCatalogSearchService, every write
 * in ServicePricingService, mirroring StockController's own shape.
 */
class ServicePricingController extends Controller
{
    public function index(Request $request, ServiceCatalogSearchService $search): Response
    {
        $this->authorize('viewAny', ServicePrice::class);

        $filters = $request->only(['search', 'service_id', 'hospital_id', 'status']);

        $query = $search->pricesQuery($filters);
        $search->scopePricesToHospital($query, $this->scopedHospitalId($request));

        $prices = $query->paginate(20)->withQueryString()
            ->through(fn (ServicePrice $price) => (new ServicePriceResource($price))->resolve($request));

        return Inertia::render('billing/pricing/index', [
            'prices' => $prices,
            'filters' => $filters,
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->can('billing-pricing.create'), 403);

        $hospitals = $request->user()->hasRole('hospital-admin')
            ? Hospital::query()->whereKey($this->scopedHospitalId($request))->select('id', 'name')->get()
            : Hospital::query()->where('status', Hospital::STATUS_ACTIVE)->select('id', 'name')->orderBy('name')->get();

        $services = Service::query()->where('status', Service::STATUS_ACTIVE)->where('is_billable', true)
            ->orderBy('name')->get(['id', 'code', 'name']);

        $practitioners = Practitioner::query()->where('status', Practitioner::STATUS_ACTIVE)
            ->with('staff:id,first_name,last_name')
            ->orderBy('practitioner_number')
            ->get(['id', 'practitioner_number', 'staff_id']);

        return Inertia::render('billing/pricing/create', [
            'hospitals' => $hospitals,
            'services' => $services,
            'practitioners' => $practitioners,
            'initialServiceId' => $request->integer('service_id') ?: null,
        ]);
    }

    public function store(CreateServicePriceRequest $request, ServicePricingService $pricing): RedirectResponse
    {
        $data = $request->validated();

        $this->authorize('createInto', [ServicePrice::class, (int) $data['hospital_id']]);

        $price = $pricing->create($data, $request);

        return to_route('billing-services.show', $price->service_id)->with('success', 'Price recorded.');
    }

    public function update(UpdateServicePriceRequest $request, ServicePrice $servicePrice, ServicePricingService $pricing): RedirectResponse
    {
        $this->authorize('update', $servicePrice);

        $data = $request->validated();

        $pricing->update($servicePrice, $data['amount'], $data['reason'], $request);

        return back()->with('success', 'Price updated.');
    }

    public function cancel(CancelServicePriceRequest $request, ServicePrice $servicePrice, ServicePricingService $pricing): RedirectResponse
    {
        $this->authorize('cancel', $servicePrice);

        $pricing->cancel($servicePrice, $request->validated()['reason'], $request);

        return back()->with('success', 'Price cancelled.');
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
