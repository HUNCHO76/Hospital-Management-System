<?php

namespace App\Http\Requests\PrescriptionOrder;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CancelPrescriptionOrderItemRequest extends FormRequest
{
    /**
     * Removing an item modifies the order's own content, so it is
     * authorized against the order's 'update' ability in the controller —
     * this request has no separate item-level authorization of its own.
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
