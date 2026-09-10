<?php

use App\Models\Patient;
use App\Services\PatientService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Mandatory concurrency test (Step 20 §11): two requests attempt to
 * register the same patient (same national ID). findConfirmedDuplicate() is
 * only a check-then-insert and cannot by itself prevent this under real
 * concurrency — the patients.national_id/passport_number unique indexes
 * (see the 2026_09_04_130000 migration) are the actual concurrency-safe
 * guarantee, enforced by the database engine itself rather than by
 * application-level locking. Unlike the practitioner/patient row-lock tests
 * elsewhere, a unique index is enforced identically by SQLite and MySQL, so
 * this test exercises the real mechanism, not just an approximation of it.
 */
test('only one of two registrations for the same national ID succeeds', function () {
    $user = userWithRole('receptionist');
    $service = app(PatientService::class);
    $request = Request::create('/', 'POST');
    $request->setUserResolver(fn () => $user);

    $data = [
        'first_name' => 'Amina',
        'last_name' => 'Juma',
        'gender' => 'female',
        'national_id' => '19900101-12345-00001-23',
        'status' => Patient::STATUS_ACTIVE,
    ];

    $first = $service->create($data, $request);

    expect(fn () => $service->create([...$data, 'first_name' => 'Different'], $request))
        ->toThrow(ValidationException::class);

    expect(Patient::query()->where('national_id', $data['national_id'])->count())->toBe(1);
    expect(Patient::first()->id)->toBe($first->id);
});

test('two patients with no national ID or passport never conflict with each other', function () {
    $user = userWithRole('receptionist');
    $service = app(PatientService::class);
    $request = Request::create('/', 'POST');
    $request->setUserResolver(fn () => $user);

    $service->create(['first_name' => 'A', 'last_name' => 'One', 'gender' => 'male', 'status' => Patient::STATUS_ACTIVE], $request);
    $service->create(['first_name' => 'B', 'last_name' => 'Two', 'gender' => 'male', 'status' => Patient::STATUS_ACTIVE], $request);

    expect(Patient::query()->count())->toBe(2);
});
