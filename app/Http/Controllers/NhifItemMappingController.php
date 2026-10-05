<?php

namespace App\Http\Controllers;

use App\Models\LaboratoryTest;
use App\Models\Medication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NhifItemMappingController extends Controller
{
    public function index(Request $request): Response
    {
        $laboratoryTests = LaboratoryTest::where('status', LaboratoryTest::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'nhif_code']);

        $medications = Medication::where('status', 'active')
            ->orderBy('generic_name')
            ->get(['id', 'code', 'generic_name', 'nhif_code']);

        return Inertia::render('nhif/item-mapping/index', [
            'laboratoryTests' => $laboratoryTests,
            'medications' => $medications,
            'stats' => [
                'lab_total' => $laboratoryTests->count(),
                'lab_mapped' => $laboratoryTests->whereNotNull('nhif_code')->where('nhif_code', '!=', '')->count(),
                'med_total' => $medications->count(),
                'med_mapped' => $medications->whereNotNull('nhif_code')->where('nhif_code', '!=', '')->count(),
            ],
        ]);
    }

    public function updateLaboratoryTest(Request $request, LaboratoryTest $laboratoryTest): RedirectResponse
    {
        $data = $request->validate([
            'nhif_code' => ['nullable', 'string', 'max:50'],
        ]);

        $laboratoryTest->update(['nhif_code' => $data['nhif_code'] ?? null]);

        return back()->with('success', "NHIF code updated for {$laboratoryTest->name}.");
    }

    public function updateMedication(Request $request, Medication $medication): RedirectResponse
    {
        $data = $request->validate([
            'nhif_code' => ['nullable', 'string', 'max:50'],
        ]);

        $medication->update(['nhif_code' => $data['nhif_code'] ?? null]);

        return back()->with('success', "NHIF code updated for {$medication->generic_name}.");
    }
}
