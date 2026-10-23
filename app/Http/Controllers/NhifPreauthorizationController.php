<?php

namespace App\Http\Controllers;

use App\Models\LaboratoryOrderItem;
use App\Models\NhifPreauthorization;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\PrescriptionOrderItem;
use App\Models\RadiologyOrderItem;
use App\Services\NHIF\NHIFPreauthorizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class NhifPreauthorizationController extends Controller
{
    /**
     * The only clinical order-item models the existing preauthorization
     * gate is actually wired into (DispensingService, LaboratorySampleService,
     * PrescriptionVerificationService, RadiologyExaminationService).
     *
     * @var array<string, string>
     */
    protected const SOURCE_TABLES = [
        PrescriptionOrderItem::class => 'prescription_order_items',
        LaboratoryOrderItem::class => 'laboratory_order_items',
        RadiologyOrderItem::class => 'radiology_order_items',
    ];

    public function index(Request $request, Patient $patient, PatientInsurance $patientInsurance): Response
    {
        abort_unless($request->user()->can('nhif-preauthorization.view'), 403);
        abort_unless($patientInsurance->patient_id === $patient->id, 404);

        $patientInsurance->load('patient', 'insuranceProvider');

        $preauthorizations = NhifPreauthorization::where('patient_insurance_id', $patientInsurance->id)
            ->with('hospital', 'createdBy', 'documents')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (NhifPreauthorization $p) => [
                'id' => $p->id,
                'status' => $p->status,
                'source_type' => $p->source_type,
                'source_id' => $p->source_id,
                'approved_amount' => $p->approved_amount,
                'rejection_reason' => $p->rejection_reason,
                'submitted_at' => $p->submitted_at?->toDateTimeString(),
                'resolved_at' => $p->resolved_at?->toDateTimeString(),
                'hospital' => $p->hospital ? ['name' => $p->hospital->name] : null,
                'created_by' => $p->createdBy?->name,
                'created_at' => $p->created_at->toDateTimeString(),
                'documents_count' => $p->documents->count(),
            ]);

        return Inertia::render('nhif/preauthorizations/index', [
            'patientInsurance' => [
                'id' => $patientInsurance->id,
                'membership_number' => $patientInsurance->membership_number,
                'patient' => $patientInsurance->patient ? [
                    'id' => $patientInsurance->patient->id,
                    'name' => $patientInsurance->patient->full_name,
                ] : null,
            ],
            'preauthorizations' => $preauthorizations,
        ]);
    }

    public function store(Request $request, Patient $patient, PatientInsurance $patientInsurance): RedirectResponse
    {
        abort_unless($request->user()->can('nhif-preauthorization.request'), 403);
        abort_unless($patientInsurance->patient_id === $patient->id, 404);

        $data = $request->validate([
            'source_type' => ['required', 'string', Rule::in(array_keys(self::SOURCE_TABLES))],
            'source_id' => ['required', 'integer'],
            'hospital_id' => ['required', 'integer', 'exists:hospitals,id'],
            'authorization_no' => ['required', 'string', 'max:100'],
        ]);

        $this->assertAuthorizedFacility($request, (int) $data['hospital_id']);

        $table = self::SOURCE_TABLES[$data['source_type']];

        if (! DB::table($table)->where('id', $data['source_id'])->exists()) {
            throw ValidationException::withMessages([
                'source_id' => 'This service does not exist.',
            ]);
        }

        $existing = NhifPreauthorization::query()
            ->where('source_type', $data['source_type'])
            ->where('source_id', $data['source_id'])
            ->where('status', '!=', NhifPreauthorization::STATUS_CANCELLED)
            ->first();

        if ($existing) {
            return redirect()
                ->route('nhif.preauthorization.show', $existing)
                ->with('success', 'A pre-authorisation request for this service already exists.');
        }

        $preauth = NhifPreauthorization::create([
            'hospital_id' => $data['hospital_id'],
            'patient_insurance_id' => $patientInsurance->id,
            'source_type' => $data['source_type'],
            'source_id' => $data['source_id'],
            'authorization_no' => $data['authorization_no'],
            'status' => NhifPreauthorization::STATUS_PENDING,
            'created_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('nhif.preauthorization.show', $preauth)
            ->with('success', 'Pre-authorisation request created.');
    }

    public function show(Request $request, NhifPreauthorization $nhifPreauthorization): Response
    {
        abort_unless($request->user()->can('nhif-preauthorization.view'), 403);
        $this->assertAuthorizedFacility($request, (int) $nhifPreauthorization->hospital_id);

        $nhifPreauthorization->load('hospital', 'patientInsurance.patient', 'createdBy', 'documents');

        return Inertia::render('nhif/preauthorizations/show', [
            'preauth' => [
                'id' => $nhifPreauthorization->id,
                'status' => $nhifPreauthorization->status,
                'source_type' => $nhifPreauthorization->source_type,
                'source_id' => $nhifPreauthorization->source_id,
                'approved_amount' => $nhifPreauthorization->approved_amount,
                'rejection_reason' => $nhifPreauthorization->rejection_reason,
                'request_data' => $nhifPreauthorization->request_data,
                'response_data' => $nhifPreauthorization->response_data,
                'submitted_at' => $nhifPreauthorization->submitted_at?->toDateTimeString(),
                'resolved_at' => $nhifPreauthorization->resolved_at?->toDateTimeString(),
                'hospital' => $nhifPreauthorization->hospital ? ['id' => $nhifPreauthorization->hospital->id, 'name' => $nhifPreauthorization->hospital->name] : null,
                'patient_insurance' => $nhifPreauthorization->patientInsurance ? [
                    'id' => $nhifPreauthorization->patientInsurance->id,
                    'membership_number' => $nhifPreauthorization->patientInsurance->membership_number,
                    'patient' => $nhifPreauthorization->patientInsurance->patient ? [
                        'id' => $nhifPreauthorization->patientInsurance->patient->id,
                        'name' => $nhifPreauthorization->patientInsurance->patient->full_name,
                    ] : null,
                ] : null,
                'created_by' => $nhifPreauthorization->createdBy?->name,
                'created_at' => $nhifPreauthorization->created_at->toDateTimeString(),
                'updated_at' => $nhifPreauthorization->updated_at->toDateTimeString(),
                'documents' => $nhifPreauthorization->documents->map(fn ($d) => [
                    'id' => $d->id,
                    'original_name' => $d->original_name,
                    'mime_type' => $d->mime_type,
                    'file_size' => $d->file_size,
                    'created_at' => $d->created_at->toDateTimeString(),
                ]),
            ],
        ]);
    }

    public function submit(Request $request, NhifPreauthorization $nhifPreauthorization, NHIFPreauthorizationService $service): RedirectResponse
    {
        abort_unless($request->user()->can('nhif-preauthorization.request'), 403);
        $this->assertAuthorizedFacility($request, (int) $nhifPreauthorization->hospital_id);

        if (! in_array($nhifPreauthorization->status, [NhifPreauthorization::STATUS_PENDING, NhifPreauthorization::STATUS_REJECTED], true)) {
            return back()->with('error', 'Only pending or rejected pre-authorisations can be submitted.');
        }

        $updated = $service->request($nhifPreauthorization, $request);

        if ($updated->status !== NhifPreauthorization::STATUS_SUBMITTED) {
            return back()->with('error', 'Could not reach NHIF — the request will remain pending. Try again shortly.');
        }

        return back()->with('success', 'Pre-authorisation submitted to NHIF. Sync to check for a decision.');
    }

    public function sync(Request $request, NhifPreauthorization $nhifPreauthorization, NHIFPreauthorizationService $service): RedirectResponse
    {
        abort_unless($request->user()->can('nhif-preauthorization.request'), 403);
        $this->assertAuthorizedFacility($request, (int) $nhifPreauthorization->hospital_id);

        if (! in_array($nhifPreauthorization->status, [NhifPreauthorization::STATUS_SUBMITTED], true)) {
            return back()->with('error', 'Only submitted pre-authorisations can be synced.');
        }

        $updated = $service->syncStatus($nhifPreauthorization, $request);

        if ($updated->status === NhifPreauthorization::STATUS_SUBMITTED) {
            return back()->with('success', 'No decision from NHIF yet — still pending.');
        }

        return back()->with('success', "Pre-authorisation status updated: {$updated->status}.");
    }

    public function cancel(Request $request, NhifPreauthorization $nhifPreauthorization): RedirectResponse
    {
        abort_unless($request->user()->can('nhif-preauthorization.cancel'), 403);
        $this->assertAuthorizedFacility($request, (int) $nhifPreauthorization->hospital_id);

        if ($nhifPreauthorization->status === NhifPreauthorization::STATUS_APPROVED) {
            return back()->with('error', 'Approved pre-authorisations cannot be cancelled.');
        }

        $nhifPreauthorization->update([
            'status' => NhifPreauthorization::STATUS_CANCELLED,
            'resolved_at' => now(),
        ]);

        return back()->with('success', 'Pre-authorisation cancelled.');
    }

    protected function assertAuthorizedFacility(Request $request, int $hospitalId): void
    {
        $user = $request->user();
        if ($user->hasRole('super-admin')) {
            return;
        }

        $staff = $user->staff;
        abort_unless($staff && (int) $staff->hospital_id === $hospitalId, 403, 'You are not authorized to access pre-authorizations for another facility.');
    }
}
