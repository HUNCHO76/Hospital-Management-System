<?php

namespace Database\Factories;

use App\Models\RadiologyAppointment;
use App\Models\RadiologyOrderItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RadiologyAppointment>
 */
class RadiologyAppointmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = $this->faker->dateTimeBetween('+1 day', '+7 days');

        return [
            'radiology_order_item_id' => RadiologyOrderItem::factory(),
            'modality' => 'x_ray',
            'resource_name' => 'X-Ray Room 1',
            'scheduled_start' => $start,
            'scheduled_end' => (clone $start)->modify('+30 minutes'),
            'status' => RadiologyAppointment::STATUS_SCHEDULED,
            'scheduled_by' => User::factory(),
        ];
    }
}
