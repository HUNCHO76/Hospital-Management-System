<?php

namespace App\Http\Resources;

use App\Models\Dispensing;
use App\Models\PrescriptionOrder;
use App\Models\PrescriptionOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * The pharmacy dispensing view of a PrescriptionOrder (Phase 6, Step 48) —
 * reused on both the dispensing worklist and detail page, mirroring
 * PrescriptionVerificationResource's own role for the verification queue.
 * Every item's dispensed/remaining quantity, and the order's overall
 * fulfillment_status, are computed purely from eager-loaded relations
 * (`items.dispensingItems.dispensing`) rather than by re-querying — the
 * caller must eager-load that chain. Deliberately never stored anywhere
 * (see Dispensing/DispensingItem migration docblocks for why): this is a
 * read-time projection only.
 *
 * @mixin PrescriptionOrder
 */
class PrescriptionDispensingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $encounter = $this->relationLoaded('clinicalEncounter') ? $this->clinicalEncounter : null;
        $items = $this->relationLoaded('items') ? $this->items : collect();
        $dispensings = $this->relationLoaded('dispensings') ? $this->dispensings : collect();

        return [
            'id' => $this->id,
            'prescription_number' => $this->prescription_number,
            'clinical_notes' => $this->clinical_notes,
            'status' => $this->status,
            'prescriber' => $this->whenLoaded('prescriber', fn () => $this->prescriber ? [
                'id' => $this->prescriber->id,
                'name' => $this->prescriber->UserName,
            ] : null),
            'prescribed_at' => optional($this->prescribed_at)->toIso8601String(),
            'verification' => $this->whenLoaded('verification', fn () => $this->verification ? [
                'status' => $this->verification->status,
                'verified_by' => $this->verification->relationLoaded('verifiedBy') && $this->verification->verifiedBy
                    ? ['id' => $this->verification->verifiedBy->id, 'name' => $this->verification->verifiedBy->UserName]
                    : null,
                'verified_at' => optional($this->verification->verified_at)->toIso8601String(),
            ] : null),
            'fulfillment_status' => $this->fulfillmentStatus($items),
            'items' => $items->map(fn (PrescriptionOrderItem $item) => [
                'id' => $item->id,
                'medication_name_snapshot' => $item->medication_name_snapshot,
                'strength_snapshot' => $item->strength_snapshot,
                'form_snapshot' => $item->form_snapshot,
                'dose' => $item->dose,
                'dose_unit' => $item->dose_unit,
                'frequency' => $item->frequency,
                'route' => $item->route,
                'duration' => $item->duration,
                'duration_unit' => $item->duration_unit,
                'quantity' => $item->quantity,
                'quantity_unit' => $item->quantity_unit,
                'instructions' => $item->instructions,
                'status' => $item->status,
                'dispensed_quantity' => $this->itemDispensedQuantity($item),
                'remaining_quantity' => max(0, $item->quantity - $this->itemDispensedQuantity($item)),
            ])->values()->all(),
            'dispensings' => $dispensings->map(fn (Dispensing $dispensing) => (new DispensingResource($dispensing))->resolve($request))->values()->all(),
            'current_dispensing' => (function () use ($dispensings, $request) {
                $current = $dispensings->firstWhere('status', Dispensing::STATUS_IN_PROGRESS);

                return $current ? (new DispensingResource($current))->resolve($request) : null;
            })(),
            'patient' => $encounter && $encounter->relationLoaded('patient') && $encounter->patient ? [
                'id' => $encounter->patient->id,
                'patient_number' => $encounter->patient->patient_number,
                'first_name' => $encounter->patient->first_name,
                'middle_name' => $encounter->patient->middle_name,
                'last_name' => $encounter->patient->last_name,
                'date_of_birth' => optional($encounter->patient->date_of_birth)->toDateString(),
                'gender' => $encounter->patient->gender,
            ] : null,
            'encounter' => $encounter ? [
                'id' => $encounter->id,
                'encounter_number' => $encounter->encounter_number,
            ] : null,
            'department' => $encounter && $encounter->relationLoaded('department') && $encounter->department ? [
                'id' => $encounter->department->id,
                'name' => $encounter->department->name,
            ] : null,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }

    /**
     * Sum this item's non-cancelled dispensing history — requires
     * `dispensingItems.dispensing` to be eager-loaded; falls back to 0
     * (rather than a stray query) when it is not, since a resource must
     * never silently N+1 query per row.
     */
    protected function itemDispensedQuantity(PrescriptionOrderItem $item): int
    {
        if (! $item->relationLoaded('dispensingItems')) {
            return 0;
        }

        return (int) $item->dispensingItems
            ->filter(fn ($dispensingItem) => $dispensingItem->relationLoaded('dispensing')
                && $dispensingItem->dispensing
                && $dispensingItem->dispensing->status !== Dispensing::STATUS_CANCELLED)
            ->sum('dispensed_quantity');
    }

    /**
     * @param  Collection<int, PrescriptionOrderItem>  $items
     * @return 'pending'|'partially_dispensed'|'dispensed'
     */
    protected function fulfillmentStatus($items): string
    {
        $activeItems = $items->where('status', PrescriptionOrderItem::STATUS_ACTIVE);

        if ($activeItems->isEmpty()) {
            return 'pending';
        }

        $anyDispensed = false;
        $allFulfilled = true;

        foreach ($activeItems as $item) {
            $dispensed = $this->itemDispensedQuantity($item);

            if ($dispensed > 0) {
                $anyDispensed = true;
            }

            if ($dispensed < $item->quantity) {
                $allFulfilled = false;
            }
        }

        if ($allFulfilled) {
            return 'dispensed';
        }

        return $anyDispensed ? 'partially_dispensed' : 'pending';
    }
}
