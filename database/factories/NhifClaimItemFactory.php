<?php

namespace Database\Factories;

use App\Models\NhifClaim;
use App\Models\NhifClaimItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NhifClaimItem>
 */
class NhifClaimItemFactory extends Factory
{
    protected $model = NhifClaimItem::class;

    public function definition(): array
    {
        return [
            'nhif_claim_id' => NhifClaim::factory(),
            'nhif_utilization_id' => null,
            'line_no' => $this->faker->numberBetween(1, 99),
            'module' => 'consultation',
            'description' => $this->faker->sentence(3),
            'service_date' => now()->toDateString(),
            'nhif_item_code' => strtoupper($this->faker->bothify('ITEM###')),
            'nhif_item_name' => $this->faker->sentence(2),
            'item_type_id' => 1,
            'quantity' => 1,
            'hms_unit_price' => 5000.00,
            'hms_amount' => 5000.00,
            'nhif_allowable_amount' => 5000.00,
            'nhif_responsibility_amount' => 5000.00,
            'patient_responsibility_amount' => 0.00,
            'removed_at' => null,
        ];
    }
}
