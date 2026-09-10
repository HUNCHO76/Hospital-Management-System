<?php

namespace App\Http\Requests\Laboratory;

use App\Models\LaboratoryBench;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLaboratoryBenchRequest extends FormRequest
{
    /**
     * The 'laboratory-benches.manage' permission is enforced by
     * LaboratoryBenchPolicy::update() in the controller.
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
        /** @var LaboratoryBench $bench */
        $bench = $this->route('laboratoryBench');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:50', Rule::unique(LaboratoryBench::class, 'code')->ignore($bench->id)],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
