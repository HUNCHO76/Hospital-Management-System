<?php

use App\Models\NhifPreApprovalSession;
use App\Models\NhifPreApprovalSessionItem;
use App\Models\PatientInsurance;
use App\Models\PrescriptionVerification;
use App\Services\DispensingService;
use App\Services\NHIF\NhifOrderPreApprovalService;
use App\Services\PrescriptionOrderService;
use Illuminate\Validation\ValidationException;

test('pharmacy dispensing start is blocked with request sent but not issued when NHIF pre-approval is unissued', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication(['nhif_code' => 'MED-001']);
    receiveStock($medication, $ctx['hospital']);

    // Give patient active NHIF insurance
    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => PatientInsurance::VERIFICATION_VERIFIED,
        'nhif_scheme_id' => 101,
        'nhif_authorization_no' => '201627864999',
        'card_number' => '9876543210',
        'nhif_facility_code' => $ctx['hospital']->code,
        'verified_at' => now(),
    ]);

    $order = app(PrescriptionOrderService::class)->create($ctx['encounter'], [
        'items' => [[
            'medication_id' => $medication->id,
            'dose' => '1 tablet',
            'frequency' => 'once_daily',
            'route' => 'oral',
            'duration' => 5,
            'duration_unit' => 'days',
            'quantity' => 5,
            'instructions' => null,
        ]],
    ], requestAs($ctx['doctor']['user']));

    $item = $order->items->sole();

    // Mark verified
    $order->verification->update([
        'status' => PrescriptionVerification::STATUS_VERIFIED,
        'verified_by' => $ctx['doctor']['user']->id,
        'verified_at' => now(),
    ]);

    // Create an NHIF Pre-Approval session with item in 'accepted' (sent to NHIF, but not issued)
    $session = NhifPreApprovalSession::create([
        'patient_id' => $ctx['patient']->id,
        'patient_insurance_id' => $insurance->id,
        'hospital_id' => $ctx['hospital']->id,
        'clinical_encounter_id' => $ctx['encounter']->id,
        'nhif_authorization_no' => $insurance->nhif_authorization_no,
        'facility_code' => $ctx['hospital']->code,
        'status' => NhifPreApprovalSession::STATUS_ACCEPTED,
        'created_by' => $ctx['doctor']['user']->id,
    ]);

    $sessionItem = NhifPreApprovalSessionItem::create([
        'nhif_pre_approval_session_id' => $session->id,
        'source_type' => NhifPreApprovalSessionItem::SOURCE_MEDICATION,
        'source_id' => $item->id,
        'nhif_item_code' => 'MED-001',
        'nhif_item_name' => 'Amoxicillin 500mg',
        'quantity' => 5,
        'unit_price' => 2000.0,
        'approval_status' => NhifPreApprovalSessionItem::APPROVAL_STATUS_ACCEPTED, // Sent but NOT issued
    ]);

    $preApprovalService = app(NhifOrderPreApprovalService::class);
    $status = $preApprovalService->statusForPrescriptionOrder($order);

    expect($status)->not->toBeNull()
        ->and($status['status'])->toBe(NhifOrderPreApprovalService::STATUS_SENT_NOT_ISSUED)
        ->and($status['label'])->toBe('Request sent but not issued')
        ->and($status['blocked'])->toBeTrue();

    $pharmacist = userWithRole('pharmacist');

    // Attempting to start dispensing must fail with ValidationException
    expect(fn () => app(DispensingService::class)->start($order->fresh(['items', 'verification']), requestAs($pharmacist)))
        ->toThrow(ValidationException::class);

    // Verify dispensing worklist reflects nhif_sent_not_issued
    $response = $this->actingAs($pharmacist)->get(route('pharmacy-dispensing.index'));
    $response->assertOk();
    $ordersData = $response->viewData('page')['props']['prescriptions']['data'];
    $orderRow = collect($ordersData)->firstWhere('id', $order->id);
    expect($orderRow['financial_status'])->toBe('nhif_sent_not_issued');

    // Once issued, dispensing start succeeds
    $sessionItem->update(['approval_status' => NhifPreApprovalSessionItem::APPROVAL_STATUS_ISSUED]);
    $session->update(['status' => NhifPreApprovalSession::STATUS_ISSUED]);

    $statusIssued = $preApprovalService->statusForPrescriptionOrder($order->fresh('items'));
    expect($statusIssued['status'])->toBe('authorized')
        ->and($statusIssued['blocked'])->toBeFalse();

    $dispensing = app(DispensingService::class)->start($order->fresh(['items', 'verification']), requestAs($pharmacist));
    expect($dispensing)->not->toBeNull();
    $this->assertDatabaseCount('dispensings', 1);
});
