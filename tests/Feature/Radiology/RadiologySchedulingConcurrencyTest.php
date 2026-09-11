<?php

use App\Models\RadiologyAppointment;
use App\Services\RadiologySchedulingService;
use Illuminate\Validation\ValidationException;

/**
 * Mandatory concurrency tests (Part 29/37): double-booking the same
 * resource/time must never succeed twice. Mirrors
 * RadiologyOrderConcurrencyTest's own back-to-back-attempts convention —
 * this codebase's established way of proving a serialized invariant holds
 * without spinning up genuinely parallel PHP processes. Each attempt below
 * is a fully separate schedule() call/transaction, so the same
 * lock-then-check (and, for the very first booking, the `active_slot_key`
 * database unique constraint) that would defend against two truly
 * simultaneous requests is exercised for real, not simulated.
 */
test('several attempts to book the identical resource and exact start time back-to-back only let one succeed', function () {
    $ctxA = radiologyOrderReadyForScheduling();
    $ctxB = radiologyOrderReadyForScheduling();
    $ctxC = radiologyOrderReadyForScheduling();
    $service = app(RadiologySchedulingService::class);

    $start = now()->addDay()->setTime(10, 0)->startOfMinute();
    $end = (clone $start)->addMinutes(30);

    $contexts = [$ctxA, $ctxB, $ctxC];
    $successes = 0;
    $rejections = 0;

    foreach ($contexts as $ctx) {
        try {
            $service->schedule($ctx['item'], [
                'resource_name' => 'CT Scanner 1',
                'scheduled_start' => $start,
                'scheduled_end' => $end,
            ], requestAs($ctx['doctor']['user']));
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(2);
    expect(RadiologyAppointment::query()->where('resource_name', 'CT Scanner 1')->whereIn('status', RadiologyAppointment::ACTIVE_STATUSES)->count())->toBe(1);
});

test('overlapping-but-different start times for the same resource are also rejected', function () {
    $ctxA = radiologyOrderReadyForScheduling();
    $ctxB = radiologyOrderReadyForScheduling();
    $service = app(RadiologySchedulingService::class);

    $service->schedule($ctxA['item'], [
        'resource_name' => 'MRI Scanner 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 45),
    ], requestAs($ctxA['doctor']['user']));

    expect(fn () => $service->schedule($ctxB['item'], [
        'resource_name' => 'MRI Scanner 1',
        'scheduled_start' => now()->addDay()->setTime(9, 30),
        'scheduled_end' => now()->addDay()->setTime(10, 0),
    ], requestAs($ctxB['doctor']['user'])))->toThrow(ValidationException::class);
});

test('the same resource can be booked back-to-back with no overlap', function () {
    $ctxA = radiologyOrderReadyForScheduling();
    $ctxB = radiologyOrderReadyForScheduling();
    $service = app(RadiologySchedulingService::class);

    $service->schedule($ctxA['item'], [
        'resource_name' => 'X-Ray Room 5',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], requestAs($ctxA['doctor']['user']));

    $second = $service->schedule($ctxB['item'], [
        'resource_name' => 'X-Ray Room 5',
        'scheduled_start' => now()->addDay()->setTime(9, 30),
        'scheduled_end' => now()->addDay()->setTime(10, 0),
    ], requestAs($ctxB['doctor']['user']));

    expect($second->status)->toBe(RadiologyAppointment::STATUS_SCHEDULED);
    expect(RadiologyAppointment::query()->where('resource_name', 'X-Ray Room 5')->count())->toBe(2);
});

test('different resources can be booked for the identical time without conflict', function () {
    $ctxA = radiologyOrderReadyForScheduling();
    $ctxB = radiologyOrderReadyForScheduling();
    $service = app(RadiologySchedulingService::class);

    $start = now()->addDay()->setTime(10, 0);
    $end = now()->addDay()->setTime(10, 30);

    $service->schedule($ctxA['item'], ['resource_name' => 'X-Ray Room 1', 'scheduled_start' => $start, 'scheduled_end' => $end], requestAs($ctxA['doctor']['user']));
    $second = $service->schedule($ctxB['item'], ['resource_name' => 'X-Ray Room 2', 'scheduled_start' => $start, 'scheduled_end' => $end], requestAs($ctxB['doctor']['user']));

    expect($second->status)->toBe(RadiologyAppointment::STATUS_SCHEDULED);
});
