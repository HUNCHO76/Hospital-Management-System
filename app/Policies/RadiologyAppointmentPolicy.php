<?php

namespace App\Policies;

use App\Models\RadiologyAppointment;
use App\Models\RadiologyOrderItem;
use App\Models\RadiologyReport;
use App\Models\User;

/**
 * Mirrors AppointmentPolicy's shape exactly, scoped through the item's
 * order's encounter's department (the same hospital-scoping chain every
 * other Radiology policy already uses) since a radiology appointment has
 * no hospital/facility column of its own.
 */
class RadiologyAppointmentPolicy
{
    /**
     * Viewing the scheduling dashboard/worklist.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('radiology-scheduling.view');
    }

    /**
     * Determine whether the user can view the appointment.
     */
    public function view(User $user, RadiologyAppointment $appointment): bool
    {
        if (! $user->can('radiology-scheduling.view')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $appointment);
    }

    /**
     * Determine whether the user can schedule the given order item. No
     * RadiologyAppointment instance exists yet here, so hospital scoping is
     * checked directly against the item's own encounter chain.
     */
    public function create(User $user, RadiologyOrderItem $item): bool
    {
        if (! $user->can('radiology-scheduling.create')) {
            return false;
        }

        if (! $user->hasRole('hospital-admin')) {
            return true;
        }

        return $user->staff?->hospital_id === $item->radiologyOrder->clinicalEncounter->department->hospital_id;
    }

    /**
     * Determine whether the user can reschedule the appointment.
     */
    public function reschedule(User $user, RadiologyAppointment $appointment): bool
    {
        if (! $user->can('radiology-scheduling.reschedule')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $appointment);
    }

    /**
     * Determine whether the user can confirm the appointment.
     */
    public function confirm(User $user, RadiologyAppointment $appointment): bool
    {
        if (! $user->can('radiology-scheduling.confirm')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $appointment);
    }

    /**
     * Determine whether the user can cancel the appointment.
     */
    public function cancel(User $user, RadiologyAppointment $appointment): bool
    {
        if (! $user->can('radiology-scheduling.cancel')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $appointment);
    }

    /**
     * Determine whether the user can mark the appointment a no-show.
     */
    public function noShow(User $user, RadiologyAppointment $appointment): bool
    {
        if (! $user->can('radiology-scheduling.no_show')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $appointment);
    }

    /**
     * Viewing the radiology reception dashboard (Phase 5, Step 40).
     */
    public function viewReception(User $user): bool
    {
        return $user->can('radiology-reception.view');
    }

    /**
     * Every reception/preparation/readiness mutation (arrive, verify, start/
     * complete/waive preparation, record screening/contrast/consent, mark
     * ready/not-ready, resume) shares one ability — they are all steps of
     * the same continuous reception workflow performed by the same
     * radiology staff, unlike scheduling's genuinely distinct actions
     * (Part 27's "do not create duplicate permissions," applied one level
     * further: also don't fragment one workflow into many permissions).
     */
    public function manage(User $user, RadiologyAppointment $appointment): bool
    {
        if (! $user->can('radiology-reception.manage')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $appointment);
    }

    /**
     * Viewing the examination worklist (Phase 5, Step 41).
     */
    public function viewExaminationWorklist(User $user): bool
    {
        return $user->can('radiology-examination.view');
    }

    /**
     * Determine whether the user can view one examination's detail page.
     */
    public function viewExamination(User $user, RadiologyAppointment $appointment): bool
    {
        if (! $user->can('radiology-examination.view')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $appointment);
    }

    /**
     * Determine whether the user can start the examination.
     */
    public function start(User $user, RadiologyAppointment $appointment): bool
    {
        if (! $user->can('radiology-examination.start')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $appointment);
    }

    /**
     * Determine whether the user can record/update technical examination
     * information.
     */
    public function updateExamination(User $user, RadiologyAppointment $appointment): bool
    {
        if (! $user->can('radiology-examination.update')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $appointment);
    }

    /**
     * Determine whether the user can complete the examination.
     */
    public function completeExamination(User $user, RadiologyAppointment $appointment): bool
    {
        if (! $user->can('radiology-examination.complete')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $appointment);
    }

    /**
     * Determine whether the user can abort the examination.
     */
    public function abortExamination(User $user, RadiologyAppointment $appointment): bool
    {
        if (! $user->can('radiology-examination.abort')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $appointment);
    }

    /**
     * Determine whether the user can attach or remove examination images.
     * Reuses the same ability as recording technical information — both are
     * the same technician-performed "documenting the examination" work, not
     * two separate permissions (mirrors the reception workflow's own
     * single-ability precedent).
     */
    public function manageImages(User $user, RadiologyAppointment $appointment): bool
    {
        if (! $user->can('radiology-examination.update')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $appointment);
    }

    /**
     * Determine whether the user can view examination images — anyone who
     * can view the examination itself (the performing technician) or the
     * resulting report (reporting/verification/release staff), since all of
     * them may legitimately need the image for context. A doctor (or anyone
     * else with no radiology-specific permission) may additionally view
     * images once — and only once — the report has been RELEASED as the
     * official clinical result, provided they can otherwise view the
     * underlying clinical encounter (ClinicalEncounterPolicy::view()) —
     * this mirrors the exact same RELEASED-only boundary
     * RadiologyResultsSection already enforces for the report's text
     * content (Phase 5, Step 44, Part 15/18): a doctor sees the official
     * result automatically, without needing a radiology-department
     * permission, but never a pre-release draft/submitted/verified image.
     */
    public function viewImages(User $user, RadiologyAppointment $appointment): bool
    {
        if (! $this->belongsToUsersHospital($user, $appointment)) {
            return false;
        }

        if ($user->can('radiology-examination.view') || $user->can('radiology-results.view')) {
            return true;
        }

        $report = $appointment->relationLoaded('report') ? $appointment->report : $appointment->report()->first();

        if (! $report || $report->status !== RadiologyReport::STATUS_RELEASED) {
            return false;
        }

        $encounter = $appointment->radiologyOrderItem->radiologyOrder->clinicalEncounter;

        return $user->can('view', $encounter);
    }

    /**
     * A hospital-admin is restricted to appointments booked under their own
     * hospital's departments; every other role holding the permission
     * (currently only super-admin, which bypasses policies via Gate::before)
     * is unrestricted.
     */
    protected function belongsToUsersHospital(User $user, RadiologyAppointment $appointment): bool
    {
        if (! $user->hasRole('hospital-admin')) {
            return true;
        }

        return $user->staff?->hospital_id === $appointment->radiologyOrderItem->radiologyOrder->clinicalEncounter->department->hospital_id;
    }
}
