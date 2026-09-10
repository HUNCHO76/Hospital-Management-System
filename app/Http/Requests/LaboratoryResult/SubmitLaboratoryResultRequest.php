<?php

namespace App\Http\Requests\LaboratoryResult;

use Illuminate\Foundation\Http\FormRequest;

class SubmitLaboratoryResultRequest extends FormRequest
{
    /**
     * The 'laboratory-results.submit' permission plus hospital scoping is
     * enforced by LaboratoryResultPolicy in the controller. Submission acts
     * on the value already saved as a draft — it carries no body of its
     * own.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
