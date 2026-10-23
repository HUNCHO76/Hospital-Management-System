<?php

namespace App\Http\Controllers;

use App\Models\LaboratoryTest;
use App\Models\Medication;
use App\Models\RadiologyProcedure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NhifItemMappingController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('nhif-service-mappings.view'), 403);

        $laboratoryTests = LaboratoryTest::where('status', LaboratoryTest::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'nhif_code']);

        $medications = Medication::where('status', 'active')
            ->orderBy('generic_name')
            ->get(['id', 'code', 'generic_name', 'nhif_code']);

        $radiologyProcedures = RadiologyProcedure::where('status', RadiologyProcedure::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'modality', 'nhif_code']);

        return Inertia::render('nhif/item-mapping/index', [
            'laboratoryTests' => $laboratoryTests,
            'medications' => $medications,
            'radiologyProcedures' => $radiologyProcedures,
            'stats' => [
                'lab_total' => $laboratoryTests->count(),
                'lab_mapped' => $laboratoryTests->whereNotNull('nhif_code')->where('nhif_code', '!=', '')->count(),
                'med_total' => $medications->count(),
                'med_mapped' => $medications->whereNotNull('nhif_code')->where('nhif_code', '!=', '')->count(),
                'rad_total' => $radiologyProcedures->count(),
                'rad_mapped' => $radiologyProcedures->whereNotNull('nhif_code')->where('nhif_code', '!=', '')->count(),
            ],
        ]);
    }

    public function updateLaboratoryTest(Request $request, LaboratoryTest $laboratoryTest): JsonResponse|RedirectResponse
    {
        abort_unless($request->user()->can('nhif-service-mappings.update'), 403);

        $data = $request->validate([
            'nhif_code' => ['nullable'],
        ]);

        $code = ! empty($data['nhif_code']) ? trim((string) $data['nhif_code']) : null;
        $laboratoryTest->update(['nhif_code' => $code]);

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'success' => true,
                'message' => "NHIF code updated for {$laboratoryTest->name}.",
                'nhif_code' => $code,
            ]);
        }

        return back()->with('success', "NHIF code updated for {$laboratoryTest->name}.");
    }

    public function updateMedication(Request $request, Medication $medication): JsonResponse|RedirectResponse
    {
        abort_unless($request->user()->can('nhif-service-mappings.update'), 403);

        $data = $request->validate([
            'nhif_code' => ['nullable'],
        ]);

        $code = ! empty($data['nhif_code']) ? trim((string) $data['nhif_code']) : null;
        $medication->update(['nhif_code' => $code]);

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'success' => true,
                'message' => "NHIF code updated for {$medication->generic_name}.",
                'nhif_code' => $code,
            ]);
        }

        return back()->with('success', "NHIF code updated for {$medication->generic_name}.");
    }

    public function updateRadiologyProcedure(Request $request, RadiologyProcedure $radiologyProcedure): JsonResponse|RedirectResponse
    {
        abort_unless($request->user()->can('nhif-service-mappings.update'), 403);

        $data = $request->validate([
            'nhif_code' => ['nullable'],
        ]);

        $code = ! empty($data['nhif_code']) ? trim((string) $data['nhif_code']) : null;
        $radiologyProcedure->update(['nhif_code' => $code]);

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'success' => true,
                'message' => "NHIF code updated for {$radiologyProcedure->name}.",
                'nhif_code' => $code,
            ]);
        }

        return back()->with('success', "NHIF code updated for {$radiologyProcedure->name}.");
    }
}
