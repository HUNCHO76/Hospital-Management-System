<?php

namespace App\Http\Controllers;

use App\Http\Resources\DispensingItemReportResource;
use App\Http\Resources\PrescriptionReportResource;
use App\Http\Resources\StockBatchReportResource;
use App\Http\Resources\StockItemResource;
use App\Http\Resources\StockTransactionResource;
use App\Models\Dispensing;
use App\Models\DispensingItem;
use App\Models\PrescriptionOrder;
use App\Models\PrescriptionVerification;
use App\Models\StockBatch;
use App\Models\StockTransaction;
use App\Services\AuditLogService;
use App\Services\DispensingService;
use App\Services\PharmacyReportService;
use App\Services\StockSearchService;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pharmacy reporting & operational analytics (Phase 6, Step 50) — every
 * report here is a live query/aggregation over the authoritative
 * Prescription/Verification/Dispensing/Stock tables Steps 44-49 already
 * own; no reporting table of its own exists (spec §26/§31). Kept thin:
 * every query lives in PharmacyReportService/StockSearchService, this
 * controller only authorizes, applies pagination, and switches between the
 * Inertia page and the CSV export of the exact same filtered/scoped query.
 *
 * A single 'pharmacy-reports.view'/'pharmacy-reports.export' permission
 * pair (not a permission per report) gates every action here, mirroring
 * 'laboratory-reports' — direct abort_unless() checks in each action (never
 * the constructor: an eager constructor-level abort_unless(request()->user())
 * would fire against the consoles's own userless request during
 * `artisan route:list`, which resolves every controller to introspect its
 * actions), mirroring PharmacyController's own precedent (no natural single
 * model exists for these cross-cutting reports to attach a policy to).
 */
class PharmacyReportController extends Controller
{
    public function __construct(protected PharmacyReportService $reports, protected StockSearchService $stockSearch) {}

    /**
     * The reports hub — links to every report page below.
     */
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('pharmacy-reports.view'), 403);

        return Inertia::render('pharmacy/reports/index');
    }

    /**
     * The enriched pharmacy dashboard's own data endpoint is
     * PharmacyController::index() itself (spec §5 asks to "create or
     * improve /pharmacy/dashboard" — the existing /pharmacy route already
     * is that dashboard); nothing is duplicated here.
     */
    public function stockBatches(Request $request): Response|HttpResponse
    {
        abort_unless($request->user()->can('pharmacy-reports.view'), 403);

        $filters = $request->only(['search', 'status', 'expiry_from', 'expiry_to']);
        $hospitalId = $this->scopedHospitalId($request);

        $query = $this->stockSearch->batchesQuery($filters);
        $this->stockSearch->scopeBatchesToHospital($query, $hospitalId);

        if ($request->boolean('export')) {
            abort_unless($request->user()->can('pharmacy-reports.export'), 403);

            return $this->exportCsv('stock-batches', $request, (clone $query)->limit(10000)->get(), function (StockBatch $batch) {
                return [
                    $batch->stockItem->medication->code,
                    $batch->stockItem->medication->generic_name,
                    $batch->batch_number,
                    $batch->expiry_date->toDateString(),
                    $batch->quantity,
                    $batch->status,
                    $batch->stockItem->hospital->name ?? '',
                    optional($batch->created_at)->toDateString(),
                ];
            }, ['Code', 'Medication', 'Batch Number', 'Expiry Date', 'Quantity', 'Status', 'Hospital', 'Received At']);
        }

        $batches = $query->paginate(20)->withQueryString()
            ->through(fn (StockBatch $batch) => (new StockBatchReportResource($batch))->resolve($request));

        return Inertia::render('pharmacy/reports/stock-batches', [
            'batches' => $batches,
            'filters' => $filters,
            'statuses' => StockBatch::STATUSES,
        ]);
    }

    public function lowStock(Request $request): Response|HttpResponse
    {
        return $this->stockStatusReport($request, 'low_stock', 'pharmacy/reports/low-stock');
    }

    public function outOfStock(Request $request): Response|HttpResponse
    {
        return $this->stockStatusReport($request, 'out_of_stock', 'pharmacy/reports/out-of-stock');
    }

    /**
     * Expired and expiring-soon batches share one page (two tabs on the
     * frontend), both reusing StockSearchService::itemsQuery() — the exact
     * classification the stock dashboard/list already use, never
     * re-derived (spec §31).
     */
    public function expiry(Request $request): Response|HttpResponse
    {
        $status = $request->string('view')->value() === 'expired' ? 'expired' : 'expiring_soon';

        return $this->stockStatusReport($request, $status, 'pharmacy/reports/expiry', ['view' => $status === 'expired' ? 'expired' : 'expiring_soon']);
    }

    public function movements(Request $request): Response|HttpResponse
    {
        abort_unless($request->user()->can('pharmacy-reports.view'), 403);

        $filters = $request->only(['search', 'transaction_type', 'medication_id', 'performed_by', 'date_from', 'date_to']);
        $hospitalId = $this->scopedHospitalId($request);

        $query = $this->stockSearch->movementsQuery($filters);
        $this->stockSearch->scopeMovementsToHospital($query, $hospitalId);

        if ($request->boolean('export')) {
            abort_unless($request->user()->can('pharmacy-reports.export'), 403);

            return $this->exportCsv('stock-movements', $request, (clone $query)->limit(10000)->get(), function (StockTransaction $transaction) {
                return [
                    $transaction->transaction_number,
                    optional($transaction->occurred_at)->toDateTimeString(),
                    $transaction->stockItem->medication->generic_name ?? '',
                    $transaction->stockBatch->batch_number ?? '',
                    $transaction->transaction_type,
                    $transaction->quantity,
                    $transaction->balance_before,
                    $transaction->balance_after,
                    $transaction->reference_type,
                    $transaction->reference_id,
                    $transaction->reason,
                    $transaction->performedBy->UserName ?? '',
                ];
            }, ['Transaction #', 'Occurred At', 'Medication', 'Batch', 'Type', 'Quantity', 'Balance Before', 'Balance After', 'Reference Type', 'Reference ID', 'Reason', 'Performed By']);
        }

        $transactions = $query->paginate(30)->withQueryString()
            ->through(fn (StockTransaction $transaction) => (new StockTransactionResource($transaction))->resolve($request));

        return Inertia::render('pharmacy/reports/stock-movements', [
            'transactions' => $transactions,
            'filters' => $filters,
            'transactionTypes' => StockTransaction::TYPES,
        ]);
    }

    public function utilization(Request $request): Response|HttpResponse
    {
        abort_unless($request->user()->can('pharmacy-reports.view'), 403);

        $filters = $request->only(['medication_id', 'date_from', 'date_to', 'group_by']);
        $hospitalId = $this->scopedHospitalId($request);

        $rows = $this->reports->medicationUtilization($filters, $hospitalId);

        if ($request->boolean('export')) {
            abort_unless($request->user()->can('pharmacy-reports.export'), 403);

            return $this->exportCsv('medication-utilization', $request, $rows, function ($row) {
                return [$row->medication_name, $row->period, $row->total_quantity, $row->dispensing_events, $row->prescription_count, $row->patient_count];
            }, ['Medication', 'Period', 'Total Quantity', 'Dispensing Events', 'Prescriptions', 'Patients']);
        }

        return Inertia::render('pharmacy/reports/medication-utilization', [
            'rows' => $rows->values(),
            'filters' => [...$filters, 'group_by' => $filters['group_by'] ?? 'month'],
        ]);
    }

    public function prescriptions(Request $request): Response|HttpResponse
    {
        abort_unless($request->user()->can('pharmacy-reports.view'), 403);

        $filters = $request->only(['search', 'status', 'verification_status', 'prescriber_id', 'date_from', 'date_to']);
        $hospitalId = $this->scopedHospitalId($request);

        $query = $this->reports->prescriptionReport($filters);
        $this->reports->scopePrescriptionsToHospital($query, $hospitalId);

        if ($request->boolean('export')) {
            abort_unless($request->user()->can('pharmacy-reports.export'), 403);

            return $this->exportCsv('prescriptions', $request, (clone $query)->limit(10000)->get(), function (PrescriptionOrder $order) {
                $patient = $order->clinicalEncounter?->patient;

                return [
                    $order->prescription_number,
                    optional($order->prescribed_at)->toDateString(),
                    $patient ? trim("{$patient->first_name} {$patient->last_name}") : '',
                    $patient->patient_number ?? '',
                    $order->prescriber->UserName ?? '',
                    $order->clinicalEncounter?->department?->name,
                    $order->status,
                    $order->verification->status ?? '',
                    $order->items->count(),
                ];
            }, ['Prescription #', 'Date', 'Patient', 'Patient Number', 'Prescriber', 'Department', 'Status', 'Verification Status', 'Medications']);
        }

        $orders = $query->paginate(20)->withQueryString()
            ->through(fn (PrescriptionOrder $order) => (new PrescriptionReportResource($order, $this->dispensingService()))->resolve($request));

        return Inertia::render('pharmacy/reports/prescriptions', [
            'prescriptions' => $orders,
            'filters' => $filters,
            'statuses' => PrescriptionOrder::STATUSES,
            'verificationStatuses' => PrescriptionVerification::STATUSES,
        ]);
    }

    public function dispensing(Request $request): Response|HttpResponse
    {
        abort_unless($request->user()->can('pharmacy-reports.view'), 403);

        $filters = $request->only(['search', 'status', 'dispenser_id', 'date_from', 'date_to']);
        $hospitalId = $this->scopedHospitalId($request);

        $query = $this->reports->dispensingReport($filters);
        $this->reports->scopeDispensingToHospital($query, $hospitalId);

        if ($request->boolean('export')) {
            abort_unless($request->user()->can('pharmacy-reports.export'), 403);

            return $this->exportCsv('dispensing', $request, (clone $query)->limit(10000)->get(), function (DispensingItem $item) {
                $order = $item->dispensing->prescriptionOrder;
                $patient = $order?->clinicalEncounter?->patient;

                return [
                    $item->dispensing->dispensing_number,
                    $order?->prescription_number,
                    $patient ? trim("{$patient->first_name} {$patient->last_name}") : '',
                    $item->prescriptionOrderItem->medication_name_snapshot ?? '',
                    $item->prescriptionOrderItem->quantity ?? '',
                    $item->dispensed_quantity,
                    $item->status,
                    $item->dispensing->dispensedBy->UserName ?? '',
                    optional($item->dispensing->completed_at)->toDateTimeString(),
                ];
            }, ['Dispensing #', 'Prescription #', 'Patient', 'Medication', 'Prescribed Qty', 'Dispensed Qty', 'Status', 'Dispenser', 'Completed At']);
        }

        $items = $query->paginate(20)->withQueryString()
            ->through(fn (DispensingItem $item) => (new DispensingItemReportResource($item))->resolve($request));

        return Inertia::render('pharmacy/reports/dispensing', [
            'items' => $items,
            'filters' => $filters,
            'statuses' => DispensingItem::STATUSES,
        ]);
    }

    public function reconciliation(Request $request): Response|HttpResponse
    {
        abort_unless($request->user()->can('pharmacy-reports.view'), 403);

        $filters = $request->only(['search', 'status', 'expiry_from', 'expiry_to']);
        $hospitalId = $this->scopedHospitalId($request);

        $query = $this->stockSearch->batchesQuery($filters);
        $this->stockSearch->scopeBatchesToHospital($query, $hospitalId);

        $batches = $query->paginate(20)->withQueryString();

        $rows = $this->reports->reconciliation(collect($batches->items()));

        if ($request->boolean('export')) {
            abort_unless($request->user()->can('pharmacy-reports.export'), 403);

            $allBatches = $this->reports->reconciliation((clone $query)->limit(10000)->get());

            return $this->exportCsv('stock-reconciliation', $request, $allBatches, function (array $row) {
                $batch = $row['batch'];

                return [
                    $batch->stockItem->medication->generic_name ?? '',
                    $batch->batch_number,
                    $row['ledger_balance'],
                    $row['recorded_quantity'],
                    $row['reconciled'] ? 'OK' : 'MISMATCH',
                ];
            }, ['Medication', 'Batch Number', 'Ledger Balance', 'Recorded Quantity', 'Status']);
        }

        return Inertia::render('pharmacy/reports/reconciliation', [
            'rows' => $rows->map(fn ($row) => [
                'batch' => (new StockBatchReportResource($row['batch']))->resolve($request),
                'ledger_balance' => $row['ledger_balance'],
                'recorded_quantity' => $row['recorded_quantity'],
                'reconciled' => $row['reconciled'],
            ])->values(),
            'links' => $batches->linkCollection(),
            'filters' => $filters,
        ]);
    }

    /**
     * Stream a CSV export of an already-filtered, already-scoped result set
     * (spec §18) — reused by every report action above rather than a
     * separate export system per report. Never bypasses authorization: the
     * constructor's own abort_unless() already ran, and this additionally
     * requires the distinct 'export' ability.
     *
     * @param  iterable<mixed>  $rows
     * @param  callable(mixed): list<mixed>  $toRow
     * @param  list<string>  $headings
     */
    protected function exportCsv(string $reportName, Request $request, iterable $rows, callable $toRow, array $headings): HttpResponse
    {
        abort_unless($request->user()->can('pharmacy-reports.export'), 403);

        $lines = [$headings];

        foreach ($rows as $row) {
            $lines[] = $toRow($row);
        }

        $handle = fopen('php://temp', 'w+');

        foreach ($lines as $line) {
            fputcsv($handle, $line);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        app(AuditLogService::class)->record('pharmacy_report_exported', null, null, ['report' => $reportName, 'row_count' => count($lines) - 1], $request);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$reportName.'-'.now()->format('Y-m-d').'.csv"',
        ]);
    }

    protected function dispensingService(): DispensingService
    {
        return app(DispensingService::class);
    }

    /**
     * The hospital a hospital-admin is restricted to, or null when the user
     * is unrestricted (super-admin and any other unscoped role).
     */
    protected function scopedHospitalId(Request $request): ?int
    {
        if (! $request->user()->hasAnyRole(['hospital-admin', 'Manager', 'manager'])) {
            return null;
        }

        return $request->user()->staff?->hospital_id;
    }

    /**
     * @param  array<string, mixed>  $extraProps
     */
    protected function stockStatusReport(Request $request, string $status, string $page, array $extraProps = []): Response|HttpResponse
    {
        abort_unless($request->user()->can('pharmacy-reports.view'), 403);

        $filters = ['search' => $request->string('search')->value() ?: null, 'status' => $status];
        $hospitalId = $this->scopedHospitalId($request);

        $query = $this->stockSearch->itemsQuery($filters);
        $this->stockSearch->scopeToHospital($query, $hospitalId);

        if ($request->boolean('export')) {
            abort_unless($request->user()->can('pharmacy-reports.export'), 403);

            return $this->exportCsv($page, $request, (clone $query)->limit(10000)->get(), function ($item) {
                return [
                    $item->medication->code ?? '',
                    $item->medication->generic_name ?? '',
                    $item->total_quantity,
                    $item->available_quantity,
                    $item->reorder_level,
                    max(0, $item->reorder_level - $item->available_quantity),
                    $item->status,
                ];
            }, ['Code', 'Medication', 'Total Quantity', 'Available Quantity', 'Reorder Level', 'Shortage', 'Status']);
        }

        $items = $query->paginate(20)->withQueryString()
            ->through(fn ($item) => (new StockItemResource($item))->resolve($request));

        return Inertia::render($page, [
            'items' => $items,
            'filters' => $request->only(['search']),
            ...$extraProps,
        ]);
    }
}
