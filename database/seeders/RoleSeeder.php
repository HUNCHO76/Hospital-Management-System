<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * The initial HMS roles, mapped to the permissions they should be granted.
     *
     * The special value '*' grants every permission in the system.
     *
     * @var array<string, list<string>|'*'>
     */
    protected array $roles = [
        'super-admin' => '*',

        'hospital-admin' => [
            'users.view', 'users.create',
            'hospitals.view', 'hospitals.update',
            'departments.view', 'departments.create', 'departments.update', 'departments.delete',
            'staff.view',
            'practitioners.view',
            'patients.view', 'patients.create', 'patients.update', 'patients.delete',
            'insurance-providers.view',
            'insurance-schemes.view', 'insurance-schemes.create', 'insurance-schemes.update', 'insurance-schemes.delete',
            'patient-insurance.view', 'patient-insurance.create', 'patient-insurance.update', 'patient-insurance.delete', 'patient-insurance.verify',
            'appointments.view', 'appointments.create', 'appointments.update', 'appointments.confirm', 'appointments.reschedule', 'appointments.cancel', 'appointments.checkin', 'appointments.complete', 'appointments.no_show',
            'reception.view', 'reception.dashboard', 'reception.checkin', 'reception.create_walkin', 'reception.update', 'reception.cancel', 'reception.complete',
            'clinical-encounters.view', 'clinical-encounters.create', 'clinical-encounters.start', 'clinical-encounters.complete', 'clinical-encounters.cancel',
            'triage.view', 'triage.create', 'triage.update', 'triage.complete', 'triage.cancel',
            'vital-signs.view', 'vital-signs.create', 'vital-signs.correct',
            'consultation.view', 'consultation.create', 'consultation.update', 'consultation.complete', 'consultation.cancel', 'consultation.correct',
            'diagnosis.view', 'diagnosis.create', 'diagnosis.update', 'diagnosis.primary', 'diagnosis.status', 'diagnosis.cancel', 'diagnosis.correct',
            'diagnosis-codes.view',
            'treatment.view', 'treatment.create', 'treatment.update', 'treatment.activate', 'treatment.complete', 'treatment.cancel', 'treatment.correct',
            'prescription.view', 'prescription.create', 'prescription.update', 'prescription.cancel',
            'medications.view',
            'clinical-notes.view', 'clinical-notes.create', 'clinical-notes.update', 'clinical-notes.sign', 'clinical-notes.amend', 'clinical-notes.cancel',
            'clinical-history.view',
            'laboratory.view', 'laboratory-orders.view', 'laboratory-orders.create', 'laboratory-orders.update', 'laboratory-orders.cancel',
            'laboratory-samples.view', 'laboratory-samples.collect', 'laboratory-samples.label', 'laboratory-samples.receive', 'laboratory-samples.reject', 'laboratory-samples.recollect', 'laboratory-samples.cancel',
            'laboratory-processing.view', 'laboratory-processing.accept', 'laboratory-processing.assign', 'laboratory-processing.start', 'laboratory-processing.complete', 'laboratory-processing.reject', 'laboratory-processing.transfer',
            'laboratory-benches.view', 'laboratory-benches.manage',
            'laboratory-results.view', 'laboratory-results.update', 'laboratory-results.submit', 'laboratory-results.amend',
            'laboratory-results.review', 'laboratory-results.verify', 'laboratory-results.authorize', 'laboratory-results.return', 'laboratory-results.amend_verified', 'laboratory-results.release',
            'laboratory-reports.view', 'laboratory-reports.print', 'laboratory-reports.download',
            'laboratory-tests.view', 'laboratory-tests.create', 'laboratory-tests.update', 'laboratory-tests.activate', 'laboratory-tests.deactivate',
            'laboratory-categories.view', 'laboratory-categories.manage',
            'radiology.view', 'radiology-orders.view', 'radiology-orders.create', 'radiology-orders.update', 'radiology-orders.cancel',
            'radiology-procedures.view', 'radiology-procedures.create', 'radiology-procedures.update', 'radiology-procedures.activate', 'radiology-procedures.deactivate',
            'radiology-scheduling.view', 'radiology-scheduling.create', 'radiology-scheduling.reschedule',
            'radiology-scheduling.confirm', 'radiology-scheduling.cancel', 'radiology-scheduling.no_show',
            'radiology-reception.view', 'radiology-reception.manage',
            'radiology-examination.view', 'radiology-examination.start', 'radiology-examination.update', 'radiology-examination.complete', 'radiology-examination.abort',
            'radiology-results.view', 'radiology-results.create', 'radiology-results.update', 'radiology-results.submit',
            'radiology-results.review', 'radiology-results.verify', 'radiology-results.return',
            'audit-logs.view',
        ],

        'doctor' => [
            'practitioners.view',
            'patients.view', 'patients.update',
            'patient-insurance.view',
            'appointments.view', 'appointments.confirm', 'appointments.checkin', 'appointments.complete', 'appointments.cancel', 'appointments.no_show',
            'clinical-encounters.view', 'clinical-encounters.start', 'clinical-encounters.complete', 'clinical-encounters.cancel',
            'triage.view', 'vital-signs.view',
            'consultation.view', 'consultation.create', 'consultation.update', 'consultation.complete', 'consultation.cancel', 'consultation.correct',
            'diagnosis.view', 'diagnosis.create', 'diagnosis.update', 'diagnosis.primary', 'diagnosis.status', 'diagnosis.cancel', 'diagnosis.correct',
            'diagnosis-codes.view',
            'treatment.view', 'treatment.create', 'treatment.update', 'treatment.activate', 'treatment.complete', 'treatment.cancel', 'treatment.correct',
            'prescription.view', 'prescription.create', 'prescription.update', 'prescription.cancel',
            'medications.view',
            'clinical-notes.view', 'clinical-notes.create', 'clinical-notes.update', 'clinical-notes.sign', 'clinical-notes.amend', 'clinical-notes.cancel',
            'clinical-history.view',
            'laboratory-orders.create', 'laboratory-orders.update', 'laboratory-orders.cancel', 'laboratory-tests.view',
            'laboratory-samples.view',
            'laboratory-processing.view',
            'laboratory-results.view',
            'laboratory-reports.view', 'laboratory-reports.print', 'laboratory-reports.download',
            'radiology-orders.create', 'radiology-orders.update', 'radiology-orders.cancel', 'radiology-procedures.view',
        ],

        'nurse' => [
            'practitioners.view',
            'patients.view', 'patients.update',
            'patient-insurance.view',
            'appointments.view', 'appointments.checkin', 'appointments.complete',
            'clinical-encounters.view', 'clinical-encounters.create', 'clinical-encounters.start', 'clinical-encounters.complete', 'clinical-encounters.cancel',
            'triage.view', 'triage.create', 'triage.update', 'triage.complete', 'triage.cancel',
            'vital-signs.view', 'vital-signs.create', 'vital-signs.correct',
            'clinical-notes.view', 'clinical-notes.create', 'clinical-notes.update', 'clinical-notes.sign', 'clinical-notes.amend', 'clinical-notes.cancel',
            'clinical-history.view',
            'laboratory-samples.view', 'laboratory-samples.collect', 'laboratory-samples.label', 'laboratory-samples.recollect', 'laboratory-samples.cancel',
        ],

        'pharmacist' => [
            'practitioners.view',
            'patients.view',
        ],

        // View access to the radiology worklist/orders (Step 36) plus full
        // scheduling (Step 39), reception/preparation (Step 40), and
        // examination (Step 41) ability — the incremental growth this
        // role's own doc-comment always anticipated. This role performs the
        // operational/technical work; interpreting the resulting images
        // (Step 42) is a distinct clinical role — see 'radiologist' below.
        'radiology-technician' => [
            'practitioners.view',
            'patients.view',
            'radiology.view', 'radiology-orders.view', 'radiology-procedures.view',
            'radiology-scheduling.view', 'radiology-scheduling.create', 'radiology-scheduling.reschedule',
            'radiology-scheduling.confirm', 'radiology-scheduling.cancel', 'radiology-scheduling.no_show',
            'radiology-reception.view', 'radiology-reception.manage',
            'radiology-examination.view', 'radiology-examination.start', 'radiology-examination.update', 'radiology-examination.complete', 'radiology-examination.abort',
        ],

        // The clinical reporting role (Phase 5, Step 42) — interprets
        // completed examinations and authors findings/impression. Kept
        // deliberately separate from both 'doctor' (which only orders
        // imaging, never interprets it) and 'radiology-technician' (which
        // performs the operational/technical work, never the clinical
        // interpretation), mirroring the same "doctor orders, specialist
        // reports" separation Laboratory has between 'doctor' and
        // 'laboratory-supervisor'.
        'radiologist' => [
            'practitioners.view',
            'patients.view',
            'radiology.view', 'radiology-orders.view', 'radiology-procedures.view',
            'radiology-results.view', 'radiology-results.create', 'radiology-results.update', 'radiology-results.submit',
            'radiology-results.review', 'radiology-results.verify', 'radiology-results.return',
        ],

        'laboratory-technician' => [
            'practitioners.view',
            'patients.view',
            'laboratory.view', 'laboratory-orders.view', 'laboratory-tests.view',
            'laboratory-samples.view', 'laboratory-samples.collect', 'laboratory-samples.label', 'laboratory-samples.receive', 'laboratory-samples.reject', 'laboratory-samples.recollect', 'laboratory-samples.cancel',
            'laboratory-processing.view', 'laboratory-processing.accept', 'laboratory-processing.assign', 'laboratory-processing.start', 'laboratory-processing.complete', 'laboratory-processing.reject', 'laboratory-processing.transfer',
            'laboratory-benches.view',
            'laboratory-results.view', 'laboratory-results.update', 'laboratory-results.submit', 'laboratory-results.amend',
            'laboratory-reports.view',
        ],

        'laboratory-supervisor' => [
            'practitioners.view',
            'patients.view',
            'laboratory.view', 'laboratory-orders.view', 'laboratory-tests.view',
            'laboratory-samples.view', 'laboratory-samples.collect', 'laboratory-samples.label', 'laboratory-samples.receive', 'laboratory-samples.reject', 'laboratory-samples.recollect', 'laboratory-samples.cancel',
            'laboratory-processing.view', 'laboratory-processing.accept', 'laboratory-processing.assign', 'laboratory-processing.start', 'laboratory-processing.complete', 'laboratory-processing.reject', 'laboratory-processing.transfer',
            'laboratory-benches.view',
            'laboratory-results.view', 'laboratory-results.update', 'laboratory-results.submit', 'laboratory-results.amend',
            'laboratory-results.review', 'laboratory-results.verify', 'laboratory-results.authorize', 'laboratory-results.return', 'laboratory-results.amend_verified', 'laboratory-results.release',
            'laboratory-reports.view', 'laboratory-reports.print', 'laboratory-reports.download',
        ],

        'receptionist' => [
            'patients.view', 'patients.create', 'patients.update',
            'patient-insurance.view', 'patient-insurance.create', 'patient-insurance.update', 'patient-insurance.verify',
            'appointments.view', 'appointments.create', 'appointments.update', 'appointments.confirm', 'appointments.reschedule', 'appointments.cancel', 'appointments.checkin', 'appointments.no_show',
            'reception.view', 'reception.dashboard', 'reception.checkin', 'reception.create_walkin', 'reception.update', 'reception.cancel', 'reception.complete',
        ],

        'accountant' => [
            'patient-insurance.view',
        ],
    ];

    /**
     * Seed the application's roles and assign their permissions.
     *
     * Uses findOrCreate() and syncPermissions() so the seeder is idempotent
     * and can be run repeatedly without creating duplicates or drifting from
     * the role/permission matrix defined above.
     */
    public function run(): void
    {
        foreach ($this->roles as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');

            $role->syncPermissions($permissions === '*' ? Permission::all() : $permissions);
        }
    }
}
