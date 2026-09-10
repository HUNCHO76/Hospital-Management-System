<?php

namespace App\Http\Requests\LaboratorySample;

use App\Models\LaboratoryTest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLaboratorySampleRequest extends FormRequest
{
    /**
     * The 'laboratory-samples.collect' permission plus hospital scoping is
     * enforced by LaboratorySamplePolicy::create() in the controller, which
     * receives the route-bound order as extra context.
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
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['distinct', 'integer'],
            'specimen_type' => ['required', 'string', Rule::in(LaboratoryTest::SPECIMEN_TYPES)],
            'container_type' => ['nullable', 'string', Rule::in(LaboratoryTest::SPECIMEN_CONTAINERS)],
        ];
    }
}
