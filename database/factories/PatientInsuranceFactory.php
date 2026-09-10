<?php

namespace Database\Factories;

use App\Models\InsuranceProvider;
use App\Models\Patient;
use App\Models\PatientInsurance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientInsurance>
 */
class PatientInsuranceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * insurance_scheme_id defaults to null rather than an independently
     * generated InsuranceScheme, since a randomly generated scheme would not
     * necessarily belong to the randomly generated provider above — tests
     * that need a scheme should create one tied to the same provider_id.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'insurance_provider_id' => InsuranceProvider::factory(),
            'insurance_scheme_id' => null,
            'membership_number' => strtoupper(fake()->unique()->bothify('MEM-######')),
            'policy_number' => strtoupper(fake()->unique()->bothify('POL-######')),
            'card_number' => strtoupper(fake()->unique()->bothify('CARD-######')),
            'principal_member_name' => fake()->name(),
            'relationship' => 'self',
            'effective_from' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'effective_to' => fake()->dateTimeBetween('now', '+1 year')->format('Y-m-d'),
            'is_primary' => true,
            'status' => 'active',
            'verification_status' => 'pending',
            'verified_at' => null,
        ];
    }
}
