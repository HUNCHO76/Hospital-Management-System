<?php

namespace App\Policies;

use App\Models\ReceptionVisit;
use App\Models\User;

class ReceptionVisitPolicy
{
    /**
     * Determine whether the user can view any reception visits.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('reception.view');
    }

    /**
     * Determine whether the user can view the reception dashboard.
     */
    public function viewDashboard(User $user): bool
    {
        return $user->can('reception.dashboard');
    }

    /**
     * Determine whether the user can view the reception visit.
     */
    public function view(User $user, ReceptionVisit $visit): bool
    {
        if (! $user->can('reception.view')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $visit);
    }

    /**
     * Determine whether the user can check in a scheduled appointment.
     */
    public function checkIn(User $user): bool
    {
        return $user->can('reception.checkin');
    }

    /**
     * Determine whether the user can register a walk-in visit.
     */
    public function createWalkIn(User $user): bool
    {
        return $user->can('reception.create_walkin');
    }

    /**
     * Determine whether the user can progress the visit's status
     * (waiting/ready/sent-to-service).
     */
    public function update(User $user, ReceptionVisit $visit): bool
    {
        if (! $user->can('reception.update')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $visit);
    }

    /**
     * Determine whether the user can complete the visit.
     */
    public function complete(User $user, ReceptionVisit $visit): bool
    {
        if (! $user->can('reception.complete')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $visit);
    }

    /**
     * Determine whether the user can cancel the visit.
     */
    public function cancel(User $user, ReceptionVisit $visit): bool
    {
        if (! $user->can('reception.cancel')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $visit);
    }

    /**
     * A hospital-admin is restricted to visits booked under their own
     * hospital's departments; every other role holding the permission
     * (currently only super-admin, which bypasses policies via
     * Gate::before) is unrestricted.
     */
    protected function belongsToUsersHospital(User $user, ReceptionVisit $visit): bool
    {
        if (! $user->hasRole('hospital-admin')) {
            return true;
        }

        return $user->staff?->hospital_id === $visit->department->hospital_id;
    }
}
