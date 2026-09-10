<?php

use App\Models\ReceptionVisit;
use App\Services\ReceptionVisitService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Mirrors AppointmentConcurrencyTest's invariant test for the same reason:
 * the test database is SQLite in-memory, which has no FOR UPDATE clause and
 * can't host two genuinely simultaneous transactions, so this proves the
 * business invariant (never two active visits for the same patient) rather
 * than true multi-connection lock blocking, which only engages against the
 * production MySQL database. See docs/appointments.md for the same caveat.
 */
test('only one of several back-to-back walk-in attempts for the same patient succeeds', function () {
    $user = userWithRole('receptionist');
    $ctx = appointmentBookingContext();
    $service = app(ReceptionVisitService::class);
    $request = Request::create('/', 'POST');
    $request->setUserResolver(fn () => $user);

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            $service->createWalkIn([
                'patient_id' => $ctx['patient']->id,
                'department_id' => $ctx['department']->id,
                'visit_type' => 'walk_in',
            ], $request);
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
    expect(ReceptionVisit::query()->where('patient_id', $ctx['patient']->id)->count())->toBe(1);
});
