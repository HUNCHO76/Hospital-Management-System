<?php

namespace App\Http\Controllers;

use App\Models\Hospital;
use App\Models\NhifService;
use App\Models\NhifServiceMapping;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NhifServiceMappingController extends Controller
{
    public function index(Request $request): Response
    {
        $mappings = NhifServiceMapping::with(['service', 'hospital'])
            ->when($request->search, fn ($q, $s) => $q->where(function ($q) use ($s) {
                $q->whereHas('service', fn ($sq) => $sq->where('name', 'like', "%{$s}%"))
                    ->orWhere('nhif_item_code', 'like', "%{$s}%")
                    ->orWhere('nhif_item_name', 'like', "%{$s}%");
            }))
            ->orderByDesc('updated_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (NhifServiceMapping $m) => [
                'id' => $m->id,
                'service' => $m->service ? ['id' => $m->service->id, 'name' => $m->service->name, 'code' => $m->service->code ?? ''] : null,
                'hospital' => $m->hospital ? ['id' => $m->hospital->id, 'name' => $m->hospital->name, 'code' => $m->hospital->code ?? ''] : null,
                'nhif_item_code' => $m->nhif_item_code,
                'nhif_item_name' => $m->nhif_item_name,
                'coverage_status' => $m->coverage_status,
                'requires_preauth' => $m->requires_preauth,
                'mapping_source' => $m->mapping_source,
                'is_active' => $m->is_active,
                'current_allowable_amount' => $m->currentAllowableAmount(),
                'effective_from' => $m->effective_from?->toDateString(),
                'effective_to' => $m->effective_to?->toDateString(),
                'created_at' => $m->created_at->toDateTimeString(),
                'updated_at' => $m->updated_at->toDateTimeString(),
            ]);

        return Inertia::render('nhif/service-mappings/index', [
            'mappings' => $mappings,
            'filters' => $request->only('search'),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('nhif/service-mappings/create', [
            'services' => Service::where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'hospitals' => Hospital::orderBy('name')->get(['id', 'name', 'code']),
            'nhifServices' => NhifService::where('is_active', true)->orderBy('nhif_item_name')->get(['id', 'nhif_item_code', 'nhif_item_name']),
            'coverageStatuses' => [
                NhifServiceMapping::COVERAGE_COVERED,
                NhifServiceMapping::COVERAGE_PARTIALLY_COVERED,
                NhifServiceMapping::COVERAGE_EXCLUDED,
                NhifServiceMapping::COVERAGE_NOT_MAPPED,
                NhifServiceMapping::COVERAGE_UNKNOWN,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'hospital_id' => ['required', 'integer', 'exists:hospitals,id'],
            'nhif_item_code' => ['required', 'string', 'max:50'],
            'nhif_item_name' => ['required', 'string', 'max:255'],
            'nhif_package_code' => ['nullable', 'string', 'max:50'],
            'nhif_package_name' => ['nullable', 'string', 'max:255'],
            'coverage_status' => ['required', 'in:covered,partially_covered,excluded,not_mapped,unknown'],
            'requires_preauth' => ['boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $mapping = NhifServiceMapping::create(array_merge($data, [
            'mapping_source' => NhifServiceMapping::SOURCE_MANUAL,
            'is_active' => true,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]));

        return redirect()->route('nhif.service-mappings.show', $mapping)
            ->with('success', 'Service mapping created.');
    }

    public function show(Request $request, NhifServiceMapping $nhifServiceMapping): Response
    {
        $nhifServiceMapping->load(['service', 'hospital', 'prices', 'createdBy', 'updatedBy']);

        return Inertia::render('nhif/service-mappings/show', [
            'mapping' => [
                'id' => $nhifServiceMapping->id,
                'service' => $nhifServiceMapping->service ? ['id' => $nhifServiceMapping->service->id, 'name' => $nhifServiceMapping->service->name] : null,
                'hospital' => $nhifServiceMapping->hospital ? ['id' => $nhifServiceMapping->hospital->id, 'name' => $nhifServiceMapping->hospital->name] : null,
                'nhif_item_code' => $nhifServiceMapping->nhif_item_code,
                'nhif_item_name' => $nhifServiceMapping->nhif_item_name,
                'nhif_package_code' => $nhifServiceMapping->nhif_package_code,
                'nhif_package_name' => $nhifServiceMapping->nhif_package_name,
                'coverage_status' => $nhifServiceMapping->coverage_status,
                'requires_preauth' => $nhifServiceMapping->requires_preauth,
                'mapping_source' => $nhifServiceMapping->mapping_source,
                'is_active' => $nhifServiceMapping->is_active,
                'effective_from' => $nhifServiceMapping->effective_from?->toDateString(),
                'effective_to' => $nhifServiceMapping->effective_to?->toDateString(),
                'notes' => $nhifServiceMapping->notes,
                'current_allowable_amount' => $nhifServiceMapping->currentAllowableAmount(),
                'prices' => $nhifServiceMapping->prices->map(fn ($p) => [
                    'id' => $p->id,
                    'allowable_amount' => $p->allowable_amount,
                    'currency' => $p->currency,
                    'effective_from' => $p->effective_from?->toDateString(),
                    'effective_to' => $p->effective_to?->toDateString(),
                    'status' => $p->status,
                ]),
                'created_by' => $nhifServiceMapping->createdBy?->name,
                'updated_by' => $nhifServiceMapping->updatedBy?->name,
                'created_at' => $nhifServiceMapping->created_at->toDateTimeString(),
                'updated_at' => $nhifServiceMapping->updated_at->toDateTimeString(),
            ],
        ]);
    }

    public function edit(Request $request, NhifServiceMapping $nhifServiceMapping): Response
    {
        $nhifServiceMapping->load(['service', 'hospital']);

        return Inertia::render('nhif/service-mappings/edit', [
            'mapping' => [
                'id' => $nhifServiceMapping->id,
                'service_id' => $nhifServiceMapping->service_id,
                'hospital_id' => $nhifServiceMapping->hospital_id,
                'nhif_item_code' => $nhifServiceMapping->nhif_item_code,
                'nhif_item_name' => $nhifServiceMapping->nhif_item_name,
                'nhif_package_code' => $nhifServiceMapping->nhif_package_code,
                'nhif_package_name' => $nhifServiceMapping->nhif_package_name,
                'coverage_status' => $nhifServiceMapping->coverage_status,
                'requires_preauth' => $nhifServiceMapping->requires_preauth,
                'effective_from' => $nhifServiceMapping->effective_from?->toDateString(),
                'effective_to' => $nhifServiceMapping->effective_to?->toDateString(),
                'notes' => $nhifServiceMapping->notes,
                'is_active' => $nhifServiceMapping->is_active,
            ],
            'services' => Service::where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'hospitals' => Hospital::orderBy('name')->get(['id', 'name', 'code']),
            'nhifServices' => NhifService::where('is_active', true)->orderBy('nhif_item_name')->get(['id', 'nhif_item_code', 'nhif_item_name']),
            'coverageStatuses' => [
                NhifServiceMapping::COVERAGE_COVERED,
                NhifServiceMapping::COVERAGE_PARTIALLY_COVERED,
                NhifServiceMapping::COVERAGE_EXCLUDED,
                NhifServiceMapping::COVERAGE_NOT_MAPPED,
                NhifServiceMapping::COVERAGE_UNKNOWN,
            ],
        ]);
    }

    public function update(Request $request, NhifServiceMapping $nhifServiceMapping): RedirectResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'hospital_id' => ['required', 'integer', 'exists:hospitals,id'],
            'nhif_item_code' => ['required', 'string', 'max:50'],
            'nhif_item_name' => ['required', 'string', 'max:255'],
            'nhif_package_code' => ['nullable', 'string', 'max:50'],
            'nhif_package_name' => ['nullable', 'string', 'max:255'],
            'coverage_status' => ['required', 'in:covered,partially_covered,excluded,not_mapped,unknown'],
            'requires_preauth' => ['boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $nhifServiceMapping->update(array_merge($data, [
            'updated_by' => $request->user()->id,
        ]));

        return redirect()->route('nhif.service-mappings.show', $nhifServiceMapping)
            ->with('success', 'Service mapping updated.');
    }

    public function deactivate(Request $request, NhifServiceMapping $nhifServiceMapping): RedirectResponse
    {
        $nhifServiceMapping->update([
            'is_active' => false,
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Service mapping deactivated.');
    }
}
