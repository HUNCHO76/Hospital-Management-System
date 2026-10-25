<?php

namespace App\Policies;

use App\Models\ClinicalEncounter;
use App\Models\LaboratoryOrder;
use App\Models\User;

/**
 * Mirrors EncounterDiagnosisPolicy exactly, now that LaboratoryOrder is a
 * first-class encounter entity (Phase 4, Step 30) rather than a
 * CarePlanItem detail record borrowing that item's own authorization.
 */
class LaboratoryOrderPolicy
{
    /**
     * Listing an encounter's laboratory orders is additionally gated by
     * ClinicalEncounterPolicy::view() in the controller (hospital scoping)
     * before this is checked; the standalone Laboratory worklist uses this
     * directly.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('laboratory-orders.view');
    }

    /**
     * No ownership restriction — any authorized clinical/lab staff in the
     * same hospital may need to read it for handoffs.
     */
    public function view(User $user, LaboratoryOrder $laboratoryOrder): bool
    {
        if (! $user->can('laboratory-orders.view')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $laboratoryOrder->clinicalEncounter);
    }

    /**
     * Determine whether the user can place a laboratory order against the
     * given encounter.
     */
    public function create(User $user, ClinicalEncounter $encounter): bool
    {
        if (! $user->can('laboratory-orders.create')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $encounter);
    }

    /**
     * Determine whether the user can update the order's editable fields or
     * add tests to it.
     */
    public function update(User $user, LaboratoryOrder $laboratoryOrder): bool
    {
        if (! $user->can('laboratory-orders.update')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $laboratoryOrder->clinicalEncounter);
    }

    /**
     * Determine whether the user can cancel the order (or one of its items).
     */
    public function cancel(User $user, LaboratoryOrder $laboratoryOrder): bool
    {
        if (! $user->can('laboratory-orders.cancel')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $laboratoryOrder->clinicalEncounter);
    }

    /**
     * The encounter's treating practitioner (the consultation's assigned
     * practitioner) may act on its laboratory orders; a hospital-admin or
     * super-admin (which bypasses policies entirely via Gate::before) may
     * act on any order within their scope.
     */
    protected function ownsOrSupervises(User $user, ClinicalEncounter $encounter): bool
    {
        if (! $this->belongsToUsersHospital($user, $encounter)) {
            return false;
        }

        if ($user->hasRole('hospital-admin') || $encounter->encounter_type === 'inpatient' || $encounter->admission_id) {
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
