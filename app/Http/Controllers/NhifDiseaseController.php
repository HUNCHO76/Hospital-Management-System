<?php

namespace App\Http\Controllers;

use App\Services\NHIF\NhifDiseaseSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NhifDiseaseController extends Controller
{
    /**
     * Search the NHIF disease reference.
     * Gated by diagnosis.create — only clinicians who can diagnose may search.
     */
    public function searchJson(Request $request, NhifDiseaseSearchService $service): JsonResponse
    {
        $this->authorize('diagnosis.create');

        $query = (string) $request->query('q', '');
        $results = $service->search($query);

        return response()->json(
            $results->map(fn ($d) => [
                'id' => $d->id,
                'disease_code' => $d->disease_code,
                'disease_name' => $d->disease_name,
                'icd_version_code' => $d->icd_version_code,
            ])
        );
    }
}
