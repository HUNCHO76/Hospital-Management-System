<?php

namespace App\Http\Requests\Laboratory;

use App\Models\LaboratoryTestCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLaboratoryTestCategoryRequest extends FormRequest
{
    /**
     * The 'laboratory-categories.manage' permission is enforced by
     * LaboratoryTestCategoryPolicy::create() in the controller.
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
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique(LaboratoryTestCategory::class, 'code')],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
