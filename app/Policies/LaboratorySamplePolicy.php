<?php

namespace App\Policies;

use App\Models\ClinicalEncounter;
use App\Models\LaboratoryOrder;
use App\Models\LaboratorySample;
use App\Models\User;

/**
 * Unlike LaboratoryOrderPolicy, sample actions are never restricted to the
 * encounter's own treating practitioner — collection and receipt are
 * performed by whichever authorized nurse/laboratory staff is on duty, not
 * necessarily anyone tied to the order itself. Every ability is therefore
 * permission + hospital scope only, mirroring the read side of
 * LaboratoryOrderPolicy.
 */
class LaboratorySamplePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('laboratory-samples.view');
    }

    public function view(User $user, LaboratorySample $sample): bool
    {
        if (! $user->can('laboratory-samples.view')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $sample->clinicalEncounter);
    }

    /**
     * Determine whether the user can view the collection workflow for the
     * given order (the worklist detail page) — same hospital scope as
     * view(), but checked against the order directly since no sample may
     * exist yet.
     */
    public function viewCollection(User $user, LaboratoryOrder $order): bool
    {
        if (! $user->can('laboratory-samples.view')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $order->clinicalEncounter);
    }

    public function create(User $user, LaboratoryOrder $order): bool
    {
        if (! $user->can('laboratory-samples.collect')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $order->clinicalEncounter);
    }

    public function collect(User $user, LaboratorySample $sample): bool
    {
        if (! $user->can('laboratory-samples.collect')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $sample->clinicalEncounter);
    }

    public function label(User $user, LaboratorySample $sample): bool
    {
        if (! $user->can('laboratory-samples.label')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $sample->clinicalEncounter);
    }

    public function receive(User $user, LaboratorySample $sample): bool
    {
        if (! $user->can('laboratory-samples.receive')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $sample->clinicalEncounter);
    }

    public function reject(User $user, LaboratorySample $sample): bool
    {
        if (! $user->can('laboratory-samples.reject')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $sample->clinicalEncounter);
    }

    public function recollect(User $user, LaboratorySample $sample): bool
    {
        if (! $user->can('laboratory-samples.recollect')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $sample->clinicalEncounter);
    }

    public function cancel(User $user, LaboratorySample $sample): bool
    {
        if (! $user->can('laboratory-samples.cancel')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $sample->clinicalEncounter);
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
