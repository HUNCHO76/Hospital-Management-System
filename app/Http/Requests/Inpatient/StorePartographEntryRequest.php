<?php

namespace App\Http\Requests\Inpatient;

use App\Models\PartographEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePartographEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inpatient-nursing.view') || $this->user()->can('maternity.partograph-record');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'recorded_at' => ['required', 'date'],
            'fetal_heart_rate' => ['nullable', 'integer', 'between:60,220'],
            'amniotic_fluid' => ['nullable', 'string', Rule::in(array_keys(PartographEntry::AMNIOTIC_FLUID_STATES))],
            'moulding' => ['nullable', 'string', Rule::in(array_keys(PartographEntry::MOULDING_GRADES))],
            'cervical_dilation_cm' => ['nullable', 'numeric', 'between:0,10'],
            'descent_of_head' => ['nullable', 'integer', 'between:0,5'],
            'contractions_per_10min' => ['nullable', 'integer', 'between:1,7'],
            'contraction_duration' => ['nullable', 'string', Rule::in(array_keys(PartographEntry::CONTRACTION_DURATIONS))],
            'oxytocin_drops_per_min' => ['nullable', 'integer', 'min:0', 'max:120'],
            'oxytocin_dose_units' => ['nullable', 'string', 'max:50'],
            'drugs_iv_fluids' => ['nullable', 'string', 'max:500'],
            'systolic_bp' => ['nullable', 'integer', 'between:40,300'],
            'diastolic_bp' => ['nullable', 'integer', 'between:20,200'],
            'pulse_bpm' => ['nullable', 'integer', 'between:30,250'],
            'temperature_c' => ['nullable', 'numeric', 'between:30,45'],
            'urine_protein' => ['nullable', 'string', 'max:20'],
            'urine_acetone' => ['nullable', 'string', 'max:20'],
            'urine_volume_ml' => ['nullable', 'integer', 'min:0', 'max:3000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_emergency' => ['nullable', 'boolean'],
            'emergency_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
