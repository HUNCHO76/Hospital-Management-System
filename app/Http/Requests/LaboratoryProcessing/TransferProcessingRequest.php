<?php

namespace App\Http\Requests\LaboratoryProcessing;

use App\Models\LaboratoryBench;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferProcessingRequest extends FormRequest
{
    /**
     * The 'laboratory-processing.transfer' permission plus hospital
     * scoping is enforced by LaboratoryProcessingPolicy in the controller.
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
            'bench_id' => ['required', 'integer', Rule::exists(LaboratoryBench::class, 'id')->where('status', LaboratoryBench::STATUS_ACTIVE)],
            'transfer_reason' => ['required', 'string', 'max:500'],
        ];
    }
}
