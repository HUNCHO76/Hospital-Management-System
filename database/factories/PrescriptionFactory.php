<?php

namespace Database\Factories;

use App\Models\CarePlanItem;
use App\Models\Medication;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prescription>
 */
class PrescriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'care_plan_item_id' => CarePlanItem::factory()->state(['type' => CarePlanItem::TYPE_MEDICATION]),
            'medication_id' => Medication::factory(),
            'strength' => '500mg',
            'dose' => '1 tablet',
            'route' => 'oral',
            'frequency' => 'twice daily',
            'duration' => '5 days',
            'quantity' => '10 tablets',
            'created_by' => User::factory(),
        ];
    }
}
