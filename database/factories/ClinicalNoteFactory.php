<?php

namespace Database\Factories;

use App\Models\ClinicalEncounter;
use App\Models\ClinicalNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicalNote>
 */
class ClinicalNoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinical_encounter_id' => ClinicalEncounter::factory(),
            // Resolved from the created encounter so the two never drift —
            // ClinicalNoteService itself sets this the same way at runtime.
            'patient_id' => fn (array $attributes) => ClinicalEncounter::find($attributes['clinical_encounter_id'])->patient_id,
            'author_id' => User::factory(),
            'note_type' => ClinicalNote::TYPE_PROGRESS,
            'note_content' => $this->faker->paragraph(),
            'status' => ClinicalNote::STATUS_DRAFT,
        ];
    }
}
