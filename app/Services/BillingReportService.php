<?php

namespace App\Services;

use App\Models\BillingAdjustment;
use App\Models\CashierSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\PaymentReversal;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Reporting/analytics over the billing data Steps 52-59 already own (Phase
 * 7, Step 60) — every figure is a live aggregation against authoritative
 * transactional tables, never a stored/cached summary (spec §4/§26/§31).
 * Mirrors PharmacyReportService's own shape exactly: thin controller +
 * service + scoped queries. No data is modified from here.
 */
class BillingReportService
{
    /**
     * The enhanced billing dashboard KPI set.
     *
     * @return array<string, mixed>
     */
    public function dashboardMetrics(?int $hospitalId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $invoiceBase = fn () => $this->scopeQuery(Invoice::query(), $hospitalId, $dateFrom, $dateTo, 'posted_at');
        $paymentBase = fn () => $this->scopeQuery(Payment::query()->where('status', Payment::STATUS_POSTED), $hospitalId, $dateFrom, $dateTo, 'posted_at');
        $reversalBase = fn () => $this->scopeQuery(PaymentReversal::query(), $hospitalId, $dateFrom, $dateTo, 'reversed_at');
        $refundBase = fn () => $this->scopeQuery(PaymentRefund::query(), $hospitalId, $dateFrom, $dateTo, 'processed_at');
        $adjustmentBase = fn () => $this->scopeQuery(BillingAdjustment::query(), $hospitalId, $dateFrom, $dateTo, 'adjusted_at');

        $postedInvoices = (clone $invoiceBase())->where('status', Invoice::STATUS_POSTED);

        $totalBilled = (float) (clone $postedInvoices)->sum('total_amount');
        $totalCollected = (float) (clone $paymentBase())->sum('amount');
        $totalReversals = (float) (clone $reversalBase())->sum('amount');
        $totalRefunds = (float) (clone $refundBase())->sum('amount');
        $creditAdjustments = (float) (clone $adjustmentBase())->where('direction', 'credit')->sum('amount');
        $debitAdjustments = (float) (clone $adjustmentBase())->where('direction', 'debit')->sum('amount');
        $totalAdjustments = $creditAdjustments - $debitAdjustments;

        $paymentsByMethod = (clone $paymentBase())
            ->select('payment_method', DB::raw('SUM(amount) as total'))
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method')
            ->map(fn ($v) => (float) $v)
            ->all();

        $invoicesByStatus = Invoice::query()
            ->when($hospitalId !== null, fn ($q) => $q->where('hospital_id', $hospitalId))
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->map(fn ($v) => (int) $v)
            ->all();

        $openCashierSessions = CashierSession::query()
            ->where('status', CashierSession::STATUS_OPEN)
            ->when($hospitalId !== null, fn ($q) => $q->where('hospital_id', $hospitalId))
            ->count();

        $cashierSessionsToday = CashierSession::query()
            ->where('opened_at', '>=', now()->startOfDay())
            ->when($hospitalId !== null, fn ($q) => $q->where('hospital_id', $hospitalId))
            ->count();

        return [
            'totalBilled' => $totalBilled,
            'totalCollected' => $totalCollected,
            'totalReversals' => $totalReversals,
            'totalRefunds' => $totalRefunds,
            'creditAdjustments' => $creditAdjustments,
            'debitAdjustments' => $debitAdjustments,
            'totalAdjustments' => $totalAdjustments,
            'paymentsByMethod' => $paymentsByMethod,
            'invoicesByStatus' => $invoicesByStatus,
            'openCashierSessions' => $openCashierSessions,
            'cashierSessionsToday' => $cashierSessionsToday,
        ];
    }

    /**
     * Invoice report query — filterable by status, date range, hospital.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Invoice>
     */
    public function invoiceReport(array $filters, ?int $hospitalId): Builder
    {
        $query = Invoice::query()
            ->with(['patient:id,first_name,last_name,patient_number', 'hospital:id,name'])
            ->when($hospitalId !== null, fn ($q) => $q->where('invoices.hospital_id', $hospitalId))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['date_from']), fn ($q) => $q->whereDate('invoice_date', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($q) => $q->whereDate('invoice_date', '<=', $filters['date_to']))
            ->when(! empty($filters['search']), function ($q) use ($filters) {
                $term = '%'.$filters['search'].'%';
                $q->where(fn ($s) => $s->where('invoice_number', 'like', $term));
            })
            ->latest('invoice_date');

        return $query;
    }

    /**
     * Payments report query — filterable by method, date range, hospital.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Payment>
     */
    public function paymentsReport(array $filters, ?int $hospitalId): Builder
    {
        return Payment::query()
            ->with(['patient:id,first_name,last_name,patient_number', 'invoice:id,invoice_number', 'receivedBy:id,UserName'])
            ->where('status', Payment::STATUS_POSTED)
            ->when($hospitalId !== null, fn ($q) => $q->where('hospital_id', $hospitalId))
            ->when(! empty($filters['payment_method']), fn ($q) => $q->where('payment_method', $filters['payment_method']))
            ->when(! empty($filters['date_from']), fn ($q) => $q->whereDate('payment_date', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($q) => $q->whereDate('payment_date', '<=', $filters['date_to']))
            ->latest('posted_at');
    }

    /**
     * Cashier session report query — filterable by status, date range, cashier.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<CashierSession>
     */
    public function cashierSessionsReport(array $filters, ?int $hospitalId): Builder
    {
        return CashierSession::query()
            ->with(['cashier:id,UserName', 'hospital:id,name'])
            ->when($hospitalId !== null, fn ($q) => $q->where('hospital_id', $hospitalId))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['cashier_id']), fn ($q) => $q->where('cashier_id', $filters['cashier_id']))
            ->when(! empty($filters['date_from']), fn ($q) => $q->whereDate('opened_at', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($q) => $q->whereDate('opened_at', '<=', $filters['date_to']))
            ->latest('opened_at');
    }

    /**
     * Corrections report — refunds, reversals, and adjustments in one view.
     *
     * @param  array<string, mixed>  $filters
     * @return array{refunds: LengthAwarePaginator, reversals: LengthAwarePaginator, adjustments: LengthAwarePaginator, totals: array<string, float>}
     */
    public function correctionsReport(array $filters, ?int $hospitalId): array
    {
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        $refunds = PaymentRefund::query()
            ->with(['patient:id,first_name,last_name,patient_number', 'processedBy:id,UserName'])
            ->when($hospitalId !== null, fn ($q) => $q->where('hospital_id', $hospitalId))
            ->when($dateFrom, fn ($q) => $q->whereDate('processed_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('processed_at', '<=', $dateTo))
            ->latest('processed_at');

        $reversals = PaymentReversal::query()
            ->with(['patient:id,first_name,last_name,patient_number', 'reversedBy:id,UserName'])
            ->when($hospitalId !== null, fn ($q) => $q->where('hospital_id', $hospitalId))
            ->when($dateFrom, fn ($q) => $q->whereDate('reversed_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('reversed_at', '<=', $dateTo))
            ->latest('reversed_at');

        $adjustments = BillingAdjustment::query()
            ->with(['patient:id,first_name,last_name,patient_number', 'adjustedBy:id,UserName'])
            ->when($hospitalId !== null, fn ($q) => $q->where('hospital_id', $hospitalId))
            ->when($dateFrom, fn ($q) => $q->whereDate('adjusted_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('adjusted_at', '<=', $dateTo))
            ->latest('adjusted_at');

        return [
            'refunds' => (clone $refunds)->paginate(20)->withQueryString(),
            'reversals' => (clone $reversals)->paginate(20)->withQueryString(),
            'adjustments' => (clone $adjustments)->paginate(20)->withQueryString(),
            'totals' => [
                'refunds' => (float) (clone $refunds)->sum('amount'),
                'reversals' => (float) (clone $reversals)->sum('amount'),
                'creditAdjustments' => (float) (clone $adjustments)->where('direction', 'credit')->sum('amount'),
                'debitAdjustments' => (float) (clone $adjustments)->where('direction', 'debit')->sum('amount'),
            ],
        ];
    }

    /**
     * Apply hospital scoping and date-range filters to any query.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function scopeQuery(Builder $query, ?int $hospitalId, ?string $dateFrom, ?string $dateTo, string $dateColumn): Builder
    {
        return $query
            ->when($hospitalId !== null, fn ($q) => $q->where('hospital_id', $hospitalId))
            ->when($dateFrom, fn ($q) => $q->whereDate($dateColumn, '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate($dateColumn, '<=', $dateTo));
    }
}
