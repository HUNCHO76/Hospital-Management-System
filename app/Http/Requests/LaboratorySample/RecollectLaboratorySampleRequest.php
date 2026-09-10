<?php

namespace App\Http\Requests\LaboratorySample;

use App\Models\LaboratoryTest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecollectLaboratorySampleRequest extends FormRequest
{
    /**
     * The 'laboratory-samples.recollect' permission plus hospital scoping
     * is enforced by LaboratorySamplePolicy in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Both fields are optional — omitted, the recollection reuses the
     * rejected sample's own specimen/container. Only overridden when the
     * rejection reason itself requires a different specimen (e.g. wrong
     * container).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'specimen_type' => ['sometimes', 'string', Rule::in(LaboratoryTest::SPECIMEN_TYPES)],
            'container_type' => ['sometimes', 'nullable', 'string', Rule::in(LaboratoryTest::SPECIMEN_CONTAINERS)],
        ];
    }
}
