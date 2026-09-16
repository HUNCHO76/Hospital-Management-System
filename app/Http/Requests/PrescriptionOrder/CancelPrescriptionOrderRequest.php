<?php

namespace App\Http\Requests\PrescriptionOrder;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CancelPrescriptionOrderRequest extends FormRequest
{
    /**
     * The 'prescription-orders.cancel' permission plus hospital/ownership
     * scoping is enforced by PrescriptionOrderPolicy in the controller.
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
