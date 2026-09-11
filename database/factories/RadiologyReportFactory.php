<?php

namespace Database\Factories;

use App\Models\Practitioner;
use App\Models\RadiologyAppointment;
use App\Models\RadiologyReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RadiologyReport>
 */
class RadiologyReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'radiology_appointment_id' => RadiologyAppointment::factory(),
            'reporting_practitioner_id' => Practitioner::factory(['practitioner_type' => 'radiologist']),
            'status' => RadiologyReport::STATUS_DRAFT,
        ];
    }
}
