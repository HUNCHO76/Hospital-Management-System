<?php

namespace App\Http\Requests\LaboratoryOrder;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CancelLaboratoryOrderItemRequest extends FormRequest
{
    /**
     * Authorized against the parent order's 'update' ability in the
     * controller — removing a test is modifying the order's content.
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
