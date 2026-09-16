<?php

namespace Database\Factories;

use App\Models\Medication;
use App\Models\PrescriptionOrder;
use App\Models\PrescriptionOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrescriptionOrderItem>
 */
class PrescriptionOrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'prescription_order_id' => PrescriptionOrder::factory(),
            'medication_id' => Medication::factory(),
            'medication_code_snapshot' => strtoupper(fake()->unique()->bothify('MED-####')),
            'medication_name_snapshot' => fake()->word(),
            'strength_snapshot' => '500mg',
            'form_snapshot' => 'tablet',
            'route_snapshot' => 'oral',
            'dose' => '1',
            'dose_unit' => 'tablet',
            'frequency' => PrescriptionOrderItem::FREQUENCIES[0],
            'route' => PrescriptionOrderItem::ROUTES[0],
            'duration' => 5,
            'duration_unit' => 'days',
            'quantity' => 15,
            'quantity_unit' => 'tablets',
            'instructions' => fake()->optional()->sentence(),
            'status' => PrescriptionOrderItem::STATUS_ACTIVE,
        ];
    }
}
