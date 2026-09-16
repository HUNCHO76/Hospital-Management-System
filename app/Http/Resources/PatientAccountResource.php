<?php

namespace App\Http\Resources;

use App\Models\PatientAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A PatientAccount (Phase 7, Step 52). Never carries a computed balance
 * itself (that would mean a per-row aggregate query on the list page,
 * spec §49's own N+1 warning) — the account SHOW page's controller computes
 * it once, directly, via PatientAccountService::balance().
 *
 * @mixin PatientAccount
 */
class PatientAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_number' => $this->account_number,
            'status' => $this->status,
            'currency' => $this->currency,
            'hospital' => $this->whenLoaded('hospital', fn () => $this->hospital ? [
                'id' => $this->hospital->id,
                'name' => $this->hospital->name,
            ] : null),
            'patient' => $this->whenLoaded('patient', fn () => $this->patient ? [
                'id' => $this->patient->id,
                'patient_number' => $this->patient->patient_number,
                'first_name' => $this->patient->first_name,
                'middle_name' => $this->patient->middle_name,
                'last_name' => $this->patient->last_name,
                'date_of_birth' => optional($this->patient->date_of_birth)->toDateString(),
                'gender' => $this->patient->gender,
            ] : null),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
