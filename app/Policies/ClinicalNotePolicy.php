<?php

namespace App\Policies;

use App\Models\ClinicalEncounter;
use App\Models\ClinicalNote;
use App\Models\User;

class ClinicalNotePolicy
{
    /**
     * Determine whether the user holds the base clinical-notes viewing
     * permission. Listing an encounter's notes is additionally gated by
     * ClinicalEncounterPolicy::view() in the controller (which covers
     * hospital scoping) before this is checked — there is no ClinicalNote
     * instance yet to scope against here.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('clinical-notes.view');
    }

    /**
     * Determine whether the user can view the note. No ownership
     * restriction — any authorized clinical staff in the same hospital may
     * need to read it for handoffs.
     */
    public function view(User $user, ClinicalNote $note): bool
    {
        if (! $user->can('clinical-notes.view')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $note->clinicalEncounter);
    }

    /**
     * Determine whether the user can author a new note against the given
     * encounter. Hospital scoping for a hospital-admin is enforced in
     * StoreClinicalNoteRequest instead, against the target encounter's own
     * department — the same split StoreConsultationRequest/StoreTriageRequest use.
     */
    public function create(User $user, ClinicalEncounter $encounter): bool
    {
        return $user->can('clinical-notes.create');
    }

    /**
     * Determine whether the user can edit a draft note. Restricted to its
     * own author unless the user holds a supervisory role.
     */
    public function update(User $user, ClinicalNote $note): bool
    {
        if (! $user->can('clinical-notes.update')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $note);
    }

    /**
     * Determine whether the user can sign the note — always the note's own
     * author (or a supervisor), since signing asserts "I attest to this
     * content."
     */
    public function sign(User $user, ClinicalNote $note): bool
    {
        if (! $user->can('clinical-notes.sign')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $note);
    }

    /**
     * Determine whether the user can amend a signed/amended note — the
     * note's own author (or a supervisor), matching spec's "amend their own
     * documentation."
     */
    public function amend(User $user, ClinicalNote $note): bool
    {
        if (! $user->can('clinical-notes.amend')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $note);
    }

    /**
     * Determine whether the user can cancel a draft note.
     */
    public function cancel(User $user, ClinicalNote $note): bool
    {
        if (! $user->can('clinical-notes.cancel')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $note);
    }

    /**
     * The note's own author may act on it; a hospital-admin or super-admin
     * (which bypasses policies entirely via Gate::before) may act on any
     * note within their scope.
     */
    protected function ownsOrSupervises(User $user, ClinicalNote $note): bool
    {
        if (! $this->belongsToUsersHospital($user, $note->clinicalEncounter)) {
            return false;
        }

        if ($user->hasRole('hospital-admin')) {
            return true;
        }

        return $note->author_id === $user->id;
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
