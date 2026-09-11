<?php

namespace App\Policies;

use App\Models\ClinicalEncounter;
use App\Models\RadiologyOrder;
use App\Models\User;

/**
 * Mirrors LaboratoryOrderPolicy exactly — RadiologyOrder is a first-class
 * encounter entity (Phase 5, Step 36) from the start, never a CarePlanItem
 * detail record.
 */
class RadiologyOrderPolicy
{
    /**
     * Listing an encounter's radiology orders is additionally gated by
     * ClinicalEncounterPolicy::view() in the controller (hospital scoping)
     * before this is checked; the standalone Radiology worklist uses this
     * directly.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('radiology-orders.view');
    }

    /**
     * No ownership restriction — any authorized clinical/radiology staff in
     * the same hospital may need to read it for handoffs.
     */
    public function view(User $user, RadiologyOrder $radiologyOrder): bool
    {
        if (! $user->can('radiology-orders.view')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $radiologyOrder->clinicalEncounter);
    }

    /**
     * Determine whether the user can place a radiology order against the
     * given encounter.
     */
    public function create(User $user, ClinicalEncounter $encounter): bool
    {
        if (! $user->can('radiology-orders.create')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $encounter);
    }

    /**
     * Determine whether the user can update the order's editable fields or
     * add procedures to it.
     */
    public function update(User $user, RadiologyOrder $radiologyOrder): bool
    {
        if (! $user->can('radiology-orders.update')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $radiologyOrder->clinicalEncounter);
    }

    /**
     * Determine whether the user can cancel the order (or one of its
     * items).
     */
    public function cancel(User $user, RadiologyOrder $radiologyOrder): bool
    {
        if (! $user->can('radiology-orders.cancel')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $radiologyOrder->clinicalEncounter);
    }

    /**
     * The encounter's treating practitioner (the consultation's assigned
     * practitioner) may act on its radiology orders; a hospital-admin or
     * super-admin (which bypasses policies entirely via Gate::before) may
     * act on any order within their scope.
     */
    protected function ownsOrSupervises(User $user, ClinicalEncounter $encounter): bool
    {
        if (! $this->belongsToUsersHospital($user, $encounter)) {
            return false;
        }

        if ($user->hasRole('hospital-admin')) {
            return true;
        }

        return $user->staff?->practitioner?->id === $encounter->consultation?->practitioner_id;
    }

    /**
     * A hospital-admin is restricted to encounters booked under their own
     * hospital's departments; every other role holding the permission
     * (currently only super-admin, which bypasses policies via Gate::before)
     * is unrestricted.
     */
    protected function belongsToUsersHospital(User $user, ClinicalEncounter $encounter): bool
    {
        if (! $user->hasRole('hospital-admin')) {
            return true;
        }

        return $user->staff?->hospital_id === $encounter->department->hospital_id;
    }
}
