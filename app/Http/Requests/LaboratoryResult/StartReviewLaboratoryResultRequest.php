<?php

namespace App\Http\Requests\LaboratoryResult;

use Illuminate\Foundation\Http\FormRequest;

class StartReviewLaboratoryResultRequest extends FormRequest
{
    /**
     * The 'laboratory-results.review' permission, hospital scoping, and
     * entry-vs-review role separation are enforced by LaboratoryResultPolicy
     * in the controller. Starting a review carries no body of its own.
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
