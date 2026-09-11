<?php

use App\Models\RadiologyOrder;
use App\Models\RadiologyProcedure;

/**
 * The full Step 37 pathway (Part 37): Admin creates a radiology procedure ->
 * activates it -> a doctor opens a clinical encounter -> clinical decision
 * -> Radiology -> searches "Chest" -> the new procedure appears -> the
 * doctor selects it -> creates a radiology order -> RAD-2026-XXXXXX ->
 * ORDERED. Then: deactivating the procedure blocks it from any *new* order,
 * while the historical order created before deactivation continues to
 * display correctly (Part 27/40 — never break historical clinical data).
 */
test('an admin-created and activated procedure flows through search into a doctor\'s radiology order', function () {
    $admin = userWithRole('hospital-admin');
    $ctx = diagnosisReadyEncounter();

    // Admin creates the procedure as inactive-by-default-then-explicitly-
    // activated, proving the full create -> activate pathway rather than
    // just relying on the service's own default-active create().
    $this->actingAs($admin)->post(route('radiology-catalog.store'), [
        'code' => 'XR-CHEST-E2E',
        'name' => 'Chest X-Ray (E2E)',
        'modality' => 'x_ray',
        'body_sites' => ['chest'],
    ])->assertRedirect();

    $procedure = RadiologyProcedure::query()->where('code', 'XR-CHEST-E2E')->firstOrFail();
    expect($procedure->status)->toBe(RadiologyProcedure::STATUS_ACTIVE);

    // Doctor searches "Chest" from the clinical decision / radiology order
    // form — the new procedure is findable through the same authoritative
    // catalog search endpoint the order form's combobox uses.
    $search = $this->actingAs($ctx['doctor']['user'])->getJson(route('radiology-procedures.search-json', ['search' => 'Chest']));
    $search->assertOk();
    expect(collect($search->json('data'))->pluck('code'))->toContain('XR-CHEST-E2E');

    // Doctor selects it and creates the radiology order.
    $this->actingAs($ctx['doctor']['user'])->post(route('clinical-encounters.radiology-orders.store', $ctx['encounter']), [
        'procedure_ids' => [$procedure->id],
        'clinical_indication' => 'Persistent cough',
    ])->assertRedirect();

    $order = RadiologyOrder::query()->where('clinical_encounter_id', $ctx['encounter']->id)->firstOrFail();
    expect($order->order_number)->toStartWith('RAD-'.now()->year.'-');
    expect($order->status)->toBe(RadiologyOrder::STATUS_ORDERED);
    expect($order->items->sole()->procedure_code)->toBe('XR-CHEST-E2E');

    // Deactivating the procedure blocks any *new* order from selecting it...
    $this->actingAs($admin)->post(route('radiology-catalog.deactivate', $procedure))->assertRedirect();

    $ctxB = diagnosisReadyEncounter();
    $this->actingAs($ctxB['doctor']['user'])->post(route('clinical-encounters.radiology-orders.store', $ctxB['encounter']), [
        'procedure_ids' => [$procedure->id],
    ])->assertSessionHasErrors('procedure_ids.0');

    // ...it no longer appears in a fresh search...
    $searchAfter = $this->actingAs($ctx['doctor']['user'])->getJson(route('radiology-procedures.search-json', ['search' => 'Chest']));
    expect(collect($searchAfter->json('data'))->pluck('code'))->not->toContain('XR-CHEST-E2E');

    // ...but the existing historical order still displays correctly.
    $detail = $this->actingAs(userWithRole('radiology-technician'))->get(route('radiology.orders.show', $order));
    $detail->assertOk();
    $detail->assertInertia(fn ($page) => $page
        ->component('radiology/show')
        ->where('order.order_number', $order->order_number)
        ->where('order.items.0.procedure_code', 'XR-CHEST-E2E')
        ->where('order.items.0.procedure_name', 'Chest X-Ray (E2E)'));
});
