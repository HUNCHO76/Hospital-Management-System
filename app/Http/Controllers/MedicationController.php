<?php

namespace App\Http\Controllers;

use App\Models\Medication;
use App\Services\MedicationSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MedicationController extends Controller
{
    public function __construct(protected MedicationSearchService $search) {}

    /**
     * Lightweight server-side medication search consumed by the
     * MedicationSearch combobox — mirrors DiagnosisCodeController::searchJson.
     * Never returns the full catalog: a query shorter than 2 characters
     * yields no results, and the result set is capped.
     */
    public function searchJson(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Medication::class);

        $search = $request->string('search')->trim()->toString();

        if (mb_strlen($search) < 2) {
            return response()->json(['data' => []]);
        }

        $medications = $this->search->query(['search' => $search, 'status' => Medication::STATUS_ACTIVE])
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'category']);

        return response()->json(['data' => $medications]);
    }
}
