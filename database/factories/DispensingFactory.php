<?php

namespace Database\Factories;

use App\Models\Dispensing;
use App\Models\PrescriptionOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dispensing>
 */
class DispensingFactory extends Factory
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
            'dispensing_number' => 'DSP-'.now()->year.'-'.fake()->unique()->numerify('######'),
            'status' => Dispensing::STATUS_IN_PROGRESS,
            'dispensed_by' => User::factory(),
            'started_at' => now(),
        ];
    }
}
