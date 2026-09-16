<?php

namespace App\Services;

use App\Models\Dispensing;
use App\Models\DispensingItem;
use App\Models\Medication;
use App\Models\PrescriptionOrder;
use App\Models\PrescriptionOrderItem;
use App\Models\PrescriptionVerification;
use App\Models\StockBatch;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reporting/analytics over the pharmacy data Steps 44-49 already own (Phase
 * 6, Step 50) — every figure here is a live aggregation against
 * authoritative transactional tables, never a stored/cached summary (spec
 * §4/§26/§31). Reuses each domain's own authoritative service wherever one
 * already exists (StockSearchService's classification subqueries,
 * StockService::reconcile(), DispensingService::fulfillmentStatus()) rather
 * than re-deriving the same business rule a second, potentially conflicting
 * way.
 */
class PharmacyReportService
{
    public function __construct(
        protected StockSearchService $stockSearch,
        protected StockService $stockService,
        protected DispensingService $dispensingService,
    ) {}

    /**
     * The enriched `/pharmacy` dashboard's own KPI set (spec §5) — real
     * counts only, never a fabricated statistic. Verification/dispensing
     * buckets are current-state counts (how many sit in that state right
     * now); prescriptions today/this_week/this_month are the only
     * explicitly time-boxed figures spec §5 itself asks for.
     *
     * @return array<string, mixed>
     */
    public function dashboardMetrics(?int $hospitalId): array
    {
        $prescriptionQuery = fn () => $this->scopedPrescriptionQuery($hospitalId);

        $prescriptions = [
            'today' => (clone $prescriptionQuery())->where('prescribed_at', '>=', now()->startOfDay())->count(),
            'this_week' => (clone $prescriptionQuery())->where('prescribed_at', '>=', now()->startOfWeek())->count(),
            'this_month' => (clone $prescriptionQuery())->where('prescribed_at', '>=', now()->startOfMonth())->count(),
            'cancelled' => (clone $prescriptionQuery())->where('status', PrescriptionOrder::STATUS_CANCELLED)->count(),
        ];

        $verificationBase = fn () => $this->scopedVerificationQuery($hospitalId);
        $verification = [
            'pending' => (clone $verificationBase())->where('prescription_verifications.status', PrescriptionVerification::STATUS_PENDING_VERIFICATION)->count(),
            'verified' => (clone $verificationBase())->where('prescription_verifications.status', PrescriptionVerification::STATUS_VERIFIED)->count(),
            'returned' => (clone $verificationBase())->where('prescription_verifications.status', PrescriptionVerification::STATUS_RETURNED)->count(),
        ];

        $dispensing = $this->dispensingBuckets($hospitalId);

        $stockFilters = fn (string $status) => $this->stockSearch->count(['status' => $status], $hospitalId);

        $stock = [
            'active_medications' => Medication::query()->where('status', Medication::STATUS_ACTIVE)->count(),
            'total_stock_items' => $this->stockSearch->count([], $hospitalId),
            'low_stock' => $stockFilters('low_stock'),
            'out_of_stock' => $stockFilters('out_of_stock'),
            'expiring_soon' => $stockFilters('expiring_soon'),
            'expired' => $stockFilters('expired'),
            'quarantined' => $stockFilters('quarantined'),
            'damaged' => $stockFilters('damaged'),
        ];

        $movements = $this->movementCounts($hospitalId, now()->startOfMonth());

        return compact('prescriptions', 'verification', 'dispensing', 'stock', 'movements');
    }

    /**
     * Build the prescription report query (spec §12) — every prescription
     * order with enough eager-loaded context to display prescriber,
     * facility, verification status, and medication count without N+1.
     * Dispensing/fulfillment status is deliberately not joined here (it has
     * no single-row SQL representation without the same conditional
     * aggregation dispensingBuckets() below performs) — the controller
     * computes it per row only for the current page via
     * DispensingService::fulfillmentStatus(), bounded by pagination.
     *
     * Supported keys: search, status (order status), verification_status,
     * prescriber_id, date_from/date_to (against prescribed_at).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<PrescriptionOrder>
     */
    public function prescriptionReport(array $filters): Builder
    {
        $query = PrescriptionOrder::query()
            ->leftJoin('prescription_verifications', 'prescription_verifications.prescription_order_id', '=', 'prescription_orders.id')
            ->select('prescription_orders.*')
            ->with([
                'items' => fn ($query) => $query->where('status', PrescriptionOrderItem::STATUS_ACTIVE),
                'items.dispensingItems.dispensing:id,status',
                'prescriber:id,UserName',
                'verification',
                'clinicalEncounter:id,encounter_number,patient_id,department_id',
                'clinicalEncounter.patient:id,patient_number,first_name,middle_name,last_name',
                'clinicalEncounter.department:id,name',
            ]);

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('prescription_orders.prescription_number', 'like', "%{$search}%")
                    ->orWhereHas('clinicalEncounter.patient', function ($query) use ($search) {
                        $query->where('patient_number', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('prescription_orders.status', $filters['status']);
        }

        if (filled($filters['verification_status'] ?? null)) {
            $query->where('prescription_verifications.status', $filters['verification_status']);
        }

        if (filled($filters['prescriber_id'] ?? null)) {
            $query->where('prescription_orders.prescriber_id', $filters['prescriber_id']);
        }

        if (filled($filters['date_from'] ?? null)) {
            $query->whereDate('prescription_orders.prescribed_at', '>=', $filters['date_from']);
        }

        if (filled($filters['date_to'] ?? null)) {
            $query->whereDate('prescription_orders.prescribed_at', '<=', $filters['date_to']);
        }

        return $query->orderByDesc('prescription_orders.prescribed_at');
    }

    /**
     * Restrict a prescriptionReport() result to the given hospital.
     *
     * @param  Builder<PrescriptionOrder>  $query
     * @return Builder<PrescriptionOrder>
     */
    public function scopePrescriptionsToHospital(Builder $query, ?int $hospitalId): Builder
    {
        if ($hospitalId === null) {
            return $query;
        }

        return $query->whereHas(
            'clinicalEncounter.department',
            fn ($query) => $query->where('hospital_id', $hospitalId),
        );
    }

    /**
     * Build the dispensing report query (spec §13) — one row per
     * DispensingItem (the medication-level fulfillment granularity the
     * report's own column list asks for: prescribed/dispensed/remaining per
     * medication), joined back to its parent Dispensing event, prescription,
     * encounter, and patient. Cancelled dispensing events never reach this
     * report — cancel() is only ever called before complete() creates any
     * DispensingItem rows, so a cancelled event has none to show; its count
     * is instead one of dashboardMetrics()'s own dispensing buckets.
     *
     * Supported keys: search, status (dispensing_items.status), dispenser_id,
     * date_from/date_to (against dispensings.completed_at).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<DispensingItem>
     */
    public function dispensingReport(array $filters): Builder
    {
        $query = DispensingItem::query()
            ->join('dispensings', 'dispensings.id', '=', 'dispensing_items.dispensing_id')
            ->join('prescription_order_items', 'prescription_order_items.id', '=', 'dispensing_items.prescription_order_item_id')
            ->join('prescription_orders', 'prescription_orders.id', '=', 'dispensings.prescription_order_id')
            ->select('dispensing_items.*')
            ->with([
                'dispensing:id,dispensing_number,status,dispensed_by,completed_at,prescription_order_id',
                'dispensing.dispensedBy:id,UserName',
                'dispensing.prescriptionOrder:id,prescription_number,clinical_encounter_id',
                'dispensing.prescriptionOrder.clinicalEncounter:id,encounter_number,patient_id,department_id',
                'dispensing.prescriptionOrder.clinicalEncounter.patient:id,patient_number,first_name,middle_name,last_name',
                'dispensing.prescriptionOrder.clinicalEncounter.department:id,name',
                'prescriptionOrderItem:id,medication_name_snapshot,quantity',
            ]);

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('prescription_orders.prescription_number', 'like', "%{$search}%")
                    ->orWhereHas('prescriptionOrderItem', fn ($query) => $query->where('medication_name_snapshot', 'like', "%{$search}%"))
                    ->orWhereHas('dispensing.prescriptionOrder.clinicalEncounter.patient', function ($query) use ($search) {
                        $query->where('patient_number', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('dispensing_items.status', $filters['status']);
        }

        if (filled($filters['dispenser_id'] ?? null)) {
            $query->where('dispensings.dispensed_by', $filters['dispenser_id']);
        }

        if (filled($filters['date_from'] ?? null)) {
            $query->whereDate('dispensings.completed_at', '>=', $filters['date_from']);
        }

        if (filled($filters['date_to'] ?? null)) {
            $query->whereDate('dispensings.completed_at', '<=', $filters['date_to']);
        }

        return $query->orderByDesc('dispensings.completed_at');
    }

    /**
     * Restrict a dispensingReport() result to the given hospital.
     *
     * @param  Builder<DispensingItem>  $query
     * @return Builder<DispensingItem>
     */
    public function scopeDispensingToHospital(Builder $query, ?int $hospitalId): Builder
    {
        if ($hospitalId === null) {
            return $query;
        }

        return $query->whereHas(
            'dispensing.prescriptionOrder.clinicalEncounter.department',
            fn ($query) => $query->where('hospital_id', $hospitalId),
        );
    }

    /**
     * Medication utilization over a period (spec §11) — quantity actually
     * dispensed (never prescribed-but-not-yet-fulfilled), grouped by
     * medication and by day/week/month. A pure aggregation query; no
     * separate summary table is ever written.
     *
     * Supported keys: medication_id, date_from/date_to (against
     * dispensings.completed_at), group_by ('day'|'week'|'month', default
     * 'month').
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    public function medicationUtilization(array $filters, ?int $hospitalId): Collection
    {
        $periodExpr = $this->utilizationPeriodExpr($filters['group_by'] ?? 'month');

        $query = DispensingItem::query()
            ->join('dispensings', 'dispensings.id', '=', 'dispensing_items.dispensing_id')
            ->join('prescription_order_items', 'prescription_order_items.id', '=', 'dispensing_items.prescription_order_item_id')
            ->join('prescription_orders', 'prescription_orders.id', '=', 'dispensings.prescription_order_id')
            ->join('clinical_encounters', 'clinical_encounters.id', '=', 'prescription_orders.clinical_encounter_id')
            ->join('departments', 'departments.id', '=', 'clinical_encounters.department_id')
            ->where('dispensing_items.status', DispensingItem::STATUS_DISPENSED)
            ->where('dispensings.status', '!=', Dispensing::STATUS_CANCELLED);

        if ($hospitalId !== null) {
            $query->where('departments.hospital_id', $hospitalId);
        }

        if (filled($filters['medication_id'] ?? null)) {
            $query->where('prescription_order_items.medication_id', $filters['medication_id']);
        }

        if (filled($filters['date_from'] ?? null)) {
            $query->whereDate('dispensings.completed_at', '>=', $filters['date_from']);
        }

        if (filled($filters['date_to'] ?? null)) {
            $query->whereDate('dispensings.completed_at', '<=', $filters['date_to']);
        }

        return $query
            ->selectRaw('prescription_order_items.medication_name_snapshot as medication_name')
            ->selectRaw("{$periodExpr} as period")
            ->selectRaw('SUM(dispensing_items.dispensed_quantity) as total_quantity')
            ->selectRaw('COUNT(DISTINCT dispensings.id) as dispensing_events')
            ->selectRaw('COUNT(DISTINCT prescription_orders.id) as prescription_count')
            ->selectRaw('COUNT(DISTINCT clinical_encounters.patient_id) as patient_count')
            ->groupBy('medication_name', 'period')
            ->orderBy('period')
            ->orderByDesc('total_quantity')
            ->get();
    }

    /**
     * Period grouping SQL for medicationUtilization(), branched on the
     * active database driver: MySQL in production, SQLite for the test
     * suite. Only the 'week'/'month' cases differ between drivers — DATE()
     * is supported by both.
     */
    protected function utilizationPeriodExpr(string $groupBy): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return match ($groupBy) {
                'day' => "strftime('%Y-%m-%d', dispensings.completed_at)",
                'week' => "strftime('%Y-W%W', dispensings.completed_at)",
                default => "strftime('%Y-%m', dispensings.completed_at)",
            };
        }

        return match ($groupBy) {
            'day' => 'DATE(dispensings.completed_at)',
            'week' => "DATE_FORMAT(dispensings.completed_at, '%x-W%v')",
            default => "DATE_FORMAT(dispensings.completed_at, '%Y-%m')",
        };
    }

    /**
     * Stock reconciliation (spec §15/§47) — reuses StockService::reconcile()
     * per batch (never a second, parallel calculation of the same ledger
     * check) and flags any mismatch rather than silently correcting it.
     *
     * @return Collection<int, array{batch: StockBatch, ledger_balance: int, recorded_quantity: int, reconciled: bool}>
     */
    public function reconciliation(Collection $batches): Collection
    {
        return $batches->map(function (StockBatch $batch) {
            return ['batch' => $batch, ...$this->stockService->reconcile($batch)];
        });
    }

    /**
     * Dispensing fulfillment buckets (spec §5's pending/partial/full/
     * cancelled dispensing KPIs) — computed by reusing
     * DispensingService::fulfillmentStatus() (the same authoritative
     * per-order calculation the dispensing worklist itself relies on, spec
     * §31) over every currently-VERIFIED, active order, bounded to a
     * hospital's own realistic in-flight prescription volume rather than
     * the whole historical table.
     *
     * @return array{pending: int, partially_dispensed: int, dispensed: int, cancelled_events: int}
     */
    protected function dispensingBuckets(?int $hospitalId): array
    {
        $orders = PrescriptionOrder::query()
            ->join('prescription_verifications', 'prescription_verifications.prescription_order_id', '=', 'prescription_orders.id')
            ->where('prescription_orders.status', PrescriptionOrder::STATUS_ACTIVE)
            ->where('prescription_verifications.status', PrescriptionVerification::STATUS_VERIFIED)
            ->select('prescription_orders.*')
            ->with(['items' => fn ($query) => $query->where('status', PrescriptionOrderItem::STATUS_ACTIVE), 'items.dispensingItems.dispensing:id,status']);

        $this->scopePrescriptionsToHospital($orders, $hospitalId);

        $buckets = ['pending' => 0, 'partially_dispensed' => 0, 'dispensed' => 0];

        foreach ($orders->get() as $order) {
            $buckets[$this->dispensingService->fulfillmentStatus($order)]++;
        }

        $cancelledEvents = Dispensing::query()
            ->where('status', Dispensing::STATUS_CANCELLED)
            ->whereHas(
                'prescriptionOrder.clinicalEncounter.department',
                fn ($query) => $hospitalId === null ? $query : $query->where('hospital_id', $hospitalId),
            )
            ->count();

        return [...$buckets, 'cancelled_events' => $cancelledEvents];
    }

    /**
     * Stock movement counts for the current month, bucketed by transaction
     * type (spec §5's movement KPIs) — reuses StockSearchService's own
     * hospital-scoping join shape.
     *
     * @return array<string, int>
     */
    protected function movementCounts(?int $hospitalId, Carbon $since): array
    {
        $query = $this->stockSearch->movementsQuery(['date_from' => $since->toDateString()]);

        $this->stockSearch->scopeMovementsToHospital($query, $hospitalId);

        $counts = (clone $query)
            ->select(['stock_transactions.transaction_type'])
            ->selectRaw('COUNT(*) as total')
            ->groupBy('stock_transactions.transaction_type')
            ->pluck('total', 'stock_transactions.transaction_type');

        return [
            'receipts' => (int) ($counts['receipt'] ?? 0),

            'dispense' => (int) ($counts['dispense'] ?? 0),

            'adjustments' => (int) ($counts['adjustment_in'] ?? 0)
                + (int) ($counts['adjustment_out'] ?? 0),

            'expired_damaged' => (int) ($counts['expired'] ?? 0)
                + (int) ($counts['damaged'] ?? 0),
        ];
    }

    /**
     * @return Builder<PrescriptionOrder>
     */
    protected function scopedPrescriptionQuery(?int $hospitalId): Builder
    {
        $query = PrescriptionOrder::query();

        return $this->scopePrescriptionsToHospital($query, $hospitalId);
    }

    /**
     * @return Builder<PrescriptionOrder>
     */
    protected function scopedVerificationQuery(?int $hospitalId): Builder
    {
        $query = PrescriptionOrder::query()
            ->join('prescription_verifications', 'prescription_verifications.prescription_order_id', '=', 'prescription_orders.id');

        return $this->scopePrescriptionsToHospital($query, $hospitalId);
    }
}
