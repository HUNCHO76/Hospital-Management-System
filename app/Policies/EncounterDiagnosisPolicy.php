<?php

namespace App\Policies;

use App\Models\ClinicalEncounter;
use App\Models\EncounterDiagnosis;
use App\Models\User;

class EncounterDiagnosisPolicy
{
    /**
     * Determine whether the user holds the base diagnosis viewing
     * permission. Listing an encounter's diagnoses is additionally gated by
     * ClinicalEncounterPolicy::view() in the controller (which covers
     * hospital scoping) before this is checked — there is no
     * EncounterDiagnosis instance yet to scope against here.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('diagnosis.view');
    }

    /**
     * Determine whether the user can view the diagnosis. No ownership
     * restriction — any authorized clinical staff in the same hospital may
     * need to read it for handoffs.
     */
    public function view(User $user, EncounterDiagnosis $diagnosis): bool
    {
        if (! $user->can('diagnosis.view')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $diagnosis->clinicalEncounter);
    }

    /**
     * Determine whether the user can record a diagnosis against the given
     * encounter. Unlike TriageAssessment/Consultation (which have no prior
     * owner to check at creation time), the encounter's consultation already
     * has an assigned practitioner by this point, so ownership is enforced
     * here too, not deferred to a later action.
     */
    public function create(User $user, ClinicalEncounter $encounter): bool
    {
        if (! $user->can('diagnosis.create')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $encounter);
    }

    /**
     * Determine whether the user can update the diagnosis's clinical
     * fields.
     */
    public function update(User $user, EncounterDiagnosis $diagnosis): bool
    {
        if (! $user->can('diagnosis.update')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $diagnosis->clinicalEncounter);
    }

    /**
     * Determine whether the user can promote the diagnosis to primary.
     */
    public function setPrimary(User $user, EncounterDiagnosis $diagnosis): bool
    {
        if (! $user->can('diagnosis.primary')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $diagnosis->clinicalEncounter);
    }

    /**
     * Determine whether the user can advance the diagnosis's status.
     */
    public function changeStatus(User $user, EncounterDiagnosis $diagnosis): bool
    {
        if (! $user->can('diagnosis.status')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $diagnosis->clinicalEncounter);
    }

    /**
     * Determine whether the user can cancel the diagnosis.
     */
    public function cancel(User $user, EncounterDiagnosis $diagnosis): bool
    {
        if (! $user->can('diagnosis.cancel')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $diagnosis->clinicalEncounter);
    }

    /**
     * Determine whether the user can amend a confirmed/resolved diagnosis
     * (spec §22's controlled correction workflow).
     */
    public function correct(User $user, EncounterDiagnosis $diagnosis): bool
    {
        if (! $user->can('diagnosis.correct')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $diagnosis->clinicalEncounter);
    }

    /**
     * The encounter's treating practitioner (the consultation's assigned
     * practitioner) may act on its diagnoses; a hospital-admin or
     * super-admin (which bypasses policies entirely via Gate::before) may
     * act on any diagnosis within their scope.
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
