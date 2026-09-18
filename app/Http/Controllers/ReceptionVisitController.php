<?php

namespace App\Http\Controllers;

use App\Http\Requests\Reception\CancelVisitRequest;
use App\Http\Requests\Reception\CheckInAppointmentRequest;
use App\Http\Requests\Reception\StoreWalkInRequest;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Department;
use App\Models\PatientAccount;
use App\Models\Practitioner;
use App\Models\ReceptionVisit;
use App\Models\Service;
use App\Services\FinancialAuthorizationService;
use App\Services\ReceptionVisitService;
use App\Services\ServicePriceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReceptionVisitController extends Controller
{
    /**
     * Display the reception dashboard: today's appointment/visit summary,
     * today's appointments (with reception + insurance status), and today's
     * walk-ins. Hospital-scoped exactly like AppointmentController.
     */
    public function dashboard(Request $request, ReceptionVisitService $service): Response
    {
        $this->authorize('viewDashboard', ReceptionVisit::class);

        return Inertia::render('reception/dashboard', $service->dashboardData($this->scopedHospitalId($request)));
    }

    /**
     * Display a filterable, paginated list of reception visits.
     */
    public function index(Request $request, FinancialAuthorizationService $financialAuth): Response
    {
        $this->authorize('viewAny', ReceptionVisit::class);

        $hospitalId = $this->scopedHospitalId($request);
        $filters = $request->only(['date', 'department_id', 'status', 'visit_type', 'search', 'patient_id']);

        $visits = ReceptionVisit::query()
            ->when($hospitalId, fn ($query, $id) => $query->whereHas('department', fn ($query) => $query->where('hospital_id', $id)))
            ->when($filters['date'] ?? null, fn ($query, $date) => $query->whereDate('created_at', $date))
            ->when($filters['department_id'] ?? null, fn ($query, $id) => $query->where('department_id', $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['visit_type'] ?? null, fn ($query, $type) => $query->where('visit_type', $type))
            ->when($filters['patient_id'] ?? null, fn ($query, $id) => $query->where('patient_id', $id))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('visit_number', 'like', "%{$search}%")
                        ->orWhereHas('patient', function ($query) use ($search) {
                            $query->where('patient_number', 'like', "%{$search}%")
                                ->orWhere('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        });
                });
            })
            ->with([
                'patient:id,patient_number,first_name,middle_name,last_name',
                'department:id,name,code,hospital_id',
                'appointment:id,appointment_number',
                'practitioner:id,practitioner_number,staff_id',
                'practitioner.staff:id,first_name,last_name',
            ])
            ->latest('created_at')
            ->paginate(15)
            ->through(function (ReceptionVisit $visit) use ($financialAuth) {
                $financialStatus = $financialAuth->statusForSource(ReceptionVisit::class, $visit->id);

                $patientAccountId = null;
                if ($visit->department?->hospital_id && $visit->patient_id) {
                    $patientAccountId = PatientAccount::query()
                        ->where('patient_id', $visit->patient_id)
                        ->where('hospital_id', $visit->department->hospital_id)
                        ->value('id');
                }

                $visit->financial_status = $financialStatus;
                $visit->patient_account_id = $patientAccountId;

                return $visit;
            })
            ->withQueryString();

        return Inertia::render('reception/visits/index', [
            'visits' => $visits,
            'filters' => $filters,
            'departments' => Department::active()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    /**
     * Display the specified reception visit.
     */
    public function show(ReceptionVisit $visit, FinancialAuthorizationService $financialAuth): Response
    {
        $this->authorize('view', $visit);

        $visit->load([
            'patient:id,patient_number,first_name,middle_name,last_name,phone,date_of_birth,gender,status',
            'patient.insurances' => fn ($query) => $query->orderByDesc('is_primary'),
            'department:id,name,code,hospital_id',
            'appointment:id,appointment_number,appointment_type,scheduled_start,status,practitioner_id',
            'appointment.practitioner:id,practitioner_number,staff_id',
            'appointment.practitioner.staff:id,first_name,last_name',
            'practitioner:id,practitioner_number,staff_id,consultation_service_id',
            'practitioner.staff:id,first_name,last_name',
        ]);

        $consultationFinancialStatus = $financialAuth->statusForSource(ReceptionVisit::class, $visit->id);

        $consultationCharge = Charge::query()
            ->where('source_type', ReceptionVisit::class)
            ->where('source_id', $visit->id)
            ->whereNotIn('status', [Charge::STATUS_VOIDED, Charge::STATUS_CANCELLED])
            ->first(['id', 'charge_number', 'status', 'unit_price', 'quantity', 'net_amount', 'currency']);

        $patientAccount = null;
        if ($visit->department?->hospital_id && $visit->patient_id) {
            $patientAccount = PatientAccount::query()
                ->where('patient_id', $visit->patient_id)
                ->where('hospital_id', $visit->department->hospital_id)
                ->first(['id']);
        }

        return Inertia::render('reception/visits/show', [
            'visit' => $visit,
            'consultationFinancialStatus' => $consultationFinancialStatus,
            'consultationCharge' => $consultationCharge,
            'patientAccountId' => $patientAccount?->id,
        ]);
    }

    /**
     * Show the guided walk-in registration form.
     */
    public function createWalkIn(): Response
    {
        $this->authorize('createWalkIn', ReceptionVisit::class);

        return Inertia::render('reception/walk-in/create', [
            'departments' => Department::active()->orderBy('name')->get(['id', 'name', 'code', 'hospital_id']),
            'visitTypes' => ReceptionVisit::TYPES,
            'practitioners' => Practitioner::query()
                ->where('status', Practitioner::STATUS_ACTIVE)
                ->with('staff:id,first_name,last_name')
                ->get(['id', 'practitioner_number', 'staff_id', 'consultation_service_id']),
        ]);
    }

    /**
     * Register a walk-in patient's visit.
     */
    public function storeWalkIn(StoreWalkInRequest $request, ReceptionVisitService $service): RedirectResponse
    {
        $this->authorize('createWalkIn', ReceptionVisit::class);

        $visit = $service->createWalkIn($request->validated(), $request);

        return to_route('reception.visits.show', $visit)->with('success', "Walk-in visit {$visit->visit_number} created.");
    }

    /**
     * Check in a scheduled appointment at the front desk.
     */
    public function checkInAppointment(CheckInAppointmentRequest $request, Appointment $appointment, ReceptionVisitService $service): RedirectResponse
    {
        $this->authorize('checkIn', ReceptionVisit::class);

        $departmentId = $request->validated('department_id') ?? $appointment->department_id;

        $visit = $service->checkInAppointment($appointment, $departmentId, $request);

        return to_route('reception.visits.show', $visit)->with('success', "Patient checked in — visit {$visit->visit_number}.");
    }

    /**
     * Move a checked-in visit to waiting.
     */
    public function markWaiting(Request $request, ReceptionVisit $visit, ReceptionVisitService $service): RedirectResponse
    {
        $this->authorize('update', $visit);

        $service->markWaiting($visit, $request);

        return back()->with('success', 'Visit marked waiting.');
    }

    /**
     * Mark a waiting visit ready for service.
     */
    public function markReady(Request $request, ReceptionVisit $visit, ReceptionVisitService $service): RedirectResponse
    {
        $this->authorize('update', $visit);

        $service->markReadyForService($visit, $request);

        return back()->with('success', 'Visit marked ready for service.');
    }

    /**
     * Send a ready visit to its destination department/service.
     * Returns a billing-direction error when financial authorization fails
     * so reception staff know to send the patient to the cashier first.
     */
    public function sendToService(Request $request, ReceptionVisit $visit, ReceptionVisitService $service): RedirectResponse
    {
        $this->authorize('update', $visit);

        $service->sendToService($visit, $request);

        return back()->with('success', 'Visit sent to service.');
    }

    /**
     * Return the financial authorization status for a visit as JSON — used
     * by the frontend to decide whether to show the "Send to Service" button
     * or the "Payment Required" block.
     */
    public function financialStatus(ReceptionVisit $visit, FinancialAuthorizationService $financialAuth): JsonResponse
    {
        $this->authorize('view', $visit);

        return response()->json([
            'status' => $financialAuth->statusForSource(ReceptionVisit::class, $visit->id),
        ]);
    }

    /**
     * Resolve the consultation price for a practitioner at a department's
     * hospital — called by the walk-in form to preview the charge before
     * submission so the receptionist can inform the patient.
     */
    public function consultationPrice(Request $request, ServicePriceResolver $resolver): JsonResponse
    {
        $this->authorize('createWalkIn', ReceptionVisit::class);

        $departmentId = (int) $request->query('department_id');
        $practitionerId = (int) $request->query('practitioner_id');

        $department = $departmentId ? Department::find($departmentId, ['id', 'hospital_id']) : null;
        $practitioner = $practitionerId ? Practitioner::find($practitionerId) : null;

        if (! $department) {
            return response()->json(['price' => null]);
        }

        $serviceId = $practitioner?->consultation_service_id;

        if (! $serviceId) {
            $code = ($practitioner && in_array($practitioner->practitioner_type, ['specialist', 'consultant', 'surgeon'], true))
                ? 'CONSULT-SPECIALIST'
                : 'CONSULT-GP';

            $serviceId = Service::query()->where('code', $code)->value('id')
                ?? Service::query()->where('category', 'consultation')->value('id');
        }

        if (! $serviceId) {
            return response()->json(['price' => null]);
        }

        $service = Service::find($serviceId);

        if (! $service) {
            return response()->json(['price' => null]);
        }

        try {
            $servicePrice = $resolver->resolvePrice($service, $department->hospital_id);

            return response()->json([
                'price' => (float) $servicePrice->amount,
                'currency' => $servicePrice->currency ?? config('billing.currency', 'KES'),
            ]);
        } catch (\Throwable) {
            return response()->json(['price' => null]);
        }
    }

    /**
     * Complete a visit.
     */
    public function complete(Request $request, ReceptionVisit $visit, ReceptionVisitService $service): RedirectResponse
    {
        $this->authorize('complete', $visit);

        $service->complete($visit, $request);

        return back()->with('success', 'Visit completed.');
    }

    /**
     * Cancel a visit.
     */
    public function cancel(CancelVisitRequest $request, ReceptionVisit $visit, ReceptionVisitService $service): RedirectResponse
    {
        $this->authorize('cancel', $visit);

        $service->cancel($visit, $request->validated()['cancellation_reason'], $request);

        return back()->with('success', 'Visit cancelled.');
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
