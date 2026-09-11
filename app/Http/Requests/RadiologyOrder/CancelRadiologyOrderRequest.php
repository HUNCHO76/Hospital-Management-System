<?php

namespace App\Http\Requests\RadiologyOrder;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CancelRadiologyOrderRequest extends FormRequest
{
    /**
     * The 'radiology-orders.cancel' permission plus hospital/ownership
     * scoping is enforced by RadiologyOrderPolicy in the controller.
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
