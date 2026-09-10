<?php

namespace App\Http\Resources;

use App\Models\DiagnosisCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DiagnosisCode
 */
class DiagnosisCodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'coding_system' => $this->coding_system,
            'code' => $this->code,
            'description' => $this->description,
            'category' => $this->category,
            'status' => $this->status,
        ];
    }
}
