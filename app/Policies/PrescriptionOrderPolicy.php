<?php

namespace App\Policies;

use App\Models\ClinicalEncounter;
use App\Models\PrescriptionOrder;
use App\Models\User;

/**
 * Mirrors RadiologyOrderPolicy exactly — PrescriptionOrder is a first-class
 * encounter entity (Phase 6, Step 46) from the start, never a CarePlanItem
 * detail record.
 */
class PrescriptionOrderPolicy
{
    /**
     * Listing an encounter's prescriptions is additionally gated by
     * ClinicalEncounterPolicy::view() in the controller (hospital scoping)
     * before this is checked; the standalone Pharmacy worklist/detail pages
     * use this directly.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('prescription-orders.view');
    }

    /**
     * No ownership restriction — any authorized clinical/pharmacy staff in
     * the same hospital may need to read it for handoffs. Prescribers and
     * encounter practitioners may also view their own orders.
     */
    public function view(User $user, PrescriptionOrder $prescriptionOrder): bool
    {
        if ($user->can('prescription-orders.view') && $this->belongsToUsersHospital($user, $prescriptionOrder->clinicalEncounter)) {
            return true;
        }

        if ($user->id === $prescriptionOrder->prescriber_id || $this->ownsOrSupervises($user, $prescriptionOrder->clinicalEncounter)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can write a prescription against the given
     * encounter.
     */
    public function create(User $user, ClinicalEncounter $encounter): bool
    {
        if (! $user->can('prescription-orders.create')) {
            return false;
        }

        if ($user->hasRole('nurse') && ! $user->can('prescriptions.nurse-prescribe')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $encounter);
    }

    /**
     * Determine whether the user can update the order's editable fields or
     * add medications to it.
     */
    public function update(User $user, PrescriptionOrder $prescriptionOrder): bool
    {
        if (! $user->can('prescription-orders.update')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $prescriptionOrder->clinicalEncounter);
    }

    /**
     * Determine whether the user can cancel the order (or one of its
     * items).
     */
    public function cancel(User $user, PrescriptionOrder $prescriptionOrder): bool
    {
        if (! $user->can('prescription-orders.cancel')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $prescriptionOrder->clinicalEncounter);
    }

    /**
     * Viewing the pharmacy verification queue (Phase 6, Step 47) — a
     * distinct permission from viewAny()'s 'prescription-orders.view'
     * (which gates the read-only worklist/detail pages), mirroring how
     * 'radiology-results.view' and the verification-specific abilities are
     * separate permissions on RadiologyReportPolicy.
     */
    public function viewVerificationQueue(User $user): bool
    {
        return $user->can('pharmacy-verification.view');
    }

    /**
     * Determine whether the user can open the order for verification review
     * (spec §8/§15) — requires independence from the order's own
     * prescriber, the same as verify()/returnForCorrection() below.
     */
    public function review(User $user, PrescriptionOrder $prescriptionOrder): bool
    {
        if (! $user->can('pharmacy-verification.review')) {
            return false;
        }

        if (! $this->belongsToUsersHospital($user, $prescriptionOrder->clinicalEncounter)) {
            return false;
        }

        return $this->independentOfPrescriber($user, $prescriptionOrder);
    }

    /**
     * Determine whether the user can verify (approve) the prescription.
     */
    public function verify(User $user, PrescriptionOrder $prescriptionOrder): bool
    {
        if (! $user->can('pharmacy-verification.verify')) {
            return false;
        }

        if (! $this->belongsToUsersHospital($user, $prescriptionOrder->clinicalEncounter)) {
            return false;
        }

        return $this->independentOfPrescriber($user, $prescriptionOrder);
    }

    /**
     * Determine whether the user can return the prescription for
     * correction.
     */
    public function returnForCorrection(User $user, PrescriptionOrder $prescriptionOrder): bool
    {
        if (! $user->can('pharmacy-verification.return')) {
            return false;
        }

        if (! $this->belongsToUsersHospital($user, $prescriptionOrder->clinicalEncounter)) {
            return false;
        }

        return $this->independentOfPrescriber($user, $prescriptionOrder);
    }

    /**
     * Viewing the pharmacy dispensing queue (Phase 6, Step 48) — a distinct
     * permission from viewAny()'s 'prescription-orders.view', mirroring
     * viewVerificationQueue() above exactly.
     */
    public function viewDispensingQueue(User $user): bool
    {
        return $user->can('pharmacy-dispensing.view');
    }

    /**
     * Determine whether the user can open a verified prescription's
     * dispensing detail page.
     */
    public function viewDispensing(User $user, PrescriptionOrder $prescriptionOrder): bool
    {
        if (! $user->can('pharmacy-dispensing.view')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $prescriptionOrder->clinicalEncounter);
    }

    /**
     * Determine whether the user can open a new dispensing event for this
     * prescription. No independence-from-prescriber/verifier requirement —
     * unlike verification's safety gate (spec §5), dispensing has no
     * equivalent instruction, so none is invented here.
     */
    public function startDispensing(User $user, PrescriptionOrder $prescriptionOrder): bool
    {
        if (! $user->can('pharmacy-dispensing.start')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $prescriptionOrder->clinicalEncounter);
    }

    /**
     * Determine whether the user can complete the prescription's current
     * in-progress dispensing event. Authorized against the parent order
     * (like cancelItem()'s own reasoning on EncounterPrescriptionOrderController)
     * rather than a separate per-dispensing permission.
     */
    public function completeDispensing(User $user, PrescriptionOrder $prescriptionOrder): bool
    {
        if (! $user->can('pharmacy-dispensing.complete')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $prescriptionOrder->clinicalEncounter);
    }

    /**
     * Determine whether the user can cancel the prescription's current
     * in-progress dispensing event.
     */
    public function cancelDispensing(User $user, PrescriptionOrder $prescriptionOrder): bool
    {
        if (! $user->can('pharmacy-dispensing.cancel')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $prescriptionOrder->clinicalEncounter);
    }

    /**
     * The encounter's treating practitioner (the consultation's assigned
     * practitioner) may act on its prescriptions; a hospital-admin or
     * super-admin (which bypasses policies entirely via Gate::before) may
     * act on any prescription within their scope.
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
     * The self-verification protection (spec §5): a user with no
     * practitioner profile is trivially independent (they cannot be the
     * order's own prescriber), unless the acting user's id is literally the
     * order's own prescriber_id — the identity comparison Prescription
     * verification actually needs, since a prescriber is a User writing the
     * order directly (PrescriptionOrder::prescriber_id), not a Practitioner
     * profile the way Radiology's reporting practitioner is. Never hard-coded
     * — configurable per spec §5's own "make that behavior configurable"
     * instruction, mirroring RadiologyReportPolicy::independentOfAuthor()
     * exactly.
     */
    protected function independentOfPrescriber(User $user, PrescriptionOrder $prescriptionOrder): bool
    {
        if (config('pharmacy.allow_self_verification')) {
            return true;
        }

        return $user->id !== $prescriptionOrder->prescriber_id;
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
