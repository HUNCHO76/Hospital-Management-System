<?php

namespace App\Http\Requests\Dispensing;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CancelDispensingRequest extends FormRequest
{
    /**
     * The 'pharmacy-dispensing.cancel' permission plus hospital/ownership
     * scoping is enforced by PrescriptionOrderPolicy::cancelDispensing() in
     * the controller.
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
            'cancellation_reason' => ['required', 'string', 'max:500'],
        ];
    }
}
