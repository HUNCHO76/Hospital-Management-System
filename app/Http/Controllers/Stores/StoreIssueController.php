<?php

namespace App\Http\Controllers\Stores;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stores\StoreIssueRequest;
use App\Models\Department;
use App\Models\DepartmentStockRequest;
use App\Models\Hospital;
use App\Models\ProcurementItem;
use App\Models\Store;
use App\Models\StoreIssue;
use App\Services\Procurement\StoreIssueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StoreIssueController extends Controller
{
    protected function getHospitalId(Request $request): int
    {
        return $request->integer('hospital_id')
            ?: ($request->user()->staff?->hospital_id ?? Hospital::query()->value('id') ?? 1);
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('stores-issues.view'), 403);

        $hospitalId = $this->getHospitalId($request);
        $status = $request->string('status')->toString();
        $departmentId = $request->integer('department_id');
        $storeId = $request->integer('store_id');
        $search = $request->string('search')->toString();

        $query = StoreIssue::with(['department', 'fromStore', 'issuedBy', 'receivedBy'])
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
            $query->where('issue_number', 'like', "%{$search}%");
        }

        $issues = $query->latest()->paginate(15)->withQueryString();
        $departments = Department::where('hospital_id', $hospitalId)->get(['id', 'name']);
        $stores = Store::where('hospital_id', $hospitalId)->get(['id', 'name']);

        return Inertia::render('stores/issues/index', [
            'issues' => $issues,
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
        abort_unless($request->user()->can('stores-issues.create'), 403);

        $hospitalId = $this->getHospitalId($request);
        $requestId = $request->integer('request_id');

        $stockRequests = DepartmentStockRequest::with(['department', 'fromStore', 'items.procurementItem'])
            ->where('hospital_id', $hospitalId)
            ->whereIn('status', [DepartmentStockRequest::STATUS_APPROVED, DepartmentStockRequest::STATUS_PARTIALLY_ISSUED])
            ->latest()
            ->get();

        $selectedRequest = $requestId ? $stockRequests->firstWhere('id', $requestId) : null;
        $stores = Store::where('hospital_id', $hospitalId)->where('status', 'active')->get(['id', 'name']);
        $departments = Department::where('hospital_id', $hospitalId)->get(['id', 'name']);
        $items = ProcurementItem::where('status', 'active')->get();

        return Inertia::render('stores/issues/create', [
            'stock_requests' => $stockRequests,
            'selected_request' => $selectedRequest,
            'stores' => $stores,
            'departments' => $departments,
            'items' => $items,
        ]);
    }

    public function store(StoreIssueRequest $request, StoreIssueService $service): RedirectResponse
    {
        abort_unless($request->user()->can('stores-issues.create'), 403);

        $data = $request->validated();
        $data['hospital_id'] = $this->getHospitalId($request);

        $issue = $service->issueStock($data, $request);

        return redirect()->route('stores.issues.show', $issue->id)
            ->with('success', 'Stock issued to department successfully.');
    }

    public function show(StoreIssue $issue, Request $request): Response
    {
        abort_unless($request->user()->can('stores-issues.view'), 403);

        $issue->load([
            'fromStore',
            'department',
            'request',
            'items.procurementItem',
            'items.stockBatch',
            'issuedBy',
            'receivedBy',
            'approvalHistories.user',
        ]);

        return Inertia::render('stores/issues/show', [
            'issue' => $issue,
        ]);
    }

    public function receive(StoreIssue $issue, Request $request, StoreIssueService $service): RedirectResponse
    {
        abort_unless($request->user()->can('stores-issues.receive'), 403);

        $service->receiveIssue($issue, $request);

        return redirect()->route('stores.issues.show', $issue->id)
            ->with('success', 'Supplies receipt confirmed by department.');
    }
}
