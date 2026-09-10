<?php

namespace App\Http\Requests\Staff;

use App\Models\Staff;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The base 'staff.update' permission and hospital-admin scoping against
     * the staff member's current hospital are enforced by StaffPolicy via
     * authorizeResource() in the controller. This also blocks a
     * hospital-admin from reassigning staff to a different hospital than
     * the one they manage.
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
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'professional_registration_no' => ['nullable', 'string', 'max:255'],
            'job_title' => ['required', 'string', 'max:255'],
            'employment_type' => ['required', Rule::in(Staff::EMPLOYMENT_TYPES)],
            'status' => ['required', Rule::in([Staff::STATUS_ACTIVE, Staff::STATUS_INACTIVE, Staff::STATUS_SUSPENDED])],
            'hired_at' => ['nullable', 'date'],
        ];
    }
}
