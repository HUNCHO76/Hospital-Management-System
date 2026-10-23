<?php

namespace App\Http\Controllers;

use App\Http\Requests\Inpatient\StoreDeliveryRecordRequest;
use App\Http\Requests\Inpatient\StoreMaternityProfileRequest;
use App\Http\Requests\Inpatient\StorePartographEntryRequest;
use App\Http\Requests\Inpatient\UpdateMaternityStageRequest;
use App\Models\Admission;
use App\Models\DeliveryRecord;
use App\Models\MaternityProfile;
use App\Models\ObservationEscalation;
use App\Models\PartographEntry;
use App\Models\Practitioner;
use App\Services\MaternityEligibilityService;
use App\Services\MaternityService;
use App\Services\NursingAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for Inpatient Maternity Nursing, Labour Monitoring & WHO-compliant Partograph.
 */
class InpatientMaternityController extends Controller
{
    public function __construct(
        protected NursingAccessService $accessService,
        protected MaternityService $maternityService,
        protected MaternityEligibilityService $eligibilityService,
    ) {}

    /**
     * Display the maternity nursing and labour workspace for an admitted patient.
     */
    public function show(Request $request, Admission $admission): Response
    {
        abort_unless($this->accessService->canAccess($request->user(), $admission), 403);
        abort_unless(
            $request->user()->can('maternity.view') || $request->user()->can('inpatient-nursing.view'),
            403
        );

        $admission->load([
            'patient',
            'department:id,name',
            'practitioner.staff:id,first_name,last_name',
        ]);

        $profile = MaternityProfile::query()
            ->where('admission_id', $admission->id)
            ->with([
                'recordedBy:id,UserName',
                'assignedMidwife:id,UserName',
                'primaryClinician:id,UserName',
                'exceptionAuthorizedBy:id,UserName',
                'stageUpdatedBy:id,UserName',
                'deliveryRecord.conductedBy:id,UserName',
                'deliveryRecord.assistedBy:id,UserName',
                'deliveryRecord.babyPatient:id,patient_number,first_name,last_name',
            ])
            ->latest()
            ->first();

        $partographEntries = $profile ? $profile->partographEntries()
            ->with('recordedBy:id,UserName')
            ->orderBy('recorded_at')
            ->get() : [];

        $practitioners = Practitioner::query()
            ->where('is_active', true)
            ->with('staff:id,first_name,last_name')
            ->get(['id', 'staff_id']);

        $eligibility = $this->eligibilityService->checkEligibility($admission->patient);

        $latestVital = $admission->vitalSigns()->latest('recorded_at')->first();

        $medications = $admission->medicationTreatments()
            ->with(['medication:id,generic_name,strength,form,route', 'marEntries' => fn ($q) => $q->latest('scheduled_at')->limit(5)])
            ->get();

        $clinicalAlerts = ObservationEscalation::query()
            ->where('admission_id', $admission->id)
            ->where('status', ObservationEscalation::STATUS_OPEN)
            ->latest('raised_at')
            ->get();

        $insurance = $admission->patient->insurances()->where('status', 'active')->latest()->first();

        return Inertia::render('inpatient/maternity-workspace', [
            'admission' => $admission,
            'profile' => $profile,
            'eligibility' => $eligibility,
            'partographEntries' => $partographEntries,
            'deliveryRecord' => $profile?->deliveryRecord,
            'latestVital' => $latestVital,
            'medications' => $medications,
            'clinicalAlerts' => $clinicalAlerts,
            'insurance' => $insurance,
            'practitioners' => $practitioners,
            'constants' => [
                'amniotic_fluid_states' => PartographEntry::AMNIOTIC_FLUID_STATES,
                'moulding_grades' => PartographEntry::MOULDING_GRADES,
                'contraction_durations' => PartographEntry::CONTRACTION_DURATIONS,
                'presentations' => MaternityProfile::PRESENTATIONS,
                'membrane_statuses' => MaternityProfile::MEMBRANE_STATUSES,
                'hiv_statuses' => MaternityProfile::HIV_STATUSES,
                'care_stages' => MaternityProfile::CARE_STAGES,
                'labour_stages' => MaternityProfile::LABOUR_STAGES,
                'delivery_modes' => DeliveryRecord::MODES,
                'perineal_statuses' => DeliveryRecord::PERINEAL_STATUSES,
                'baby_outcomes' => DeliveryRecord::BABY_OUTCOMES,
            ],
            'canAuthorizeException' => $request->user()->can('maternity.clinical-exception') || $request->user()->hasRole(['doctor', 'super-admin', 'hospital-admin']),
        ]);
    }

    /**
     * Store or update maternity profile (obstetric history & labour episode).
     */
    public function storeProfile(StoreMaternityProfileRequest $request, Admission $admission): RedirectResponse
    {
        abort_unless($this->accessService->canAccess($request->user(), $admission), 403);
        abort_unless(
            $request->user()->can('maternity.manage') || $request->user()->can('inpatient-nursing.view'),
            403
        );

        $this->maternityService->saveProfile($admission, $request->validated(), $request);

        return back()->with('success', 'Maternity episode profile updated successfully.');
    }

    /**
     * Advance or transition the maternity care stage.
     */
    public function updateStage(
        UpdateMaternityStageRequest $request,
        Admission $admission,
        MaternityProfile $profile
    ): RedirectResponse {
        abort_unless($this->accessService->canAccess($request->user(), $admission), 403);
        abort_unless((int) $profile->admission_id === (int) $admission->id, 404);
        abort_unless(
            $request->user()->can('maternity.manage') || $request->user()->can('inpatient-nursing.view'),
            403
        );

        $this->maternityService->transitionStage(
            $profile,
            $request->validated()['care_stage'],
            $request,
            $request->validated()['notes'] ?? null
        );

        return back()->with('success', 'Maternity care stage updated successfully.');
    }

    /**
     * Record a WHO-compliant partograph observation entry.
     */
    public function storePartograph(
        StorePartographEntryRequest $request,
        Admission $admission,
        MaternityProfile $profile
    ): RedirectResponse {
        abort_unless($this->accessService->canAccess($request->user(), $admission), 403);
        abort_unless((int) $profile->admission_id === (int) $admission->id, 404);
        abort_unless(
            $request->user()->can('maternity.partograph-record') || $request->user()->can('maternity.manage'),
            403
        );

        $this->maternityService->recordPartograph($profile, $request->validated(), $request);

        return back()->with('success', 'Partograph reading recorded successfully.');
    }

    /**
     * Document delivery outcome.
     */
    public function storeDelivery(
        StoreDeliveryRecordRequest $request,
        Admission $admission,
        MaternityProfile $profile
    ): RedirectResponse {
        abort_unless($this->accessService->canAccess($request->user(), $admission), 403);
        abort_unless((int) $profile->admission_id === (int) $admission->id, 404);
        abort_unless(
            $request->user()->can('maternity.delivery-record') || $request->user()->can('maternity.manage'),
            403
        );

        $this->maternityService->recordDelivery($profile, $request->validated(), $request);

        return back()->with('success', 'Delivery and newborn outcome documented successfully.');
    }
}
