<?php

namespace App\Services;

use App\Models\Service;
use App\Models\ServicePrice;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The single authoritative "what does this service cost, at this hospital,
 * on this date" lookup (Phase 7, Step 53, spec §30/§49) — Step 54's charge
 * capture calls this directly so price-selection logic exists in exactly one
 * place. Never trusts a client-supplied price (spec §30).
 *
 * Specialist/provider-specific pricing: when a $practitionerId is supplied,
 * the resolver first looks for a price row where practitioner_id = that ID.
 * If one exists it wins unconditionally over the facility-wide price
 * (practitioner_id IS NULL). If no practitioner-specific row exists, the
 * facility-wide price is returned as a fallback. This enables per-specialist
 * consultation fees while preserving backward compatibility with all
 * existing service prices that have no practitioner_id set.
 */
class ServicePriceResolver
{
    /**
     * @throws ValidationException
     */
    public function resolvePrice(Service $service, int $hospitalId, ?Carbon $date = null, ?int $practitionerId = null): ServicePrice
    {
        $date ??= now();

        if (! $service->is_billable) {
            throw ValidationException::withMessages(['service' => "\"{$service->name}\" is not billable."]);
        }

        if ($service->status !== Service::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['service' => "\"{$service->name}\" is not currently active."]);
        }

        $dateString = $date->toDateString();

        $baseQuery = fn () => ServicePrice::query()
            ->where('service_id', $service->id)
            ->where('hospital_id', $hospitalId)
            ->where('status', ServicePrice::STATUS_ACTIVE)
            ->whereDate('effective_from', '<=', $dateString)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $dateString));

        // When a practitioner is known, prefer a practitioner-specific price.
        if ($practitionerId !== null) {
            $practitionerPrices = $baseQuery()->where('practitioner_id', $practitionerId)->get();

            if ($practitionerPrices->count() === 1) {
                return $practitionerPrices->first();
            }

            if ($practitionerPrices->count() > 1) {
                throw ValidationException::withMessages([
                    'price' => "Ambiguous pricing for \"{$service->name}\" for this practitioner at this facility on {$dateString} — multiple overlapping prices exist and require investigation.",
                ]);
            }
            // Fall through to facility-wide lookup.
        }

        // Facility-wide price (practitioner_id IS NULL).
        $prices = $baseQuery()->whereNull('practitioner_id')->get();

        if ($prices->isEmpty()) {
            throw ValidationException::withMessages([
                'price' => "No price is configured for \"{$service->name}\" at this facility on {$dateString}.",
            ]);
        }

        if ($prices->count() > 1) {
            throw ValidationException::withMessages([
                'price' => "Ambiguous pricing for \"{$service->name}\" at this facility on {$dateString} — multiple overlapping prices exist and require investigation.",
            ]);
        }

        return $prices->first();
    }
}
