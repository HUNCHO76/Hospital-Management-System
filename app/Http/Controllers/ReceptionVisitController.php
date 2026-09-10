<?php

namespace App\Http\Controllers;

use App\Http\Requests\Reception\CancelVisitRequest;
use App\Http\Requests\Reception\CheckInAppointmentRequest;
use App\Http\Requests\Reception\StoreWalkInRequest;
use App\Models\Appointment;
use App\Models\Department;
use App\Models\ReceptionVisit;
use App\Services\ReceptionVisitService;
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
    public function index(Request $request): Response
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
                'department:id,name,code',
                'appointment:id,appointment_number',
            ])
            ->latest('created_at')
            ->paginate(15)
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
    public function show(ReceptionVisit $visit): Response
    {
        $this->authorize('view', $visit);

        $visit->load([
            'patient:id,patient_number,first_name,middle_name,last_name,phone,date_of_birth,gender,status',
            'patient.insurances' => fn ($query) => $query->orderByDesc('is_primary'),
            'department:id,name,code',
            'appointment:id,appointment_number,appointment_type,scheduled_start,status,practitioner_id',
            'appointment.practitioner:id,practitioner_number,staff_id',
            'appointment.practitioner.staff:id,first_name,last_name',
        ]);

        return Inertia::render('reception/visits/show', [
            'visit' => $visit,
        ]);
    }

    /**
     * Show the guided walk-in registration form.
     */
    public function createWalkIn(): Response
    {
        $this->authorize('createWalkIn', ReceptionVisit::class);

        return Inertia::render('reception/walk-in/create', [
            'departments' => Department::active()->orderBy('name')->get(['id', 'name', 'code']),
            'visitTypes' => ReceptionVisit::TYPES,
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
     */
    public function sendToService(Request $request, ReceptionVisit $visit, ReceptionVisitService $service): RedirectResponse
    {
        $this->authorize('update', $visit);

        $service->sendToService($visit, $request);

        return back()->with('success', 'Visit sent to service.');
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
