<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Builds the payment list/filter query and the "eligible posted invoices
 * with outstanding patient balance" query for the create-payment screen
 * (Phase 7, Step 57) — mirrors InvoiceSearchService/BillingSearchService's
 * own "one query builder, several callers" shape.
 */
class PaymentSearchService
{
    public function __construct(protected PaymentService $payments) {}

    /**
     * Supported keys: search (payment/receipt number, patient name/number),
     * status, payment_method.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Payment>
     */
    public function paymentsQuery(array $filters): Builder
    {
        $query = Payment::query()->with([
            'patient:id,patient_number,first_name,middle_name,last_name',
            'hospital:id,name',
            'invoice:id,invoice_number',
        ]);

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('payment_number', 'like', "%{$search}%")
                    ->orWhere('receipt_number', 'like', "%{$search}%")
                    ->orWhere('reference_number', 'like', "%{$search}%")
                    ->orWhereHas('patient', function ($query) use ($search) {
                        $query->where('patient_number', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['payment_method'] ?? null)) {
            $query->where('payment_method', $filters['payment_method']);
        }

        return $query->latest('id');
    }

    /**
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeToHospital(Builder $query, ?int $hospitalId): Builder
    {
        if ($hospitalId === null) {
            return $query;
        }

        return $query->where('hospital_id', $hospitalId);
    }

    /**
     * Every POSTED, non-cancelled invoice for this patient that still
     * carries an outstanding patient balance (spec §57.26's own "view
     * eligible posted invoices" create-screen step) — never the final
     * word, only what the UI offers; createPayment() re-validates the exact
     * same calculation under a lock regardless of what this returned.
     * Bounded by a patient's realistic invoice count (mirrors this
     * codebase's own repeated "acceptable for pagination-scale data"
     * precedent, e.g. Step 50's dispensing-status computation) — never
     * paginated separately since a patient with a genuinely unbounded
     * number of posted invoices does not occur in practice.
     *
     * @return Collection<int, array{invoice: Invoice, outstanding: array}>
     */
    public function eligibleInvoicesForPatient(Patient $patient, ?int $hospitalId): Collection
    {
        $query = Invoice::query()
            ->where('patient_id', $patient->id)
            ->where('status', Invoice::STATUS_POSTED)
            ->with(['hospital:id,name']);

        if ($hospitalId !== null) {
            $query->where('hospital_id', $hospitalId);
        }

        return $query->latest('id')->get()
            ->map(fn (Invoice $invoice) => ['invoice' => $invoice, 'outstanding' => $this->payments->calculateOutstanding($invoice)])
            ->filter(fn (array $row) => $row['outstanding']['patient_outstanding'] > 0.0)
            ->values();
    }
}
