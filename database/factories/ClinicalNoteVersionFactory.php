<?php

namespace Database\Factories;

use App\Models\ClinicalNote;
use App\Models\ClinicalNoteVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicalNoteVersion>
 */
class ClinicalNoteVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinical_note_id' => ClinicalNote::factory(),
            'version_number' => 1,
            'note_type' => ClinicalNote::TYPE_PROGRESS,
            'note_content' => $this->faker->paragraph(),
            'action' => 'signed',
            'author_id' => User::factory(),
            'reason' => null,
        ];
    }
}
