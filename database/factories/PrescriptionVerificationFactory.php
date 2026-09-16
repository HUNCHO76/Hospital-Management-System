<?php

namespace Database\Factories;

use App\Models\PrescriptionOrder;
use App\Models\PrescriptionVerification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrescriptionVerification>
 */
class PrescriptionVerificationFactory extends Factory
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
            'status' => PrescriptionVerification::STATUS_PENDING_VERIFICATION,
            'submitted_by' => User::factory(),
            'submitted_at' => now(),
        ];
    }
}
