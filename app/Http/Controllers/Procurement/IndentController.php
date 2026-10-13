<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Procurement\IndentRequest;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\Indent;
use App\Models\ProcurementItem;
use App\Models\Store;
use App\Services\Procurement\IndentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IndentController extends Controller
{
    protected function getHospitalId(Request $request): int
    {
        return $request->integer('hospital_id')
            ?: ($request->user()->staff?->hospital_id ?? Hospital::query()->value('id') ?? 1);
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('procurement-indents.view'), 403);

        $hospitalId = $this->getHospitalId($request);
        $status = $request->string('status')->toString();
        $departmentId = $request->integer('department_id');
        $search = $request->string('search')->toString();

        $query = Indent::with(['department', 'requestedBy', 'targetStore'])
            ->where('hospital_id', $hospitalId);

        if ($status) {
            $query->where('status', $status);
        }

        if ($departmentId) {
            $query->where('department_id', $departmentId);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('indent_number', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $indents = $query->latest()->paginate(15)->withQueryString();
        $departments = Department::where('hospital_id', $hospitalId)->get(['id', 'name']);

        return Inertia::render('procurement/indents/index', [
            'indents' => $indents,
            'departments' => $departments,
            'filters' => [
                'status' => $status,
                'department_id' => $departmentId,
                'search' => $search,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->can('procurement-indents.create'), 403);

        $hospitalId = $this->getHospitalId($request);
        $departments = Department::where('hospital_id', $hospitalId)->get(['id', 'name']);
        $stores = Store::where('hospital_id', $hospitalId)->where('status', 'active')->get(['id', 'name', 'type']);
        $items = ProcurementItem::where('status', 'active')->get();

        return Inertia::render('procurement/indents/create', [
            'departments' => $departments,
            'stores' => $stores,
            'items' => $items,
        ]);
    }

    public function store(IndentRequest $request, IndentService $service): RedirectResponse
    {
        abort_unless($request->user()->can('procurement-indents.create'), 403);

        $data = $request->validated();
        $data['hospital_id'] = $this->getHospitalId($request);

        $indent = $service->create($data, $request);

        return redirect()->route('procurement.indents.show', $indent->id)
            ->with('success', 'Purchase requisition indent created successfully.');
    }

    public function show(Indent $indent, Request $request): Response
    {
        abort_unless($request->user()->can('procurement-indents.view'), 403);

        $indent->load([
            'items.procurementItem',
            'department',
            'requestedBy',
            'targetStore',
            'approvedBy',
            'approvalHistories.user',
        ]);

        return Inertia::render('procurement/indents/show', [
            'indent' => $indent,
        ]);
    }

    public function edit(Indent $indent, Request $request): Response|RedirectResponse
    {
        abort_unless($request->user()->can('procurement-indents.update'), 403);

        if ($indent->status !== Indent::STATUS_DRAFT) {
            return redirect()->route('procurement.indents.show', $indent->id)
                ->with('error', 'Only draft indents can be edited.');
        }

        $hospitalId = $this->getHospitalId($request);
        $departments = Department::where('hospital_id', $hospitalId)->get(['id', 'name']);
        $stores = Store::where('hospital_id', $hospitalId)->where('status', 'active')->get(['id', 'name', 'type']);
        $items = ProcurementItem::where('status', 'active')->get();

        $indent->load('items.procurementItem');

        return Inertia::render('procurement/indents/edit', [
            'indent' => $indent,
            'departments' => $departments,
            'stores' => $stores,
            'items' => $items,
        ]);
    }

    public function update(Indent $indent, IndentRequest $request, IndentService $service): RedirectResponse
    {
        abort_unless($request->user()->can('procurement-indents.update'), 403);

        $service->update($indent, $request->validated(), $request);

        return redirect()->route('procurement.indents.show', $indent->id)
            ->with('success', 'Indent updated successfully.');
    }

    public function submit(Indent $indent, Request $request, IndentService $service): RedirectResponse
    {
        abort_unless($request->user()->can('procurement-indents.submit'), 403);

        $service->submit($indent, $request);

        return redirect()->route('procurement.indents.show', $indent->id)
            ->with('success', 'Indent submitted for approval.');
    }

    public function approve(Indent $indent, Request $request, IndentService $service): RedirectResponse
    {
        abort_unless($request->user()->can('procurement-indents.approve'), 403);

        $validated = $request->validate([
            'comments' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity_approved' => ['required', 'integer', 'min:0'],
        ]);

        $service->approve($indent, $validated['items'], $validated['comments'] ?? null, $request);

        return redirect()->route('procurement.indents.show', $indent->id)
            ->with('success', 'Indent approved successfully.');
    }

    public function reject(Indent $indent, Request $request, IndentService $service): RedirectResponse
    {
        abort_unless($request->user()->can('procurement-indents.reject'), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $service->reject($indent, $validated['reason'], $request);

        return redirect()->route('procurement.indents.show', $indent->id)
            ->with('success', 'Indent rejected.');
    }

    public function cancel(Indent $indent, Request $request, IndentService $service): RedirectResponse
    {
        abort_unless($request->user()->can('procurement-indents.cancel'), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $service->cancel($indent, $validated['reason'], $request);

        return redirect()->route('procurement.indents.show', $indent->id)
            ->with('success', 'Indent cancelled.');
    }
}
