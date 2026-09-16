<?php

namespace App\Services;

use App\Models\Dispensing;
use App\Models\PrescriptionOrder;
use App\Models\PrescriptionOrderItem;
use App\Models\PrescriptionVerification;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Builds the pharmacy dispensing queue query (Phase 6, Step 48) — every
 * VERIFIED, still-active PrescriptionOrder that has at least one active
 * medication with a remaining quantity greater than zero. Mirrors
 * PharmacySearchService's shape. "Remaining" is expressed directly in SQL
 * (never computed by fetching everything and filtering in PHP) so
 * pagination stays correct.
 */
class DispensingSearchService
{
    /**
     * Supported keys: search (prescription/patient number/name or
     * medication name), status ('' = actionable/pending, 'dispensed' =
     * fully fulfilled), date_from, date_to (against verification.verified_at
     * — when the prescription became eligible for dispensing).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<PrescriptionOrder>
     */
    public function query(array $filters): Builder
    {
        $query = PrescriptionOrder::query()
            ->join('prescription_verifications', 'prescription_verifications.prescription_order_id', '=', 'prescription_orders.id')
            ->select('prescription_orders.*')
            ->where('prescription_orders.status', PrescriptionOrder::STATUS_ACTIVE)
            ->where('prescription_verifications.status', PrescriptionVerification::STATUS_VERIFIED)
            ->with([
                'items.medication:id,generic_name,category',
                'prescriber:id,UserName',
                'verification.verifiedBy:id,UserName',
                'clinicalEncounter:id,encounter_number,patient_id,department_id',
                'clinicalEncounter.patient:id,patient_number,first_name,middle_name,last_name',
                'clinicalEncounter.department:id,name',
            ]);

        $status = $filters['status'] ?? null;

        if ($status === 'dispensed') {
            $query->whereDoesntHave('items', fn ($query) => $this->withRemainingQuantity($query));
        } else {
            $query->whereHas('items', fn ($query) => $this->withRemainingQuantity($query));
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('prescription_number', 'like', "%{$search}%")
                    ->orWhereHas('items.medication', function ($query) use ($search) {
                        $query->where('generic_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('clinicalEncounter.patient', function ($query) use ($search) {
                        $query->where('patient_number', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        if (filled($filters['date_from'] ?? null)) {
            $query->whereDate('prescription_verifications.verified_at', '>=', $filters['date_from']);
        }

        if (filled($filters['date_to'] ?? null)) {
            $query->whereDate('prescription_verifications.verified_at', '<=', $filters['date_to']);
        }

        return $query->orderByDesc('prescription_verifications.verified_at');
    }

    /**
     * Restrict a query() result to orders booked under the given hospital's
     * departments.
     *
     * @param  Builder<PrescriptionOrder>  $query
     * @return Builder<PrescriptionOrder>
     */
    public function scopeToHospital(Builder $query, ?int $hospitalId): Builder
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
     * Constrain an items() query to active items whose prescribed quantity
     * still exceeds everything dispensed against it across every
     * non-cancelled Dispensing event — the SQL expression of
     * DispensingService::remainingQuantity() > 0.
     */
    protected function withRemainingQuantity(Builder $query): Builder
    {
        return $query->where('status', PrescriptionOrderItem::STATUS_ACTIVE)
            ->whereRaw(
                'quantity > COALESCE((SELECT SUM(dispensing_items.dispensed_quantity) FROM dispensing_items '.
                'INNER JOIN dispensings ON dispensings.id = dispensing_items.dispensing_id '.
                'WHERE dispensing_items.prescription_order_item_id = prescription_order_items.id '.
                'AND dispensings.status != ?), 0)',
                [Dispensing::STATUS_CANCELLED],
            );
    }
}
