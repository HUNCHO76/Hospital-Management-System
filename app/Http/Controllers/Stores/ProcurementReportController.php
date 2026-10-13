<?php

namespace App\Http\Controllers\Stores;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\ProcurementItem;
use App\Models\Store;
use App\Services\Procurement\ProcurementReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProcurementReportController extends Controller
{
    protected function getHospitalId(Request $request): int
    {
        return $request->integer('hospital_id')
            ?: ($request->user()->staff?->hospital_id ?? Hospital::query()->value('id') ?? 1);
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('stores-reports.view'), 403);

        return Inertia::render('stores/reports/index');
    }

    public function stockValuation(Request $request, ProcurementReportService $service): Response
    {
        abort_unless($request->user()->can('stores-reports.view'), 403);

        $hospitalId = $this->getHospitalId($request);
        $storeId = $request->integer('store_id') ?: null;
        $category = $request->string('category')->toString() ?: null;

        $report = $service->getStockValuationReport($hospitalId, $storeId, $category);
        $stores = Store::where('hospital_id', $hospitalId)->get(['id', 'name']);

        return Inertia::render('stores/reports/stock_valuation', [
            'report' => $report,
            'stores' => $stores,
            'categories' => ProcurementItem::CATEGORIES,
            'filters' => [
                'store_id' => $storeId,
                'category' => $category,
            ],
        ]);
    }

    public function expiry(Request $request, ProcurementReportService $service): Response
    {
        abort_unless($request->user()->can('stores-reports.view'), 403);

        $hospitalId = $this->getHospitalId($request);
        $storeId = $request->integer('store_id') ?: null;

        $report = $service->getStockExpiryReport($hospitalId, $storeId);
        $stores = Store::where('hospital_id', $hospitalId)->get(['id', 'name']);

        return Inertia::render('stores/reports/expiry', [
            'report' => $report,
            'stores' => $stores,
            'filters' => [
                'store_id' => $storeId,
            ],
        ]);
    }

    public function reorderAlerts(Request $request, ProcurementReportService $service): Response
    {
        abort_unless($request->user()->can('stores-reports.view'), 403);

        $hospitalId = $this->getHospitalId($request);
        $storeId = $request->integer('store_id') ?: null;

        $report = $service->getReorderAlertsReport($hospitalId, $storeId);
        $stores = Store::where('hospital_id', $hospitalId)->get(['id', 'name']);

        return Inertia::render('stores/reports/reorder_alerts', [
            'report' => $report,
            'stores' => $stores,
            'filters' => [
                'store_id' => $storeId,
            ],
        ]);
    }

    public function discrepancies(Request $request, ProcurementReportService $service): Response
    {
        abort_unless($request->user()->can('stores-reports.view'), 403);

        $hospitalId = $this->getHospitalId($request);
        $report = $service->getInvoiceDiscrepancyReport($hospitalId);

        return Inertia::render('stores/reports/discrepancies', [
            'report' => $report,
        ]);
    }

    public function departmentConsumption(Request $request, ProcurementReportService $service): Response
    {
        abort_unless($request->user()->can('stores-reports.view'), 403);

        $hospitalId = $this->getHospitalId($request);
        $departmentId = $request->integer('department_id') ?: null;
        $startDate = $request->string('start_date')->toString() ?: null;
        $endDate = $request->string('end_date')->toString() ?: null;

        $report = $service->getDepartmentConsumptionReport($hospitalId, $departmentId, $startDate, $endDate);
        $departments = Department::where('hospital_id', $hospitalId)->get(['id', 'name']);

        return Inertia::render('stores/reports/department_consumption', [
            'report' => $report,
            'departments' => $departments,
            'filters' => [
                'department_id' => $departmentId,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }
}
