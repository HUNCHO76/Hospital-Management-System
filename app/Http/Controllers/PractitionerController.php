<?php

namespace App\Http\Controllers;

use App\Http\Requests\Practitioner\StorePractitionerRequest;
use App\Http\Requests\Practitioner\UpdatePractitionerRequest;
use App\Models\Hospital;
use App\Models\Practitioner;
use App\Models\Staff;
use App\Services\PractitionerService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PractitionerController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Practitioner::class, 'practitioner');
    }

    /**
     * Display a paginated, searchable, filterable list of practitioners.
     */
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();
        $hospitalId = $this->scopedHospitalId($request) ?? $request->integer('hospital_id') ?: null;

        $practitioners = Practitioner::query()
            ->with(['staff:id,hospital_id,department_id,first_name,middle_name,last_name', 'staff.hospital:id,name', 'staff.department:id,name'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('practitioner_number', 'like', "%{$search}%")
                        ->orWhere('professional_registration_number', 'like', "%{$search}%")
                        ->orWhere('license_number', 'like', "%{$search}%")
                        ->orWhere('specialization', 'like', "%{$search}%")
                        ->orWhereHas('staff', function ($query) use ($search) {
                            $query->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        });
                });
            })
            ->when($hospitalId, fn ($query, $id) => $query->whereHas('staff', fn ($query) => $query->where('hospital_id', $id)))
            ->when($request->integer('department_id'), fn ($query, $departmentId) => $query->whereHas('staff', fn ($query) => $query->where('department_id', $departmentId)))
            ->when($request->string('practitioner_type')->toString(), fn ($query, $type) => $query->where('practitioner_type', $type))
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->when($request->string('specialization')->toString(), fn ($query, $specialization) => $query->where('specialization', 'like', "%{$specialization}%"))
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('practitioners/index', [
            'practitioners' => $practitioners,
            'filters' => $request->only(['search', 'hospital_id', 'department_id', 'practitioner_type', 'status', 'specialization']),
            'hospitals' => Hospital::query()->orderBy('name')->get(['id', 'name', 'code']),
            'practitionerTypes' => Practitioner::TYPES,
        ]);
    }

    /**
     * Show the form for creating a new practitioner profile.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('practitioners/create', [
            'eligibleStaff' => $this->eligibleStaff($request),
            'practitionerTypes' => Practitioner::TYPES,
        ]);
    }

    /**
     * Store a newly created practitioner profile.
     */
    public function store(StorePractitionerRequest $request, PractitionerService $practitioners): RedirectResponse
    {
        $practitioners->create($request->validated(), $request);

        return to_route('practitioners.index')->with('success', 'Practitioner profile created successfully.');
    }

    /**
     * Display the specified practitioner.
     */
    public function show(Practitioner $practitioner): Response
    {
        $practitioner->load([
            'staff:id,hospital_id,department_id,staff_number,first_name,middle_name,last_name',
            'staff.hospital:id,name,code',
            'staff.department:id,name',
            'latestNhifAttendance',
        ]);

        return Inertia::render('practitioners/show', [
            'practitioner' => $practitioner,
        ]);
    }

    /**
     * Show the form for editing the specified practitioner.
     */
    public function edit(Practitioner $practitioner): Response
    {
        $practitioner->load(['staff:id,hospital_id,department_id,staff_number,first_name,middle_name,last_name']);

        return Inertia::render('practitioners/edit', [
            'practitioner' => $practitioner,
            'practitionerTypes' => Practitioner::TYPES,
        ]);
    }

    /**
     * Update the specified practitioner.
     */
    public function update(UpdatePractitionerRequest $request, Practitioner $practitioner, PractitionerService $practitioners): RedirectResponse
    {
        $practitioners->update($practitioner, $request->validated(), $request);

        return to_route('practitioners.index')->with('success', 'Practitioner profile updated successfully.');
    }

    /**
     * Delete the specified practitioner profile. The underlying staff
     * record is not affected.
     */
    public function destroy(Request $request, Practitioner $practitioner, PractitionerService $practitioners): RedirectResponse
    {
        $practitioners->delete($practitioner, $request);

        return to_route('practitioners.index')->with('success', 'Practitioner profile deleted successfully.');
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

    /**
     * Staff eligible to receive a practitioner profile: no existing
     * profile, and (for a hospital-admin) within their own hospital.
     *
     * @return Collection<int, Staff>
     */
    protected function eligibleStaff(Request $request): Collection
    {
        $hospitalId = $this->scopedHospitalId($request);

        return Staff::query()
            ->select(['id', 'hospital_id', 'department_id', 'staff_number', 'first_name', 'middle_name', 'last_name', 'job_title'])
            ->doesntHave('practitioner')
            ->when($hospitalId, fn ($query, $id) => $query->where('hospital_id', $id))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }
}
