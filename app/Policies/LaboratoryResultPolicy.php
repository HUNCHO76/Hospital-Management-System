<?php

namespace App\Policies;

use App\Models\ClinicalEncounter;
use App\Models\LaboratoryResult;
use App\Models\User;

/**
 * Mirrors LaboratoryProcessingPolicy exactly — every ability is permission +
 * hospital scope only, never restricted to a specific technician (a result
 * worklist is shared work, not personally owned) — except review/verify/
 * authorize, which additionally enforce entry-vs-review role separation
 * (Step 34 spec §6-7/§39): the user who entered a result may never review,
 * verify, or authorize that same result. super-admin bypasses every check
 * here, including this one, via Gate::before — the same existing, global
 * exception every other policy in this app already carries.
 */
class LaboratoryResultPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('laboratory-results.view');
    }

    public function view(User $user, LaboratoryResult $result): bool
    {
        if (! $user->can('laboratory-results.view')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $result);
    }

    public function update(User $user, LaboratoryResult $result): bool
    {
        if (! $user->can('laboratory-results.update')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $result);
    }

    public function submit(User $user, LaboratoryResult $result): bool
    {
        if (! $user->can('laboratory-results.submit')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $result);
    }

    /**
     * Pre-verification amendment (Step 33) — correcting a result that has
     * been submitted but not yet reviewed. Never restricted to a different
     * user than the one who entered it (spec draws the hard separation line
     * at review/verify/authorize, not at this earlier, lower-risk stage).
     */
    public function amend(User $user, LaboratoryResult $result): bool
    {
        if (! $user->can('laboratory-results.amend')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $result);
    }

    /**
     * Post-verification/authorization/release controlled amendment (Step 34
     * spec §22, extended by Step 35 Part 17 to also cover an already-
     * RELEASED result) — a distinct, stricter permission from ordinary
     * `amend`, since correcting already-verified/authorized/released
     * clinical data is materially more sensitive than correcting an
     * unreviewed draft.
     */
    public function amendVerified(User $user, LaboratoryResult $result): bool
    {
        if (! $user->can('laboratory-results.amend_verified')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $result);
    }

    /**
     * Release an authorized result (Step 35 Part 4/29). No entry-vs-release
     * separation is required by spec — only the entry-vs-review boundary
     * above is a hard rule.
     */
    public function release(User $user, LaboratoryResult $result): bool
    {
        if (! $user->can('laboratory-results.release')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $result);
    }

    public function review(User $user, LaboratoryResult $result): bool
    {
        if (! $user->can('laboratory-results.review')) {
            return false;
        }

        if (! $this->belongsToUsersHospital($user, $result)) {
            return false;
        }

        return $this->isNotTheEnteringUser($user, $result);
    }

    public function verify(User $user, LaboratoryResult $result): bool
    {
        if (! $user->can('laboratory-results.verify')) {
            return false;
        }

        if (! $this->belongsToUsersHospital($user, $result)) {
            return false;
        }

        return $this->isNotTheEnteringUser($user, $result);
    }

    public function returnForCorrection(User $user, LaboratoryResult $result): bool
    {
        if (! $user->can('laboratory-results.return')) {
            return false;
        }

        if (! $this->belongsToUsersHospital($user, $result)) {
            return false;
        }

        return $this->isNotTheEnteringUser($user, $result);
    }

    public function authorize(User $user, LaboratoryResult $result): bool
    {
        if (! $user->can('laboratory-results.authorize')) {
            return false;
        }

        if (! $this->belongsToUsersHospital($user, $result)) {
            return false;
        }

        // Verifying and authorizing may be the same person (spec §17/§39
        // only requires entry-vs-review separation, not verify-vs-authorize
        // separation) — only the entering user is barred here.
        return $this->isNotTheEnteringUser($user, $result);
    }

    /**
     * A hospital-admin is restricted to encounters booked under their own
     * hospital's departments; every other role holding the permission
     * (currently only super-admin, which bypasses policies via Gate::before)
     * is unrestricted.
     */
    protected function belongsToUsersHospital(User $user, LaboratoryResult $result): bool
    {
        if (! $user->hasRole('hospital-admin')) {
            return true;
        }

        /** @var ClinicalEncounter $encounter */
        $encounter = $result->item->laboratoryOrder->clinicalEncounter;

        return $user->staff?->hospital_id === $encounter->department->hospital_id;
    }

    /**
     * The result-entry/review separation rule (spec §6-7/§39) — the user
     * who entered a result's value may never review, verify, return, or
     * authorize that same result.
     */
    protected function isNotTheEnteringUser(User $user, LaboratoryResult $result): bool
    {
        return $result->entered_by === null || $result->entered_by !== $user->id;
    }
}
