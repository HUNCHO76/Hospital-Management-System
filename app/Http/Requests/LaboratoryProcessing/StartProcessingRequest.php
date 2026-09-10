<?php

namespace App\Http\Requests\LaboratoryProcessing;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StartProcessingRequest extends FormRequest
{
    /**
     * The 'laboratory-processing.start' permission plus hospital scoping
     * is enforced by LaboratoryProcessingPolicy in the controller.
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
            'processing_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
