<?php

namespace App\Http\Controllers;

use App\Http\Requests\RadiologyAppointment\CancelRadiologyAppointmentRequest;
use App\Http\Requests\RadiologyAppointment\RescheduleRadiologyAppointmentRequest;
use App\Http\Requests\RadiologyAppointment\ScheduleRadiologyOrderItemRequest;
use App\Http\Resources\RadiologyAppointmentResource;
use App\Http\Resources\UnscheduledRadiologyOrderItemResource;
use App\Models\PatientAccount;
use App\Models\RadiologyAppointment;
use App\Models\RadiologyOrder;
use App\Models\RadiologyOrderItem;
use App\Models\RadiologyProcedure;
use App\Services\FinancialAuthorizationService;
use App\Services\NHIF\NhifOrderPreApprovalService;
use App\Services\RadiologySchedulingSearchService;
use App\Services\RadiologySchedulingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The radiology scheduling dashboard (Phase 5, Step 39): the "awaiting
 * scheduling" queue plus a chosen day/resource's existing bookings, and the
 * mutation endpoints (schedule/reschedule/confirm/cancel/no-show). Ends at
 * SCHEDULED — patient arrival/preparation/reception is Step 40's job.
 */
class RadiologySchedulingController extends Controller
{
    public function index(Request $request, RadiologySchedulingSearchService $search): Response
    {
        $this->authorize('viewAny', RadiologyAppointment::class);

        $unscheduledFilters = $request->only(['search', 'modality', 'priority']);

        $unscheduledQuery = $search->unscheduledItemsQuery($unscheduledFilters)->with([
            'radiologyOrder:id,order_number,priority,clinical_indication,ordered_at,clinical_encounter_id',
            'radiologyOrder.clinicalEncounter:id,encounter_number,patient_id',
            'radiologyOrder.clinicalEncounter.patient:id,patient_number,first_name,middle_name,last_name,date_of_birth,gender',
        ]);
        $search->scopeUnscheduledToHospital($unscheduledQuery, $this->scopedHospitalId($request));

        $financialAuth = app(FinancialAuthorizationService::class);
        $nhifOrderPreApproval = app(NhifOrderPreApprovalService::class);

        $unscheduledItems = $unscheduledQuery->paginate(15, ['*'], 'unscheduled_page')->withQueryString()
            ->through(function (RadiologyOrderItem $item) use ($request, $financialAuth, $nhifOrderPreApproval) {
                $data = (new UnscheduledRadiologyOrderItemResource($item))->resolve($request);
                $data['financial_status'] = $financialAuth->statusForSource(RadiologyOrderItem::class, $item->id);
                $data['nhif_pre_approval'] = $nhifOrderPreApproval->statusForRadiologyOrderItem($item);

                $patientId = $item->radiologyOrder?->clinicalEncounter?->patient_id;
                $hospitalId = $item->radiologyOrder?->clinicalEncounter?->department?->hospital_id;

                $patientAccountId = null;
                if ($patientId && $hospitalId) {
                    $patientAccountId = PatientAccount::query()
                        ->where('patient_id', $patientId)
                        ->where('hospital_id', $hospitalId)
                        ->value('id');
                }
                $data['patient_account_id'] = $patientAccountId;

                return $data;
            });

        // Existing bookings for the selected date (optionally narrowed to
        // one resource), so staff can see what's already taken before
        // picking a free time. No synthetic slot grid is generated: no
        // working-hours/slot-duration/room-registry configuration exists
        // anywhere in this codebase yet (Part 6's own documented-limitation
        // escape hatch) — availability is instead enforced authoritatively
        // server-side at submit time via RadiologySchedulingService's
        // conflict check.
        $date = $request->date('date') ?? Carbon::now();
        $resourceName = $request->string('resource_name')->toString() ?: null;

        $existingBookingsQuery = RadiologyAppointment::query()
            ->whereIn('status', RadiologyAppointment::ACTIVE_STATUSES)
            ->whereDate('scheduled_start', $date);

        if ($resourceName) {
            $existingBookingsQuery->where('resource_name', $resourceName);
        }

        $search->scopeWorklistToHospital($existingBookingsQuery, $this->scopedHospitalId($request));

        $existingBookings = $existingBookingsQuery
            ->with([
                'radiologyOrderItem:id,radiology_order_id,procedure_code,procedure_name',
                'radiologyOrderItem.radiologyOrder:id,order_number,clinical_encounter_id',
                'radiologyOrderItem.radiologyOrder.clinicalEncounter:id,patient_id',
                'radiologyOrderItem.radiologyOrder.clinicalEncounter.patient:id,patient_number,first_name,middle_name,last_name',
            ])
            ->orderBy('scheduled_start')
            ->get()
            ->map(fn (RadiologyAppointment $appointment) => (new RadiologyAppointmentResource($appointment))->resolve($request));

        $knownResources = RadiologyAppointment::query()
            ->whereIn('status', RadiologyAppointment::ACTIVE_STATUSES)
            ->distinct()
            ->orderBy('resource_name')
            ->pluck('resource_name');

        return Inertia::render('radiology/scheduling/index', [
            'unscheduledItems' => $unscheduledItems,
            'unscheduledFilters' => $unscheduledFilters,
            'existingBookings' => $existingBookings,
            'selectedDate' => $date->toDateString(),
            'selectedResource' => $resourceName,
            'knownResources' => $knownResources,
            'modalities' => RadiologyProcedure::MODALITIES,
            'priorities' => RadiologyOrder::PRIORITIES,
        ]);
    }

    /**
     * Schedule an order item onto a resource/time slot.
     */
    public function store(ScheduleRadiologyOrderItemRequest $request, RadiologySchedulingService $service): RedirectResponse
    {
        $item = RadiologyOrderItem::findOrFail($request->validated()['radiology_order_item_id']);

        $this->authorize('create', [RadiologyAppointment::class, $item]);

        $service->schedule($item, $request->validated(), $request);

        return back()->with('success', 'Radiology appointment scheduled.');
    }

    /**
     * Reschedule an existing appointment to a new time.
     */
    public function reschedule(RescheduleRadiologyAppointmentRequest $request, RadiologyAppointment $radiologyAppointment, RadiologySchedulingService $service): RedirectResponse
    {
        $this->authorize('reschedule', $radiologyAppointment);

        $validated = $request->validated();

        $service->reschedule(
            $radiologyAppointment,
            Carbon::parse($validated['scheduled_start']),
            Carbon::parse($validated['scheduled_end']),
            $validated['reason'],
            $request,
        );

        return back()->with('success', 'Radiology appointment rescheduled.');
    }

    /**
     * Confirm the specified appointment.
     */
    public function confirm(Request $request, RadiologyAppointment $radiologyAppointment, RadiologySchedulingService $service): RedirectResponse
    {
        $this->authorize('confirm', $radiologyAppointment);

        $service->confirm($radiologyAppointment, $request);

        return back()->with('success', 'Radiology appointment confirmed.');
    }

    /**
     * Cancel the specified appointment.
     */
    public function cancel(CancelRadiologyAppointmentRequest $request, RadiologyAppointment $radiologyAppointment, RadiologySchedulingService $service): RedirectResponse
    {
        $this->authorize('cancel', $radiologyAppointment);

        $service->cancel($radiologyAppointment, $request->validated()['cancellation_reason'], $request);

        return back()->with('success', 'Radiology appointment cancelled.');
    }

    /**
     * Mark the specified appointment as a no-show.
     */
    public function noShow(Request $request, RadiologyAppointment $radiologyAppointment, RadiologySchedulingService $service): RedirectResponse
    {
        $this->authorize('noShow', $radiologyAppointment);

        $service->markNoShow($radiologyAppointment, $request);

        return back()->with('success', 'Radiology appointment marked as no-show.');
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
