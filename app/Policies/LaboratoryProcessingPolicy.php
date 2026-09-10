<?php

namespace App\Policies;

use App\Models\ClinicalEncounter;
use App\Models\LaboratoryProcessing;
use App\Models\LaboratorySample;
use App\Models\User;

/**
 * Mirrors LaboratorySamplePolicy exactly — every ability is permission +
 * hospital scope only, never restricted to a specific technician. Any
 * authorized laboratory staff may act on any sample/processing record
 * within their own hospital, matching how collection/receipt already work
 * (a processing queue is shared work, not personally owned).
 */
class LaboratoryProcessingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('laboratory-processing.view');
    }

    public function view(User $user, LaboratoryProcessing $processing): bool
    {
        if (! $user->can('laboratory-processing.view')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $processing->sample->clinicalEncounter);
    }

    /**
     * Viewing/accepting/rejecting a sample's processing (spec §7/§18 —
     * whole-specimen actions) is authorized against the LaboratorySample
     * directly, since no LaboratoryProcessing record may exist yet the
     * instant a sample is first received.
     */
    public function viewSample(User $user, LaboratorySample $sample): bool
    {
        if (! $user->can('laboratory-processing.view')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $sample->clinicalEncounter);
    }

    public function accept(User $user, LaboratorySample $sample): bool
    {
        if (! $user->can('laboratory-processing.accept')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $sample->clinicalEncounter);
    }

    public function reject(User $user, LaboratorySample $sample): bool
    {
        if (! $user->can('laboratory-processing.reject')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $sample->clinicalEncounter);
    }

    public function assign(User $user, LaboratoryProcessing $processing): bool
    {
        if (! $user->can('laboratory-processing.assign')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $processing->sample->clinicalEncounter);
    }

    public function start(User $user, LaboratoryProcessing $processing): bool
    {
        if (! $user->can('laboratory-processing.start')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $processing->sample->clinicalEncounter);
    }

    public function complete(User $user, LaboratoryProcessing $processing): bool
    {
        if (! $user->can('laboratory-processing.complete')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $processing->sample->clinicalEncounter);
    }

    public function transfer(User $user, LaboratoryProcessing $processing): bool
    {
        if (! $user->can('laboratory-processing.transfer')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $processing->sample->clinicalEncounter);
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
