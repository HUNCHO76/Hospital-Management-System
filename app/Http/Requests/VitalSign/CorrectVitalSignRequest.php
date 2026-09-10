<?php

namespace App\Http\Requests\VitalSign;

use App\Http\Requests\Concerns\ValidatesVitalSignMeasurements;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CorrectVitalSignRequest extends FormRequest
{
    use ValidatesVitalSignMeasurements;

    /**
     * The 'vital-signs.correct' permission plus hospital scoping is
     * enforced by VitalSignPolicy in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->measurementRules(),
            'correction_reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->withMeasurementValidator($validator);
    }
}
