<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Patient;
use App\Models\ReceptionVisit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReceptionVisit>
 */
class ReceptionVisitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'appointment_id' => null,
            'department_id' => Department::factory(),
            'visit_type' => 'walk_in',
            'status' => ReceptionVisit::STATUS_CHECKED_IN,
        ];
    }
}
