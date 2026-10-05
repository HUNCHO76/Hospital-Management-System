<?php

/**
 * InpatientIntegrationTest — Phase 9 Step 85
 *
 * Five end-to-end lifecycle scenarios covering the full inpatient workflow
 * from admission through billing, clinical investigation, and discharge.
 *
 * Scenario A: CASH Inpatient — admission charge → invoice → payment → paid → discharge
 * Scenario B: NHIF Inpatient — lab order from inpatient encounter → authorization gate
 * Scenario C: NHIF Copay   — partial NHIF coverage → patient pays remainder → paid
 * Scenario D: Transfer      — active admission → pending transfer → complete → ward updated
 * Scenario E: Investigation — inpatient encounter → lab order → sample → result → release
 */

use App\Models\Admission;
use App\Models\AdmissionTransfer;
use App\Models\Charge;
use App\Models\ChargeInsuranceAllocation;
use App\Models\Hospital;
use App\Models\LaboratoryOrderItem;
use App\Models\LaboratoryProcessing;
use App\Models\LaboratoryResult;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\Staff;
use App\Services\AdmissionService;
use App\Services\ChargeService;
use App\Services\FinancialAuthorizationService;
use App\Services\InpatientBillingService;
use App\Services\InpatientClinicalService;
use App\Services\InvoiceService;
use App\Services\LaboratoryOrderService;
use App\Services\LaboratoryProcessingService;
use App\Services\LaboratoryResultService;
use App\Services\LaboratorySampleService;

// ===========================================================================
// Scenario A: CASH Inpatient — full billing lifecycle
// Admission auto-captures a pending charge for the configured inpatient
// service. Once posted, invoiced, and paid the billing summary reflects paid.
// ===========================================================================

test('[A] CASH: admission charge posts, invoice pays, and billing summary is paid before discharge', function () {
    ['admission' => $admission, 'charge' => $charge, 'actor' => $actor] = chargedAdmission(['amount' => 12000]);

    // 1. Post pending charge.
    app(ChargeService::class)->post($charge, requestAs($actor));

    // 2. Invoice the charge.
    $account = $charge->fresh()->patientAccount;
    $invoice = app(InvoiceService::class)->createDraft($account, [$charge->id], null, requestAs($actor));
    $invoice = app(InvoiceService::class)->post($invoice, requestAs($actor));

    // 3. Pay in full.
    paymentFor($invoice->fresh(), ['amount' => 12000]);

    // 4. Billing summary must be paid.
    $summary = app(InpatientBillingService::class)->summary($admission);
    expect($summary['status'])->toBe('paid')
        ->and($summary['outstanding'])->toBeLessThanOrEqual(0.0)
        ->and($summary['cash_paid'])->toBe(12000.0);
});

// ===========================================================================
// Scenario B: NHIF Inpatient — authorization gate on lab item
// A lab order placed from an inpatient encounter for a patient WITHOUT a
// pre-approval session returns STATUS_PAYMENT_REQUIRED from the financial
// authorization service, proving the NHIF gate is wired up.
// ===========================================================================

test('[B] NHIF: lab order item without pre-approval returns PAYMENT_REQUIRED for NHIF patient', function () {
    $ctx = activeAdmission();
    $hospital = $ctx['hospital'];
    $patient = $ctx['patient'];

    // Attach NHIF coverage to the patient (simulates successful VerifyCard).
    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'nhif_authorization_no' => 'AUTH-NHIF-INPAT-001',
        'verification_status' => 'verified',
    ]);

    // Set up a billable lab test for this hospital.
    $test = billableLaboratoryTest($hospital);

    // Open an inpatient encounter — no consultation required (bypass in place).
    $doctor = userWithRole('doctor');
    Staff::factory()->create(['user_id' => $doctor->id, 'hospital_id' => $hospital->id]);

    $encounter = app(InpatientClinicalService::class)->openEncounter(
        $ctx['admission'],
        $ctx['practitioner']->id,
        requestAs($doctor),
    );

    // Place lab order — works because assertConsultationEligible bypasses for inpatient.
    $order = app(LaboratoryOrderService::class)->create($encounter->fresh(), [
        'test_ids' => [$test->id],
    ], requestAs($doctor));

    $item = $order->items->sole();

    // NHIF gate: no pre-approval session → PAYMENT_REQUIRED.
    $status = app(FinancialAuthorizationService::class)->statusForSource(
        LaboratoryOrderItem::class,
        $item->id,
    );

    expect($status)->toBe(FinancialAuthorizationService::STATUS_PAYMENT_REQUIRED);
});

// ===========================================================================
// Scenario C: NHIF Copay — partial coverage, patient pays remainder
// The insurer covers 80%; the patient is responsible for 20%. After paying
// the patient responsibility the outstanding balance drops to zero (paid).
// ===========================================================================

test('[C] NHIF copay: patient pays 20% remainder after 80% insurance allocation, billing shows paid', function () {
    ['admission' => $admission, 'charge' => $charge, 'actor' => $actor, 'hospital' => $hospital, 'patient' => $patient] =
        chargedAdmission(['amount' => 10000]);

    // Post and invoice the charge (invoice_id required on ChargeInsuranceAllocation).
    app(ChargeService::class)->post($charge, requestAs($actor));
    $account = $charge->fresh()->patientAccount;
    $invoice = app(InvoiceService::class)->createDraft($account, [$charge->id], null, requestAs($actor));
    $invoice = app(InvoiceService::class)->post($invoice, requestAs($actor));

    // NHIF covers 80%; patient owes 20%.
    ChargeInsuranceAllocation::factory()->create([
        'hospital_id' => $hospital->id,
        'patient_id' => $patient->id,
        'invoice_id' => $invoice->id,
        'charge_id' => $charge->id,
        'eligible_amount' => 10000,
        'covered_amount' => 8000,
        'patient_responsibility' => 2000,
        'status' => ChargeInsuranceAllocation::STATUS_PARTIALLY_COVERED,
    ]);

    // Billing outstanding before payment = 2000.
    $before = app(InpatientBillingService::class)->summary($admission);
    expect($before['patient_responsibility'])->toBe(2000.0)
        ->and($before['outstanding'])->toBe(2000.0)
        ->and($before['status'])->toBe('outstanding');

    // Patient pays their 2000 share.
    paymentFor($invoice->fresh(), ['amount' => 2000]);

    // After payment: outstanding clears, status = paid.
    $after = app(InpatientBillingService::class)->summary($admission);
    expect($after['status'])->toBe('paid')
        ->and($after['outstanding'])->toBeLessThanOrEqual(0.0);
});

// ===========================================================================
// Scenario D: Transfer — intra-hospital ward move
// An active admission can be transferred (pending), then the transfer can be
// completed — the admission's ward_type is updated to the destination ward.
// ===========================================================================

test('[D] Transfer: pending transfer completes and updates the admission ward_type', function () {
    $ctx = activeAdmission();
    $admission = $ctx['admission'];
    $admin = $ctx['admin'];

    $fromWard = $admission->ward_type;
    $toWard = 'ICU';

    // Create a pending transfer to ICU.
    $transfer = app(AdmissionService::class)->storeTransfer($admission, [
        'to_hospital_id' => $ctx['hospital']->id,
        'to_ward_type' => $toWard,
        'reason' => 'Deteriorating condition requires ICU monitoring',
    ], requestAs($admin));

    expect($transfer->status)->toBe(AdmissionTransfer::STATUS_PENDING)
        ->and($transfer->from_ward_type)->toBe($fromWard)
        ->and($transfer->to_ward_type)->toBe($toWard);

    // Complete the transfer.
    $completed = app(AdmissionService::class)->completeTransfer(
        $admission,
        $transfer,
        requestAs($admin),
    );

    expect($completed->status)->toBe(AdmissionTransfer::STATUS_COMPLETED);

    // Admission's ward_type must reflect the destination.
    expect($admission->fresh()->ward_type)->toBe($toWard);
});

// ===========================================================================
// Scenario E: Investigation — inpatient lab order lifecycle end-to-end
// Previously blocked by assertConsultationEligible; now allowed because
// inpatient encounters bypass the consultation requirement.
// Full flow: openEncounter → lab order → pay charge → sample → processing
// → draft result → submit → review → verify → authorize → release.
// ===========================================================================

test('[E] Investigation: inpatient lab order can be placed, processed, and result released without a consultation', function () {
    $ctx = activeAdmission();
    $hospital = $ctx['hospital'];
    $patient = $ctx['patient'];

    // Set up a billable lab test with explicit specimen and result_type fields.
    $test = billableLaboratoryTest($hospital, [
        'specimen_type' => 'Blood',
        'specimen_container' => 'EDTA Tube',
        'result_type' => 'numeric',
    ]);

    // Open inpatient encounter (no consultation needed).
    $doctor = userWithRole('doctor');
    Staff::factory()->create(['user_id' => $doctor->id, 'hospital_id' => $hospital->id]);
    $doctorRequest = requestAs($doctor);

    $encounter = app(InpatientClinicalService::class)->openEncounter(
        $ctx['admission'],
        $ctx['practitioner']->id,
        $doctorRequest,
    );

    // Place lab order — bypasses assertConsultationEligible for inpatient.
    $order = app(LaboratoryOrderService::class)->create($encounter->fresh(), [
        'test_ids' => [$test->id],
    ], $doctorRequest);

    $item = $order->items->sole();

    // Pay the lab charge so the financial gate is cleared for sample registration.
    $charge = Charge::query()
        ->where('source_type', LaboratoryOrderItem::class)
        ->where('source_id', $item->id)
        ->whereNotIn('status', [Charge::STATUS_VOIDED, Charge::STATUS_CANCELLED])
        ->sole();

    $actor = userWithRole('accountant');
    Staff::factory()->create(['user_id' => $actor->id, 'hospital_id' => $hospital->id]);
    $actorRequest = requestAs($actor);

    app(ChargeService::class)->post($charge, $actorRequest);
    $account = $charge->fresh()->patientAccount;
    $invoice = app(InvoiceService::class)->createDraft($account, [$charge->id], null, $actorRequest);
    $invoice = app(InvoiceService::class)->post($invoice, $actorRequest);
    paymentFor($invoice->fresh(), ['amount' => (int) $charge->unit_price]);

    // Register specimen → collect → label → receive.
    $labTechRequest = requestAs(userWithRole('laboratory-technician'));

    $sample = app(LaboratorySampleService::class)->create($order->fresh('items'), [
        'item_ids' => [$item->id],
        'specimen_type' => $test->specimen_type,
        'container_type' => $test->specimen_container,
    ], $labTechRequest);

    $sample = app(LaboratorySampleService::class)->collect($sample, [], $labTechRequest);
    $sample = app(LaboratorySampleService::class)->label($sample, $labTechRequest);
    $sample = app(LaboratorySampleService::class)->receive($sample, $labTechRequest);

    // Initialize processing records and run through the processing pipeline.
    app(LaboratoryProcessingService::class)->initializeForSample($sample, $labTechRequest);
    app(LaboratoryProcessingService::class)->acceptSample($sample->fresh(), $labTechRequest);

    $processing = LaboratoryProcessing::where('laboratory_sample_id', $sample->id)->sole();
    $processing = app(LaboratoryProcessingService::class)->start($processing, [], $labTechRequest);
    $processing = app(LaboratoryProcessingService::class)->complete($processing, [], $labTechRequest);

    // Initialize result and enter the result value.
    app(LaboratoryResultService::class)->initializeForItem($processing->item, $processing->sample->fresh(), $labTechRequest);
    $result = LaboratoryResult::where('laboratory_order_item_id', $item->id)->sole();

    $resultService = app(LaboratoryResultService::class);
    $result = $resultService->saveDraft($result, ['value_numeric' => '14.2'], $labTechRequest);
    $result = $resultService->submit($result, $labTechRequest);

    // Supervisor reviews, verifies, authorizes and releases.
    $supervisor = userWithRole('laboratory-supervisor');
    $supervisorRequest = requestAs($supervisor);

    $result = $resultService->startReview($result, $supervisorRequest);
    $result = $resultService->verifyResult($result, [], $supervisorRequest);
    $result = $resultService->authorizeResult($result, $supervisorRequest);
    $result = $resultService->release($result, [], $supervisorRequest);

    expect($result->fresh()->status)->toBe(LaboratoryResult::STATUS_RELEASED);

    // The encounter is linked to the admission and has a released lab result.
    expect($encounter->fresh()->admission_id)->toBe($ctx['admission']->id);
});
