<?php

use App\Models\LaboratorySample;
use App\Models\NhifPreApprovalSession;
use App\Models\NhifPreApprovalSessionItem;
use App\Models\PatientInsurance;
use App\Services\LaboratorySampleService;
use App\Services\NHIF\NhifOrderPreApprovalService;
use Illuminate\Validation\ValidationException;

test('laboratory sample collection is blocked with request sent but not issued when NHIF pre-approval is unissued', function () {
    $cbc = activeLaboratoryTest(['code' => 'CBC', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube']);
    $ctx = laboratoryOrderReadyForSampling([$cbc]);

    // Give the patient active NHIF insurance
    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => PatientInsurance::VERIFICATION_VERIFIED,
        'nhif_scheme_id' => 101,
        'nhif_authorization_no' => '201627864221',
        'card_number' => '1234567890',
        'nhif_facility_code' => $ctx['hospital']->code,
        'verified_at' => now(),
    ]);

    $order = $ctx['order'];
    $item = $order->items->sole();

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
        'source_type' => NhifPreApprovalSessionItem::SOURCE_LAB,
        'source_id' => $item->id,
        'nhif_item_code' => '20001',
        'nhif_item_name' => 'Complete Blood Count',
        'quantity' => 1,
        'unit_price' => 10000.0,
        'approval_status' => NhifPreApprovalSessionItem::APPROVAL_STATUS_ACCEPTED, // Sent but NOT issued
    ]);

    $preApprovalService = app(NhifOrderPreApprovalService::class);
    $status = $preApprovalService->statusForLaboratoryOrder($order);

    expect($status)->not->toBeNull()
        ->and($status['status'])->toBe(NhifOrderPreApprovalService::STATUS_SENT_NOT_ISSUED)
        ->and($status['label'])->toBe('Request sent but not issued')
        ->and($status['blocked'])->toBeTrue();

    $request = requestAs($ctx['doctor']['user']);

    // Attempting to register sample must fail with ValidationException
    expect(fn () => app(LaboratorySampleService::class)->create($order, [
        'item_ids' => [$item->id],
        'specimen_type' => 'whole_blood',
        'container_type' => 'edta_tube',
    ], $request))->toThrow(ValidationException::class);

    // Verify index reflects nhif_sent_not_issued
    $nurse = userWithRole('nurse');
    $response = $this->actingAs($nurse)->get(route('laboratory.collection.index'));
    $response->assertOk();
    $ordersData = $response->viewData('page')['props']['orders']['data'];
    $orderRow = collect($ordersData)->firstWhere('id', $order->id);
    expect($orderRow['financial_status'])->toBe('nhif_sent_not_issued');

    // Once issued, specimen registration succeeds
    $sessionItem->update(['approval_status' => NhifPreApprovalSessionItem::APPROVAL_STATUS_ISSUED]);
    $session->update(['status' => NhifPreApprovalSession::STATUS_ISSUED]);

    $statusIssued = $preApprovalService->statusForLaboratoryOrder($order->fresh('items'));
    expect($statusIssued['status'])->toBe('authorized')
        ->and($statusIssued['blocked'])->toBeFalse();

    $sample = app(LaboratorySampleService::class)->create($order->fresh(), [
        'item_ids' => [$item->id],
        'specimen_type' => 'whole_blood',
        'container_type' => 'edta_tube',
    ], $request);

    expect($sample)->toBeInstanceOf(LaboratorySample::class)
        ->and($sample->status)->toBe(LaboratorySample::STATUS_PENDING_COLLECTION);
});
