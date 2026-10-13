<?php

namespace App\Http\Controllers\Stores;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stores\DepartmentStockRequestFormRequest;
use App\Models\Department;
use App\Models\DepartmentStockRequest;
use App\Models\Hospital;
use App\Models\ProcurementItem;
use App\Models\Store;
use App\Services\Procurement\StoreIssueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentStockRequestController extends Controller
{
    protected function getHospitalId(Request $request): int
    {
        return $request->integer('hospital_id')
            ?: ($request->user()->staff?->hospital_id ?? Hospital::query()->value('id') ?? 1);
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('stores-requests.view'), 403);

        $hospitalId = $this->getHospitalId($request);
        $status = $request->string('status')->toString();
        $departmentId = $request->integer('department_id');
        $storeId = $request->integer('store_id');
        $search = $request->string('search')->toString();

        $query = DepartmentStockRequest::with(['department', 'fromStore', 'requestedBy'])
            ->where('hospital_id', $hospitalId);

        if ($status) {
            $query->where('status', $status);
        }

        if ($departmentId) {
            $query->where('department_id', $departmentId);
        }

        if ($storeId) {
            $query->where('from_store_id', $storeId);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('request_number', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $requests = $query->latest()->paginate(15)->withQueryString();
        $departments = Department::where('hospital_id', $hospitalId)->get(['id', 'name']);
        $stores = Store::where('hospital_id', $hospitalId)->get(['id', 'name']);

        return Inertia::render('stores/requests/index', [
            'requests' => $requests,
            'departments' => $departments,
            'stores' => $stores,
            'filters' => [
                'status' => $status,
                'department_id' => $departmentId,
                'store_id' => $storeId,
                'search' => $search,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->can('stores-requests.create'), 403);

        $hospitalId = $this->getHospitalId($request);
        $departments = Department::where('hospital_id', $hospitalId)->get(['id', 'name']);
        $stores = Store::where('hospital_id', $hospitalId)->where('status', 'active')->get(['id', 'name']);
        $items = ProcurementItem::where('status', 'active')->get();

        return Inertia::render('stores/requests/create', [
            'departments' => $departments,
            'stores' => $stores,
            'items' => $items,
        ]);
    }

    public function store(DepartmentStockRequestFormRequest $request, StoreIssueService $service): RedirectResponse
    {
        abort_unless($request->user()->can('stores-requests.create'), 403);

        $data = $request->validated();
        $data['hospital_id'] = $this->getHospitalId($request);

        $stockRequest = $service->createStockRequest($data, $request);

        return redirect()->route('stores.requests.show', $stockRequest->id)
            ->with('success', 'Department stock request submitted.');
    }

    public function show(DepartmentStockRequest $stockRequest, Request $request): Response
    {
        abort_unless($request->user()->can('stores-requests.view'), 403);

        $stockRequest->load([
            'department',
            'fromStore',
            'items.procurementItem',
            'requestedBy',
            'approvedBy',
            'issues.items',
            'approvalHistories.user',
        ]);

        return Inertia::render('stores/requests/show', [
            'stock_request' => $stockRequest,
        ]);
    }

    public function approve(DepartmentStockRequest $stockRequest, Request $request, StoreIssueService $service): RedirectResponse
    {
        abort_unless($request->user()->can('stores-requests.approve'), 403);

        $validated = $request->validate([
            'comments' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity_approved' => ['required', 'integer', 'min:0'],
        ]);

        $service->approveStockRequest($stockRequest, $validated['items'], $validated['comments'] ?? null, $request);

        return redirect()->route('stores.requests.show', $stockRequest->id)
            ->with('success', 'Department stock request approved.');
    }
}
