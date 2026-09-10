<?php

use App\Models\Appointment;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\ReceptionVisit;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('the dashboard reports correct today counts', function () {
    $user = userWithRole('super-admin');
    $ctx = appointmentBookingContext();

    Appointment::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'scheduled_start' => now()->addHour(),
        'scheduled_end' => now()->addHour()->addMinutes(30),
        'status' => Appointment::STATUS_NO_SHOW,
    ]);
    Appointment::factory()->create([
        'patient_id' => Patient::factory()->create()->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'scheduled_start' => now()->addHours(2),
        'scheduled_end' => now()->addHours(2)->addMinutes(30),
        'status' => Appointment::STATUS_SCHEDULED,
    ]);

    ReceptionVisit::factory()->create([
        'patient_id' => Patient::factory()->create()->id,
        'department_id' => $ctx['department']->id,
        'status' => ReceptionVisit::STATUS_WAITING,
    ]);
    ReceptionVisit::factory()->create([
        'patient_id' => Patient::factory()->create()->id,
        'department_id' => $ctx['department']->id,
        'status' => ReceptionVisit::STATUS_COMPLETED,
        'completed_at' => now(),
    ]);

    $response = $this->actingAs($user)->get(route('reception.dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('summary.total_appointments', 2)
        ->where('summary.no_show', 1)
        ->where('summary.waiting', 1)
        ->where('summary.completed', 1)
    );
});

test('a hospital-admin only sees their own hospital on the dashboard', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $ctxA = appointmentBookingContext($hospitalA);
    $ctxB = appointmentBookingContext($hospitalB);

    Appointment::factory()->create([
        'patient_id' => $ctxA['patient']->id,
        'department_id' => $ctxA['department']->id,
        'practitioner_id' => $ctxA['practitioner']->id,
        'scheduled_start' => now()->addHour(),
        'scheduled_end' => now()->addHour()->addMinutes(30),
    ]);
    Appointment::factory()->create([
        'patient_id' => $ctxB['patient']->id,
        'department_id' => $ctxB['department']->id,
        'practitioner_id' => $ctxB['practitioner']->id,
        'scheduled_start' => now()->addHour(),
        'scheduled_end' => now()->addHour()->addMinutes(30),
    ]);

    $adminUser = User::factory()->create();
    $adminUser->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminUser->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminUser)
        ->get(route('reception.dashboard'))
        ->assertInertia(fn ($page) => $page->where('summary.total_appointments', 1));
});

test('the dashboard does not trigger an N+1 query explosion', function () {
    $user = userWithRole('super-admin');

    foreach (range(1, 8) as $i) {
        $ctx = appointmentBookingContext();
        Appointment::factory()->create([
            'patient_id' => $ctx['patient']->id,
            'department_id' => $ctx['department']->id,
            'practitioner_id' => $ctx['practitioner']->id,
            'scheduled_start' => now()->addHour(),
            'scheduled_end' => now()->addHour()->addMinutes(30),
        ]);
        ReceptionVisit::factory()->create([
            'patient_id' => Patient::factory()->create()->id,
            'department_id' => $ctx['department']->id,
            'visit_type' => 'walk_in',
        ]);
    }

    DB::enableQueryLog();
    $this->actingAs($user)->get(route('reception.dashboard'))->assertOk();
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    // A handful of fixed queries (auth/session/permissions + the small,
    // constant set the dashboard itself runs) regardless of how many
    // appointments/visits exist today — proves eager loading is working
    // rather than growing per row.
    expect($queryCount)->toBeLessThan(25);
});
