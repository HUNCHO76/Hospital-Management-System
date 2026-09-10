<?php

namespace App\Http\Requests\LaboratoryResult;

use Illuminate\Foundation\Http\FormRequest;

class AuthorizeLaboratoryResultRequest extends FormRequest
{
    /**
     * The 'laboratory-results.authorize' permission, hospital scoping, and
     * entry-vs-review role separation are enforced by LaboratoryResultPolicy
     * in the controller. Authorization carries no body of its own.
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
