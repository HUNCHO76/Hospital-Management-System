<?php

namespace App\Http\Requests\Hospital;

use App\Models\Hospital;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHospitalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The base 'hospitals.update' permission and hospital-admin scoping are
     * already enforced by HospitalPolicy via authorizeResource() in the
     * controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Hospital $hospital */
        $hospital = $this->route('hospital');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('hospitals', 'code')->ignore($hospital->id)],
            'registration_number' => ['nullable', 'string', 'max:255', Rule::unique('hospitals', 'registration_number')->ignore($hospital->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'region' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'ward' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in([Hospital::STATUS_ACTIVE, Hospital::STATUS_INACTIVE])],
            'default_consultation_service_id' => ['nullable', 'integer', 'exists:services,id'],
        ];
    }
}
