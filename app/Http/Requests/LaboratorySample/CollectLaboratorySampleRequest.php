<?php

namespace App\Http\Requests\LaboratorySample;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CollectLaboratorySampleRequest extends FormRequest
{
    /**
     * The 'laboratory-samples.collect' permission plus hospital scoping is
     * enforced by LaboratorySamplePolicy in the controller.
     *
     * collected_by/collected_at are never accepted from the client — the
     * service stamps them from the authenticated user and the server clock
     * (spec §17-18).
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
            'collection_site' => ['nullable', 'string', 'max:100'],
            'collection_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
