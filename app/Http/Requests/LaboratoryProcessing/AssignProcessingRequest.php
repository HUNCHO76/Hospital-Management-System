<?php

namespace App\Http\Requests\LaboratoryProcessing;

use App\Models\LaboratoryBench;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignProcessingRequest extends FormRequest
{
    /**
     * The 'laboratory-processing.assign' permission plus hospital scoping
     * is enforced by LaboratoryProcessingPolicy in the controller. The
     * assignee is never trusted beyond existing as a real user — this app
     * has no per-role "must be a technician" constraint to check.
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
            'assigned_to' => ['required', 'integer', Rule::exists(User::class, 'id')],
            'bench_id' => ['nullable', 'integer', Rule::exists(LaboratoryBench::class, 'id')->where('status', LaboratoryBench::STATUS_ACTIVE)],
        ];
    }
}
