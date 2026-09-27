<?php

namespace Database\Factories;

use App\Models\Admission;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Admission>
 */
class AdmissionFactory extends Factory
{
    protected $model = Admission::class;

    public function definition(): array
    {
        return [
            'hospital_id' => Hospital::factory(),
            'patient_id' => Patient::factory(),
            'department_id' => null,
            'clinical_encounter_id' => null,
            'patient_insurance_id' => null,
            'practitioner_id' => null,
            'service_id' => null,
            'admission_type' => $this->faker->randomElement(Admission::ADMISSION_TYPES),
            'ward_type' => $this->faker->randomElement(['general', 'icu', 'maternity', 'pediatric']),
            'room_type' => null,
            'diagnosis_at_admission' => $this->faker->sentence(),
            'charges_per_day' => null,
            'notes' => null,
            'status' => Admission::STATUS_PENDING,
            'admitted_at' => null,
            'discharged_at' => null,
            'discharge_type' => null,
            'discharge_reason' => null,
            'cancellation_reason' => null,
            'rejection_reason' => null,
            'created_by' => User::factory(),
            'admitted_by' => null,
            'discharged_by' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'approved_by' => null,
            'approved_at' => null,
            'rejected_by' => null,
            'rejected_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => Admission::STATUS_PENDING]);
    }

    public function underReview(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => Admission::STATUS_UNDER_REVIEW,
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => Admission::STATUS_APPROVED,
            'reviewed_by' => User::factory(),
            'reviewed_at' => now()->subMinutes(5),
            'approved_by' => User::factory(),
            'approved_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => Admission::STATUS_REJECTED,
            'reviewed_by' => User::factory(),
            'reviewed_at' => now()->subMinutes(5),
            'rejected_by' => User::factory(),
            'rejected_at' => now(),
            'rejection_reason' => 'Does not meet admission criteria.',
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => Admission::STATUS_ACTIVE,
            'reviewed_by' => User::factory(),
            'reviewed_at' => now()->subHour(),
            'approved_by' => User::factory(),
            'approved_at' => now()->subMinutes(30),
            'admitted_at' => now(),
            'admitted_by' => User::factory(),
        ]);
    }

    public function discharged(): static
    {
        return $this->active()->state(fn (array $attrs) => [
            'status' => Admission::STATUS_DISCHARGED,
            'discharged_at' => now(),
            'discharged_by' => User::factory(),
            'discharge_type' => 'routine',
            'discharge_reason' => 'Patient recovered.',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state([
            'status' => Admission::STATUS_CANCELLED,
            'cancellation_reason' => 'Patient declined admission.',
        ]);
    }

    public function forHospital(Hospital $hospital): static
    {
        return $this->state(['hospital_id' => $hospital->id]);
    }

    public function forPatient(Patient $patient): static
    {
        return $this->state(['patient_id' => $patient->id]);
    }

    public function withDepartment(Department $department): static
    {
        return $this->state(['department_id' => $department->id]);
    }

    public function withPractitioner(Practitioner $practitioner): static
    {
        return $this->state(['practitioner_id' => $practitioner->id]);
    }
}
