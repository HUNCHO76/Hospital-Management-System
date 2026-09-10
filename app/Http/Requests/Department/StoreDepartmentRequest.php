<?php

namespace App\Http\Requests\Department;

use App\Models\Department;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDepartmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The base 'departments.create' permission is enforced by
     * DepartmentPolicy via authorizeResource() in the controller. Since no
     * Department instance exists yet at that point, the hospital-scoping
     * restriction for hospital-admins is enforced here instead, against the
     * hospital_id being submitted.
     */
    public function authorize(): bool
    {
        if (! $this->user()->hasRole('hospital-admin')) {
            return true;
        }

        return $this->user()->staff?->hospital_id === (int) $this->input('hospital_id');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'hospital_id' => ['required', 'integer', 'exists:hospitals,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('departments', 'code')->where('hospital_id', $this->input('hospital_id')),
            ],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::in([Department::STATUS_ACTIVE, Department::STATUS_INACTIVE])],
        ];
    }
}
