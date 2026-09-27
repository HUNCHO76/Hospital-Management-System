<?php

namespace Database\Seeders;

use App\Models\Hospital;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seed a starter, facility-wide ServicePrice for every active Service at
 * every active Hospital — covering the two consultation services
 * ServiceCatalogSeeder creates (CONSULT-GP, CONSULT-SPECIALIST) as well as
 * all laboratory-test and radiology-procedure services created by
 * LaboratoryTestSeeder/RadiologyProcedureSeeder.
 *
 * Without prices, ServicePriceResolver::resolvePrice() has nothing to
 * resolve, so ChargeCaptureService silently skips charge capture for every
 * clinical order — no lab bill, no radiology bill, no consultation charge,
 * no PatientAccount (auto-provisioned only as a side effect of the first
 * ChargeService::create() call per docs/billing.md).
 *
 * 20,000.00 TZS is an explicit placeholder amount, not a real tariff —
 * correctable later through the billing UI (Billing → Services → Add Price)
 * once real pricing is available.
 *
 * Follows ServiceCatalogSeeder's/NhifInsuranceProviderSeeder's own
 * convention: writes directly via ServicePrice::create(), bypassing
 * ServicePricingService (requires a real authenticated Request/actor) —
 * seed/fixture data has no genuine business actor or event to audit.
 *
 * Idempotent: checks for an existing active, facility-wide
 * (practitioner_id IS NULL) price before creating one — re-running
 * db:seed never duplicates rows.
 */
class ServicePriceSeeder extends Seeder
{
    protected string $placeholderAmount = '20000.00';

    public function run(): void
    {
        $actor = User::query()->where('email', 'test@example.com')->first() ?? User::query()->firstOrFail();

        $services = Service::query()->where('status', Service::STATUS_ACTIVE)->get();

        Hospital::active()->get()->each(function (Hospital $hospital) use ($services, $actor): void {
            $services->each(function (Service $service) use ($hospital, $actor): void {
                $exists = ServicePrice::query()
                    ->where('service_id', $service->id)
                    ->where('hospital_id', $hospital->id)
                    ->whereNull('practitioner_id')
                    ->where('status', ServicePrice::STATUS_ACTIVE)
                    ->exists();

                if ($exists) {
                    return;
                }

                ServicePrice::create([
                    'service_id' => $service->id,
                    'hospital_id' => $hospital->id,
                    'practitioner_id' => null,
                    'amount' => $this->placeholderAmount,
                    'currency' => config('billing.currency', 'TZS'),
                    'effective_from' => now()->toDateString(),
                    'effective_to' => null,
                    'status' => ServicePrice::STATUS_ACTIVE,
                    'created_by' => $actor->id,
                ]);
            });
        });
    }
}
