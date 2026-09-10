<?php

namespace Database\Seeders;

use App\Models\Practitioner;
use App\Models\Staff;
use Illuminate\Database\Seeder;

class PractitionerSeeder extends Seeder
{
    /**
     * Seed practitioner profiles only for staff in clinical roles.
     *
     * Receptionists, accountants, IT officers and other non-clinical staff are
     * intentionally left without a practitioner profile. Eligibility is
     * driven by Practitioner::CLINICAL_JOB_TITLES, the single source of
     * truth for which job titles default to a practitioner profile.
     */
    public function run(): void
    {
        Staff::query()
            ->whereIn('job_title', array_keys(Practitioner::CLINICAL_JOB_TITLES))
            ->whereDoesntHave('practitioner')
            ->each(function (Staff $staff): void {
                $staff->practitioner()->create([
                    'practitioner_type' => Practitioner::CLINICAL_JOB_TITLES[$staff->job_title],
                    'specialization' => null,
                    'status' => 'active',
                ]);
            });
    }
}
