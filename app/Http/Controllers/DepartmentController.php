<?php

namespace App\Http\Controllers;

use App\Http\Requests\Department\StoreDepartmentRequest;
use App\Http\Requests\Department\UpdateDepartmentRequest;
use App\Models\Department;
use App\Models\Hospital;
use App\Services\DepartmentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Department::class, 'department');
    }

    /**
     * Display a paginated, searchable, filterable list of departments.
     */
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();

        $departments = Department::query()
            ->with('hospital:id,name,code')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhereHas('hospital', fn ($query) => $query->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->integer('hospital_id'), fn ($query, $hospitalId) => $query->where('hospital_id', $hospitalId))
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('departments/index', [
            'departments' => $departments,
            'filters' => $request->only(['search', 'hospital_id', 'status']),
            'hospitals' => Hospital::query()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    /**
     * Show the form for creating a new department.
     */
    public function create(): Response
    {
        return Inertia::render('departments/create', [
            'hospitals' => $this->selectableHospitals(),
        ]);
    }

    /**
     * Store a newly created department.
     */
    public function store(StoreDepartmentRequest $request, DepartmentService $departments): RedirectResponse
    {
        $departments->create($request->validated(), $request);

        return to_route('departments.index')->with('success', 'Department created successfully.');
    }

    /**
     * Display the specified department.
     */
    public function show(Department $department): Response
    {
        $department->load('hospital:id,name,code');

        return Inertia::render('departments/show', [
            'department' => $department,
        ]);
    }

    /**
     * Show the form for editing the specified department.
     */
    public function edit(Department $department): Response
    {
        return Inertia::render('departments/edit', [
            'department' => $department,
            'hospitals' => $this->selectableHospitals($department->hospital_id),
        ]);
    }

    /**
     * Update the specified department.
     */
    public function update(UpdateDepartmentRequest $request, Department $department, DepartmentService $departments): RedirectResponse
    {
        $departments->update($department, $request->validated(), $request);

        return to_route('departments.index')->with('success', 'Department updated successfully.');
    }

    /**
     * Soft-delete the specified department.
     */
    public function destroy(Request $request, Department $department, DepartmentService $departments): RedirectResponse
    {
        try {
            $departments->delete($department, $request);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return to_route('departments.index')->with('success', 'Department deleted successfully.');
    }

    /**
     * Hospitals selectable in the department form: active hospitals, plus the
     * department's current hospital even if it has since become inactive.
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
}
