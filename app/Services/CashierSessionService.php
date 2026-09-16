<?php

namespace App\Services;

use App\Models\CashierSession;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\PaymentReversal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the cashier session lifecycle (Phase 7, Step 59): open, summarise
 * collections, and close with reconciliation. Financial data is never
 * duplicated — getCollectionSummary() reads from the existing Payment,
 * PaymentRefund, and PaymentReversal tables using the cashier_id + time
 * window rather than maintaining a separate ledger.
 */
class CashierSessionService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Open a new cashier session (spec §59.3) — acquires a lock on the
     * cashier's existing open sessions to prevent concurrent duplicates,
     * rejects the request if one is already open, then creates the session
     * atomically.
     *
     * @param  array{hospital_id: int, opening_balance?: float|string|null, notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function open(array $data, Request $request): CashierSession
    {
        $openingBalance = round((float) ($data['opening_balance'] ?? 0), 2);

        if ($openingBalance < 0.0) {
            throw ValidationException::withMessages([
                'opening_balance' => 'Opening balance cannot be negative.',
            ]);
        }

        $session = DB::transaction(function () use ($data, $openingBalance, $request) {
            // Lock the set of open sessions for this cashier+hospital to
            // serialise concurrent open attempts — same pattern as
            // PaymentService::assertReferenceNotAlreadyUsed().
            $alreadyOpen = CashierSession::query()
                ->where('hospital_id', $data['hospital_id'])
                ->where('cashier_id', $request->user()->id)
                ->where('status', CashierSession::STATUS_OPEN)
                ->lockForUpdate()
                ->exists();

            if ($alreadyOpen) {
                throw ValidationException::withMessages([
                    'status' => 'You already have an open session at this facility. Close it before opening a new one.',
                ]);
            }

            return CashierSession::create([
                'hospital_id' => $data['hospital_id'],
                'cashier_id' => $request->user()->id,
                'status' => CashierSession::STATUS_OPEN,
                'opening_balance' => $openingBalance,
                'opened_at' => now(),
                'notes' => filled($data['notes'] ?? null) ? $data['notes'] : null,
            ]);
        });

        $this->auditLog->record(
            'cashier_session_opened',
            $session,
            null,
            ['session_number' => $session->session_number, 'opening_balance' => $session->opening_balance],
            $request,
        );

        return $session->fresh(['hospital', 'cashier']);
    }

    /**
     * Aggregate the real financial activity that occurred during this session
     * (spec §59.5) — queries Payment, PaymentRefund, and PaymentReversal by
     * cashier_id + hospital_id + time window, never a cached figure. Returns
     * payment-method subtotals for the close/reconcile screen.
     *
     * @return array{
     *     payment_totals: array<string, float>,
     *     refund_totals: array<string, float>,
     *     reversal_total: float,
     *     net_cash_collected: float,
     * }
     */
    public function getCollectionSummary(CashierSession $session): array
    {
        $closingAt = $session->closed_at ?? now();

        // Payments received by this cashier in this hospital during the window.
        $paymentRows = Payment::query()
            ->where('hospital_id', $session->hospital_id)
            ->where('received_by', $session->cashier_id)
            ->where('status', Payment::STATUS_POSTED)
            ->whereBetween('posted_at', [$session->opened_at, $closingAt])
            ->selectRaw('payment_method, SUM(amount) as total')
            ->groupBy('payment_method')
            ->get();

        $paymentTotals = [];
        foreach (Payment::METHODS as $method) {
            $paymentTotals[$method] = 0.0;
        }
        foreach ($paymentRows as $row) {
            $paymentTotals[$row->payment_method] = round((float) $row->total, 2);
        }

        // Refunds processed by this cashier in this hospital during the window.
        $refundRows = PaymentRefund::query()
            ->where('hospital_id', $session->hospital_id)
            ->where('processed_by', $session->cashier_id)
            ->whereBetween('processed_at', [$session->opened_at, $closingAt])
            ->selectRaw('refund_method, SUM(amount) as total')
            ->groupBy('refund_method')
            ->get();

        $refundTotals = [];
        foreach (Payment::METHODS as $method) {
            $refundTotals[$method] = 0.0;
        }
        foreach ($refundRows as $row) {
            $refundTotals[$row->refund_method] = round((float) $row->total, 2);
        }

        // Reversals processed by this cashier during the window.
        $reversalTotal = round((float) PaymentReversal::query()
            ->where('hospital_id', $session->hospital_id)
            ->where('reversed_by', $session->cashier_id)
            ->whereBetween('reversed_at', [$session->opened_at, $closingAt])
            ->sum('amount'), 2);

        // Net physical cash in the drawer = opening + cash payments - cash refunds.
        $netCashCollected = round(
            $paymentTotals[Payment::METHOD_CASH] - $refundTotals[Payment::METHOD_CASH],
            2
        );

        return compact('paymentTotals', 'refundTotals', 'reversalTotal', 'netCashCollected');
    }

    /**
     * Close a session and record reconciliation (spec §59.6) — computes the
     * expected closing balance from real financial records, stores the actual
     * closing balance, computes the variance, and requires a variance_reason
     * when the variance is non-zero.
     *
     * @param  array{actual_closing_balance: float|string, variance_reason?: string|null, notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function close(CashierSession $session, array $data, Request $request): CashierSession
    {
        $actualClosing = round((float) ($data['actual_closing_balance'] ?? 0), 2);

        if ($actualClosing < 0.0) {
            throw ValidationException::withMessages([
                'actual_closing_balance' => 'Actual closing balance cannot be negative.',
            ]);
        }

        $session = DB::transaction(function () use ($session, $actualClosing, $data) {
            $locked = CashierSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages([
                    'status' => 'This session is already closed.',
                ]);
            }

            $closedAt = now();
            $summary = $this->getCollectionSummary($locked);
            $expectedClosing = round((float) $locked->opening_balance + $summary['netCashCollected'], 2);
            $variance = round($actualClosing - $expectedClosing, 2);

            $varianceReason = trim($data['variance_reason'] ?? '');

            if ($variance != 0.0 && $varianceReason === '') {
                throw ValidationException::withMessages([
                    'variance_reason' => 'A variance reason is required when the actual closing balance differs from the expected balance.',
                ]);
            }

            $locked->update([
                'status' => CashierSession::STATUS_CLOSED,
                'expected_closing_balance' => $expectedClosing,
                'actual_closing_balance' => $actualClosing,
                'variance' => $variance,
                'variance_reason' => $variance != 0.0 ? $varianceReason : null,
                'closed_at' => $closedAt,
                'notes' => filled($data['notes'] ?? null) ? $data['notes'] : $locked->notes,
            ]);

            return $locked;
        });

        $this->auditLog->record(
            'cashier_session_closed',
            $session,
            ['status' => CashierSession::STATUS_OPEN],
            [
                'session_number' => $session->session_number,
                'expected_closing_balance' => $session->expected_closing_balance,
                'actual_closing_balance' => $session->actual_closing_balance,
                'variance' => $session->variance,
            ],
            $request,
        );

        return $session->fresh(['hospital', 'cashier']);
    }
}
