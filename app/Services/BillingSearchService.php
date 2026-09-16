<?php

namespace App\Services;

use App\Models\PatientAccount;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Builds the patient-account search/filter query (Phase 7, Step 52) —
 * mirrors StockSearchService/DispensingSearchService's "one query builder,
 * several callers" shape.
 */
class BillingSearchService
{
    /**
     * Supported keys: search (patient number/name or account number),
     * status.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<PatientAccount>
     */
    public function accountsQuery(array $filters): Builder
    {
        $query = PatientAccount::query()->with(['patient:id,patient_number,first_name,middle_name,last_name', 'hospital:id,name']);

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('account_number', 'like', "%{$search}%")
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

        return $query->latest('id');
    }

    /**
     * @param  Builder<PatientAccount>  $query
     * @return Builder<PatientAccount>
     */
    public function scopeToHospital(Builder $query, ?int $hospitalId): Builder
    {
        if ($hospitalId === null) {
            return $query;
        }

        return $query->where('hospital_id', $hospitalId);
    }
}
