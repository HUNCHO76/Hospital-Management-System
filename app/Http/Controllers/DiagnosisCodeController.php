<?php

namespace App\Http\Controllers;

use App\Models\DiagnosisCode;
use App\Services\DiagnosisCodeSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiagnosisCodeController extends Controller
{
    public function __construct(protected DiagnosisCodeSearchService $search) {}

    /**
     * Lightweight server-side diagnosis-code search consumed by the
     * DiagnosisCodeSearch combobox — mirrors PatientController::searchJson.
     * Never returns the full catalog: a query shorter than 2 characters
     * yields no results, and the result set is capped.
     */
    public function searchJson(Request $request): JsonResponse
    {
        $this->authorize('viewAny', DiagnosisCode::class);

        $search = $request->string('search')->trim()->toString();

        if (mb_strlen($search) < 2) {
            return response()->json(['data' => []]);
        }

        $codes = $this->search->query(['search' => $search, 'status' => DiagnosisCode::STATUS_ACTIVE])
            ->orderBy('code')
            ->limit(10)
            ->get(['id', 'coding_system', 'code', 'description', 'category']);

        return response()->json(['data' => $codes]);
    }
}
