<?php

/**
 * NhifConsultationFinancialStatusTest
 *
 * Validates the NHIF-aware consultation financial-status flow:
 *   - CASH patients go through standard payment gate
 *   - NHIF accepted → authorized (no cash required)
 *   - NHIF pending  → nhif_pending (blocked)
 *   - NHIF rejected → nhif_rejected (blocked)
 *   - Backend sendToService enforces the gate
 *   - Dashboard batch computation is NHIF-aware
 */

use App\Models\Charge;
use App\Models\ChargeInsuranceAllocation;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\NhifPreApprovalSession;
use App\Models\NhifPreApprovalSessionItem;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\PatientInsurance;
use App\Models\Practitioner;
use App\Models\ReceptionVisit;
use App\Models\Service;
use App\Models\Staff;
use App\Services\ReceptionVisitService;
use Illuminate\Validation\ValidationException;

// ---------------------------------------------------------------------------
// Shared setup
// ---------------------------------------------------------------------------

function financialStatusContext(array $overrides = []): array
{
    $hospital = Hospital::factory()->create();
    $department = Department::factory()->create(['hospital_id' => $hospital->id]);
    $service = Service::factory()->create(['category' => 'consultation']);

    $user = userWithRole('receptionist');
    Staff::factory()->create(['user_id' => $user->id, 'hospital_id' => $hospital->id]);

    $patient = Patient::factory()->create();
    $practitioner = Practitioner::factory()->create(['consultation_service_id' => $service->id]);

    $visit = ReceptionVisit::factory()->create([
        'patient_id' => $patient->id,
        'department_id' => $department->id,
        'practitioner_id' => $practitioner->id,
        'status' => $overrides['visit_status'] ?? ReceptionVisit::STATUS_READY_FOR_SERVICE,
    ]);

    $account = PatientAccount::factory()->create([
        'patient_id' => $patient->id,
        'hospital_id' => $hospital->id,
    ]);

    $invoice = Invoice::factory()->create([
        'patient_account_id' => $account->id,
        'status' => Invoice::STATUS_POSTED,
    ]);

    $charge = Charge::factory()->create([
        'source_type' => ReceptionVisit::class,
        'source_id' => $visit->id,
        'service_id' => $service->id,
        'hospital_id' => $hospital->id,
        'patient_id' => $patient->id,
        'status' => Charge::STATUS_POSTED,
        'unit_price' => 5000,
        'net_amount' => 5000,
        'currency' => 'TZS',
        'invoice_id' => $invoice->id,
    ]);

    return compact('hospital', 'department', 'service', 'patient', 'practitioner', 'visit', 'account', 'invoice', 'charge', 'user');
}

function createNhifPreApproval(array $ctx, string $approvalStatus, ?string $rejectionReason = null): NhifPreApprovalSessionItem
{
    $session = NhifPreApprovalSession::factory()->create([
        'patient_insurance_id' => PatientInsurance::factory()->create([
            'patient_id' => $ctx['patient']->id,
            'status' => PatientInsurance::STATUS_ACTIVE,
            'is_primary' => true,
        ])->id,
        'status' => NhifPreApprovalSession::STATUS_SUBMITTED,
        'nhif_request_no' => 'REQ-'.fake()->unique()->numberBetween(1000, 9999),
    ]);

    return NhifPreApprovalSessionItem::factory()->create([
        'nhif_pre_approval_session_id' => $session->id,
        'source_type' => ReceptionVisit::class,
        'source_id' => $ctx['visit']->id,
        'approval_status' => $approvalStatus,
        'rejection_reason' => $rejectionReason,
    ]);
}

// ---------------------------------------------------------------------------
// 1. Cash visit — unpaid charge → payment_required
// ---------------------------------------------------------------------------

test('cash visit with unpaid charge returns payment_required', function () {
    $ctx = financialStatusContext();

    $status = app(ReceptionVisitService::class)->consultationAccessStatus($ctx['visit']->id);

    expect($status)->toBe('payment_required');
});

// ---------------------------------------------------------------------------
// 2. Cash visit — paid charge → authorized
// ---------------------------------------------------------------------------

test('cash visit with fully paid charge returns authorized', function () {
    $ctx = financialStatusContext();

    ChargeInsuranceAllocation::factory()->create([
        'charge_id' => $ctx['charge']->id,
        'invoice_id' => $ctx['invoice']->id,
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
        'patient_responsibility' => 0,
        'eligible_amount' => 5000,
        'covered_amount' => 5000,
        'status' => ChargeInsuranceAllocation::STATUS_FULLY_COVERED,
    ]);

    $status = app(ReceptionVisitService::class)->consultationAccessStatus($ctx['visit']->id);

    expect($status)->toBe('authorized');
});

// ---------------------------------------------------------------------------
// 3. NHIF accepted → authorized (even without ChargeInsuranceAllocation)
// ---------------------------------------------------------------------------

test('NHIF accepted visit returns authorized without cash payment', function () {
    $ctx = financialStatusContext();

    createNhifPreApproval($ctx, NhifPreApprovalSessionItem::APPROVAL_STATUS_ACCEPTED);

    $status = app(ReceptionVisitService::class)->consultationAccessStatus($ctx['visit']->id);

    expect($status)->toBe('authorized');
});

// ---------------------------------------------------------------------------
// 4. NHIF approved → authorized
// ---------------------------------------------------------------------------

test('NHIF approved visit returns authorized', function () {
    $ctx = financialStatusContext();

    createNhifPreApproval($ctx, NhifPreApprovalSessionItem::APPROVAL_STATUS_APPROVED);

    $status = app(ReceptionVisitService::class)->consultationAccessStatus($ctx['visit']->id);

    expect($status)->toBe('authorized');
});

// ---------------------------------------------------------------------------
// 5. NHIF pending_approval → nhif_pending (blocks service)
// ---------------------------------------------------------------------------

test('NHIF pending visit returns nhif_pending', function () {
    $ctx = financialStatusContext();

    createNhifPreApproval($ctx, NhifPreApprovalSessionItem::APPROVAL_STATUS_PENDING_APPROVAL);

    $status = app(ReceptionVisitService::class)->consultationAccessStatus($ctx['visit']->id);

    expect($status)->toBe('nhif_pending');
});

// ---------------------------------------------------------------------------
// 6. NHIF rejected → nhif_rejected (blocks service)
// ---------------------------------------------------------------------------

test('NHIF rejected visit returns nhif_rejected', function () {
    $ctx = financialStatusContext();

    createNhifPreApproval($ctx, NhifPreApprovalSessionItem::APPROVAL_STATUS_REJECTED, 'Not covered under scheme');

    $status = app(ReceptionVisitService::class)->consultationAccessStatus($ctx['visit']->id);

    expect($status)->toBe('nhif_rejected');
});

// ---------------------------------------------------------------------------
// 7. sendToService allows NHIF-accepted visit through the gate
// ---------------------------------------------------------------------------

test('sendToService allows NHIF-accepted visit without cash payment', function () {
    $ctx = financialStatusContext();

    createNhifPreApproval($ctx, NhifPreApprovalSessionItem::APPROVAL_STATUS_ACCEPTED);

    $service = app(ReceptionVisitService::class);
    $result = $service->sendToService($ctx['visit'], requestAs($ctx['user']));

    expect($result->status)->toBe(ReceptionVisit::STATUS_SENT_TO_SERVICE);
});

// ---------------------------------------------------------------------------
// 8. sendToService blocks NHIF-pending visit
// ---------------------------------------------------------------------------

test('sendToService blocks NHIF-pending visit with descriptive message', function () {
    $ctx = financialStatusContext();

    createNhifPreApproval($ctx, NhifPreApprovalSessionItem::APPROVAL_STATUS_PENDING_APPROVAL);

    $service = app(ReceptionVisitService::class);

    expect(fn () => $service->sendToService($ctx['visit'], requestAs($ctx['user'])))
        ->toThrow(ValidationException::class, 'NHIF authorization is still pending');
});

// ---------------------------------------------------------------------------
// 9. sendToService blocks NHIF-rejected visit
// ---------------------------------------------------------------------------

test('sendToService blocks NHIF-rejected visit with descriptive message', function () {
    $ctx = financialStatusContext();

    createNhifPreApproval($ctx, NhifPreApprovalSessionItem::APPROVAL_STATUS_REJECTED);

    $service = app(ReceptionVisitService::class);

    expect(fn () => $service->sendToService($ctx['visit'], requestAs($ctx['user'])))
        ->toThrow(ValidationException::class, 'NHIF authorization was rejected');
});

// ---------------------------------------------------------------------------
// 10. Batch status computation is NHIF-aware (dashboard)
// ---------------------------------------------------------------------------

test('batch consultationAccessStatuses returns correct statuses for mixed visits', function () {
    $cashUnpaid = financialStatusContext();
    $nhifAccepted = financialStatusContext();
    $nhifPending = financialStatusContext();
    $nhifRejected = financialStatusContext();

    createNhifPreApproval($nhifAccepted, NhifPreApprovalSessionItem::APPROVAL_STATUS_ACCEPTED);
    createNhifPreApproval($nhifPending, NhifPreApprovalSessionItem::APPROVAL_STATUS_PENDING_APPROVAL);
    createNhifPreApproval($nhifRejected, NhifPreApprovalSessionItem::APPROVAL_STATUS_REJECTED);

    $visitIds = [
        $cashUnpaid['visit']->id,
        $nhifAccepted['visit']->id,
        $nhifPending['visit']->id,
        $nhifRejected['visit']->id,
    ];

    $statuses = app(ReceptionVisitService::class)->consultationAccessStatuses($visitIds);

    expect($statuses[$cashUnpaid['visit']->id])->toBe('payment_required')
        ->and($statuses[$nhifAccepted['visit']->id])->toBe('authorized')
        ->and($statuses[$nhifPending['visit']->id])->toBe('nhif_pending')
        ->and($statuses[$nhifRejected['visit']->id])->toBe('nhif_rejected');
});
