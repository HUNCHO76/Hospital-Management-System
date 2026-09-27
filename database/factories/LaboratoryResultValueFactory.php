<?php

namespace Database\Factories;

use App\Models\LaboratoryResult;
use App\Models\LaboratoryResultValue;
use App\Models\LaboratoryTestParameter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LaboratoryResultValue>
 */
class LaboratoryResultValueFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'laboratory_result_id' => LaboratoryResult::factory(),
            'laboratory_test_parameter_id' => LaboratoryTestParameter::factory(),
            'value_numeric' => null,
            'value_text' => null,
            'value_code' => null,
            'unit' => 'g/dL',
            'reference_low' => null,
            'reference_high' => null,
            'reference_critical_low' => null,
            'reference_critical_high' => null,
            'abnormal_flag' => null,
            'result_comment' => null,
        ];
    }

    public function numeric(float $value = 14.5): static
    {
        return $this->state([
            'value_numeric' => $value,
            'unit' => 'g/dL',
            'reference_low' => 13.0,
            'reference_high' => 17.0,
            'reference_critical_low' => 7.0,
            'reference_critical_high' => 20.0,
            'abnormal_flag' => 'normal',
        ]);
    }

    public function option(string $value = 'negative'): static
    {
        return $this->state([
            'value_code' => $value,
            'unit' => null,
            'abnormal_flag' => 'normal',
        ]);
    }

    public function text(string $value = 'No significant findings.'): static
    {
        return $this->state([
            'value_text' => $value,
            'unit' => null,
        ]);
    }

    public function flagged(string $flag = 'low'): static
    {
        return $this->state(['abnormal_flag' => $flag]);
    }
}
