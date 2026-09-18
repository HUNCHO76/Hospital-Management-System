<?php

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\LaboratoryOrderItem;
use App\Models\PrescriptionOrderItem;
use App\Models\RadiologyOrderItem;
use App\Services\ChargeCaptureService;
use App\Services\ChargeService;
use App\Services\InvoiceService;
use App\Services\LaboratoryOrderService;
use App\Services\PatientAccountService;
use App\Services\PrescriptionOrderService;
use App\Services\RadiologyOrderService;

/**
 * The full realistic workflow (Phase 7, Step 55, spec §46): Patient ->
 * Clinical Encounter -> Billable Service -> Charge -> Patient Account ->
 * Eligible Charge -> Draft Invoice -> Review -> Post Invoice -> Invoice
 * Details, verified for Laboratory, Radiology, and Pharmacy sources
 * specifically (each already produces its own automated charge via Step
 * 54's ChargeCaptureService — this step only groups and finalizes them).
 */
test('a charge sourced from a laboratory order traces end to end through invoicing', function () {
    $ctx = diagnosisReadyEncounter();
    $test = billableLaboratoryTest($ctx['hospital'], [], ['amount' => 15000]);
    $doctorRequest = requestAs($ctx['doctor']['user']);

    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], ['test_ids' => [$test->id]], $doctorRequest);
    $item = $order->items->sole();
    $charge = Charge::query()->where('source_type', LaboratoryOrderItem::class)->where('source_id', $item->id)->sole();

    $accountant = userWithRole('accountant');
    app(ChargeService::class)->post($charge, requestAs($accountant));

    $account = app(PatientAccountService::class)->ensureAccountExists($ctx['hospital']->id, $ctx['patient']->id, requestAs($accountant));
    $invoiceService = app(InvoiceService::class);
    $draft = $invoiceService->createDraft($account, [$charge->fresh()->id], 'Lab billing', requestAs($accountant));
    $posted = $invoiceService->post($draft, requestAs($accountant));

    expect($posted->status)->toBe(Invoice::STATUS_POSTED);
    expect((float) $posted->total_amount)->toBe(15000.0);
    expect($posted->patient_id)->toBe($ctx['patient']->id);
    expect($posted->hospital_id)->toBe($ctx['hospital']->id);

    $posted->load('charges');
    $invoicedCharge = $posted->charges->sole();
    expect($invoicedCharge->source_type)->toBe(LaboratoryOrderItem::class);
    expect($invoicedCharge->source_id)->toBe($item->id);
    expect((float) $invoicedCharge->unit_price)->toBe(15000.0);
    expect($invoicedCharge->invoice_id)->toBe($posted->id);

    $this->assertDatabaseHas('audit_logs', ['action' => 'invoice_created']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'invoice_posted']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'charge_added_to_invoice']);
});

test('a charge sourced from a radiology order traces end to end through invoicing', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = billableRadiologyProcedure($ctx['hospital'], [], ['amount' => 45000]);
    $doctorRequest = requestAs($ctx['doctor']['user']);

    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], ['procedure_ids' => [$procedure->id]], $doctorRequest);
    $item = $order->items->sole();
    $charge = Charge::query()->where('source_type', RadiologyOrderItem::class)->where('source_id', $item->id)->sole();

    $accountant = userWithRole('accountant');
    app(ChargeService::class)->post($charge, requestAs($accountant));

    $account = app(PatientAccountService::class)->ensureAccountExists($ctx['hospital']->id, $ctx['patient']->id, requestAs($accountant));
    $invoiceService = app(InvoiceService::class);
    $posted = $invoiceService->post(
        $invoiceService->createDraft($account, [$charge->fresh()->id], null, requestAs($accountant)),
        requestAs($accountant),
    );

    expect((float) $posted->total_amount)->toBe(45000.0);
    $invoicedCharge = $posted->fresh('charges')->charges->sole();
    expect($invoicedCharge->source_type)->toBe(RadiologyOrderItem::class);
    expect($invoicedCharge->source_id)->toBe($item->id);
});

test('a charge sourced from a pharmacy prescription order traces end to end through invoicing', function () {
    // Step 62: charge is now captured at ORDER time (PrescriptionOrderItem source),
    // quantity = prescribed quantity (15), so total = 15 × 8000 = 120000.
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication(['price' => 8000]);

    $order = app(PrescriptionOrderService::class)->create($ctx['encounter'], [
        'items' => [[
            'medication_id' => $medication->id,
            'dose' => '1 tablet',
            'frequency' => 'three_times_daily',
            'route' => 'oral',
            'duration' => 5,
            'duration_unit' => 'days',
            'quantity' => 15,
            'instructions' => 'Take after meals',
        ]],
    ], requestAs($ctx['doctor']['user']));

    $orderItem = $order->items->sole();
    $charge = Charge::query()
        ->where('source_type', PrescriptionOrderItem::class)
        ->where('source_id', $orderItem->id)
        ->sole();

    $accountant = userWithRole('accountant');
    app(ChargeService::class)->post($charge, requestAs($accountant));

    $account = app(PatientAccountService::class)->ensureAccountExists($ctx['hospital']->id, $ctx['patient']->id, requestAs($accountant));
    $invoiceService = app(InvoiceService::class);
    $posted = $invoiceService->post(
        $invoiceService->createDraft($account, [$charge->fresh()->id], null, requestAs($accountant)),
        requestAs($accountant),
    );

    expect((float) $posted->total_amount)->toBe(120000.0);
    $invoicedCharge = $posted->fresh('charges')->charges->sole();
    expect($invoicedCharge->source_type)->toBe(PrescriptionOrderItem::class);
    expect($invoicedCharge->source_id)->toBe($orderItem->id);
    expect($invoicedCharge->service_id)->toBeNull();
});

test('an invoice can combine charges from multiple sources (laboratory, radiology, and a manual charge) in one document', function () {
    $ctx = diagnosisReadyEncounter();
    $accountant = userWithRole('accountant');

    $test = billableLaboratoryTest($ctx['hospital'], [], ['amount' => 10000]);
    $labOrder = app(LaboratoryOrderService::class)->create($ctx['encounter'], ['test_ids' => [$test->id]], requestAs($ctx['doctor']['user']));
    $labCharge = Charge::query()->where('source_type', LaboratoryOrderItem::class)->where('source_id', $labOrder->items->sole()->id)->sole();
    app(ChargeService::class)->post($labCharge, requestAs($accountant));

    $service = serviceFor();
    priceFor($service, $ctx['hospital'], ['amount' => 20000]);
    $manualCharge = app(ChargeCaptureService::class)->captureManual([
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
        'service_id' => $service->id,
        'notes' => 'Walk-in add-on',
    ], requestAs($accountant));
    app(ChargeService::class)->post($manualCharge, requestAs($accountant));

    $account = app(PatientAccountService::class)->ensureAccountExists($ctx['hospital']->id, $ctx['patient']->id, requestAs($accountant));
    $invoiceService = app(InvoiceService::class);
    $posted = $invoiceService->post(
        $invoiceService->createDraft($account, [$labCharge->fresh()->id, $manualCharge->fresh()->id], null, requestAs($accountant)),
        requestAs($accountant),
    );

    expect((float) $posted->total_amount)->toBe(30000.0);
    expect($posted->fresh('charges')->charges)->toHaveCount(2);
});
