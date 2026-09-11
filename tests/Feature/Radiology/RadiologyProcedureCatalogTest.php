<?php

use App\Models\RadiologyProcedure;
use App\Services\RadiologyOrderService;
use App\Services\RadiologyProcedureService;
use Illuminate\Validation\ValidationException;

test('creating a radiology procedure normalizes the code to uppercase', function () {
    $admin = userWithRole('hospital-admin');
    $service = app(RadiologyProcedureService::class);

    $procedure = $service->create(['code' => ' xr-chest ', 'name' => 'Chest X-Ray'], requestAs($admin));

    expect($procedure->code)->toBe('XR-CHEST');
    expect($procedure->status)->toBe(RadiologyProcedure::STATUS_ACTIVE);
    expect($procedure->created_by)->toBe($admin->id);
});

test('creating a radiology procedure records an audit event', function () {
    $admin = userWithRole('hospital-admin');
    $service = app(RadiologyProcedureService::class);

    $procedure = $service->create(['code' => 'XR-TEST', 'name' => 'Test X-Ray'], requestAs($admin));

    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_procedure_created', 'auditable_id' => $procedure->id]);
});

test('a radiology procedure can be updated while unreferenced, including its code', function () {
    $admin = userWithRole('hospital-admin');
    $service = app(RadiologyProcedureService::class);

    $procedure = $service->create(['code' => 'OLD-CODE', 'name' => 'Old Name'], requestAs($admin));
    $updated = $service->update($procedure, ['code' => 'NEW-CODE', 'name' => 'New Name'], requestAs($admin));

    expect($updated->code)->toBe('NEW-CODE');
    expect($updated->name)->toBe('New Name');
    expect($updated->updated_by)->toBe($admin->id);
    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_procedure_updated', 'auditable_id' => $procedure->id]);
});

test('a radiology procedure\'s code becomes immutable once referenced by an order', function () {
    $admin = userWithRole('hospital-admin');
    $service = app(RadiologyProcedureService::class);
    $procedure = $service->create(['code' => 'LOCKED', 'name' => 'Locked Procedure'], requestAs($admin));

    $ctx = diagnosisReadyEncounter();
    app(RadiologyOrderService::class)->create($ctx['encounter'], ['procedure_ids' => [$procedure->id]], requestAs($ctx['doctor']['user']));

    expect($procedure->isReferenced())->toBeTrue();

    expect(fn () => $service->update($procedure, ['code' => 'CHANGED'], requestAs($admin)))->toThrow(ValidationException::class);

    // Non-code fields remain editable even once referenced.
    $updated = $service->update($procedure, ['description' => 'Updated description'], requestAs($admin));
    expect($updated->description)->toBe('Updated description');
    expect($updated->code)->toBe('LOCKED');
});

test('updating configuration fields records a distinct configuration-changed audit event', function () {
    $admin = userWithRole('hospital-admin');
    $service = app(RadiologyProcedureService::class);
    $procedure = $service->create(['code' => 'CFG', 'name' => 'Config Procedure'], requestAs($admin));

    $service->update($procedure, ['modality' => 'ct', 'turnaround_time_minutes' => 90], requestAs($admin));

    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_procedure_configuration_changed', 'auditable_id' => $procedure->id]);
});

test('updating price or nhif_code records their own distinct audit events', function () {
    $admin = userWithRole('hospital-admin');
    $service = app(RadiologyProcedureService::class);
    $procedure = $service->create(['code' => 'BILL', 'name' => 'Billable Procedure'], requestAs($admin));

    $service->update($procedure, ['price' => '25000.00'], requestAs($admin));
    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_procedure_service_mapping_changed', 'auditable_id' => $procedure->id]);

    $service->update($procedure, ['nhif_code' => 'NHIF-RAD-001'], requestAs($admin));
    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_procedure_nhif_mapping_changed', 'auditable_id' => $procedure->id]);
});

test('a radiology procedure can be deactivated and reactivated', function () {
    $admin = userWithRole('hospital-admin');
    $service = app(RadiologyProcedureService::class);
    $procedure = $service->create(['code' => 'TOGGLE', 'name' => 'Toggle Procedure'], requestAs($admin));

    $deactivated = $service->deactivate($procedure, requestAs($admin));
    expect($deactivated->status)->toBe(RadiologyProcedure::STATUS_INACTIVE);
    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_procedure_deactivated', 'auditable_id' => $procedure->id]);

    $reactivated = $service->activate($deactivated->fresh(), requestAs($admin));
    expect($reactivated->status)->toBe(RadiologyProcedure::STATUS_ACTIVE);
    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_procedure_activated', 'auditable_id' => $procedure->id]);
});

test('a deactivated procedure is excluded from new radiology orders but a prior order referencing it still works', function () {
    $admin = userWithRole('hospital-admin');
    $service = app(RadiologyProcedureService::class);
    $procedure = $service->create(['code' => 'HIST', 'name' => 'Historical Procedure'], requestAs($admin));

    $ctx = diagnosisReadyEncounter();
    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], ['procedure_ids' => [$procedure->id]], requestAs($ctx['doctor']['user']));

    $service->deactivate($procedure, requestAs($admin));

    // The existing order still resolves its procedure correctly.
    expect($order->fresh()->items->sole()->radiologyProcedure->id)->toBe($procedure->id);

    // A new order cannot select the now-inactive procedure.
    $ctxB = diagnosisReadyEncounter();

    $this->actingAs($ctxB['doctor']['user'])->post(route('clinical-encounters.radiology-orders.store', $ctxB['encounter']), [
        'procedure_ids' => [$procedure->id],
    ])->assertSessionHasErrors('procedure_ids.0');

    $this->assertDatabaseCount('radiology_orders', 1);
});

test('an active procedure appears in the order-time search-json endpoint but an inactive one does not', function () {
    $admin = userWithRole('doctor');
    $active = activeRadiologyProcedure(['code' => 'ZZ-ACTIVE', 'name' => 'Zebra Active Scan']);
    $inactive = activeRadiologyProcedure(['code' => 'ZZ-INACTIVE', 'name' => 'Zebra Inactive Scan', 'status' => 'inactive']);

    $response = $this->actingAs($admin)->getJson(route('radiology-procedures.search-json', ['search' => 'Zebra']));
    $response->assertOk();

    $codes = collect($response->json('data'))->pluck('code');
    expect($codes)->toContain('ZZ-ACTIVE');
    expect($codes)->not->toContain('ZZ-INACTIVE');
});
