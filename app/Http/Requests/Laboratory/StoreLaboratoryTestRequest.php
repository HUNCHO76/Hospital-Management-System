<?php

namespace App\Http\Requests\Laboratory;

use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLaboratoryTestRequest extends FormRequest
{
    /**
     * The 'laboratory-tests.create' permission is enforced by
     * LaboratoryTestPolicy::create() in the controller.
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
            'code' => ['required', 'string', 'max:50', Rule::unique(LaboratoryTest::class, 'code')],
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category_id' => ['nullable', 'integer', Rule::exists(LaboratoryTestCategory::class, 'id')],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'specimen_type' => ['nullable', 'string', Rule::in(LaboratoryTest::SPECIMEN_TYPES)],
            'specimen_container' => ['nullable', 'string', Rule::in(LaboratoryTest::SPECIMEN_CONTAINERS)],
            'collection_notes' => ['nullable', 'string', 'max:2000'],
            'result_type' => ['nullable', 'string', Rule::in(LaboratoryTest::RESULT_TYPES)],
            'unit' => ['nullable', 'string', 'max:50'],
            'reference_range' => ['nullable', 'string', 'max:500'],
            'result_options' => ['nullable', 'array'],
            'result_options.*' => ['string', 'max:100'],
            'turnaround_time_minutes' => ['nullable', 'integer', 'min:0'],
            'default_priority' => ['nullable', 'string', Rule::in(['routine', 'urgent', 'stat'])],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'nhif_code' => ['nullable', 'string', 'max:50'],
            'price' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
