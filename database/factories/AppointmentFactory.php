<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Department;
use App\Models\Patient;
use App\Models\Practitioner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('+1 day', '+2 weeks');
        $end = (clone $start)->modify('+30 minutes');

        return [
            'patient_id' => Patient::factory(),
            'department_id' => Department::factory(),
            'practitioner_id' => Practitioner::factory(),
            'appointment_type' => fake()->randomElement(Appointment::TYPES),
            'scheduled_start' => $start,
            'scheduled_end' => $end,
            'status' => Appointment::STATUS_SCHEDULED,
            'reason' => fake()->optional()->sentence(),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
