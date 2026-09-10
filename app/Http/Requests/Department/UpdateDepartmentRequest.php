<?php

namespace App\Http\Requests\Department;

use App\Models\Department;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The base 'departments.update' permission and hospital-admin scoping
     * against the department's current hospital are enforced by
     * DepartmentPolicy via authorizeResource() in the controller. This also
     * blocks a hospital-admin from reassigning the department to a
     * different hospital than the one they manage.
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
        /** @var Department $department */
        $department = $this->route('department');

        return [
            'hospital_id' => ['required', 'integer', 'exists:hospitals,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('departments', 'code')
                    ->where('hospital_id', $this->input('hospital_id'))
                    ->ignore($department->id),
            ],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::in([Department::STATUS_ACTIVE, Department::STATUS_INACTIVE])],
        ];
    }
}
