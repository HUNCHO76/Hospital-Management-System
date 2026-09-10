<?php

namespace App\Http\Requests\Laboratory;

use App\Models\LaboratoryTestCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLaboratoryTestCategoryRequest extends FormRequest
{
    /**
     * The 'laboratory-categories.manage' permission is enforced by
     * LaboratoryTestCategoryPolicy::update() in the controller.
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
        /** @var LaboratoryTestCategory $category */
        $category = $this->route('laboratoryTestCategory');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:50', Rule::unique(LaboratoryTestCategory::class, 'code')->ignore($category->id)],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
