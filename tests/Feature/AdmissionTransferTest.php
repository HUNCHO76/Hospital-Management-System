<?php

/**
 * AdmissionTransferTest — Phase 9 Step 72
 *
 * Tests the admission transfer lifecycle:
 * storeTransfer(), completeTransfer(), submitTransferNhif()
 * and the HTTP routes that delegate to them.
 */

use App\Models\Admission;
use App\Models\AdmissionTransfer;
use App\Models\Hospital;
use App\Models\Staff;
use App\Services\AdmissionService;
use App\Services\NHIF\NhifHttpClient;
use Illuminate\Validation\ValidationException;

// ---------------------------------------------------------------------------
// 1. storeTransfer
// ---------------------------------------------------------------------------

test('storeTransfer creates a pending transfer for an active admission', function () {
    $ctx = activeAdmission();
    $toHospital = Hospital::factory()->create();

    $transfer = app(AdmissionService::class)->storeTransfer(
        $ctx['admission'],
        [
            'to_hospital_id' => $toHospital->id,
            'to_ward_type' => 'icu',
            'reason' => 'Patient needs intensive care',
        ],
        requestAs($ctx['user']),
    );

    expect($transfer->status)->toBe(AdmissionTransfer::STATUS_PENDING)
        ->and($transfer->from_hospital_id)->toBe($ctx['hospital']->id)
        ->and($transfer->to_hospital_id)->toBe($toHospital->id)
        ->and($transfer->from_ward_type)->toBe($ctx['admission']->ward_type)
        ->and($transfer->to_ward_type)->toBe('icu')
        ->and($transfer->requested_by)->toBe($ctx['user']->id)
        ->and($transfer->nhif_status)->toBe(AdmissionTransfer::NHIF_STATUS_PENDING);
});

test('storeTransfer throws for a non-active admission', function () {
    $hospital = Hospital::factory()->create();
    $admission = Admission::factory()->create(['hospital_id' => $hospital->id, 'status' => Admission::STATUS_PENDING]);
    $toHospital = Hospital::factory()->create();
    $user = userWithRole('nurse');

    expect(fn () => app(AdmissionService::class)->storeTransfer(
        $admission,
        ['to_hospital_id' => $toHospital->id, 'reason' => 'Transfer'],
        requestAs($user),
    ))->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// 2. completeTransfer
// ---------------------------------------------------------------------------

test('completeTransfer marks transfer completed and updates admission ward', function () {
    $ctx = activeAdmission();
    $toHospital = Hospital::factory()->create();

    $transfer = AdmissionTransfer::factory()->create([
        'admission_id' => $ctx['admission']->id,
        'from_hospital_id' => $ctx['hospital']->id,
        'to_hospital_id' => $toHospital->id,
        'from_ward_type' => $ctx['admission']->ward_type,
        'to_ward_type' => 'maternity',
        'status' => AdmissionTransfer::STATUS_PENDING,
    ]);

    app(AdmissionService::class)->completeTransfer(
        $ctx['admission'],
        $transfer,
        requestAs($ctx['user']),
    );

    expect($transfer->fresh()->status)->toBe(AdmissionTransfer::STATUS_COMPLETED)
        ->and($transfer->fresh()->completed_by)->toBe($ctx['user']->id);

    expect($ctx['admission']->fresh()->hospital_id)->toBe($toHospital->id)
        ->and($ctx['admission']->fresh()->ward_type)->toBe('maternity');
});

test('completeTransfer throws when transfer is already completed', function () {
    $ctx = activeAdmission();
    $toHospital = Hospital::factory()->create();

    $transfer = AdmissionTransfer::factory()->completed()->create([
        'admission_id' => $ctx['admission']->id,
        'from_hospital_id' => $ctx['hospital']->id,
        'to_hospital_id' => $toHospital->id,
    ]);

    expect(fn () => app(AdmissionService::class)->completeTransfer(
        $ctx['admission'],
        $transfer,
        requestAs($ctx['user']),
    ))->toThrow(ValidationException::class);
});

test('completeTransfer throws when transfer belongs to a different admission', function () {
    $ctx = activeAdmission();
    $otherAdmission = Admission::factory()->active()->create();
    $toHospital = Hospital::factory()->create();

    $transfer = AdmissionTransfer::factory()->create([
        'admission_id' => $otherAdmission->id,
        'from_hospital_id' => $ctx['hospital']->id,
        'to_hospital_id' => $toHospital->id,
    ]);

    expect(fn () => app(AdmissionService::class)->completeTransfer(
        $ctx['admission'],
        $transfer,
        requestAs($ctx['user']),
    ))->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// 3. submitTransferNhif
// ---------------------------------------------------------------------------

test('submitTransferNhif submits to NHIF and marks transfer nhif_status submitted', function () {
    $ctx = activeAdmission();
    $toHospital = Hospital::factory()->create();

    $transfer = AdmissionTransfer::factory()->create([
        'admission_id' => $ctx['admission']->id,
        'from_hospital_id' => $ctx['hospital']->id,
        'to_hospital_id' => $toHospital->id,
        'status' => AdmissionTransfer::STATUS_PENDING,
        'nhif_status' => AdmissionTransfer::NHIF_STATUS_PENDING,
    ]);

    $this->mock(NhifHttpClient::class, fn ($mock) => $mock->shouldReceive('submitTransfer')->once()->andReturn([
        'success' => true,
        'data' => ['transferNo' => 'NHIF-TRF-001'],
        'http_status' => 200,
        'error' => null,
    ])
    );

    $updated = app(AdmissionService::class)->submitTransferNhif(
        $ctx['admission'],
        $transfer,
        requestAs($ctx['user']),
    );

    expect($updated->nhif_status)->toBe(AdmissionTransfer::NHIF_STATUS_SUBMITTED)
        ->and($updated->nhif_transfer_no)->toBe('NHIF-TRF-001');
});

test('submitTransferNhif records failure but does not throw on NHIF outage', function () {
    $ctx = activeAdmission();
    $toHospital = Hospital::factory()->create();

    $transfer = AdmissionTransfer::factory()->create([
        'admission_id' => $ctx['admission']->id,
        'from_hospital_id' => $ctx['hospital']->id,
        'to_hospital_id' => $toHospital->id,
        'nhif_status' => AdmissionTransfer::NHIF_STATUS_PENDING,
    ]);

    $this->mock(NhifHttpClient::class, fn ($mock) => $mock->shouldReceive('submitTransfer')->once()->andReturn([
        'success' => false,
        'data' => [],
        'http_status' => 503,
        'error' => 'NHIF unreachable',
    ])
    );

    $updated = app(AdmissionService::class)->submitTransferNhif(
        $ctx['admission'],
        $transfer,
        requestAs($ctx['user']),
    );

    expect($updated->nhif_status)->toBe(AdmissionTransfer::NHIF_STATUS_PENDING)
        ->and($updated->error_message)->toBe('NHIF unreachable');
});

// ---------------------------------------------------------------------------
// 4. HTTP routes
// ---------------------------------------------------------------------------

test('POST admissions/{admission}/transfers stores a transfer', function () {
    $ctx = activeAdmission();
    $toHospital = Hospital::factory()->create();

    $this->actingAs($ctx['user'])
        ->post(route('admissions.transfers.store', $ctx['admission']), [
            'to_hospital_id' => $toHospital->id,
            'reason' => 'Ward transfer for monitoring',
            'to_ward_type' => 'general',
        ])
        ->assertRedirect();

    expect(AdmissionTransfer::where('admission_id', $ctx['admission']->id)->count())->toBe(1);
});

test('POST admissions/{admission}/transfers/{transfer}/complete completes a transfer', function () {
    $ctx = activeAdmission();
    $toHospital = Hospital::factory()->create();

    $transfer = AdmissionTransfer::factory()->create([
        'admission_id' => $ctx['admission']->id,
        'from_hospital_id' => $ctx['hospital']->id,
        'to_hospital_id' => $toHospital->id,
        'status' => AdmissionTransfer::STATUS_PENDING,
    ]);

    $this->actingAs($ctx['user'])
        ->post(route('admissions.transfers.complete', [$ctx['admission'], $transfer]))
        ->assertRedirect();

    expect($transfer->fresh()->status)->toBe(AdmissionTransfer::STATUS_COMPLETED);
});

test('hospital-admin cannot transfer an admission from another hospital', function () {
    $ctx = activeAdmission();
    $otherHospital = Hospital::factory()->create();
    $toHospital = Hospital::factory()->create();
    $otherAdmin = userWithRole('hospital-admin');
    Staff::factory()->create(['user_id' => $otherAdmin->id, 'hospital_id' => $otherHospital->id]);

    $this->actingAs($otherAdmin)
        ->post(route('admissions.transfers.store', $ctx['admission']), [
            'to_hospital_id' => $toHospital->id,
            'reason' => 'Unauthorized transfer',
        ])
        ->assertForbidden();
});
