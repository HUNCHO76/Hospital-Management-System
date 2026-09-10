<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The auditable morph defaults to null so a log can stand alone (as
     * authentication and system events do); callers set it explicitly when
     * exercising data-change events.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'action' => fake()->randomElement([
                'patient_created',
                'patient_updated',
                'staff_created',
                'login_success',
                'logout',
            ]),
            'auditable_type' => null,
            'auditable_id' => null,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'url' => fake()->url(),
        ];
    }

    /**
     * Indicate that the event was performed without an authenticated user.
     */
    public function withoutUser(): static
    {
        return $this->state(fn () => ['user_id' => null]);
    }
}
