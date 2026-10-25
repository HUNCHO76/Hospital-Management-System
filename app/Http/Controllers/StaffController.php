<?php

namespace App\Http\Controllers;

use App\Http\Requests\Staff\StoreStaffRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\Staff;
use App\Services\StaffService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class StaffController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Staff::class, 'staff');
    }

    /**
     * Display a paginated, searchable, filterable list of staff.
     */
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();

        // A hospital-admin's own hospital always wins over any submitted
        // hospital_id filter — they cannot browse another hospital's staff.
        $hospitalId = $this->scopedHospitalId($request) ?? $request->integer('hospital_id') ?: null;

        $staff = Staff::query()
            ->with([
                'hospital:id,name,code',
                'department:id,name,code',
                'practitioner:id,staff_id,practitioner_number,practitioner_type',
                'user:id,UserName,email',
            ])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('staff_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('middle_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('job_title', 'like', "%{$search}%")
                        ->orWhere('professional_registration_no', 'like', "%{$search}%");
                });
            })
            ->when($hospitalId, fn ($query, $id) => $query->where('hospital_id', $id))
            ->when($request->integer('department_id'), fn ($query, $departmentId) => $query->where('department_id', $departmentId))
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->when($request->string('employment_type')->toString(), fn ($query, $type) => $query->where('employment_type', $type))
            ->when($request->string('job_title')->toString(), fn ($query, $jobTitle) => $query->where('job_title', $jobTitle))
            ->when($request->has('has_practitioner'), function ($query) use ($request) {
                $request->boolean('has_practitioner') ? $query->has('practitioner') : $query->doesntHave('practitioner');
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('staff/index', [
            'staff' => $staff,
            'filters' => $request->only(['search', 'hospital_id', 'department_id', 'status', 'employment_type', 'job_title', 'has_practitioner']),
            'hospitals' => Hospital::query()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    /**
     * Show the form for creating a new staff member.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('staff/create', [
            'hospitals' => $this->selectableHospitals(),
            'departments' => Department::query()->orderBy('name')->get(['id', 'hospital_id', 'name', 'code']),
            'roles' => $this->assignableRoles($request->user()),
            'employmentTypes' => Staff::EMPLOYMENT_TYPES,
        ]);
    }

    /**
     * Store a newly created staff member, optionally with a login account.
     */
    public function store(StoreStaffRequest $request, StaffService $staffService): RedirectResponse
    {
        $validated = $request->validated();

        $accountData = null;

        if ($validated['create_account'] ?? false) {
            $accountData = [
                'email' => $validated['email'],
                'password' => $validated['password'],
                'role' => $validated['role'],
            ];
        }

        unset($validated['create_account'], $validated['password'], $validated['role']);

        $staffService->create($validated, $accountData, $request);

        return to_route('staff.index')->with('success', 'Staff member created successfully.');
    }

    /**
     * Display the specified staff member.
     */
    public function show(Staff $staff): Response
    {
        $staff->load([
            'hospital:id,name,code',
            'department:id,name,code',
            'practitioner',
            'user:id,UserName,email,status',
        ]);

        return Inertia::render('staff/show', [
            'staff' => $staff,
            'roles' => $staff->user ? $staff->user->getRoleNames() : [],
        ]);
    }

    /**
     * Show the form for editing the specified staff member.
     */
    public function edit(Request $request, Staff $staff): Response
    {
        $staff->load('user');

        return Inertia::render('staff/edit', [
            'staff' => $staff,
            'hospitals' => $this->selectableHospitals($staff->hospital_id),
            'departments' => Department::query()->orderBy('name')->get(['id', 'hospital_id', 'name', 'code']),
            'employmentTypes' => Staff::EMPLOYMENT_TYPES,
            'roles' => $this->assignableRoles($request->user()),
            'account' => $staff->user ? [
                'id' => $staff->user->id,
                'username' => $staff->user->UserName,
                'email' => $staff->user->email,
                'phone' => $staff->user->phone,
                'status' => $staff->user->status,
                'role' => $staff->user->roles->first()?->name,
            ] : null,
        ]);
    }

    /**
     * Update the specified staff member.
     */
    public function update(UpdateStaffRequest $request, Staff $staff, StaffService $staffService): RedirectResponse
    {
        $staffService->update($staff, $request->validated(), $request);

        return to_route('staff.index')->with('success', 'Staff member updated successfully.');
    }

    /**
     * Soft-delete the specified staff member.
     */
    public function destroy(Request $request, Staff $staff, StaffService $staffService): RedirectResponse
    {
        try {
            $staffService->delete($staff, $request);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return to_route('staff.index')->with('success', 'Staff member deleted successfully.');
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
     * Hospitals selectable in the staff form: active hospitals, plus the
     * staff member's current hospital even if it has since become inactive.
     *
     * @return Collection<int, Hospital>
     */
    protected function selectableHospitals(?int $includeHospitalId = null): Collection
    {
        return Hospital::query()
            ->where(function ($query) use ($includeHospitalId) {
                $query->where('status', Hospital::STATUS_ACTIVE);

                if ($includeHospitalId) {
                    $query->orWhere('id', $includeHospitalId);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }

    /**
     * Roles an administrator or manager is allowed to assign.
     * super-admin is excluded unless the current user is a super-admin.
     */
    protected function assignableRoles(?\App\Models\User $currentUser = null): \Illuminate\Support\Collection
    {
        $query = Role::query()->orderBy('name');
        if (! $currentUser?->hasRole('super-admin')) {
            $query->where('name', '!=', 'super-admin');
        }

        return $query->pluck('name');
    }
}
