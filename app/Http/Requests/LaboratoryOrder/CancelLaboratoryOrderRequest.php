<?php

namespace App\Http\Requests\LaboratoryOrder;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CancelLaboratoryOrderRequest extends FormRequest
{
    /**
     * The 'laboratory-orders.cancel' permission plus hospital/ownership
     * scoping is enforced by LaboratoryOrderPolicy in the controller.
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
