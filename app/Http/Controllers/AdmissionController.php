<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admission\ApproveAdmissionRequest;
use App\Http\Requests\Admission\CancelAdmissionRequest;
use App\Http\Requests\Admission\DischargeAdmissionRequest;
use App\Http\Requests\Admission\RejectAdmissionRequest;
use App\Http\Requests\Admission\ReturnAdmissionRequest;
use App\Http\Requests\Admission\StoreAdmissionRequest;
use App\Http\Requests\Admission\StoreTransferRequest;
use App\Models\Admission;
use App\Models\AdmissionTransfer;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\Practitioner;
use App\Models\Service;
use App\Services\AdmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inpatient admission lifecycle (Phase 9, Step 71) + transfers (Step 72).
 */
class AdmissionController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Admission::class);

        $hospitalId = $this->scopedHospitalId($request);
        $filters = $request->only(['status', 'department_id', 'hospital_id', 'patient_id', 'admission_type']);

        $admissions = Admission::query()
            ->when($hospitalId, fn ($query, $id) => $query->where('hospital_id', $id))
            ->when($filters['hospital_id'] ?? null, fn ($query, $id) => $query->where('hospital_id', $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['department_id'] ?? null, fn ($query, $id) => $query->where('department_id', $id))
            ->when($filters['patient_id'] ?? null, fn ($query, $id) => $query->where('patient_id', $id))
            ->when($filters['admission_type'] ?? null, fn ($query, $type) => $query->where('admission_type', $type))
            ->with(['patient:id,patient_number,first_name,middle_name,last_name', 'hospital:id,name', 'department:id,name'])
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('admissions/index', [
            'admissions' => $admissions,
            'filters' => $filters,
            'departments' => Department::query()->active()->orderBy('name')->get(['id', 'name']),
            'admissionTypes' => Admission::ADMISSION_TYPES,
            'statuses' => [
                Admission::STATUS_PENDING,
                Admission::STATUS_UNDER_REVIEW,
                Admission::STATUS_APPROVED,
                Admission::STATUS_ACTIVE,
                Admission::STATUS_REJECTED,
                Admission::STATUS_DISCHARGED,
                Admission::STATUS_CANCELLED,
                Admission::STATUS_TRANSFERRED,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('viewAny', Admission::class);

        return Inertia::render('admissions/create', [
            'hospitals' => Hospital::query()->active()->orderBy('name')->get(['id', 'name']),
            'departments' => Department::query()->active()->orderBy('name')->get(['id', 'name', 'hospital_id']),
            'practitioners' => Practitioner::query()->where('status', 'active')->with('staff:id,first_name,last_name')->get(['id', 'practitioner_number', 'staff_id']),
            'services' => Service::query()->where('status', Service::STATUS_ACTIVE)->where('is_billable', true)->where('category', 'inpatient')->orderBy('name')->get(['id', 'code', 'name']),
            'admissionTypes' => Admission::ADMISSION_TYPES,
        ]);
    }

    public function store(StoreAdmissionRequest $request, AdmissionService $service): RedirectResponse
    {
        $admission = $service->create($request->validated(), $request);

        return to_route('admissions.show', $admission)->with('success', "Admission request {$admission->admission_number} created.");
    }

    public function show(Request $request, Admission $admission): Response
    {
        $this->authorize('view', $admission);

        $admission->load([
            'patient:id,patient_number,first_name,middle_name,last_name,phone',
            'hospital:id,name',
            'department:id,name',
            'practitioner:id,practitioner_number,staff_id',
            'practitioner.staff:id,first_name,last_name',
            'service:id,code,name',
            'admittedBy:id,UserName',
            'dischargedBy:id,UserName',
            'createdBy:id,UserName',
            'reviewedBy:id,UserName',
            'approvedBy:id,UserName',
            'rejectedBy:id,UserName',
            'nhifAdmission',
        ]);

        return Inertia::render('admissions/show', [
            'admission' => $admission,
            'canReview' => $request->user()->can('review', $admission),
            'canApprove' => $request->user()->can('approve', $admission),
            'canReject' => $request->user()->can('reject', $admission),
            'canReturnForCorrection' => $request->user()->can('returnForCorrection', $admission),
            'canConfirm' => $request->user()->can('confirm', $admission),
            'canDischarge' => $request->user()->can('discharge', $admission),
            'canCancel' => $request->user()->can('cancel', $admission),
            'canSubmitNhif' => $request->user()->can('submitNhif', $admission),
            'dischargeTypes' => Admission::DISCHARGE_TYPES,
        ]);
    }

    public function startReview(Request $request, Admission $admission, AdmissionService $service): RedirectResponse
    {
        $this->authorize('review', $admission);

        try {
            $service->startReview($admission, $request);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        return back()->with('success', 'Admission placed under review.');
    }

    public function approve(ApproveAdmissionRequest $request, Admission $admission, AdmissionService $service): RedirectResponse
    {
        $this->authorize('approve', $admission);

        try {
            $service->approve($admission, $request->validated('notes'), $request);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        return back()->with('success', 'Admission approved.');
    }

    public function reject(RejectAdmissionRequest $request, Admission $admission, AdmissionService $service): RedirectResponse
    {
        $this->authorize('reject', $admission);

        try {
            $service->reject($admission, $request->validated('reason'), $request);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        return back()->with('success', 'Admission request rejected.');
    }

    public function returnForCorrection(ReturnAdmissionRequest $request, Admission $admission, AdmissionService $service): RedirectResponse
    {
        $this->authorize('returnForCorrection', $admission);

        try {
            $service->returnForCorrection($admission, $request->validated('notes'), $request);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        return back()->with('success', 'Admission returned for correction.');
    }

    public function confirm(Request $request, Admission $admission, AdmissionService $service): RedirectResponse
    {
        $this->authorize('confirm', $admission);

        try {
            $service->confirm($admission, $request);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        return back()->with('success', 'Admission confirmed — patient is now admitted.');
    }

    public function discharge(DischargeAdmissionRequest $request, Admission $admission, AdmissionService $service): RedirectResponse
    {
        $this->authorize('discharge', $admission);

        try {
            $service->discharge($admission, $request->validated(), $request);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        return back()->with('success', 'Patient discharged.');
    }

    public function cancel(CancelAdmissionRequest $request, Admission $admission, AdmissionService $service): RedirectResponse
    {
        $this->authorize('cancel', $admission);

        try {
            $service->cancel($admission, $request->validated()['reason'], $request);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        return back()->with('success', 'Admission request cancelled.');
    }

    public function storeTransfer(StoreTransferRequest $request, Admission $admission, AdmissionService $service): RedirectResponse
    {
        $this->authorize('storeTransfer', $admission);

        try {
            $service->storeTransfer($admission, $request->validated(), $request);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        return back()->with('success', 'Transfer request recorded.');
    }

    public function completeTransfer(Request $request, Admission $admission, AdmissionTransfer $transfer, AdmissionService $service): RedirectResponse
    {
        $this->authorize('completeTransfer', $admission);

        try {
            $service->completeTransfer($admission, $transfer, $request);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        return back()->with('success', 'Transfer completed.');
    }

    public function submitNhif(Request $request, Admission $admission, AdmissionService $service): RedirectResponse
    {
        $this->authorize('submitNhif', $admission);

        $service->submitNhif($admission, $request);

        return back()->with('success', 'Admission submitted to NHIF.');
    }

    public function submitTransferNhif(Request $request, Admission $admission, AdmissionTransfer $transfer, AdmissionService $service): RedirectResponse
    {
        $this->authorize('submitTransferNhif', $admission);

        try {
            $service->submitTransferNhif($admission, $transfer, $request);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        return back()->with('success', 'Transfer submitted to NHIF.');
    }

    /**
     * The hospital a hospital-admin is restricted to, or null when the user
     * is unrestricted (super-admin and any other unscoped role).
     */
    protected function scopedHospitalId(Request $request): ?int
    {
        if (! $request->user()->hasRole('hospital-admin')) {
            return null;
        }

        return $request->user()->staff?->hospital_id;
    }
}
