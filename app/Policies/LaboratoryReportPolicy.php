<?php

namespace App\Policies;

use App\Models\ClinicalEncounter;
use App\Models\LaboratoryReport;
use App\Models\User;

/**
 * Mirrors LaboratoryResultPolicy's own hospital-scope shape exactly — a
 * report is reached through its order, not owned by a specific reader, so
 * every ability is permission + hospital scope only. Protects against IDOR:
 * a hospital-admin cannot open, print, or download another hospital's
 * report by guessing its id (spec Part 22/24/32/39).
 */
class LaboratoryReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('laboratory-reports.view');
    }

    public function view(User $user, LaboratoryReport $report): bool
    {
        if (! $user->can('laboratory-reports.view')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $report);
    }

    public function print(User $user, LaboratoryReport $report): bool
    {
        if (! $user->can('laboratory-reports.print')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $report);
    }

    public function download(User $user, LaboratoryReport $report): bool
    {
        if (! $user->can('laboratory-reports.download')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $report);
    }

    /**
     * A hospital-admin is restricted to encounters booked under their own
     * hospital's departments; every other role holding the permission
     * (currently only super-admin, which bypasses policies via Gate::before)
     * is unrestricted.
     */
    protected function belongsToUsersHospital(User $user, LaboratoryReport $report): bool
    {
        if (! $user->hasRole('hospital-admin')) {
            return true;
        }

        /** @var ClinicalEncounter $encounter */
        $encounter = $report->laboratoryOrder->clinicalEncounter;

        return $user->staff?->hospital_id === $encounter->department->hospital_id;
    }
}
