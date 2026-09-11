<?php

namespace Database\Factories;

use App\Models\RadiologyOrder;
use App\Models\RadiologyOrderItem;
use App\Models\RadiologyProcedure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RadiologyOrderItem>
 */
class RadiologyOrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $procedure = RadiologyProcedure::factory()->create();

        return [
            'radiology_order_id' => RadiologyOrder::factory(),
            'radiology_procedure_id' => $procedure->id,
            'procedure_code' => $procedure->code,
            'procedure_name' => $procedure->name,
            'status' => RadiologyOrderItem::STATUS_ORDERED,
        ];
    }
}
