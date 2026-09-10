<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Hospital;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hospital_id' => Hospital::factory(),
            'name' => fake()->randomElement([
                'Outpatient', 'Emergency', 'Laboratory', 'Pharmacy',
                'Radiology', 'Inpatient', 'Maternity', 'Theatre',
            ]),
            'code' => strtoupper(fake()->unique()->bothify('DPT-###')),
            'description' => fake()->sentence(),
            'status' => 'active',
        ];
    }
}
