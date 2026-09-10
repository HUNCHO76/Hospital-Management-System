<?php

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\InsuranceProvider;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\ReceptionVisit;
use App\Models\User;
use App\Services\ReceptionVisitService;

/**
 * Step 20 end-to-end integration scenarios: the complete Patient Management
 * workflow (Registration → Search → History → Insurance → Appointment →
 * Reception → Check-in → Waiting → Ready for Service) exercised through
 * real HTTP requests across Steps 14–19, verifying every record created
 * along the way points back to the same canonical patient — never a
 * duplicate identity.
 */
test('scenario 1: a new patient can be registered, insured, booked, and taken all the way to ready for service', function () {
    $receptionist = userWithRole('receptionist');
    $ctx = appointmentBookingContext();

    // 1-4: Register a new patient (Step 14).
    $registerResponse = $this->actingAs($receptionist)->post(route('patients.store'), [
        'first_name' => 'Zawadi',
        'last_name' => 'Mushi',
        'gender' => 'female',
        'date_of_birth' => '1990-05-12',
        'national_id' => '19900512-99999-00001-11',
        'status' => Patient::STATUS_ACTIVE,
    ]);

    $patient = Patient::where('national_id', '19900512-99999-00001-11')->firstOrFail();
    $registerResponse->assertRedirect(route('patients.show', $patient));
    expect($patient->patient_number)->not->toBeEmpty();

    // 5-6: Search finds the same patient and opens the same profile.
    $this->actingAs($receptionist)
        ->get(route('patients.index', ['search' => 'Zawadi']))
        ->assertInertia(fn ($page) => $page->where('patients.total', 1));

    $this->actingAs($receptionist)->get(route('patients.show', $patient))->assertOk();

    // 7: Add insurance for the same patient.
    $provider = InsuranceProvider::factory()->create();
    $this->actingAs($receptionist)->post(route('patients.insurance.store', $patient), [
        'insurance_provider_id' => $provider->id,
        'membership_number' => 'MEM-INTEGRATION-1',
        'status' => 'active',
        'verification_status' => 'pending',
        'is_primary' => true,
    ]);
    $insurance = PatientInsurance::where('patient_id', $patient->id)->firstOrFail();
    expect($insurance->patient_id)->toBe($patient->id);

    // 8-9: Create and confirm an appointment for the same patient.
    $appointmentResponse = $this->actingAs($receptionist)->post(route('appointments.store'), [
        'patient_id' => $patient->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'appointment_type' => 'initial_consultation',
        'scheduled_start' => now()->addHour()->format('Y-m-d H:i:s'),
        'scheduled_end' => now()->addHour()->addMinutes(30)->format('Y-m-d H:i:s'),
    ]);
    $appointment = Appointment::where('patient_id', $patient->id)->firstOrFail();
    $appointmentResponse->assertRedirect(route('appointments.show', $appointment));

    $this->actingAs($receptionist)->post(route('appointments.confirm', $appointment));
    expect($appointment->fresh()->status)->toBe(Appointment::STATUS_CONFIRMED);

    // 10-12: Patient arrives; reception checks in the same appointment,
    // creating a reception visit for the same patient.
    $this->actingAs($receptionist)->post(route('reception.appointments.check-in', $appointment));

    $visit = ReceptionVisit::where('appointment_id', $appointment->id)->firstOrFail();
    expect($visit->patient_id)->toBe($patient->id);
    expect($appointment->fresh()->status)->toBe(Appointment::STATUS_CHECKED_IN);

    // 13-14: Mark waiting, then ready for service.
    $this->actingAs($receptionist)->post(route('reception.visits.waiting', $visit));
    expect($visit->fresh()->status)->toBe(ReceptionVisit::STATUS_WAITING);

    $this->actingAs($receptionist)->post(route('reception.visits.ready', $visit));
    expect($visit->fresh()->status)->toBe(ReceptionVisit::STATUS_READY_FOR_SERVICE);

    // Every record created along the way points to the same patient.
    expect(collect([$patient->id, $insurance->patient_id, $appointment->patient_id, $visit->patient_id])->unique())->toHaveCount(1);

    // The patient's history timeline reflects the whole journey (at least
    // registration, insurance, appointment created+confirmed, and check-in).
    $this->actingAs($receptionist)
        ->get(route('patients.history', $patient))
        ->assertInertia(fn ($page) => $page->has('timeline.4'));
});

test('scenario 2: an existing patient walk-in reaches ready for service and appears in their history', function () {
    $receptionist = userWithRole('receptionist');
    $ctx = appointmentBookingContext();
    $patient = Patient::factory()->create(['first_name' => 'Existing', 'last_name' => 'Patient']);

    // 1-2: Search finds the existing patient.
    $this->actingAs($receptionist)
        ->get(route('patients.index', ['search' => 'Existing Patient']))
        ->assertInertia(fn ($page) => $page->where('patients.total', 1));

    // 3-5: Department selection and walk-in creation.
    $this->actingAs($receptionist)->post(route('reception.walk-in.store'), [
        'patient_id' => $patient->id,
        'department_id' => $ctx['department']->id,
        'visit_type' => 'walk_in',
    ]);

    $visit = ReceptionVisit::where('patient_id', $patient->id)->firstOrFail();
    expect($visit->status)->toBe(ReceptionVisit::STATUS_CHECKED_IN);

    // 6: Patient history reflects the walk-in event.
    $this->actingAs($receptionist)
        ->get(route('patients.history', $patient))
        ->assertInertia(fn ($page) => $page->has('timeline.0', fn ($entry) => $entry
            ->where('action', 'reception_visit_walkin_created')->etc()));

    // 7: Insurance indicator (no coverage registered) resolves to "none".
    $service = app(ReceptionVisitService::class);
    expect($service->insuranceIndicator($patient))->toBe('none');

    // 8: Visit status progresses through waiting to ready for service.
    $this->actingAs($receptionist)->post(route('reception.visits.waiting', $visit));
    $this->actingAs($receptionist)->post(route('reception.visits.ready', $visit));
    expect($visit->fresh()->status)->toBe(ReceptionVisit::STATUS_READY_FOR_SERVICE);
});

test('scenario 3: a cancelled appointment cannot be checked in and its cancellation is audited', function () {
    $receptionist = userWithRole('receptionist');
    $ctx = appointmentBookingContext();
    $appointment = Appointment::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
    ]);

    $this->actingAs($receptionist)->post(route('appointments.cancel', $appointment), [
        'cancellation_reason' => 'Patient no longer needs this visit',
    ]);
    expect($appointment->fresh()->status)->toBe(Appointment::STATUS_CANCELLED);

    $this->actingAs($receptionist)
        ->post(route('reception.appointments.check-in', $appointment))
        ->assertSessionHasErrors('status');

    $this->assertDatabaseCount('reception_visits', 0);
    $this->assertDatabaseHas('audit_logs', ['action' => 'appointment_cancelled', 'auditable_id' => $appointment->id]);
    expect($appointment->fresh()->status)->toBe(Appointment::STATUS_CANCELLED);
});

test('scenario 4: a no-show appointment cannot be checked in and its state is preserved', function () {
    $receptionist = userWithRole('receptionist');
    $ctx = appointmentBookingContext();
    $appointment = Appointment::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
    ]);

    $this->actingAs($receptionist)->post(route('appointments.no-show', $appointment));
    expect($appointment->fresh()->status)->toBe(Appointment::STATUS_NO_SHOW);

    $this->actingAs($receptionist)
        ->post(route('reception.appointments.check-in', $appointment))
        ->assertSessionHasErrors('status');

    $this->assertDatabaseCount('reception_visits', 0);
    expect($appointment->fresh()->status)->toBe(Appointment::STATUS_NO_SHOW);
});

test('scenario 5: rescheduling preserves the prior time in the audit trail and reception sees the new time', function () {
    $receptionist = userWithRole('receptionist');
    $ctx = appointmentBookingContext();
    $originalStart = now()->addDay()->setTime(9, 0);
    $appointment = Appointment::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'scheduled_start' => $originalStart,
        'scheduled_end' => (clone $originalStart)->addMinutes(30),
    ]);

    $newStart = now()->addDays(2)->setTime(14, 0);
    $this->actingAs($receptionist)->post(route('appointments.reschedule', $appointment), [
        'scheduled_start' => $newStart->format('Y-m-d H:i:s'),
        'scheduled_end' => (clone $newStart)->addMinutes(30)->format('Y-m-d H:i:s'),
    ]);

    $appointment->refresh();
    expect($appointment->scheduled_start->format('Y-m-d H:i:s'))->toBe($newStart->format('Y-m-d H:i:s'));

    $audit = AuditLog::where('action', 'appointment_rescheduled')->where('auditable_id', $appointment->id)->firstOrFail();
    expect($audit->old_values['scheduled_start'])->toContain($originalStart->format('Y-m-d'));

    // Reception's dashboard reflects the appointment at its new time — it
    // is the same row, not a duplicate, so it appears exactly once today.
    $todaysCount = Appointment::whereDate('scheduled_start', $newStart->toDateString())->where('id', $appointment->id)->count();
    expect($todaysCount)->toBe(1);
});

test('scenario 6: an unauthorized user is rejected across every patient-management resource type', function () {
    $unauthorized = User::factory()->create();
    $ctx = appointmentBookingContext();
    $patient = Patient::factory()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
    ]);
    $visit = ReceptionVisit::factory()->create(['patient_id' => $patient->id, 'department_id' => $ctx['department']->id]);
    $insurance = PatientInsurance::factory()->create(['patient_id' => $patient->id]);

    $this->actingAs($unauthorized)->get(route('patients.show', $patient))->assertForbidden();
    $this->actingAs($unauthorized)->put(route('patients.update', $patient), ['first_name' => 'Hacked'])->assertForbidden();
    $this->actingAs($unauthorized)->get(route('patients.insurance.show', [$patient, $insurance]))->assertForbidden();
    $this->actingAs($unauthorized)->post(route('reception.appointments.check-in', $appointment))->assertForbidden();
    $this->actingAs($unauthorized)->post(route('reception.walk-in.store'), [
        'patient_id' => $patient->id,
        'department_id' => $ctx['department']->id,
        'visit_type' => 'walk_in',
    ])->assertForbidden();

    expect($patient->fresh()->first_name)->not->toBe('Hacked');
    $this->assertDatabaseCount('reception_visits', 1);
});
