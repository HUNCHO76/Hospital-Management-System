<?php

namespace App\Http\Controllers;

use App\Http\Resources\RadiologyAppointmentResource;
use App\Models\RadiologyAppointment;
use App\Models\RadiologyOrder;
use App\Models\RadiologyProcedure;
use App\Services\RadiologySchedulingSearchService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The radiology worklist (Phase 5, Step 39, Part 14): "what does the
 * department need to handle, and when" — a read-focused operational view
 * over the same RadiologyAppointment data the scheduling dashboard
 * mutates. Reuses the 'radiology-scheduling.view' permission rather than a
 * separate 'radiology-worklist.view' — viewing the worklist and viewing the
 * scheduling dashboard are the same capability over the same data (Part
 * 26's own "do not duplicate permissions that already exist" instruction).
 */
class RadiologyWorklistController extends Controller
{
    public function index(Request $request, RadiologySchedulingSearchService $search): Response
    {
        $this->authorize('viewAny', RadiologyAppointment::class);

        $filters = $request->only(['search', 'status', 'modality', 'resource_name', 'priority', 'date', 'date_from', 'date_to', 'overdue']);

        $query = $search->worklistQuery($filters)->with([
            'radiologyOrderItem:id,radiology_order_id,procedure_code,procedure_name,modality_snapshot',
            'radiologyOrderItem.radiologyOrder:id,order_number,priority,clinical_indication,clinical_encounter_id',
            'radiologyOrderItem.radiologyOrder.clinicalEncounter:id,encounter_number,patient_id',
            'radiologyOrderItem.radiologyOrder.clinicalEncounter.patient:id,patient_number,first_name,middle_name,last_name,date_of_birth,gender',
            'scheduledBy:id,UserName',
        ]);
        $search->scopeWorklistToHospital($query, $this->scopedHospitalId($request));

        $appointments = $query->paginate(20)->withQueryString()
            ->through(fn (RadiologyAppointment $appointment) => (new RadiologyAppointmentResource($appointment))->resolve($request));

        return Inertia::render('radiology/worklist/index', [
            'appointments' => $appointments,
            'filters' => $filters,
            'statuses' => RadiologyAppointment::STATUSES,
            'modalities' => RadiologyProcedure::MODALITIES,
            'priorities' => RadiologyOrder::PRIORITIES,
        ]);
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
