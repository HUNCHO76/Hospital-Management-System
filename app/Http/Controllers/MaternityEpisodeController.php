<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\ClinicalEncounter;
use App\Models\Patient;
use App\Services\MaternityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Controller for registering and managing maternity care episodes across Outpatient, Emergency, and Inpatient pathways.
 *
 * Separates maternity episode initiation from hospital admission so patients are not automatically admitted.
 */
class MaternityEpisodeController extends Controller
{
    public function __construct(
        protected MaternityService $maternityService,
    ) {}

    /**
     * Initiate/register a maternity care episode for a patient.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        abort_unless(
            $request->user()->can('maternity.manage')
            || $request->user()->can('inpatient-nursing.view')
            || $request->user()->hasRole(['doctor', 'nurse', 'midwife', 'super-admin']),
            403
        );

        $validated = $request->validate([
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
            'admission_id' => ['nullable', 'integer', 'exists:admissions,id'],
            'clinical_encounter_id' => ['nullable', 'integer', 'exists:clinical_encounters,id'],
            'care_pathway' => ['nullable', 'string', 'in:outpatient,emergency,inpatient'],
            'gravida' => ['required', 'integer', 'min:1', 'max:25'],
            'para' => ['required', 'integer', 'min:0', 'max:25'],
            'living_children' => ['nullable', 'integer', 'min:0', 'max:25'],
            'abortions' => ['nullable', 'integer', 'min:0', 'max:25'],
            'last_menstrual_period' => ['nullable', 'date', 'before_or_equal:today'],
            'estimated_date_of_delivery' => ['nullable', 'date'],
            'gestational_age_weeks' => ['nullable', 'numeric', 'between:10,45'],
            'presentation' => ['nullable', 'string'],
            'membrane_status' => ['nullable', 'string'],
            'blood_group' => ['nullable', 'string', 'max:10'],
            'rhesus' => ['nullable', 'string', 'max:10'],
            'hiv_status' => ['nullable', 'string'],
            'clinical_exception_reason' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $patient = Patient::findOrFail($validated['patient_id']);
        $admission = ! empty($validated['admission_id']) ? Admission::find($validated['admission_id']) : null;
        $encounter = ! empty($validated['clinical_encounter_id']) ? ClinicalEncounter::find($validated['clinical_encounter_id']) : null;

        $profile = $this->maternityService->registerEpisode(
            $patient,
            $validated,
            $request,
            $admission,
            $encounter
        );

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Maternity episode registered successfully.',
                'profile' => $profile,
            ], 201);
        }

        return back()->with('success', "Maternity episode #{$profile->episode_number} registered successfully.");
    }
}
