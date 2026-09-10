<?php

namespace App\Policies;

use App\Models\ClinicalEncounter;
use App\Models\Consultation;
use App\Models\User;

class ConsultationPolicy
{
    /**
     * Determine whether the user can view the consultation. No ownership
     * restriction — any authorized clinical staff in the same hospital may
     * need to read it for handoffs.
     */
    public function view(User $user, Consultation $consultation): bool
    {
        if (! $user->can('consultation.view')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $consultation->clinicalEncounter);
    }

    /**
     * Determine whether the user can start a consultation on some encounter.
     * Since no Consultation instance exists yet at this point, the
     * hospital-scoping restriction for hospital-admins is enforced in
     * StoreConsultationRequest instead, against the target encounter's own
     * department — the same split StoreTriageRequest uses.
     */
    public function create(User $user): bool
    {
        return $user->can('consultation.create');
    }

    /**
     * Determine whether the user can update the consultation's clinical
     * fields. Restricted to the assigned practitioner unless the user holds
     * a supervisory role — prevents one doctor from editing another's
     * consultation (spec §22/23).
     */
    public function update(User $user, Consultation $consultation): bool
    {
        if (! $user->can('consultation.update')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $consultation);
    }

    /**
     * Determine whether the user can transition the consultation from
     * pending to in_progress. Reuses the 'consultation.create' permission
     * rather than introducing a separate one, mirroring
     * TriageAssessmentPolicy::start().
     */
    public function start(User $user, Consultation $consultation): bool
    {
        if (! $user->can('consultation.create')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $consultation);
    }

    /**
     * Determine whether the user can complete the consultation.
     */
    public function complete(User $user, Consultation $consultation): bool
    {
        if (! $user->can('consultation.complete')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $consultation);
    }

    /**
     * Determine whether the user can cancel the consultation.
     */
    public function cancel(User $user, Consultation $consultation): bool
    {
        if (! $user->can('consultation.cancel')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $consultation);
    }

    /**
     * Determine whether the user can amend a completed consultation
     * (spec §24's controlled correction workflow).
     */
    public function correct(User $user, Consultation $consultation): bool
    {
        if (! $user->can('consultation.correct')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $consultation);
    }

    /**
     * The assigned practitioner may act on their own consultation; a
     * hospital-admin or super-admin (which bypasses policies entirely via
     * Gate::before) may act on any consultation within their scope.
     */
    protected function ownsOrSupervises(User $user, Consultation $consultation): bool
    {
        if (! $this->belongsToUsersHospital($user, $consultation->clinicalEncounter)) {
            return false;
        }

        if ($user->hasRole('hospital-admin')) {
            return true;
        }

        return $user->staff?->practitioner?->id === $consultation->practitioner_id;
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
