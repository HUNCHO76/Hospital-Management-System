<?php

use App\Exceptions\AppointmentConflictException;
use App\Models\Appointment;
use App\Models\Patient;
use App\Services\AppointmentService;
use Illuminate\Http\Request;

/**
 * Mandatory concurrency test (spec §48): two requests for the same
 * practitioner/time slot must never both succeed.
 *
 * The test suite runs against a single in-memory SQLite connection
 * (phpunit.xml), which cannot host two genuinely simultaneous transactions —
 * and Laravel's SQLite grammar has no FOR UPDATE clause at all, so
 * lockForUpdate() is a no-op there. What this test *can* and does prove is
 * the invariant AppointmentService::create() guarantees: even when a second
 * caller's business logic runs immediately after the first without any
 * externally-visible gap (i.e. no window to re-check "is this still free?"
 * from outside the service), the second call still detects the first's
 * committed row and refuses to create an overlapping appointment — because
 * the conflict re-check happens *inside* the same transaction as the lock
 * and the insert, not before it. Under the production database (MySQL,
 * where lockForUpdate() takes a real row lock), that same ordering is what
 * serializes two genuinely concurrent requests instead of merely two
 * sequential ones.
 */
test('only one of two requests for the same practitioner and time slot succeeds', function () {
    $user = userWithRole('receptionist');
    $ctx = appointmentBookingContext();
    $service = app(AppointmentService::class);
    $request = Request::create('/', 'POST');
    $request->setUserResolver(fn () => $user);

    $start = now()->addDay()->setTime(10, 0);
    $end = (clone $start)->addMinutes(30);

    $dataA = [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'appointment_type' => 'initial_consultation',
        'scheduled_start' => $start,
        'scheduled_end' => $end,
    ];

    $dataB = [
        ...$dataA,
        'patient_id' => Patient::factory()->create()->id,
    ];

    $created = $service->create($dataA, $request);

    expect(fn () => $service->create($dataB, $request))
        ->toThrow(AppointmentConflictException::class);

    expect(Appointment::query()->where('practitioner_id', $ctx['practitioner']->id)->count())->toBe(1);
    expect(Appointment::first()->id)->toBe($created->id);
});

test('repeated attempts against the same slot never produce more than one active appointment', function () {
    $user = userWithRole('receptionist');
    $ctx = appointmentBookingContext();
    $service = app(AppointmentService::class);
    $request = Request::create('/', 'POST');
    $request->setUserResolver(fn () => $user);

    $start = now()->addDay()->setTime(14, 0);
    $end = (clone $start)->addMinutes(30);

    $successes = 0;
    $conflicts = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            $service->create([
                'patient_id' => Patient::factory()->create()->id,
                'department_id' => $ctx['department']->id,
                'practitioner_id' => $ctx['practitioner']->id,
                'appointment_type' => 'initial_consultation',
                'scheduled_start' => $start,
                'scheduled_end' => $end,
            ], $request);
            $successes++;
        } catch (AppointmentConflictException) {
            $conflicts++;
        }
    }

    expect($successes)->toBe(1);
    expect($conflicts)->toBe(4);
    expect(Appointment::query()->where('practitioner_id', $ctx['practitioner']->id)->count())->toBe(1);
});
