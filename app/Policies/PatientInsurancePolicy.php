<?php

namespace App\Policies;

use App\Models\PatientInsurance;
use App\Models\User;

class PatientInsurancePolicy
{
    /**
     * Determine whether the user can view any patient insurance records.
     *
     * Patients are a global identity with no hospital_id (see the Patient
     * Registration step), so patient insurance access mirrors PatientPolicy
     * exactly — no hospital scoping applies here either.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('patient-insurance.view');
    }

    /**
     * Determine whether the user can view the patient insurance record.
     */
    public function view(User $user, PatientInsurance $patientInsurance): bool
    {
        return $user->can('patient-insurance.view');
    }

    /**
     * Determine whether the user can create patient insurance records.
     */
    public function create(User $user): bool
    {
        return $user->can('patient-insurance.create');
    }

    /**
     * Determine whether the user can update the patient insurance record.
     */
    public function update(User $user, PatientInsurance $patientInsurance): bool
    {
        return $user->can('patient-insurance.update');
    }

    /**
     * Determine whether the user can delete/deactivate the patient
     * insurance record.
     */
    public function delete(User $user, PatientInsurance $patientInsurance): bool
    {
        return $user->can('patient-insurance.delete');
    }

    /**
     * Determine whether the user can set/change the internal verification
     * status of the patient insurance record.
     */
    public function verify(User $user, PatientInsurance $patientInsurance): bool
    {
        return $user->can('patient-insurance.verify');
    }
}
