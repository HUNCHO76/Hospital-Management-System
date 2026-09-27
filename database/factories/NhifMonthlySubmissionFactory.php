<?php

namespace Database\Factories;

use App\Models\Hospital;
use App\Models\NhifMonthlySubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NhifMonthlySubmission>
 */
class NhifMonthlySubmissionFactory extends Factory
{
    protected $model = NhifMonthlySubmission::class;

    public function definition(): array
    {
        return [
            'hospital_id' => Hospital::factory(),
            'facility_code' => $this->faker->bothify('FAC###'),
            'claim_year' => (int) now()->format('Y'),
            'claim_month' => (int) now()->format('n'),
            'folios_submitted' => $this->faker->numberBetween(1, 50),
            'total_amount_claimed' => $this->faker->numberBetween(100000, 5000000),
            'submission_remarks' => null,
            'nhif_response' => null,
            'status' => NhifMonthlySubmission::STATUS_PENDING,
            'submitted_at' => null,
            'submitted_by' => null,
        ];
    }

    public function submitted(): static
    {
        return $this->state([
            'status' => NhifMonthlySubmission::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'submitted_by' => User::factory(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(['status' => NhifMonthlySubmission::STATUS_FAILED]);
    }
}
