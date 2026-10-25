<?php

namespace App\Http\Controllers;

use App\Http\Requests\Reception\CancelVisitRequest;
use App\Http\Requests\Reception\CheckInAppointmentRequest;
use App\Http\Requests\Reception\StoreWalkInRequest;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Department;
use App\Models\NhifPreApprovalSessionItem;
use App\Models\NhifSchemeService;
use App\Models\NhifServiceMapping;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\PatientInsurance;
use App\Models\Practitioner;
use App\Models\ReceptionVisit;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Services\FinancialAuthorizationService;
use App\Services\NHIF\NhifHttpClient;
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
            ->withQueryString();

        $visitIds = $visits->getCollection()->pluck('id')->all();
        $accessStatuses = app(ReceptionVisitService::class)->consultationAccessStatuses($visitIds);

        $visits->through(function (ReceptionVisit $visit) use ($accessStatuses) {
            $patientAccountId = null;
            if ($visit->department?->hospital_id && $visit->patient_id) {
                $patientAccountId = PatientAccount::query()
                    ->where('patient_id', $visit->patient_id)
                    ->where('hospital_id', $visit->department->hospital_id)
                    ->value('id');
            }

            $visit->financial_status = $accessStatuses[$visit->id] ?? FinancialAuthorizationService::STATUS_AUTHORIZED;
            $visit->patient_account_id = $patientAccountId;

            return $visit;
        });

        return Inertia::render('reception/visits/index', [
            'visits' => $visits,
            'filters' => $filters,
            'departments' => Department::active()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    /**
     * Display the specified reception visit.
     */
    public function show(ReceptionVisit $visit, ReceptionVisitService $visitService): Response
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

        $consultationFinancialStatus = $visitService->consultationAccessStatus($visit->id);

        $consultationCharge = Charge::query()
            ->where('source_type', ReceptionVisit::class)
            ->where('source_id', $visit->id)
            ->whereNotIn('status', [Charge::STATUS_VOIDED, Charge::STATUS_CANCELLED])
            ->first(['id', 'charge_number', 'status', 'unit_price', 'quantity', 'net_amount', 'currency']);

        $nhifSessionItem = NhifPreApprovalSessionItem::query()
            ->where('source_type', ReceptionVisit::class)
            ->where('source_id', $visit->id)
            ->with('session:id,nhif_request_no,nhif_request_id,status')
            ->latest()
            ->first();

        $nhifPreApprovalItem = $nhifSessionItem ? [
            'approval_status' => $nhifSessionItem->approval_status,
            'nhif_request_no' => $nhifSessionItem->session?->nhif_request_no,
            'rejection_reason' => $nhifSessionItem->rejection_reason,
            'nhif_percent_covered' => $nhifSessionItem->nhif_percent_covered !== null
                ? (float) $nhifSessionItem->nhif_percent_covered
                : null,
        ] : null;

        return Inertia::render('reception/visits/show', [
            'visit' => $visit,
            'consultationFinancialStatus' => $consultationFinancialStatus,
            'consultationCharge' => $consultationCharge,
            'nhifPreApprovalItem' => $nhifPreApprovalItem,
        ]);
    }

    /**
     * Show the guided walk-in registration form.
     */
    public function createWalkIn(): Response
    {
        $this->authorize('createWalkIn', ReceptionVisit::class);

        $services = Service::query()
            ->where('status', Service::STATUS_ACTIVE)
            ->where('is_billable', true)
            ->whereIn('category', ['consultation', 'dental', 'maternity', 'emergency', 'inpatient', 'procedure', 'other'])
            ->with(['prices' => fn ($q) => $q->where('status', ServicePrice::STATUS_ACTIVE)->whereNull('practitioner_id')])
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'category', 'description']);

        $departmentServices = $services->map(function ($s) {
            $price = $s->prices->first();

            return [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'category' => $s->category,
                'price' => $price ? (float) $price->amount : null,
                'currency' => $price?->currency ?? 'TZS',
            ];
        });

        return Inertia::render('reception/walk-in/create', [
            'departments' => Department::active()->orderBy('name')->get(['id', 'name', 'code', 'hospital_id']),
            'visitTypes' => ReceptionVisit::TYPES,
            'departmentServices' => $departmentServices,
            'practitioners' => Practitioner::query()
                ->where('status', Practitioner::STATUS_ACTIVE)
                ->with(['staff:id,first_name,last_name,department_id,job_title', 'consultationService:id,code,name'])
                ->get(['id', 'practitioner_number', 'staff_id', 'consultation_service_id', 'practitioner_type', 'nhif_practitioner_no']),
        ]);
    }

    /**
     * Register a walk-in patient's visit.
     */
    public function storeWalkIn(StoreWalkInRequest $request, ReceptionVisitService $service): RedirectResponse
    {
        $this->authorize('createWalkIn', ReceptionVisit::class);

        $visit = $service->createWalkIn($request->validated(), $request);

        $redirect = to_route('reception.visits.show', $visit)->with('success', "Walk-in visit {$visit->visit_number} created.");

        if ($visit->billing_warning ?? null) {
            $redirect->with('warning', $visit->billing_warning);
        }

        return $redirect;
    }

    /**
     * Return NHIF scheme consultation services for a given patient.
     * Called from the walk-in form when a patient is selected.
     * Returns an empty array for cash/non-NHIF patients.
     */
    public function nhifConsultationServices(Request $request): JsonResponse
    {
        $this->authorize('createWalkIn', ReceptionVisit::class);

        $emptyResponse = ['services' => [], 'insurance' => null];

        $patientId = (int) $request->query('patient_id');
        if (! $patientId) {
            return response()->json($emptyResponse);
        }

        $patient = Patient::find($patientId);
        $insurance = $patient?->activeNhifInsurance();

        if (! $insurance) {
            $latestIns = $patient?->latestNhifInsurance();
            if ($latestIns && $latestIns->isAuthorizationExpired()) {
                return response()->json([
                    'services' => [],
                    'insurance' => null,
                    'auth_expired' => true,
                    'previous_auth_no' => $latestIns->nhif_authorization_no,
                    'verified_at' => $latestIns->verified_at?->toIso8601String(),
                    'expires_at' => $latestIns->authorizationExpiresAt()?->toIso8601String(),
                    'patient_insurance_id' => $latestIns->id,
                ]);
            }

            return response()->json($emptyResponse);
        }

        $insuranceData = [
            'id' => $insurance->id,
            'scheme_id' => $insurance->nhif_scheme_id,
            'scheme_name' => $insurance->insuranceScheme?->name,
            'nhif_authorization_no' => $insurance->nhif_authorization_no,
            'card_number' => $insurance->card_number,
            'verification_status' => $insurance->verification_status,
            'verified_at' => $insurance->verified_at?->toIso8601String(),
            'expires_at' => $insurance->authorizationExpiresAt()?->toIso8601String(),
            'hours_remaining' => $insurance->authorizationHoursRemaining(),
            'nhif_pf_number' => $insurance->nhif_pf_number,
        ];

        if (! $insurance->nhif_scheme_id) {
            return response()->json(['services' => [], 'insurance' => $insuranceData]);
        }

        $typeKeywords = config('nhif.consultation_type_name_keywords', []);
        $typeIds = config('nhif.consultation_item_type_ids', []);

        $applyTypeFilter = function ($query) use ($typeKeywords, $typeIds) {
            $query->when(! empty($typeKeywords), function ($q) use ($typeKeywords) {
                $q->where(function ($inner) use ($typeKeywords) {
                    foreach ($typeKeywords as $keyword) {
                        $inner->orWhere('type_name', 'like', '%'.trim($keyword).'%');
                    }
                });
            })->when(empty($typeKeywords) && ! empty($typeIds), fn ($q) => $q->whereIn('item_type_id', $typeIds));
        };

        $columns = ['id', 'item_code', 'item_name', 'item_type_id', 'type_name'];

        $castItemCode = fn ($svc) => $svc->setAttribute('item_code', (string) $svc->item_code);

        $services = null;
        try {
            $services = NhifSchemeService::active()
                ->where('scheme_id', $insurance->nhif_scheme_id)
                ->tap($applyTypeFilter)
                ->orderBy('item_name')
                ->get([...$columns, 'unit_price'])
                ->map($castItemCode);

            if ($services->isEmpty()) {
                $services = NhifSchemeService::catalog()
                    ->where('scheme_id', $insurance->nhif_scheme_id)
                    ->tap($applyTypeFilter)
                    ->orderBy('item_name')
                    ->get($columns)
                    ->unique('item_code')
                    ->values()
                    ->map($castItemCode);
            }
        } catch (\Exception $e) {
            // Log the exception if needed
            // logger()->error('Failed to fetch NHIF services', ['exception' => $e]);
            $services = collect();
        }

        if ($services && $services->isNotEmpty()) {
            $itemCodes = $services->pluck('item_code')->all();
            $mappings = NhifServiceMapping::query()
                ->whereIn('nhif_item_code', $itemCodes)
                ->where('coverage_status', NhifServiceMapping::COVERAGE_COVERED)
                ->pluck('service_id', 'nhif_item_code');
            $servicesByCode = Service::query()
                ->whereIn('code', $itemCodes)
                ->pluck('id', 'code');

            $services->transform(function ($svc) use ($mappings, $servicesByCode) {
                $svc->setAttribute('service_id', $mappings[$svc->item_code] ?? $servicesByCode[$svc->item_code] ?? null);

                return $svc;
            });
        }

        return response()->json(['services' => $services, 'insurance' => $insuranceData]);
    }

    /**
     * Check in a scheduled appointment at the front desk.
     */
    public function checkInAppointment(CheckInAppointmentRequest $request, Appointment $appointment, ReceptionVisitService $service): RedirectResponse
    {
        $this->authorize('checkIn', ReceptionVisit::class);

        $departmentId = $request->validated('department_id') ?? $appointment->department_id;

        $visit = $service->checkInAppointment($appointment, $departmentId, $request);

        $redirect = to_route('reception.visits.show', $visit)->with('success', "Patient checked in — visit {$visit->visit_number}.");

        if ($visit->billing_warning ?? null) {
            $redirect->with('warning', $visit->billing_warning);
        }

        return $redirect;
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
    public function financialStatus(ReceptionVisit $visit, ReceptionVisitService $visitService): JsonResponse
    {
        $this->authorize('view', $visit);

        return response()->json([
            'status' => $visitService->consultationAccessStatus($visit->id),
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
            return response()->json(['price' => null, 'nhif_unit_price' => null]);
        }

        $serviceId = $practitioner?->consultation_service_id;

        // NHIF scheme path: resolve HMS service via NhifServiceMapping
        $nhifItemCode = $request->query('nhif_item_code');
        if ($nhifItemCode && $department) {
            $mapping = NhifServiceMapping::query()
                ->where('hospital_id', $department->hospital_id)
                ->where('nhif_item_code', $nhifItemCode)
                ->where('coverage_status', NhifServiceMapping::COVERAGE_COVERED)
                ->first();
            if ($mapping?->service_id) {
                $serviceId = $mapping->service_id;
            }
        }

        if (! $serviceId) {
            $practitionerType = $practitioner?->practitioner_type;
            $specialtyType = $request->query('specialty_type');

            $code = match (true) {
                $practitionerType === 'dentist' => 'CONSULT-DENTAL',
                $practitionerType === 'ophthalmologist' => 'CONSULT-EYE',
                in_array($practitionerType, ['specialist', 'consultant', 'surgeon'], true) => 'CONSULT-SPECIALIST',
                $specialtyType === 'dental' => 'CONSULT-DENTAL',
                $specialtyType === 'eye' => 'CONSULT-EYE',
                default => 'CONSULT-GP',
            };

            $serviceId = Service::query()->where('code', $code)->value('id')
                ?? Service::query()->where('category', 'consultation')->value('id');
        }

        if (! $serviceId) {
            return response()->json(['price' => null, 'nhif_unit_price' => null]);
        }

        $service = Service::find($serviceId);

        if (! $service) {
            return response()->json(['price' => null, 'nhif_unit_price' => null]);
        }

        try {
            $servicePrice = $resolver->resolvePrice($service, $department->hospital_id);

            $nhifUnitPrice = null;
            if ($nhifItemCode) {
                $patientId = (int) $request->query('patient_id');
                $insurance = $patientId
                    ? PatientInsurance::where('patient_id', $patientId)
                        ->where('status', PatientInsurance::STATUS_ACTIVE)
                        ->where('verification_status', PatientInsurance::VERIFICATION_VERIFIED)
                        ->whereNotNull('nhif_facility_code')
                        ->first(['nhif_facility_code'])
                    : null;

                $facilityCode = $insurance?->nhif_facility_code;
                if ($facilityCode) {
                    $nhifResult = app(NhifHttpClient::class)
                        ->getPricePackageByItemCode($facilityCode, $nhifItemCode);
                    if ($nhifResult['success']) {
                        $apiData = $nhifResult['data'];
                        if (isset($apiData[0]) && is_array($apiData[0])) {
                            $apiData = $apiData[0];
                        }
                        $nhifUnitPrice = $apiData['UnitPrice'] ?? null;
                    }
                }
            }

            return response()->json([
                'price' => (float) $servicePrice->amount,
                'currency' => $servicePrice->currency ?? config('billing.currency', 'TZS'),
                'nhif_unit_price' => $nhifUnitPrice !== null ? (float) $nhifUnitPrice : null,
            ]);
        } catch (\Throwable) {
            return response()->json(['price' => null, 'nhif_unit_price' => null]);
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
        if (! $request->user()->hasAnyRole(['hospital-admin', 'Manager', 'manager'])) {
            return null;
        }

        return $request->user()->staff?->hospital_id;
    }
}
