<?php

namespace App\Http\Controllers;

use App\Exceptions\AppointmentConflictException;
use App\Http\Requests\Appointment\CancelAppointmentRequest;
use App\Http\Requests\Appointment\RescheduleAppointmentRequest;
use App\Http\Requests\Appointment\StoreAppointmentRequest;
use App\Http\Requests\Appointment\UpdateAppointmentRequest;
use App\Models\Appointment;
use App\Models\Department;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Services\AppointmentSearchService;
use App\Services\AppointmentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Appointment::class, 'appointment');
    }

    /**
     * Display a paginated, searchable, filterable list of appointments.
     */
    public function index(Request $request, AppointmentSearchService $search): Response
    {
        $filters = $request->only(['search', 'patient_id', 'department_id', 'practitioner_id', 'status', 'date_from', 'date_to', 'sort']);

        $query = $search->query($filters);
        $search->scopeToHospital($query, $this->scopedHospitalId($request));

        $query->with([
            'patient:id,patient_number,first_name,middle_name,last_name',
            'department:id,name,code',
            'practitioner:id,practitioner_number,staff_id',
            'practitioner.staff:id,first_name,last_name',
        ]);

        $search->applySort($query, $filters['sort'] ?? null);

        $appointments = $query->paginate(15)->withQueryString();

        return Inertia::render('appointments/index', [
            'appointments' => $appointments,
            'filters' => $filters,
            'departments' => Department::active()->orderBy('name')->get(['id', 'name', 'code']),
            'practitioners' => $this->activePractitioners(),
        ]);
    }

    /**
     * Display a day/week calendar view of appointments within a date range.
     * Never loads the whole table — always scoped to the requested range.
     */
    public function calendar(Request $request, AppointmentSearchService $search): Response
    {
        $start = $request->date('start')?->startOfDay() ?? now()->startOfWeek();
        $end = $request->date('end')?->endOfDay() ?? (clone $start)->addWeek();

        $query = Appointment::query()
            ->whereBetween('scheduled_start', [$start, $end])
            ->with([
                'patient:id,patient_number,first_name,middle_name,last_name',
                'department:id,name,code',
                'practitioner:id,practitioner_number,staff_id',
                'practitioner.staff:id,first_name,last_name',
            ]);

        $search->scopeToHospital($query, $this->scopedHospitalId($request));

        $appointments = $query->orderBy('scheduled_start')->get();

        return Inertia::render('appointments/calendar', [
            'appointments' => $appointments,
            'range' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'departments' => Department::active()->orderBy('name')->get(['id', 'name', 'code']),
            'practitioners' => $this->activePractitioners(),
        ]);
    }

    /**
     * Show the form for booking a new appointment.
     */
    public function create(Request $request): Response
    {
        $patientId = $request->integer('patient_id') ?: null;

        return Inertia::render('appointments/create', [
            'departments' => Department::active()->orderBy('name')->get(['id', 'name', 'code']),
            'practitioners' => $this->activePractitioners(),
            'appointmentTypes' => Appointment::TYPES,
            // Optional pre-fill — used by the clinical encounter disposition
            // dialog's "Schedule Follow-up Appointment" link (Step 27) to
            // hand off into this existing form rather than a second
            // scheduling flow.
            'preselectedPatient' => $patientId
                ? Patient::query()->find($patientId, ['id', 'patient_number', 'first_name', 'middle_name', 'last_name', 'phone'])
                : null,
            'preselectedDepartmentId' => $request->integer('department_id') ?: null,
        ]);
    }

    /**
     * Book a new appointment. A scheduling conflict surfaces as a normal
     * inline field error rather than a raw exception page.
     */
    public function store(StoreAppointmentRequest $request, AppointmentService $service): RedirectResponse
    {
        try {
            $appointment = $service->create($request->validated(), $request);
        } catch (AppointmentConflictException) {
            throw ValidationException::withMessages([
                'scheduled_start' => 'This practitioner is already booked during the selected time. Please choose another time.',
            ]);
        }

        return to_route('appointments.show', $appointment)->with('success', 'Appointment booked successfully.');
    }

    /**
     * Display the specified appointment.
     */
    public function show(Appointment $appointment): Response
    {
        $appointment->load([
            'patient:id,patient_number,first_name,middle_name,last_name,phone',
            'department:id,name,code',
            'practitioner:id,practitioner_number,practitioner_type,staff_id',
            'practitioner.staff:id,first_name,last_name',
        ]);

        return Inertia::render('appointments/show', [
            'appointment' => $appointment,
        ]);
    }

    /**
     * Show the form for editing the specified appointment's non-scheduling
     * fields. Use the reschedule action to change its time.
     */
    public function edit(Appointment $appointment): Response
    {
        return Inertia::render('appointments/edit', [
            'appointment' => $appointment,
            'appointmentTypes' => Appointment::TYPES,
        ]);
    }

    /**
     * Update the specified appointment's non-scheduling fields.
     */
    public function update(UpdateAppointmentRequest $request, Appointment $appointment, AppointmentService $service): RedirectResponse
    {
        $service->update($appointment, $request->validated(), $request);

        return to_route('appointments.show', $appointment)->with('success', 'Appointment updated successfully.');
    }

    /**
     * Reschedule the specified appointment to a new time.
     */
    public function reschedule(RescheduleAppointmentRequest $request, Appointment $appointment, AppointmentService $service): RedirectResponse
    {
        $this->authorize('reschedule', $appointment);

        $validated = $request->validated();

        try {
            $service->reschedule(
                $appointment,
                Carbon::parse($validated['scheduled_start']),
                Carbon::parse($validated['scheduled_end']),
                $request,
            );
        } catch (AppointmentConflictException) {
            throw ValidationException::withMessages([
                'scheduled_start' => 'This practitioner is already booked during the selected time. Please choose another time.',
            ]);
        }

        return to_route('appointments.show', $appointment)->with('success', 'Appointment rescheduled successfully.');
    }

    /**
     * Confirm the specified appointment.
     */
    public function confirm(Request $request, Appointment $appointment, AppointmentService $service): RedirectResponse
    {
        $this->authorize('confirm', $appointment);

        $service->confirm($appointment, $request);

        return back()->with('success', 'Appointment confirmed.');
    }

    /**
     * Check in the patient for the specified appointment.
     */
    public function checkIn(Request $request, Appointment $appointment, AppointmentService $service): RedirectResponse
    {
        $this->authorize('checkIn', $appointment);

        $service->checkIn($appointment, $request);

        return back()->with('success', 'Patient checked in.');
    }

    /**
     * Mark the specified appointment as in progress.
     */
    public function start(Request $request, Appointment $appointment, AppointmentService $service): RedirectResponse
    {
        $this->authorize('start', $appointment);

        $service->start($appointment, $request);

        return back()->with('success', 'Appointment marked in progress.');
    }

    /**
     * Mark the specified appointment as completed.
     */
    public function complete(Request $request, Appointment $appointment, AppointmentService $service): RedirectResponse
    {
        $this->authorize('complete', $appointment);

        $service->complete($appointment, $request);

        return back()->with('success', 'Appointment marked complete.');
    }

    /**
     * Cancel the specified appointment.
     */
    public function cancel(CancelAppointmentRequest $request, Appointment $appointment, AppointmentService $service): RedirectResponse
    {
        $this->authorize('cancel', $appointment);

        $service->cancel($appointment, $request->validated()['cancellation_reason'], $request);

        return back()->with('success', 'Appointment cancelled.');
    }

    /**
     * Mark the specified appointment as a no-show.
     */
    public function noShow(Request $request, Appointment $appointment, AppointmentService $service): RedirectResponse
    {
        $this->authorize('noShow', $appointment);

        $service->markNoShow($appointment, $request);

        return back()->with('success', 'Appointment marked as no-show.');
    }

    /**
     * The hospital a hospital-admin is restricted to, or null when the user
     * is unrestricted (super-admin and any other unscoped role).
     */
    protected function scopedHospitalId(Request $request): ?int
    {
        if (! $request->user()->hasAnyRole(['hospital-admin', 'Manager', 'manager'])) {
            return null;
        }

        return $request->user()->staff?->hospital_id;
    }

    /**
     * Active practitioners selectable in the appointment form, with just
     * enough staff data to display a name and filter by department.
     *
     * @return Collection<int, Practitioner>
     */
    protected function activePractitioners(): Collection
    {
        return Practitioner::query()
            ->where('status', Practitioner::STATUS_ACTIVE)
            ->with('staff:id,first_name,last_name,department_id,hospital_id')
            ->get(['id', 'staff_id', 'practitioner_number', 'practitioner_type']);
    }
}
