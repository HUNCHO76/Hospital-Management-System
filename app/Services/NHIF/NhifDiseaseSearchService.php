<?php

namespace App\Services\NHIF;

use App\Models\NhifDisease;
use Illuminate\Database\Eloquent\Collection;

class NhifDiseaseSearchService
{
    /**
     * Search NHIF disease reference by code or name.
     * Requires at least 2 characters. Returns up to 25 matches.
     *
     * @return Collection<int, NhifDisease>
     */
    public function search(string $query): Collection
    {
        if (mb_strlen(trim($query)) < 2) {
            return new Collection;
        }

        $term = '%'.mb_strtolower(trim($query)).'%';

        return NhifDisease::query()
            ->where(function ($q) use ($term): void {
                $q->whereRaw('LOWER(disease_code) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(disease_name) LIKE ?', [$term]);
            })
            ->orderBy('disease_name')
            ->limit(25)
            ->get();
    }
}
