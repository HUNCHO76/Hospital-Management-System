<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Procurement\VendorRequest;
use App\Models\Hospital;
use App\Models\Vendor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VendorController extends Controller
{
    protected function getHospitalId(Request $request): int
    {
        return $request->integer('hospital_id')
            ?: ($request->user()->staff?->hospital_id ?? Hospital::query()->value('id') ?? 1);
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('procurement-vendors.view'), 403);

        $hospitalId = $this->getHospitalId($request);
        $search = $request->string('search')->toString();
        $status = $request->string('status')->toString();

        $query = Vendor::where('hospital_id', $hospitalId);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        $vendors = $query->orderBy('name')->paginate(15)->withQueryString();

        return Inertia::render('procurement/vendors/index', [
            'vendors' => $vendors,
            'filters' => [
                'search' => $search,
                'status' => $status,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->can('procurement-vendors.create'), 403);

        return Inertia::render('procurement/vendors/create');
    }

    public function store(VendorRequest $request): RedirectResponse
    {
        abort_unless($request->user()->can('procurement-vendors.create'), 403);

        $data = $request->validated();
        $data['hospital_id'] = $this->getHospitalId($request);
        $data['created_by'] = $request->user()->id;

        $vendor = Vendor::create($data);

        return redirect()->route('procurement.vendors.show', $vendor->id)
            ->with('success', 'Vendor registered successfully.');
    }

    public function show(Vendor $vendor, Request $request): Response
    {
        abort_unless($request->user()->can('procurement-vendors.view'), 403);

        $vendor->load(['purchaseOrders' => function ($q) {
            $q->latest()->limit(5);
        }, 'invoices' => function ($q) {
            $q->latest()->limit(5);
        }]);

        return Inertia::render('procurement/vendors/show', [
            'vendor' => $vendor,
        ]);
    }

    public function edit(Vendor $vendor, Request $request): Response
    {
        abort_unless($request->user()->can('procurement-vendors.update'), 403);

        return Inertia::render('procurement/vendors/edit', [
            'vendor' => $vendor,
        ]);
    }

    public function update(Vendor $vendor, VendorRequest $request): RedirectResponse
    {
        abort_unless($request->user()->can('procurement-vendors.update'), 403);

        $data = $request->validated();
        $data['updated_by'] = $request->user()->id;

        $vendor->update($data);

        return redirect()->route('procurement.vendors.show', $vendor->id)
            ->with('success', 'Vendor details updated successfully.');
    }
}
