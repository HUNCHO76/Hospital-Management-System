<?php

namespace Database\Factories;

use App\Models\RadiologyAppointment;
use App\Models\RadiologyImage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RadiologyImage>
 */
class RadiologyImageFactory extends Factory
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
            'path' => 'radiology-images/test/'.fake()->uuid().'.jpg',
            'original_filename' => fake()->word().'.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(50_000, 3_000_000),
            'uploaded_by' => User::factory(),
        ];
    }
}
