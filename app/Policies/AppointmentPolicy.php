<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;

class AppointmentPolicy
{
    /**
     * Determine whether the user can view any appointments.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('appointments.view');
    }

    /**
     * Determine whether the user can view the appointment.
     */
    public function view(User $user, Appointment $appointment): bool
    {
        if (! $user->can('appointments.view')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $appointment);
    }

    /**
     * Determine whether the user can create appointments.
     *
     * The hospital of the selected department/practitioner is validated
     * separately in StoreAppointmentRequest, since no Appointment instance
     * exists yet here.
     */
    public function create(User $user): bool
    {
        return $user->can('appointments.create');
    }

    /**
     * Determine whether the user can update the appointment (non-scheduling
     * fields) or reschedule it.
     */
    public function update(User $user, Appointment $appointment): bool
    {
        if (! $user->can('appointments.update')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $appointment);
    }

    /**
     * Determine whether the user can reschedule the appointment.
     */
    public function reschedule(User $user, Appointment $appointment): bool
    {
        if (! $user->can('appointments.reschedule')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $appointment);
    }

    /**
     * Determine whether the user can confirm the appointment.
     */
    public function confirm(User $user, Appointment $appointment): bool
    {
        if (! $user->can('appointments.confirm')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $appointment);
    }

    /**
     * Determine whether the user can cancel the appointment.
     */
    public function cancel(User $user, Appointment $appointment): bool
    {
        if (! $user->can('appointments.cancel')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $appointment);
    }

    /**
     * Determine whether the user can check in the appointment.
     */
    public function checkIn(User $user, Appointment $appointment): bool
    {
        if (! $user->can('appointments.checkin')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $appointment);
    }

    /**
     * Determine whether the user can start the appointment (mark the
     * checked-in patient as in progress). Reuses the 'complete' permission
     * rather than introducing a separate one — both are provider-side
     * actions taken once the patient is in the room.
     */
    public function start(User $user, Appointment $appointment): bool
    {
        if (! $user->can('appointments.complete')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $appointment);
    }

    /**
     * Determine whether the user can mark the appointment complete.
     */
    public function complete(User $user, Appointment $appointment): bool
    {
        if (! $user->can('appointments.complete')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $appointment);
    }

    /**
     * Determine whether the user can mark the appointment a no-show.
     */
    public function noShow(User $user, Appointment $appointment): bool
    {
        if (! $user->can('appointments.no_show')) {
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
    protected function belongsToUsersHospital(User $user, Appointment $appointment): bool
    {
        if (! $user->hasRole('hospital-admin')) {
            return true;
        }

        return $user->staff?->hospital_id === $appointment->department->hospital_id;
    }
}
