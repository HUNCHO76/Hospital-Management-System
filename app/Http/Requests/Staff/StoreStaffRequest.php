<?php

namespace App\Http\Requests\Staff;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;
use Spatie\Permission\Models\Role;

class StoreStaffRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The base 'staff.create' permission is enforced by StaffPolicy via
     * authorizeResource() in the controller. Since no Staff instance exists
     * yet at that point, the hospital-scoping restriction for
     * hospital-admins is enforced here instead, against the hospital_id
     * being submitted.
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
            'department_id' => [
                'nullable',
                'integer',
                Rule::exists('departments', 'id')->where(fn ($query) => $query->where('hospital_id', $this->input('hospital_id'))),
            ],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'gender' => ['required', Rule::in(['male', 'female'])],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => [$this->boolean('create_account') ? 'required' : 'nullable', 'string', 'email', 'max:255'],
            'professional_registration_no' => ['nullable', 'string', 'max:255'],
            'job_title' => ['required', 'string', 'max:255'],
            'employment_type' => ['required', Rule::in(Staff::EMPLOYMENT_TYPES)],
            'status' => ['required', Rule::in([Staff::STATUS_ACTIVE, Staff::STATUS_INACTIVE, Staff::STATUS_SUSPENDED])],
            'hired_at' => ['nullable', 'date'],

            'create_account' => ['boolean'],
            'password' => [Rule::requiredIf($this->boolean('create_account')), Password::defaults()],
            'role' => [Rule::requiredIf($this->boolean('create_account')), Rule::in($this->assignableRoles())],
        ];
    }

    /**
     * Extra validation that can't be expressed as simple rules.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->boolean('create_account') && User::query()->where('email', $this->input('email'))->exists()) {
                $validator->errors()->add('email', 'A user account with this email already exists.');
            }
        });
    }

    /**
     * Roles an administrator is allowed to assign to a new staff account.
     * super-admin is deliberately excluded from routine staff onboarding.
     *
     * @return list<string>
     */
    protected function assignableRoles(): array
    {
        return Role::query()->where('name', '!=', 'super-admin')->pluck('name')->all();
    }
}
