<?php

namespace App\Http\Controllers;

use App\Http\Requests\LaboratoryOrder\StoreLaboratoryOrderRequest;
use App\Http\Requests\PrescriptionOrder\StorePrescriptionOrderRequest;
use App\Http\Requests\RadiologyOrder\StoreRadiologyOrderRequest;
use App\Models\ClinicalEncounter;
use App\Models\Consultation;
use App\Models\DeliveryRecord;
use App\Models\Department;
use App\Models\FetalAssessment;
use App\Models\LaboratoryOrder;
use App\Models\MaternityProfile;
use App\Models\NewbornGrowthRecord;
use App\Models\PartographEntry;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Models\PrescriptionOrder;
use App\Models\PrescriptionOrderItem;
use App\Models\RadiologyOrder;
use App\Models\RadiologyOrderItem;
use App\Models\RadiologyProcedure;
use App\Models\ReceptionVisit;
use App\Models\Staff;
use App\Models\VitalSign;
use App\Services\AuditLogService;
use App\Services\LaboratoryOrderService;
use App\Services\MaternityService;
use App\Services\NHIF\NhifEncounterPreApprovalService;
use App\Services\NHIF\NhifLabAuthorizationService;
use App\Services\PrescriptionOrderService;
use App\Services\RadiologyOrderService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for Dedicated Outpatient Maternity Mother Assessment (Phase 3).
 *
 * Allows nurses, midwives, and clinicians to record:
 * - Patient identification and pregnancy context
 * - Gravida, Para, living children, abortions, previous pregnancy and obstetric history
 * - LMP, EDD (Naegele's rule), Gestational Age, risk classification
 * - Medical history, allergies, and current medications
 * - Maternal vital signs & bedside observations with repeated chronological records (never overwritten)
 */
class MaternityAssessmentController extends Controller
{
    public function __construct(
        protected AuditLogService $auditLog,
        protected MaternityService $maternityService,
    ) {}

    /**
     * Parse combined blood group string (e.g. "A-", "O+", "B") into [ABO, Rh].
     *
     * @return array{0: ?string, 1: string}
     */
    private static function parseBloodGroup(?string $blood): array
    {
        if (! $blood) {
            return [null, '+'];
        }

        $trimmed = trim(strtoupper($blood));
        $rh = str_ends_with($trimmed, '-') ? '-' : '+';
        $abo = rtrim($trimmed, '+-');

        if (! in_array($abo, ['A', 'B', 'AB', 'O'], true)) {
            $abo = null;
        }

        return [$abo, $rh];
    }

    /**
     * Show the Mother Assessment screen for a maternity visit.
     */
    public function show(Request $request, ReceptionVisit $visit): Response
    {
        abort_unless(
            $request->user()->can('maternity.view')
            || $request->user()->can('maternity.manage')
            || $request->user()->can('inpatient-nursing.view')
            || $request->user()->hasRole(['doctor', 'nurse', 'midwife', 'super-admin']),
            403,
            'You do not have permission to view maternity assessments.'
        );

        $visit->load([
            'patient',
            'department:id,name,code',
            'practitioner.staff:id,first_name,last_name',
        ]);

        // Ensure a ClinicalEncounter is linked to this outpatient visit
        $encounter = $visit->clinicalEncounter;
        if (! $encounter) {
            $encounter = ClinicalEncounter::create([
                'patient_id' => $visit->patient_id,
                'reception_visit_id' => $visit->id,
                'appointment_id' => $visit->appointment_id,
                'department_id' => $visit->department_id,
                'practitioner_id' => $visit->practitioner_id,
                'encounter_type' => 'opd',
                'status' => ClinicalEncounter::STATUS_IN_PROGRESS,
                'started_at' => now(),
                'created_by' => $request->user()->id,
            ]);
        }

        // Retrieve or initialize active MaternityProfile
        $profile = MaternityProfile::query()
            ->where(function ($q) use ($visit) {
                $q->where('reception_visit_id', $visit->id)
                    ->orWhere(function ($sub) use ($visit) {
                        $sub->where('patient_id', $visit->patient_id)
                            ->where('status', 'active');
                    });
            })
            ->with([
                'recordedBy:id,UserName',
                'assignedMidwife:id,UserName',
                'primaryClinician:id,UserName',
            ])
            ->latest()
            ->first();

        if (! $profile) {
            [$initAbo, $initRh] = self::parseBloodGroup($visit->patient?->blood_group);

            $profile = MaternityProfile::create([
                'patient_id' => $visit->patient_id,
                'reception_visit_id' => $visit->id,
                'clinical_encounter_id' => $encounter->id,
                'care_pathway' => MaternityProfile::CARE_PATHWAY_OUTPATIENT,
                'care_stage' => MaternityProfile::CARE_STAGE_AWAITING_ASSESSMENT,
                'gravida' => 1,
                'para' => 0,
                'blood_group' => $initAbo,
                'rhesus' => $initRh,
                'risk_classification' => MaternityProfile::RISK_LOW,
                'status' => 'active',
                'recorded_by' => $request->user()->id,
            ]);
        } else {
            // Backfill links and blood group if patient already has one
            $updates = [];
            if (empty($profile->reception_visit_id)) {
                $updates['reception_visit_id'] = $visit->id;
            }
            if (empty($profile->clinical_encounter_id)) {
                $updates['clinical_encounter_id'] = $encounter->id;
            }
            if (empty($profile->blood_group) && ! empty($visit->patient?->blood_group)) {
                [$abo, $rh] = self::parseBloodGroup($visit->patient->blood_group);
                if ($abo) {
                    $updates['blood_group'] = $abo;
                    $updates['rhesus'] = $rh;
                }
            }
            if (! empty($updates)) {
                $profile->update($updates);
            }
        }

        // Load repeated vital signs / maternal observations for this visit & encounter
        $vitalSigns = VitalSign::query()
            ->where(function ($q) use ($encounter, $visit, $profile) {
                $q->where('clinical_encounter_id', $encounter->id)
                    ->orWhere('reception_visit_id', $visit->id)
                    ->orWhere('maternity_profile_id', $profile->id);
            })
            ->with('recordedBy:id,UserName')
            ->orderBy('recorded_at', 'desc')
            ->get();

        // Load repeated fetal assessments / fetal wellbeing observations (Phase 4)
        $fetalAssessments = FetalAssessment::query()
            ->where(function ($q) use ($encounter, $visit, $profile) {
                $q->where('maternity_profile_id', $profile->id)
                    ->orWhere('reception_visit_id', $visit->id)
                    ->orWhere('clinical_encounter_id', $encounter->id);
            })
            ->with('assessedBy:id,UserName')
            ->orderBy('assessed_at', 'desc')
            ->get();

        // Load repeated labour monitoring & WHO partograph entries (Phase 5)
        $partographEntries = $profile->partographEntries()
            ->with(['recordedBy:id,UserName', 'emergencyAuthorizedBy:id,UserName'])
            ->orderBy('recorded_at', 'desc')
            ->get();

        // Load delivery records and newborn outcomes (Phase 7 & 8)
        $deliveryRecords = DeliveryRecord::query()
            ->where(function ($q) use ($profile, $visit) {
                $q->where('maternity_profile_id', $profile->id)
                    ->orWhere('reception_visit_id', $visit->id);
            })
            ->with([
                'babyPatient.newbornGrowthRecords.recordedBy:id,UserName',
                'conductedBy:id,UserName',
                'assistedBy:id,UserName',
                'recordedBy:id,UserName',
            ])
            ->orderBy('delivered_at', 'desc')
            ->get();

        // Historical profiles of earlier pregnancies for this mother
        $previousProfiles = MaternityProfile::query()
            ->where('patient_id', $visit->patient_id)
            ->where('id', '!=', $profile->id)
            ->with(['recordedBy:id,UserName', 'deliveryRecord'])
            ->latest()
            ->take(5)
            ->get();

        $practitioners = Practitioner::query()
            ->where('status', Practitioner::STATUS_ACTIVE)
            ->with('staff:id,first_name,last_name,job_title')
            ->get(['id', 'staff_id', 'practitioner_number', 'practitioner_type'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->staff ? trim("{$p->staff->first_name} {$p->staff->last_name}") : "Practitioner #{$p->id}",
                'type' => $p->practitioner_type,
            ]);

        $laboratoryOrders = LaboratoryOrder::query()
            ->where('clinical_encounter_id', $encounter->id)
            ->with([
                'items.laboratoryTest',
                'items.result',
                'orderedBy:id,UserName',
            ])
            ->latest('ordered_at')
            ->get();

        $prescriptionOrders = PrescriptionOrder::query()
            ->where('clinical_encounter_id', $encounter->id)
            ->with([
                'items.medication',
                'items.dispensingItems',
                'prescriber:id,UserName',
                'verification',
            ])
            ->latest('created_at')
            ->get();

        $radiologyOrders = RadiologyOrder::query()
            ->where('clinical_encounter_id', $encounter->id)
            ->with([
                'items.radiologyProcedure',
                'items.activeRadiologyAppointment.report.reportingPractitioner.staff.user',
                'items.activeRadiologyAppointment.report.verifyingPractitioner.staff.user',
                'items.activeRadiologyAppointment.report.releasedBy:id,UserName',
                'items.activeRadiologyAppointment.images.uploadedBy:id,UserName',
                'orderedBy:id,UserName',
            ])
            ->latest('ordered_at')
            ->get();

        $radiologyProcedures = RadiologyProcedure::query()
            ->where('status', RadiologyProcedure::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'modality', 'price']);

        $patientInsurance = $visit->patient?->currentNhifInsurance()?->load('insuranceProvider');

        $this->auditLog->record(
            'maternity_mother_assessment_viewed',
            $profile,
            null,
            ['visit_id' => $visit->id, 'patient_id' => $visit->patient_id],
            $request
        );

        return Inertia::render('maternity/mother-assessment', [
            'visit' => $visit,
            'encounter' => $encounter,
            'profile' => $profile,
            'vitalSigns' => $vitalSigns,
            'fetalAssessments' => $fetalAssessments,
            'partographEntries' => $partographEntries,
            'previousProfiles' => $previousProfiles,
            'practitioners' => $practitioners,
            'laboratoryOrders' => $laboratoryOrders,
            'prescriptionOrders' => $prescriptionOrders,
            'radiologyOrders' => $radiologyOrders,
            'radiologyProcedures' => $radiologyProcedures,
            'patientInsurance' => $patientInsurance,
            'canOrderLabs' => $request->user()->can('laboratory-orders.create')
                || $request->user()->can('maternity.manage')
                || $request->user()->can('inpatient-nursing.view')
                || $request->user()->hasRole(['doctor', 'nurse', 'midwife', 'super-admin']),
            'canPrescribe' => $request->user()->can('prescription-orders.create')
                || $request->user()->can('prescriptions.nurse-prescribe')
                || $request->user()->can('maternity.manage')
                || $request->user()->hasRole(['doctor', 'nurse', 'midwife', 'super-admin']),
            'canOrderRadiology' => $request->user()->can('radiology-orders.create')
                || $request->user()->can('maternity.manage')
                || $request->user()->can('inpatient-nursing.view')
                || $request->user()->hasRole(['doctor', 'nurse', 'midwife', 'super-admin']),
            'riskClassifications' => MaternityProfile::RISK_CLASSIFICATIONS,
            'maternityStatuses' => ReceptionVisit::MATERNITY_STATUSES,
            'urineOptions' => ['nil' => 'Nil', 'trace' => 'Trace', '1+' => '1+', '2+' => '2+', '3+' => '3+', '4+' => '4+'],
            'fetalPresentations' => FetalAssessment::PRESENTATIONS,
            'fetalLies' => FetalAssessment::LIES,
            'fetalMovements' => FetalAssessment::MOVEMENTS,
            'labourStages' => array_combine(MaternityProfile::LABOUR_STAGES, array_map(fn ($s) => ucwords(str_replace('_', ' ', $s)), MaternityProfile::LABOUR_STAGES)),
            'labourOnsetTypes' => MaternityProfile::LABOUR_ONSET_TYPES,
            'amnioticFluidStates' => PartographEntry::AMNIOTIC_FLUID_STATES,
            'mouldingGrades' => PartographEntry::MOULDING_GRADES,
            'contractionDurations' => PartographEntry::CONTRACTION_DURATIONS,
            'deliveryRecords' => $deliveryRecords,
            'deliveryModes' => DeliveryRecord::MODES,
            'perinealStatuses' => DeliveryRecord::PERINEAL_STATUSES,
            'babyOutcomes' => DeliveryRecord::BABY_OUTCOMES,
            'feedingMethods' => NewbornGrowthRecord::FEEDING_METHODS,
        ]);
    }

    /**
     * Save/update mother's pregnancy and patient information.
     */
    public function updateProfile(Request $request, ReceptionVisit $visit): RedirectResponse
    {
        abort_unless(
            $request->user()->can('maternity.manage')
            || $request->user()->can('inpatient-nursing.view')
            || $request->user()->hasRole(['doctor', 'nurse', 'midwife', 'super-admin']),
            403
        );

        $validated = $request->validate([
            'gravida' => ['required', 'integer', 'min:1', 'max:25'],
            'para' => ['required', 'integer', 'min:0', 'max:25'],
            'living_children' => ['nullable', 'integer', 'min:0', 'max:25'],
            'abortions' => ['nullable', 'integer', 'min:0', 'max:25'],
            'last_menstrual_period' => ['nullable', 'date', 'before_or_equal:today'],
            'estimated_date_of_delivery' => ['nullable', 'date'],
            'gestational_age_weeks' => ['nullable', 'numeric', 'between:1,45'],
            'risk_classification' => ['required', 'string', Rule::in(array_keys(MaternityProfile::RISK_CLASSIFICATIONS))],
            'risk_factors' => ['nullable', 'array'],
            'previous_pregnancy_history' => ['nullable', 'string', 'max:5000'],
            'medical_history' => ['nullable', 'string', 'max:5000'],
            'obstetric_history' => ['nullable', 'string', 'max:5000'],
            'allergies' => ['nullable', 'string', 'max:2000'],
            'current_medications' => ['nullable', 'string', 'max:2000'],
            'presentation' => ['nullable', 'string', 'max:50'],
            'membrane_status' => ['nullable', 'string', 'max:30'],
            'blood_group' => ['nullable', 'string', 'max:10'],
            'rhesus' => ['nullable', 'string', 'max:10'],
            'hiv_status' => ['nullable', 'string', 'max:30'],
            'vdrl_rpr_status' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        // Auto calculate EDD and Gestational Age from LMP if omitted
        if (! empty($validated['last_menstrual_period'])) {
            $lmp = Carbon::parse($validated['last_menstrual_period']);
            if (empty($validated['estimated_date_of_delivery'])) {
                // Naegele's rule: LMP + 280 days
                $validated['estimated_date_of_delivery'] = $lmp->copy()->addDays(280)->toDateString();
            }
            if (empty($validated['gestational_age_weeks'])) {
                $days = $lmp->diffInDays(now());
                $validated['gestational_age_weeks'] = round($days / 7, 1);
            }
        }

        $profile = MaternityProfile::query()
            ->where('reception_visit_id', $visit->id)
            ->orWhere(function ($q) use ($visit) {
                $q->where('patient_id', $visit->patient_id)
                    ->where('status', 'active');
            })
            ->latest()
            ->first();

        if (! $profile) {
            $encounter = $visit->clinicalEncounter;
            $profile = MaternityProfile::create([
                ...$validated,
                'patient_id' => $visit->patient_id,
                'reception_visit_id' => $visit->id,
                'clinical_encounter_id' => $encounter?->id,
                'care_pathway' => MaternityProfile::CARE_PATHWAY_OUTPATIENT,
                'care_stage' => MaternityProfile::CARE_STAGE_ASSESSMENT_IN_PROGRESS,
                'status' => 'active',
                'recorded_by' => $request->user()->id,
            ]);
        } else {
            $profile->update([
                ...$validated,
                'reception_visit_id' => $visit->id,
            ]);
        }

        // Permanently sync blood group to master Patient record if provided
        if (! empty($validated['blood_group'])) {
            $combined = $validated['blood_group'] . ($validated['rhesus'] ?? '+');
            if (in_array($combined, Patient::BLOOD_GROUPS, true)) {
                $visit->patient?->update(['blood_group' => $combined]);
            }
        }

        // Advance maternity queue status if still waiting for assessment
        if ($visit->maternity_status === ReceptionVisit::MATERNITY_STATUS_WAITING) {
            $visit->update([
                'maternity_status' => ReceptionVisit::MATERNITY_STATUS_ASSESSMENT_IN_PROGRESS,
            ]);
        }

        $this->auditLog->record(
            'maternity_mother_assessment_updated',
            $profile,
            null,
            $validated,
            $request
        );

        return back()->with('success', 'Mother pregnancy details and obstetric history updated.');
    }

    /**
     * Record a new maternal vital sign / observation (repeated observations supported, never overwriting).
     */
    public function storeVitalSign(Request $request, ReceptionVisit $visit): RedirectResponse
    {
        abort_unless(
            $request->user()->can('maternity.manage')
            || $request->user()->can('vital-signs.create')
            || $request->user()->can('inpatient-nursing.view')
            || $request->user()->hasRole(['doctor', 'nurse', 'midwife', 'super-admin']),
            403
        );

        $validated = $request->validate([
            'systolic_mmhg' => ['nullable', 'integer', 'min:40', 'max:300'],
            'diastolic_mmhg' => ['nullable', 'integer', 'min:20', 'max:200'],
            'pulse_bpm' => ['nullable', 'integer', 'min:20', 'max:250'],
            'temperature_c' => ['nullable', 'numeric', 'min:30', 'max:45'],
            'respiratory_rate_bpm' => ['nullable', 'integer', 'min:5', 'max:100'],
            'oxygen_saturation_pct' => ['nullable', 'integer', 'min:50', 'max:100'],
            'weight_kg' => ['nullable', 'numeric', 'min:20', 'max:350'],
            'height_cm' => ['nullable', 'numeric', 'min:50', 'max:250'],
            'urine_protein' => ['nullable', 'string', 'in:nil,trace,1+,2+,3+,4+'],
            'urine_glucose' => ['nullable', 'string', 'in:nil,trace,1+,2+,3+,4+'],
            'urine_acetone' => ['nullable', 'string', 'in:nil,trace,1+,2+,3+,4+'],
            'blood_glucose_mmol' => ['nullable', 'numeric', 'min:0.5', 'max:50'],
            'observation_notes' => ['nullable', 'string', 'max:2000'],
            'measured_at' => ['nullable', 'date'],
        ]);

        // Require at least one measurement
        $measurementKeys = [
            'systolic_mmhg', 'diastolic_mmhg', 'pulse_bpm', 'temperature_c',
            'respiratory_rate_bpm', 'oxygen_saturation_pct', 'weight_kg', 'height_cm',
            'urine_protein', 'urine_glucose', 'urine_acetone', 'blood_glucose_mmol',
        ];

        $hasAnyMeasurement = false;
        foreach ($measurementKeys as $key) {
            if (isset($validated[$key]) && $validated[$key] !== '') {
                $hasAnyMeasurement = true;
                break;
            }
        }

        if (! $hasAnyMeasurement) {
            throw ValidationException::withMessages([
                'measurements' => 'Please record at least one vital sign or biochemical observation.',
            ]);
        }

        // Calculate BMI automatically from weight and height if both provided
        $bmi = null;
        if (! empty($validated['weight_kg']) && ! empty($validated['height_cm'])) {
            $heightM = (float) $validated['height_cm'] / 100;
            $bmi = round((float) $validated['weight_kg'] / ($heightM * $heightM), 2);
        }

        $encounter = $visit->clinicalEncounter;
        $profile = MaternityProfile::query()
            ->where('reception_visit_id', $visit->id)
            ->orWhere(function ($q) use ($visit) {
                $q->where('patient_id', $visit->patient_id)
                    ->where('status', 'active');
            })
            ->latest()
            ->first();

        $measuredAt = ! empty($validated['measured_at']) ? Carbon::parse($validated['measured_at']) : now();

        $vitalSign = VitalSign::create([
            ...$validated,
            'bmi' => $bmi,
            'clinical_encounter_id' => $encounter?->id,
            'reception_visit_id' => $visit->id,
            'maternity_profile_id' => $profile?->id,
            'recorded_by' => $request->user()->id,
            'recorded_at' => now(),
            'measured_at' => $measuredAt,
        ]);

        $this->auditLog->record(
            'maternity_vital_sign_recorded',
            $vitalSign,
            null,
            [
                'visit_id' => $visit->id,
                'encounter_id' => $encounter?->id,
                'profile_id' => $profile?->id,
                'bp' => "{$vitalSign->systolic_mmhg}/{$vitalSign->diastolic_mmhg}",
                'temp' => $vitalSign->temperature_c,
                'pulse' => $vitalSign->pulse_bpm,
                'urine_protein' => $vitalSign->urine_protein,
                'blood_glucose' => $vitalSign->blood_glucose_mmol,
            ],
            $request
        );

        return back()->with('success', 'Maternal observation recorded successfully.');
    }

    /**
     * Record a new dedicated Fetal Assessment & Monitoring observation (Phase 4).
     *
     * Stored separately from maternal vital signs. Supports multi-fetal pregnancies
     * (distinguishing each fetus) and repeated historical readings.
     */
    public function storeFetalAssessment(Request $request, ReceptionVisit $visit): RedirectResponse
    {
        abort_unless(
            $request->user()->can('maternity.manage')
            || $request->user()->can('inpatient-nursing.view')
            || $request->user()->hasRole(['doctor', 'nurse', 'midwife', 'super-admin']),
            403
        );

        $validated = $request->validate([
            'gestational_age_weeks' => ['nullable', 'numeric', 'between:1,45'],
            'number_of_fetuses' => ['required', 'integer', 'min:1', 'max:5'],
            'fetus_identifier' => ['required', 'string', 'max:50'],
            'fetal_heart_rate' => ['nullable', 'integer', 'between:60,240'],
            'fetal_movements' => ['nullable', 'string', Rule::in(array_keys(FetalAssessment::MOVEMENTS))],
            'fundal_height_cm' => ['nullable', 'numeric', 'between:5,60'],
            'presentation' => ['nullable', 'string', Rule::in(array_keys(FetalAssessment::PRESENTATIONS))],
            'lie' => ['nullable', 'string', Rule::in(array_keys(FetalAssessment::LIES))],
            'estimated_fetal_weight_grams' => ['nullable', 'integer', 'between:100,7000'],
            'ultrasound_findings' => ['nullable', 'string', 'max:3000'],
            'clinical_notes' => ['nullable', 'string', 'max:3000'],
            'assessed_at' => ['nullable', 'date'],
        ]);

        // Require at least one clinical measurement or finding
        $fetalFields = [
            'fetal_heart_rate', 'fetal_movements', 'fundal_height_cm',
            'presentation', 'lie', 'estimated_fetal_weight_grams',
            'ultrasound_findings', 'clinical_notes',
        ];

        $hasAnyObservation = false;
        foreach ($fetalFields as $field) {
            if (isset($validated[$field]) && $validated[$field] !== '') {
                $hasAnyObservation = true;
                break;
            }
        }

        if (! $hasAnyObservation) {
            throw ValidationException::withMessages([
                'fetal_observations' => 'Please document at least one fetal wellbeing parameter (e.g. FHR, movements, fundal height, ultrasound findings).',
            ]);
        }

        $encounter = $visit->clinicalEncounter;
        $profile = MaternityProfile::query()
            ->where('reception_visit_id', $visit->id)
            ->orWhere(function ($q) use ($visit) {
                $q->where('patient_id', $visit->patient_id)
                    ->where('status', 'active');
            })
            ->latest()
            ->firstOrFail();

        $assessedAt = ! empty($validated['assessed_at']) ? Carbon::parse($validated['assessed_at']) : now();

        $fetalAssessment = FetalAssessment::create([
            ...$validated,
            'maternity_profile_id' => $profile->id,
            'patient_id' => $visit->patient_id,
            'reception_visit_id' => $visit->id,
            'clinical_encounter_id' => $encounter?->id,
            'assessed_by' => $request->user()->id,
            'assessed_at' => $assessedAt,
        ]);

        $this->auditLog->record(
            'maternity_fetal_assessment_recorded',
            $fetalAssessment,
            null,
            [
                'visit_id' => $visit->id,
                'encounter_id' => $encounter?->id,
                'profile_id' => $profile->id,
                'fetus' => $fetalAssessment->fetus_identifier,
                'fhr' => $fetalAssessment->fetal_heart_rate,
                'fundal_height' => $fetalAssessment->fundal_height_cm,
                'presentation' => $fetalAssessment->presentation,
                'efw' => $fetalAssessment->estimated_fetal_weight_grams,
            ],
            $request
        );

        return back()->with('success', "Fetal assessment for {$fetalAssessment->fetus_identifier} recorded successfully.");
    }

    /**
     * Update labour onset, current labour stage, cervical effacement, and membrane status (Phase 5).
     */
    public function updateLabourStatus(Request $request, ReceptionVisit $visit): RedirectResponse
    {
        abort_unless(
            $request->user()->can('maternity.manage')
            || $request->user()->can('inpatient-nursing.view')
            || $request->user()->hasRole(['doctor', 'nurse', 'midwife', 'super-admin']),
            403
        );

        $validated = $request->validate([
            'labour_stage' => ['required', 'string', Rule::in(MaternityProfile::LABOUR_STAGES)],
            'labour_onset_at' => ['nullable', 'date'],
            'labour_onset_type' => ['nullable', 'string', Rule::in(array_keys(MaternityProfile::LABOUR_ONSET_TYPES))],
            'cervix_effacement_pct' => ['nullable', 'integer', 'between:0,100'],
            'membrane_status' => ['required', 'string', Rule::in(MaternityProfile::MEMBRANE_STATUSES)],
            'membrane_ruptured_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $profile = MaternityProfile::query()
            ->where('reception_visit_id', $visit->id)
            ->orWhere(function ($q) use ($visit) {
                $q->where('patient_id', $visit->patient_id)
                    ->where('status', 'active');
            })
            ->latest()
            ->firstOrFail();

        $profile->update([
            ...$validated,
            'care_stage' => in_array($validated['labour_stage'], ['active_phase', 'second_stage', 'third_stage'], true)
                ? MaternityProfile::CARE_STAGE_IN_LABOUR
                : $profile->care_stage,
        ]);

        // If in active labour, advance queue status
        if (in_array($validated['labour_stage'], ['active_phase', 'second_stage', 'third_stage'], true)) {
            if (in_array($visit->maternity_status, [
                ReceptionVisit::MATERNITY_STATUS_WAITING,
                ReceptionVisit::MATERNITY_STATUS_ASSESSMENT_IN_PROGRESS,
            ], true)) {
                $visit->update([
                    'maternity_status' => ReceptionVisit::MATERNITY_STATUS_TREATMENT_IN_PROGRESS,
                ]);
            }
        }

        $this->auditLog->record(
            'maternity_labour_status_updated',
            $profile,
            null,
            $validated,
            $request
        );

        return back()->with('success', 'Labour stage and onset information updated.');
    }

    /**
     * Record a WHO-compliant partograph observation entry during labour monitoring (Phase 5).
     * Captures contractions, dilation, descent, maternal vitals, medications, and alerts.
     */
    public function storePartographEntry(Request $request, ReceptionVisit $visit): RedirectResponse
    {
        abort_unless(
            $request->user()->can('maternity.manage')
            || $request->user()->can('maternity.partograph-record')
            || $request->user()->can('inpatient-nursing.view')
            || $request->user()->hasRole(['doctor', 'nurse', 'midwife', 'super-admin']),
            403
        );

        $validated = $request->validate([
            'recorded_at' => ['required', 'date'],
            'fetal_heart_rate' => ['nullable', 'integer', 'between:60,240'],
            'amniotic_fluid' => ['nullable', 'string', Rule::in(array_keys(PartographEntry::AMNIOTIC_FLUID_STATES))],
            'moulding' => ['nullable', 'string', Rule::in(array_keys(PartographEntry::MOULDING_GRADES))],
            'cervical_dilation_cm' => ['nullable', 'numeric', 'between:0,10'],
            'descent_of_head' => ['nullable', 'integer', 'between:0,5'],
            'contractions_per_10min' => ['nullable', 'integer', 'between:1,10'],
            'contraction_duration' => ['nullable', 'string', Rule::in(array_keys(PartographEntry::CONTRACTION_DURATIONS))],
            'oxytocin_drops_per_min' => ['nullable', 'integer', 'min:0', 'max:120'],
            'oxytocin_dose_units' => ['nullable', 'string', 'max:50'],
            'drugs_iv_fluids' => ['nullable', 'string', 'max:500'],
            'systolic_bp' => ['nullable', 'integer', 'between:40,300'],
            'diastolic_bp' => ['nullable', 'integer', 'between:20,200'],
            'pulse_bpm' => ['nullable', 'integer', 'between:30,250'],
            'temperature_c' => ['nullable', 'numeric', 'between:30,45'],
            'urine_protein' => ['nullable', 'string', 'max:20'],
            'urine_acetone' => ['nullable', 'string', 'max:20'],
            'urine_volume_ml' => ['nullable', 'integer', 'min:0', 'max:3000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_emergency' => ['nullable', 'boolean'],
            'emergency_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $profile = MaternityProfile::query()
            ->where('reception_visit_id', $visit->id)
            ->orWhere(function ($q) use ($visit) {
                $q->where('patient_id', $visit->patient_id)
                    ->where('status', 'active');
            })
            ->latest()
            ->firstOrFail();

        $validated['reception_visit_id'] = $visit->id;
        $validated['clinical_encounter_id'] = $visit->clinicalEncounter?->id;

        $entry = $this->maternityService->recordPartograph($profile, $validated, $request);

        // If action line was crossed or severe hypertension detected, auto-recommend admission if not yet admitted
        if ($entry->action_line_crossed || ($entry->systolic_bp && $entry->systolic_bp >= 160)) {
            if ($visit->maternity_status !== ReceptionVisit::MATERNITY_STATUS_ADMITTED) {
                $visit->update([
                    'maternity_status' => ReceptionVisit::MATERNITY_STATUS_ADMISSION_RECOMMENDED,
                ]);
            }
        }

        return back()->with('success', 'Labour observation recorded in partograph successfully.');
    }

    /**
     * Document delivery and create linked independent newborn clinical record (Phase 7).
     */
    public function storeDelivery(Request $request, ReceptionVisit $visit): RedirectResponse
    {
        abort_unless(
            $request->user()->can('maternity.manage')
            || $request->user()->can('inpatient-nursing.view')
            || $request->user()->hasRole(['doctor', 'nurse', 'midwife', 'super-admin']),
            403
        );

        $validated = $request->validate([
            'delivered_at' => ['required', 'date'],
            'delivery_mode' => ['required', 'string', Rule::in(array_keys(DeliveryRecord::MODES))],
            'indication' => ['nullable', 'string', 'max:255'],
            'conducted_by' => ['nullable', 'integer', 'exists:users,id'],
            'assisted_by' => ['nullable', 'integer', 'exists:users,id'],
            'placenta_delivered_at' => ['nullable', 'date'],
            'placenta_mode' => ['nullable', 'string', 'max:40'],
            'placenta_complete' => ['nullable', 'boolean'],
            'blood_loss_ml' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'postpartum_hemorrhage' => ['nullable', 'boolean'],
            'perineal_status' => ['nullable', 'string', Rule::in(array_keys(DeliveryRecord::PERINEAL_STATUSES))],
            'suture_materials_used' => ['nullable', 'string', 'max:255'],
            'oxytocic_given' => ['nullable', 'string', 'max:100'],
            'baby_outcome' => ['required', 'string', Rule::in(array_keys(DeliveryRecord::BABY_OUTCOMES))],
            'baby_gender' => ['required', 'string', 'in:male,female,ambiguous'],
            'baby_name' => ['nullable', 'string', 'max:150'],
            'birth_order' => ['nullable', 'integer', 'min:1', 'max:10'],
            'birth_weight_grams' => ['nullable', 'integer', 'min:300', 'max:8000'],
            'birth_length_cm' => ['nullable', 'numeric', 'between:20,80'],
            'head_circumference_cm' => ['nullable', 'numeric', 'between:15,60'],
            'apgar_1min' => ['nullable', 'integer', 'between:0,10'],
            'apgar_5min' => ['nullable', 'integer', 'between:0,10'],
            'apgar_10min' => ['nullable', 'integer', 'between:0,10'],
            'resuscitation_required' => ['nullable', 'boolean'],
            'resuscitation_notes' => ['nullable', 'string', 'max:1000'],
            'vitamin_k_given' => ['nullable', 'boolean'],
            'eye_ointment_given' => ['nullable', 'boolean'],
            'bcg_given' => ['nullable', 'boolean'],
            'polio_0_given' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'is_emergency' => ['nullable', 'boolean'],
            'emergency_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $profile = MaternityProfile::query()
            ->where('reception_visit_id', $visit->id)
            ->orWhere(function ($q) use ($visit) {
                $q->where('patient_id', $visit->patient_id)
                    ->where('status', 'active');
            })
            ->latest()
            ->firstOrFail();

        $validated['reception_visit_id'] = $visit->id;
        $validated['clinical_encounter_id'] = $visit->clinicalEncounter?->id;

        $record = $this->maternityService->recordDelivery($profile, $validated, $request);

        // Advance visit status to completed if outpatient delivery is concluded
        if ($visit->maternity_status !== ReceptionVisit::MATERNITY_STATUS_ADMITTED) {
            $visit->update([
                'maternity_status' => ReceptionVisit::MATERNITY_STATUS_COMPLETED,
            ]);
        }

        return back()->with('success', "Delivery documented. Newborn patient #{$record->babyPatient?->patient_number} clinical record created.");
    }

    /**
     * Record serial newborn growth and developmental monitoring (Phase 8).
     */
    public function storeNewbornGrowth(Request $request, Patient $baby): RedirectResponse
    {
        abort_unless(
            $request->user()->can('maternity.manage')
            || $request->user()->can('inpatient-nursing.view')
            || $request->user()->can('vital-signs.create')
            || $request->user()->hasRole(['doctor', 'nurse', 'midwife', 'super-admin']),
            403
        );

        $validated = $request->validate([
            'recorded_at' => ['required', 'date'],
            'weight_kg' => ['required', 'numeric', 'between:0.3,30.0'],
            'length_height_cm' => ['required', 'numeric', 'between:20.0,150.0'],
            'head_circumference_cm' => ['required', 'numeric', 'between:15.0,70.0'],
            'temperature_c' => ['nullable', 'numeric', 'between:30.0,43.0'],
            'feeding_method' => ['required', 'string', Rule::in(array_keys(NewbornGrowthRecord::FEEDING_METHODS))],
            'immunizations_given' => ['nullable', 'array'],
            'developmental_milestones' => ['nullable', 'string', 'max:2000'],
            'clinical_notes' => ['nullable', 'string', 'max:3000'],
            'reception_visit_id' => ['nullable', 'integer', 'exists:reception_visits,id'],
            'clinical_encounter_id' => ['nullable', 'integer', 'exists:clinical_encounters,id'],
        ]);

        $this->maternityService->recordNewbornGrowth($baby, $validated, $request);

        return back()->with('success', 'Newborn growth and developmental measurements recorded.');
    }

    /**
     * Document maternity follow-up appointment plan and clinical instructions (Phase 9 & 10).
     */
    public function updateFollowUp(Request $request, ReceptionVisit $visit): RedirectResponse
    {
        abort_unless(
            $request->user()->can('maternity.manage')
            || $request->user()->can('inpatient-nursing.view')
            || $request->user()->hasRole(['doctor', 'nurse', 'midwife', 'super-admin']),
            403
        );

        $validated = $request->validate([
            'follow_up_date' => ['nullable', 'date', 'after_or_equal:today'],
            'follow_up_purpose' => ['nullable', 'string', 'max:50'],
            'follow_up_notes' => ['nullable', 'string', 'max:2000'],
            'maternity_status' => ['nullable', 'string', Rule::in(array_keys(ReceptionVisit::MATERNITY_STATUSES))],
        ]);

        $profile = MaternityProfile::query()
            ->where('reception_visit_id', $visit->id)
            ->orWhere(function ($q) use ($visit) {
                $q->where('patient_id', $visit->patient_id)
                    ->where('status', 'active');
            })
            ->latest()
            ->firstOrFail();

        $profile->update([
            'follow_up_date' => $validated['follow_up_date'] ?? null,
            'follow_up_purpose' => $validated['follow_up_purpose'] ?? null,
            'follow_up_notes' => $validated['follow_up_notes'] ?? null,
        ]);

        if (! empty($validated['maternity_status'])) {
            $visit->update([
                'maternity_status' => $validated['maternity_status'],
            ]);
        }

        $this->auditLog->record(
            'maternity_follow_up_scheduled',
            $profile,
            null,
            $validated,
            $request
        );

        return back()->with('success', 'Maternity follow-up plan saved successfully.');
    }

    /**
     * Order laboratory tests for an outpatient maternity visit.
     */
    public function storeLaboratoryOrder(
        StoreLaboratoryOrderRequest $request,
        ReceptionVisit $visit,
        LaboratoryOrderService $service,
        NhifLabAuthorizationService $nhifLab,
    ): RedirectResponse {
        abort_unless(
            $request->user()->can('laboratory-orders.create')
            || $request->user()->can('maternity.manage')
            || $request->user()->can('inpatient-nursing.view')
            || $request->user()->hasRole(['doctor', 'nurse', 'midwife', 'super-admin']),
            403
        );

        $encounter = $this->resolveOrPrepareMaternityEncounter($visit, $request);

        $order = $service->create($encounter, $request->validated(), $request);

        try {
            $nhifLab->authorizeOrder($order, $encounter, $request);
        } catch (\Throwable $e) {
            Log::warning('NHIF lab authorization in outpatient maternity failed (non-blocking)', [
                'laboratory_order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($visit->maternity_status === ReceptionVisit::MATERNITY_STATUS_WAITING) {
            $visit->update([
                'maternity_status' => ReceptionVisit::MATERNITY_STATUS_ASSESSMENT_IN_PROGRESS,
            ]);
        }

        $redirect = back()->with('success', "Laboratory order {$order->order_number} placed successfully.");

        if ($order->billing_warning ?? null) {
            $redirect->with('warning', $order->billing_warning);
        }

        return $redirect;
    }

    /**
     * Prescribe medications to pharmacy for an outpatient maternity visit.
     */
    public function storePrescriptionOrder(
        StorePrescriptionOrderRequest $request,
        ReceptionVisit $visit,
        PrescriptionOrderService $service,
        NhifEncounterPreApprovalService $nhifPreApproval,
    ): RedirectResponse {
        abort_unless(
            $request->user()->can('prescription-orders.create')
            || $request->user()->can('prescriptions.nurse-prescribe')
            || $request->user()->can('maternity.manage')
            || $request->user()->hasRole(['doctor', 'nurse', 'midwife', 'super-admin']),
            403
        );

        $encounter = $this->resolveOrPrepareMaternityEncounter($visit, $request);

        $order = $service->create($encounter, $request->validated(), $request);

        $insurance = $visit->patient?->currentNhifInsurance();
        $autoPreApproval = $request->boolean('send_preapproval', true);
        $preApprovalMessage = null;

        if ($insurance && $autoPreApproval) {
            try {
                $itemIds = $order->items->where('status', '!=', PrescriptionOrderItem::STATUS_CANCELLED)->pluck('id')->all();
                $session = $nhifPreApproval->createAndSubmitFromSelection(
                    $insurance,
                    $encounter,
                    [],
                    $itemIds,
                    $request,
                    $order->clinical_notes,
                    $encounter->practitioner?->nhif_practitioner_no,
                );
                $preApprovalMessage = "NHIF Pre-Approval request submitted (#{$session->id}).";
            } catch (\Throwable $e) {
                Log::warning('Automatic NHIF pre-approval submission failed for outpatient maternity prescription', [
                    'prescription_order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($visit->maternity_status === ReceptionVisit::MATERNITY_STATUS_WAITING) {
            $visit->update([
                'maternity_status' => ReceptionVisit::MATERNITY_STATUS_TREATMENT_IN_PROGRESS,
            ]);
        }

        $message = $preApprovalMessage
            ? "Prescription order {$order->order_number} sent to pharmacy. {$preApprovalMessage}"
            : "Prescription order {$order->order_number} sent to pharmacy.";

        return back()->with('success', $message);
    }

    /**
     * Order ultrasound / radiology procedures for an outpatient maternity visit.
     */
    public function storeRadiologyOrder(
        StoreRadiologyOrderRequest $request,
        ReceptionVisit $visit,
        RadiologyOrderService $service,
        NhifEncounterPreApprovalService $nhifPreApproval,
    ): RedirectResponse {
        abort_unless(
            $request->user()->can('radiology-orders.create')
            || $request->user()->can('maternity.manage')
            || $request->user()->can('inpatient-nursing.view')
            || $request->user()->hasRole(['doctor', 'nurse', 'midwife', 'super-admin']),
            403
        );

        $encounter = $this->resolveOrPrepareMaternityEncounter($visit, $request);

        $order = $service->create($encounter, $request->validated(), $request);

        $insurance = $visit->patient?->currentNhifInsurance();
        $autoPreApproval = $request->boolean('send_preapproval', true);
        $preApprovalMessage = null;

        if ($insurance && $autoPreApproval) {
            try {
                $itemIds = $order->items->where('status', '!=', RadiologyOrderItem::STATUS_CANCELLED)->pluck('id')->all();
                $session = $nhifPreApproval->createAndSubmitFromSelection(
                    $insurance,
                    $encounter,
                    [],
                    [],
                    $request,
                    $order->clinical_indication ?? $order->notes,
                    $encounter->practitioner?->nhif_practitioner_no,
                    $itemIds,
                );
                $preApprovalMessage = "NHIF Pre-Approval request submitted (#{$session->id}).";
            } catch (\Throwable $e) {
                Log::warning('Automatic NHIF pre-approval submission failed for outpatient maternity radiology', [
                    'radiology_order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($visit->maternity_status === ReceptionVisit::MATERNITY_STATUS_WAITING) {
            $visit->update([
                'maternity_status' => ReceptionVisit::MATERNITY_STATUS_ASSESSMENT_IN_PROGRESS,
            ]);
        }

        $message = $preApprovalMessage
            ? "Radiology order {$order->order_number} placed successfully. {$preApprovalMessage}"
            : "Radiology order {$order->order_number} placed successfully.";

        return back()->with('success', $message);
    }

    /**
     * Ensure an active ClinicalEncounter and an active Consultation exist for this maternity visit
     * so that LaboratoryOrderService, RadiologyOrderService, and PrescriptionOrderService can
     * record clinical orders without requiring a separate OPD doctor consultation flow first.
     */
    protected function resolveOrPrepareMaternityEncounter(ReceptionVisit $visit, Request $request): ClinicalEncounter
    {
        $encounter = $visit->clinicalEncounter;

        if (! $encounter) {
            $encounter = ClinicalEncounter::create([
                'patient_id' => $visit->patient_id,
                'reception_visit_id' => $visit->id,
                'appointment_id' => $visit->appointment_id,
                'department_id' => $visit->department_id,
                'practitioner_id' => $visit->practitioner_id,
                'encounter_type' => 'opd',
                'status' => ClinicalEncounter::STATUS_IN_PROGRESS,
                'started_at' => now(),
                'created_by' => $request->user()->id,
            ]);
        } elseif (! in_array($encounter->status, ClinicalEncounter::ACTIVE_STATUSES, true)) {
            $encounter->update([
                'status' => ClinicalEncounter::STATUS_IN_PROGRESS,
            ]);
        }

        $consultation = $encounter->consultation;

        if (! $consultation || ! in_array($consultation->status, [Consultation::STATUS_IN_PROGRESS, Consultation::STATUS_COMPLETED], true)) {
            $practitionerId = $encounter->practitioner_id
                ?? $visit->practitioner_id
                ?? $request->user()->staff?->practitioner?->id
                ?? Practitioner::where('status', Practitioner::STATUS_ACTIVE)->value('id');

            if (! $practitionerId) {
                $staffId = $request->user()->staff?->id ?? Staff::query()->value('id');
                if (! $staffId) {
                    $hospitalId = $visit->department?->hospital_id ?? \App\Models\Hospital::query()->value('id');
                    $staff = Staff::create([
                        'staff_number' => 'STF-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
                        'user_id' => $request->user()->id,
                        'hospital_id' => $hospitalId,
                        'department_id' => $visit->department_id,
                        'first_name' => $request->user()->UserName ?? 'Maternity',
                        'last_name' => 'Clinician',
                        'gender' => 'female',
                        'job_title' => 'Midwife / Nurse',
                        'employment_type' => 'full_time',
                        'status' => 'active',
                    ]);
                    $staffId = $staff->id;
                }
                $practitioner = Practitioner::create([
                    'practitioner_number' => 'PRC-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
                    'staff_id' => $staffId,
                    'practitioner_type' => 'nurse',
                    'professional_registration_number' => 'PRN-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
                    'license_number' => 'LIC-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
                    'status' => Practitioner::STATUS_ACTIVE,
                ]);
                $practitionerId = $practitioner->id;
            }

            if ($consultation) {
                $consultation->update([
                    'status' => Consultation::STATUS_IN_PROGRESS,
                    'started_at' => $consultation->started_at ?? now(),
                ]);
            } else {
                Consultation::create([
                    'clinical_encounter_id' => $encounter->id,
                    'practitioner_id' => $practitionerId,
                    'created_by' => $request->user()->id,
                    'status' => Consultation::STATUS_IN_PROGRESS,
                    'started_at' => now(),
                    'chief_complaint' => $visit->visit_purpose ?? 'Maternity Assessment',
                ]);
            }

            $encounter->unsetRelation('consultation');
        }

        return $encounter;
    }
}

