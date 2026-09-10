<?php

namespace App\Http\Requests\Reception;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CancelVisitRequest extends FormRequest
{
    /**
     * The 'reception.cancel' permission plus hospital scoping is enforced
     * by ReceptionVisitPolicy in the controller.
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
