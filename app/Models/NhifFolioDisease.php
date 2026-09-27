<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NhifFolioDisease extends Model
{
    use HasFactory;

    protected $fillable = [
        'nhif_claim_id',
        'disease_code',
        'status',
        'remarks',
        'is_main',
    ];

    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(NhifClaim::class, 'nhif_claim_id');
    }
}
