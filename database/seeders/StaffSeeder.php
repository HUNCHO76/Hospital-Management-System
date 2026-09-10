<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Hospital;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Seeder;

class StaffSeeder extends Seeder
{
    /**
     * The sample staff to seed for the main hospital.
     *
     * Each entry maps to a department code, job title, employment type, and Spatie role.
     *
     * @var list<array{first_name: string, last_name: string, gender: string, department: string, job_title: string, employment_type: string, role: string}>
     */
    protected array $staff = [
        ['first_name' => 'Amina', 'last_name' => 'Juma', 'gender' => 'female', 'department' => 'OPD', 'job_title' => 'Doctor', 'employment_type' => 'full_time', 'role' => 'doctor'],
        ['first_name' => 'Baraka', 'last_name' => 'Mushi', 'gender' => 'male', 'department' => 'INPATIENT', 'job_title' => 'Nurse', 'employment_type' => 'full_time', 'role' => 'nurse'],
        ['first_name' => 'Catherine', 'last_name' => 'Mwakalinga', 'gender' => 'female', 'department' => 'PHARMACY', 'job_title' => 'Pharmacist', 'employment_type' => 'full_time', 'role' => 'pharmacist'],
        ['first_name' => 'David', 'last_name' => 'Kessy', 'gender' => 'male', 'department' => 'LAB', 'job_title' => 'Laboratory Technician', 'employment_type' => 'full_time', 'role' => 'laboratory-technician'],
        ['first_name' => 'Edith', 'last_name' => 'Massawe', 'gender' => 'female', 'department' => 'OPD', 'job_title' => 'Receptionist', 'employment_type' => 'full_time', 'role' => 'receptionist'],
        ['first_name' => 'Frank', 'last_name' => 'Ngowi', 'gender' => 'male', 'department' => null, 'job_title' => 'Accountant', 'employment_type' => 'full_time', 'role' => 'accountant'],
        ['first_name' => 'Grace', 'last_name' => 'Temba', 'gender' => 'female', 'department' => null, 'job_title' => 'IT Officer', 'employment_type' => 'full_time', 'role' => null],
    ];

    /**
     * Seed sample staff for the main hospital, each with a linked user account and Spatie role.
     */
    public function run(): void
    {
        $hospital = Hospital::query()->where('code', 'MAIN')->firstOrFail();

        foreach ($this->staff as $entry) {
            $email = strtolower($entry['first_name'].'.'.$entry['last_name']).'@mainhospital.test';

            $user = User::query()->firstOrCreate(
                ['email' => $email],
                ['UserName' => "{$entry['first_name']} {$entry['last_name']}", 'password' => 'password']
            );

            if ($entry['role']) {
                $user->assignRole($entry['role']);
            }

            $department = $entry['department']
                ? Department::query()->where('hospital_id', $hospital->id)->where('code', $entry['department'])->first()
                : null;

            Staff::query()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'hospital_id' => $hospital->id,
                    'department_id' => $department?->id,
                    'first_name' => $entry['first_name'],
                    'last_name' => $entry['last_name'],
                    'gender' => $entry['gender'],
                    'job_title' => $entry['job_title'],
                    'employment_type' => $entry['employment_type'],
                    'status' => 'active',
                    'hired_at' => now()->subYear(),
                ]
            );
        }
    }
}
