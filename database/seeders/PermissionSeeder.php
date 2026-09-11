<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    /**
     * The permissions available in the system, grouped by module.
     *
     * @var array<string, list<string>>
     */
    protected array $permissions = [
        'users' => ['view', 'create', 'update', 'delete'],
        'roles' => ['view', 'create', 'update', 'delete'],
        'permissions' => ['view', 'assign'],
        'hospitals' => ['view', 'create', 'update', 'delete'],
        'departments' => ['view', 'create', 'update', 'delete'],
        'staff' => ['view', 'create', 'update', 'delete'],
        'practitioners' => ['view', 'create', 'update', 'delete'],
        'patients' => ['view', 'create', 'update', 'delete'],
        'insurance-providers' => ['view', 'create', 'update', 'delete'],
        'insurance-schemes' => ['view', 'create', 'update', 'delete'],
        'patient-insurance' => ['view', 'create', 'update', 'delete', 'verify'],
        'appointments' => ['view', 'create', 'update', 'confirm', 'reschedule', 'cancel', 'checkin', 'complete', 'no_show'],
        'reception' => ['view', 'dashboard', 'checkin', 'create_walkin', 'update', 'cancel', 'complete'],
        'clinical-encounters' => ['view', 'create', 'start', 'complete', 'cancel'],
        'triage' => ['view', 'create', 'update', 'complete', 'cancel'],
        'vital-signs' => ['view', 'create', 'correct'],
        'consultation' => ['view', 'create', 'update', 'complete', 'cancel', 'correct'],
        'diagnosis' => ['view', 'create', 'update', 'primary', 'status', 'cancel', 'correct'],
        'diagnosis-codes' => ['view'],
        'treatment' => ['view', 'create', 'update', 'activate', 'complete', 'cancel', 'correct'],
        'prescription' => ['view', 'create', 'update', 'cancel'],
        'medications' => ['view'],
        'clinical-notes' => ['view', 'create', 'update', 'sign', 'amend', 'cancel'],
        'clinical-history' => ['view'],
        'laboratory' => ['view'],
        'laboratory-orders' => ['view', 'create', 'update', 'cancel'],
        'laboratory-samples' => ['view', 'collect', 'label', 'receive', 'reject', 'recollect', 'cancel'],
        'laboratory-processing' => ['view', 'accept', 'assign', 'start', 'complete', 'reject', 'transfer'],
        'laboratory-benches' => ['view', 'manage'],
        'laboratory-results' => ['view', 'update', 'submit', 'amend', 'review', 'verify', 'authorize', 'return', 'amend_verified', 'release'],
        'laboratory-reports' => ['view', 'print', 'download'],
        'laboratory-tests' => ['view', 'create', 'update', 'activate', 'deactivate'],
        'laboratory-categories' => ['view', 'manage'],
        'radiology' => ['view'],
        'radiology-orders' => ['view', 'create', 'update', 'cancel'],
        'radiology-procedures' => ['view', 'create', 'update', 'activate', 'deactivate'],
        'radiology-scheduling' => ['view', 'create', 'reschedule', 'confirm', 'cancel', 'no_show'],
        'radiology-reception' => ['view', 'manage'],
        'radiology-examination' => ['view', 'start', 'update', 'complete', 'abort'],
        'radiology-results' => ['view', 'create', 'update', 'submit', 'review', 'verify', 'return'],
        'audit-logs' => ['view'],
    ];

    /**
     * Seed the application's permissions.
     *
     * Uses findOrCreate() so the seeder is idempotent and can be run repeatedly.
     */
    public function run(): void
    {
        foreach ($this->permissions as $module => $actions) {
            foreach ($actions as $action) {
                Permission::findOrCreate("{$module}.{$action}", 'web');
            }
        }
    }
}
