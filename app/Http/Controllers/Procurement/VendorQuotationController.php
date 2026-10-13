<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Procurement\VendorQuotationRequest;
use App\Models\Hospital;
use App\Models\Indent;
use App\Models\ProcurementItem;
use App\Models\Vendor;
use App\Models\VendorQuotation;
use App\Services\Procurement\VendorQuotationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VendorQuotationController extends Controller
{
    protected function getHospitalId(Request $request): int
    {
        return $request->integer('hospital_id')
            ?: ($request->user()->staff?->hospital_id ?? Hospital::query()->value('id') ?? 1);
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('procurement-quotations.view'), 403);

        $hospitalId = $this->getHospitalId($request);
        $status = $request->string('status')->toString();
        $vendorId = $request->integer('vendor_id');
        $search = $request->string('search')->toString();

        $query = VendorQuotation::with(['vendor', 'indent'])
            ->where('hospital_id', $hospitalId);

        if ($status) {
            $query->where('status', $status);
        }

        if ($vendorId) {
            $query->where('vendor_id', $vendorId);
        }

        if ($search) {
            $query->where('quotation_number', 'like', "%{$search}%");
        }

        $quotations = $query->latest()->paginate(15)->withQueryString();
        $vendors = Vendor::where('hospital_id', $hospitalId)->where('status', 'active')->get(['id', 'name']);

        return Inertia::render('procurement/quotations/index', [
            'quotations' => $quotations,
            'vendors' => $vendors,
            'filters' => [
                'status' => $status,
                'vendor_id' => $vendorId,
                'search' => $search,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->can('procurement-quotations.create'), 403);

        $hospitalId = $this->getHospitalId($request);
        $vendors = Vendor::where('hospital_id', $hospitalId)->where('status', 'active')->get(['id', 'name']);
        $indents = Indent::with('items.procurementItem')
            ->where('hospital_id', $hospitalId)
            ->whereIn('status', [Indent::STATUS_APPROVED, Indent::STATUS_SUBMITTED])
            ->get();
        $items = ProcurementItem::where('status', 'active')->get();

        $indentId = $request->integer('indent_id');
        $selectedIndent = $indentId ? $indents->firstWhere('id', $indentId) : null;

        return Inertia::render('procurement/quotations/create', [
            'vendors' => $vendors,
            'indents' => $indents,
            'items' => $items,
            'selected_indent' => $selectedIndent,
        ]);
    }

    public function store(VendorQuotationRequest $request, VendorQuotationService $service): RedirectResponse
    {
        abort_unless($request->user()->can('procurement-quotations.create'), 403);

        $data = $request->validated();
        $data['hospital_id'] = $this->getHospitalId($request);

        $quotation = $service->create($data, $request);

        return redirect()->route('procurement.quotations.show', $quotation->id)
            ->with('success', 'Vendor quotation recorded successfully.');
    }

    public function show(VendorQuotation $quotation, Request $request): Response
    {
        abort_unless($request->user()->can('procurement-quotations.view'), 403);

        $quotation->load([
            'vendor',
            'indent.department',
            'items.procurementItem',
            'selectedBy',
            'approvalHistories.user',
        ]);

        return Inertia::render('procurement/quotations/show', [
            'quotation' => $quotation,
        ]);
    }

    public function compare(Request $request, VendorQuotationService $service): Response
    {
        abort_unless($request->user()->can('procurement-quotations.compare'), 403);

        $hospitalId = $this->getHospitalId($request);
        $indentId = $request->integer('indent_id');

        $indents = Indent::where('hospital_id', $hospitalId)
            ->whereHas('items')
            ->orderBy('id', 'desc')
            ->get(['id', 'indent_number', 'status']);

        $comparisonData = $indentId ? $service->getComparison($indentId) : null;
        $indent = $indentId ? Indent::with('department')->find($indentId) : null;

        return Inertia::render('procurement/quotations/compare', [
            'indents' => $indents,
            'selected_indent_id' => $indentId,
            'selected_indent' => $indent,
            'comparison' => $comparisonData,
        ]);
    }

    public function select(VendorQuotation $quotation, Request $request, VendorQuotationService $service): RedirectResponse
    {
        abort_unless($request->user()->can('procurement-quotations.select'), 403);

        $validated = $request->validate([
            'justification' => ['required', 'string', 'max:1000'],
        ]);

        $service->selectQuotation($quotation, $validated['justification'], $request);

        return redirect()->route('procurement.quotations.show', $quotation->id)
            ->with('success', 'Quotation marked as selected.');
    }
}
