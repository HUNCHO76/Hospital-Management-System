<?php

namespace Database\Factories;

use App\Models\InsuranceCoverageRule;
use App\Models\InsuranceScheme;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InsuranceCoverageRule>
 */
class InsuranceCoverageRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'insurance_scheme_id' => InsuranceScheme::factory(),
            'service_id' => null,
            'category' => null,
            'coverage_type' => InsuranceCoverageRule::TYPE_PERCENTAGE,
            'coverage_percentage' => 80,
            'fixed_copay_amount' => null,
            'requires_preauthorization' => false,
            'status' => InsuranceCoverageRule::STATUS_ACTIVE,
            'effective_from' => now()->subMonth()->toDateString(),
            'effective_to' => null,
            'created_by' => User::factory(),
        ];
    }
}
