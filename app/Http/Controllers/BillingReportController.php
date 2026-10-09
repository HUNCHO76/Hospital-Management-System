<?php

namespace App\Http\Controllers;

use App\Models\BillingAdjustment;
use App\Models\CashierSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\PaymentReversal;
use App\Services\AuditLogService;
use App\Services\BillingReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Billing reporting & operational analytics (Phase 7, Step 60) — every
 * report here is a live query/aggregation over the authoritative billing
 * tables Steps 52-59 already own; no reporting table of its own exists
 * (spec §26/§31). Kept thin: every query lives in BillingReportService,
 * this controller only authorizes, applies pagination, and switches between
 * the Inertia page and the CSV export of the exact same filtered/scoped
 * query.
 *
 * A single 'billing-reports.view'/'billing-reports.export' permission pair
 * gates every action here, mirroring 'pharmacy-reports' — direct
 * abort_unless() checks in each action (never the constructor: an eager
 * constructor-level abort_unless() would fire against the console's own
 * userless request during `artisan route:list`).
 */
class BillingReportController extends Controller
{
    public function __construct(protected BillingReportService $reports) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('billing-reports.view'), 403);

        return Inertia::render('billing/reports/index');
    }

    public function invoices(Request $request): Response|HttpResponse
    {
        abort_unless($request->user()->can('billing-reports.view'), 403);

        $filters = $request->only(['search', 'status', 'date_from', 'date_to']);
        $hospitalId = $this->scopedHospitalId($request);

        $query = $this->reports->invoiceReport($filters, $hospitalId);

        if ($request->boolean('export')) {
            abort_unless($request->user()->can('billing-reports.export'), 403);

            return $this->exportCsv('invoices', $request, (clone $query)->limit(10000)->get(), function (Invoice $invoice) {
                $patient = $invoice->patient;

                return [
                    $invoice->invoice_number,
                    optional($invoice->invoice_date)->toDateString(),
                    $patient ? trim("{$patient->first_name} {$patient->last_name}") : '',
                    $patient->patient_number ?? '',
                    $invoice->hospital->name ?? '',
                    $invoice->status,
                    $invoice->currency,
                    number_format((float) $invoice->total_amount, 2, '.', ''),
                    optional($invoice->posted_at)->toDateTimeString(),
                ];
            }, ['Invoice #', 'Date', 'Patient', 'Patient Number', 'Hospital', 'Status', 'Currency', 'Total Amount', 'Posted At']);
        }

        $invoices = $query->paginate(20)->withQueryString()
            ->through(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => optional($invoice->invoice_date)->toDateString(),
                'status' => $invoice->status,
                'total_amount' => (float) $invoice->total_amount,
                'currency' => $invoice->currency,
                'posted_at' => optional($invoice->posted_at)->toDateTimeString(),
                'patient' => $invoice->patient ? [
                    'id' => $invoice->patient->id,
                    'name' => trim("{$invoice->patient->first_name} {$invoice->patient->last_name}"),
                    'patient_number' => $invoice->patient->patient_number,
                ] : null,
                'hospital' => $invoice->hospital ? ['id' => $invoice->hospital->id, 'name' => $invoice->hospital->name] : null,
            ]);

        return Inertia::render('billing/reports/invoices', [
            'invoices' => $invoices,
            'filters' => $filters,
            'statuses' => Invoice::STATUSES,
        ]);
    }

    public function payments(Request $request): Response|HttpResponse
    {
        abort_unless($request->user()->can('billing-reports.view'), 403);

        $filters = $request->only(['payment_method', 'date_from', 'date_to']);
        $hospitalId = $this->scopedHospitalId($request);

        $query = $this->reports->paymentsReport($filters, $hospitalId);

        if ($request->boolean('export')) {
            abort_unless($request->user()->can('billing-reports.export'), 403);

            return $this->exportCsv('payments', $request, (clone $query)->limit(10000)->get(), function (Payment $payment) {
                $patient = $payment->patient;

                return [
                    $payment->payment_number,
                    $payment->receipt_number,
                    optional($payment->payment_date)->toDateString(),
                    $patient ? trim("{$patient->first_name} {$patient->last_name}") : '',
                    $patient->patient_number ?? '',
                    $payment->invoice->invoice_number ?? '',
                    $payment->payment_method,
                    $payment->currency,
                    number_format((float) $payment->amount, 2, '.', ''),
                    $payment->receivedBy->UserName ?? '',
                ];
            }, ['Payment #', 'Receipt #', 'Date', 'Patient', 'Patient Number', 'Invoice #', 'Method', 'Currency', 'Amount', 'Received By']);
        }

        $payments = $query->paginate(20)->withQueryString()
            ->through(fn (Payment $payment) => [
                'id' => $payment->id,
                'payment_number' => $payment->payment_number,
                'receipt_number' => $payment->receipt_number,
                'payment_date' => optional($payment->payment_date)->toDateString(),
                'payment_method' => $payment->payment_method,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'patient' => $payment->patient ? [
                    'id' => $payment->patient->id,
                    'name' => trim("{$payment->patient->first_name} {$payment->patient->last_name}"),
                    'patient_number' => $payment->patient->patient_number,
                ] : null,
                'invoice' => $payment->invoice ? ['id' => $payment->invoice->id, 'invoice_number' => $payment->invoice->invoice_number] : null,
                'received_by' => $payment->receivedBy ? ['id' => $payment->receivedBy->id, 'name' => $payment->receivedBy->UserName] : null,
            ]);

        return Inertia::render('billing/reports/payments', [
            'payments' => $payments,
            'filters' => $filters,
            'paymentMethods' => Payment::METHODS,
        ]);
    }

    public function cashierSessions(Request $request): Response|HttpResponse
    {
        abort_unless($request->user()->can('billing-reports.view'), 403);

        $filters = $request->only(['status', 'date_from', 'date_to', 'cashier_id']);
        $hospitalId = $this->scopedHospitalId($request);

        $query = $this->reports->cashierSessionsReport($filters, $hospitalId);

        if ($request->boolean('export')) {
            abort_unless($request->user()->can('billing-reports.export'), 403);

            return $this->exportCsv('cashier-sessions', $request, (clone $query)->limit(10000)->get(), function (CashierSession $session) {
                return [
                    $session->session_number,
                    $session->hospital->name ?? '',
                    $session->cashier->UserName ?? '',
                    $session->status,
                    number_format((float) $session->opening_balance, 2, '.', ''),
                    number_format((float) $session->expected_closing_balance, 2, '.', ''),
                    number_format((float) $session->actual_closing_balance, 2, '.', ''),
                    number_format((float) $session->variance, 2, '.', ''),
                    optional($session->opened_at)->toDateTimeString(),
                    optional($session->closed_at)->toDateTimeString(),
                ];
            }, ['Session #', 'Hospital', 'Cashier', 'Status', 'Opening Balance', 'Expected Closing', 'Actual Closing', 'Variance', 'Opened At', 'Closed At']);
        }

        $sessions = $query->paginate(20)->withQueryString()
            ->through(fn (CashierSession $session) => [
                'id' => $session->id,
                'session_number' => $session->session_number,
                'status' => $session->status,
                'opening_balance' => (float) $session->opening_balance,
                'expected_closing_balance' => $session->expected_closing_balance !== null ? (float) $session->expected_closing_balance : null,
                'actual_closing_balance' => $session->actual_closing_balance !== null ? (float) $session->actual_closing_balance : null,
                'variance' => $session->variance !== null ? (float) $session->variance : null,
                'opened_at' => optional($session->opened_at)->toDateTimeString(),
                'closed_at' => optional($session->closed_at)->toDateTimeString(),
                'cashier' => $session->cashier ? ['id' => $session->cashier->id, 'name' => $session->cashier->UserName] : null,
                'hospital' => $session->hospital ? ['id' => $session->hospital->id, 'name' => $session->hospital->name] : null,
            ]);

        return Inertia::render('billing/reports/cashier-sessions', [
            'sessions' => $sessions,
            'filters' => $filters,
            'statuses' => [CashierSession::STATUS_OPEN, CashierSession::STATUS_CLOSED],
        ]);
    }

    public function corrections(Request $request): Response
    {
        abort_unless($request->user()->can('billing-reports.view'), 403);

        $filters = $request->only(['date_from', 'date_to']);
        $hospitalId = $this->scopedHospitalId($request);

        $data = $this->reports->correctionsReport($filters, $hospitalId);

        return Inertia::render('billing/reports/corrections', [
            'refunds' => $data['refunds']->through(fn (PaymentRefund $r) => [
                'id' => $r->id,
                'refund_number' => $r->refund_number,
                'amount' => (float) $r->amount,
                'currency' => $r->currency,
                'refund_method' => $r->refund_method,
                'reason' => $r->reason,
                'processed_at' => optional($r->processed_at)->toDateTimeString(),
                'patient' => $r->patient ? ['id' => $r->patient->id, 'name' => trim("{$r->patient->first_name} {$r->patient->last_name}")] : null,
                'processed_by' => $r->processedBy ? ['id' => $r->processedBy->id, 'name' => $r->processedBy->UserName] : null,
            ]),
            'reversals' => $data['reversals']->through(fn (PaymentReversal $r) => [
                'id' => $r->id,
                'reversal_number' => $r->reversal_number,
                'amount' => (float) $r->amount,
                'currency' => $r->currency,
                'reason' => $r->reason,
                'reversed_at' => optional($r->reversed_at)->toDateTimeString(),
                'patient' => $r->patient ? ['id' => $r->patient->id, 'name' => trim("{$r->patient->first_name} {$r->patient->last_name}")] : null,
                'reversed_by' => $r->reversedBy ? ['id' => $r->reversedBy->id, 'name' => $r->reversedBy->UserName] : null,
            ]),
            'adjustments' => $data['adjustments']->through(fn (BillingAdjustment $a) => [
                'id' => $a->id,
                'adjustment_number' => $a->adjustment_number,
                'type' => $a->type,
                'direction' => $a->direction,
                'amount' => (float) $a->amount,
                'currency' => $a->currency,
                'reason' => $a->reason,
                'adjusted_at' => optional($a->adjusted_at)->toDateTimeString(),
                'patient' => $a->patient ? ['id' => $a->patient->id, 'name' => trim("{$a->patient->first_name} {$a->patient->last_name}")] : null,
                'adjusted_by' => $a->adjustedBy ? ['id' => $a->adjustedBy->id, 'name' => $a->adjustedBy->UserName] : null,
            ]),
            'totals' => $data['totals'],
            'filters' => $filters,
        ]);
    }

    /**
     * Stream a CSV export of an already-filtered, already-scoped result set —
     * reused by every report action above, mirroring PharmacyReportController
     * exactly.
     *
     * @param  iterable<mixed>  $rows
     * @param  callable(mixed): list<mixed>  $toRow
     * @param  list<string>  $headings
     */
    protected function exportCsv(string $reportName, Request $request, iterable $rows, callable $toRow, array $headings): HttpResponse
    {
        abort_unless($request->user()->can('billing-reports.export'), 403);

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

        app(AuditLogService::class)->record('billing_report_exported', null, null, ['report' => $reportName, 'row_count' => count($lines) - 1], $request);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$reportName.'-'.now()->format('Y-m-d').'.csv"',
        ]);
    }

    protected function scopedHospitalId(Request $request): ?int
    {
        if (! $request->user()->hasAnyRole(['hospital-admin', 'Manager', 'manager'])) {
            return null;
        }

        return $request->user()->staff?->hospital_id;
    }
}
