<?php

namespace App\Http\Requests\Consultation;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CancelConsultationRequest extends FormRequest
{
    /**
     * The 'consultation.cancel' permission plus hospital/ownership scoping
     * is enforced by ConsultationPolicy in the controller.
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
