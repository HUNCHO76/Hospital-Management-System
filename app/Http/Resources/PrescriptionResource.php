<?php

namespace App\Http\Resources;

use App\Models\Prescription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Prescription
 */
class PrescriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'medication' => $this->whenLoaded('medication', fn () => $this->medication ? [
                'id' => $this->medication->id,
                'name' => $this->medication->name,
            ] : null),
            'strength' => $this->strength,
            'dose' => $this->dose,
            'route' => $this->route,
            'frequency' => $this->frequency,
            'duration' => $this->duration,
            'quantity' => $this->quantity,
            'instructions' => $this->instructions,
        ];
    }
}
