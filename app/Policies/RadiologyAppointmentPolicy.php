<?php

namespace App\Policies;

use App\Models\RadiologyAppointment;
use App\Models\RadiologyOrderItem;
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
