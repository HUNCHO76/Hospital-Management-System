<?php

namespace Database\Factories;

use App\Models\LaboratoryOrder;
use App\Models\LaboratoryReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LaboratoryReport>
 */
class LaboratoryReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'laboratory_order_id' => LaboratoryOrder::factory(),
            'version' => 1,
            'status' => LaboratoryReport::STATUS_ACTIVE,
            'results_snapshot' => [],
            'released_by' => User::factory(),
            'released_at' => now(),
        ];
    }
}
