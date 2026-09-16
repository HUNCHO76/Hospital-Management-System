<?php

namespace App\Services;

use App\Models\Service;
use App\Models\ServicePrice;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The single authoritative "what does this service cost, at this hospital,
 * on this date" lookup (Phase 7, Step 53, spec §30/§49) — Step 54's future
 * charge capture is meant to call this directly rather than querying
 * `ServicePrice` itself, so price-selection logic exists in exactly one
 * place. Never trusts a client-supplied price (spec §30's own "a browser-
 * supplied unit_price must not be trusted"); this is the only legitimate
 * source of a unit price for a billable service.
 */
class ServicePriceResolver
{
    /**
     * @throws ValidationException
     */
    public function resolvePrice(Service $service, int $hospitalId, ?Carbon $date = null): ServicePrice
    {
        $date ??= now();

        if (! $service->is_billable) {
            throw ValidationException::withMessages(['service' => "\"{$service->name}\" is not billable."]);
        }

        if ($service->status !== Service::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['service' => "\"{$service->name}\" is not currently active."]);
        }

        $dateString = $date->toDateString();

        // whereDate() (not where()), deliberately: a `date`-cast attribute
        // is still persisted as a full "Y-m-d H:i:s" string (Eloquent's
        // date cast only zeroes the time component, it does not shorten the
        // stored format), so a plain string `<=`/`>=` comparison against a
        // bare "Y-m-d" value silently misses today's own exact-match row on
        // SQLite (no real DATE type to coerce it, unlike MySQL) — whereDate()
        // extracts just the date part in the SQL itself, correct on every
        // driver.
        $prices = ServicePrice::query()
            ->where('service_id', $service->id)
            ->where('hospital_id', $hospitalId)
            ->where('status', ServicePrice::STATUS_ACTIVE)
            ->whereDate('effective_from', '<=', $dateString)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $dateString))
            ->get();

        if ($prices->isEmpty()) {
            throw ValidationException::withMessages([
                'price' => "No price is configured for \"{$service->name}\" at this facility on {$dateString}.",
            ]);
        }

        if ($prices->count() > 1) {
            // Should be structurally impossible — ServicePricingService::
            // create() prevents any overlapping active period from ever
            // being created. Surfaced loudly rather than silently picking
            // one, exactly the "flag for investigation, never silently
            // correct" principle Step 49's own reconcile() already applies.
            throw ValidationException::withMessages([
                'price' => "Ambiguous pricing for \"{$service->name}\" at this facility on {$dateString} — multiple overlapping prices exist and require investigation.",
            ]);
        }

        return $prices->first();
    }
}
