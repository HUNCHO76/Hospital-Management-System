<?php

namespace App\Http\Requests\Admission;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CancelAdmissionRequest extends FormRequest
{
    /**
     * The 'admission.cancel' permission and hospital scoping are enforced
     * by an explicit AdmissionPolicy::cancel() check in the controller
     * before this request's rules() ever run.
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
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
