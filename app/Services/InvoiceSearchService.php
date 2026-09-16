<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\PatientAccount;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Builds the invoice list/filter query and the "eligible uninvoiced
 * charges" query for the invoice builder (Phase 7, Step 55) — mirrors
 * BillingSearchService's own "one query builder, several callers" shape.
 */
class InvoiceSearchService
{
    /**
     * Supported keys: search (invoice number/patient name/account number),
     * status.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Invoice>
     */
    public function invoicesQuery(array $filters): Builder
    {
        $query = Invoice::query()->with([
            'patient:id,patient_number,first_name,middle_name,last_name',
            'hospital:id,name',
            'patientAccount:id,account_number',
        ]);

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('invoice_number', 'like', "%{$search}%")
                    ->orWhereHas('patient', function ($query) use ($search) {
                        $query->where('patient_number', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('patientAccount', function ($query) use ($search) {
                        $query->where('account_number', 'like', "%{$search}%");
                    });
            });
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        return $query->latest('id');
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeToHospital(Builder $query, ?int $hospitalId): Builder
    {
        if ($hospitalId === null) {
            return $query;
        }

        return $query->where('hospital_id', $hospitalId);
    }

    /**
     * Every charge eligible for invoicing on this account right now — POSTED
     * and not already linked to another invoice (spec §10/§16). This is the
     * one query both the "create draft" builder and the "add charge to an
     * existing draft" picker use; never trusted as the final word (every
     * selection is re-validated under a lock by InvoiceService::
     * addCharge()), only as what the UI offers.
     *
     * @return Builder<Charge>
     */
    public function eligibleChargesQuery(PatientAccount $account): Builder
    {
        return Charge::query()
            ->where('patient_account_id', $account->id)
            ->where('status', Charge::STATUS_POSTED)
            ->whereNull('invoice_id')
            ->with('service:id,code,name')
            ->orderBy('charged_at');
    }
}
