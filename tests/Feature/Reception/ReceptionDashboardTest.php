<?php

use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\ReceptionVisit;
use App\Models\Staff;
use App\Models\User;
use App\Services\ReceptionVisitService;
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
        hospitalWithDefaultConsultationService($ctx['hospital'], ['amount' => 5000]);

        $appointment = Appointment::factory()->create([
            'patient_id' => $ctx['patient']->id,
            'department_id' => $ctx['department']->id,
            'practitioner_id' => $ctx['practitioner']->id,
            'scheduled_start' => now()->addHour(),
            'scheduled_end' => now()->addHour()->addMinutes(30),
        ]);
        ReceptionVisit::factory()->create([
            'patient_id' => $ctx['patient']->id,
            'department_id' => $ctx['department']->id,
            'appointment_id' => $appointment->id,
            'visit_type' => 'appointment',
        ]);

        app(ReceptionVisitService::class)->createWalkIn([
            'patient_id' => Patient::factory()->create()->id,
            'department_id' => $ctx['department']->id,
            'visit_type' => 'walk_in',
            'practitioner_id' => $ctx['practitioner']->id,
        ], requestAs($user));
    }

    DB::enableQueryLog();
    $this->actingAs($user)->get(route('reception.dashboard'))->assertOk();
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    // A handful of fixed queries (auth/session/permissions + the small,
    // constant set the dashboard itself runs, including a few grouped-sum
    // queries per statusesForSources() call for financial status) regardless
    // of how many appointments/visits exist today — proves eager loading and
    // the batched financial_status/patient_account_id lookups are working
    // rather than growing per row, even when every row has a priced,
    // charged consultation. The threshold is comfortably above the fixed
    // baseline (~29 with charges/invoices/payments in play) but would still
    // catch a true N+1 regression, which scales into the hundreds at 8 rows.
    expect($queryCount)->toBeLessThan(40);
});

test('the dashboard reports financial status per row for appointments and walk-ins', function () {
    $user = userWithRole('super-admin');
    $ctx = appointmentBookingContext();
    hospitalWithDefaultConsultationService($ctx['hospital'], ['amount' => 7000]);

    $paidVisit = app(ReceptionVisitService::class)->createWalkIn([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'visit_type' => 'walk_in',
        'practitioner_id' => $ctx['practitioner']->id,
    ], requestAs($user));

    $charge = Charge::query()->where('source_type', ReceptionVisit::class)->where('source_id', $paidVisit->id)->sole();
    paymentFor($charge->invoice, ['amount' => 7000]);

    $unpaidPatient = Patient::factory()->create();
    $unpaidVisit = app(ReceptionVisitService::class)->createWalkIn([
        'patient_id' => $unpaidPatient->id,
        'department_id' => $ctx['department']->id,
        'visit_type' => 'walk_in',
        'practitioner_id' => $ctx['practitioner']->id,
    ], requestAs($user));

    $data = app(ReceptionVisitService::class)->dashboardData($ctx['hospital']->id);

    $paidRow = $data['todaysWalkIns']->firstWhere('id', $paidVisit->id);
    $unpaidRow = $data['todaysWalkIns']->firstWhere('id', $unpaidVisit->id);

    expect($paidRow->financial_status)->toBe('authorized');
    expect($unpaidRow->financial_status)->toBe('payment_required');
    expect($unpaidRow->patient_account_id)->not->toBeNull();
});
