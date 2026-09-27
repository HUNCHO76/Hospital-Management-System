<?php

namespace App\Http\Controllers;

use App\Http\Requests\Hospital\StoreHospitalRequest;
use App\Http\Requests\Hospital\UpdateHospitalRequest;
use App\Models\Hospital;
use App\Models\Service;
use App\Services\HospitalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class HospitalController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Hospital::class, 'hospital');
    }

    /**
     * Display a paginated, searchable, filterable list of hospitals.
     */
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();

        $hospitals = Hospital::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('registration_number', 'like', "%{$search}%")
                        ->orWhere('region', 'like', "%{$search}%")
                        ->orWhere('district', 'like', "%{$search}%");
                });
            })
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->when($request->string('region')->toString(), fn ($query, $region) => $query->where('region', $region))
            ->when($request->string('district')->toString(), fn ($query, $district) => $query->where('district', $district))
            ->withCount('departments')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('hospitals/index', [
            'hospitals' => $hospitals,
            'filters' => $request->only(['search', 'status', 'region', 'district']),
        ]);
    }

    /**
     * Show the form for creating a new hospital.
     */
    public function create(): Response
    {
        return Inertia::render('hospitals/create', [
            'consultationServices' => Service::consultationOptions(),
        ]);
    }

    /**
     * Store a newly created hospital.
     */
    public function store(StoreHospitalRequest $request, HospitalService $hospitals): RedirectResponse
    {
        $hospitals->create($request->validated(), $request);

        return to_route('hospitals.index')->with('success', 'Hospital created successfully.');
    }

    /**
     * Display the specified hospital, including its departments.
     */
    public function show(Hospital $hospital): Response
    {
        $hospital->load([
            'departments' => fn ($query) => $query->orderBy('name'),
            'defaultConsultationService:id,code,name',
        ]);

        return Inertia::render('hospitals/show', [
            'hospital' => $hospital,
        ]);
    }

    /**
     * Show the form for editing the specified hospital.
     */
    public function edit(Hospital $hospital): Response
    {
        return Inertia::render('hospitals/edit', [
            'hospital' => $hospital,
            'consultationServices' => Service::consultationOptions(),
        ]);
    }

    /**
     * Update the specified hospital.
     */
    public function update(UpdateHospitalRequest $request, Hospital $hospital, HospitalService $hospitals): RedirectResponse
    {
        $hospitals->update($hospital, $request->validated(), $request);

        return to_route('hospitals.index')->with('success', 'Hospital updated successfully.');
    }

    /**
     * Soft-delete the specified hospital.
     */
    public function destroy(Request $request, Hospital $hospital, HospitalService $hospitals): RedirectResponse
    {
        try {
            $hospitals->delete($hospital, $request);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return to_route('hospitals.index')->with('success', 'Hospital deleted successfully.');
    }
}
