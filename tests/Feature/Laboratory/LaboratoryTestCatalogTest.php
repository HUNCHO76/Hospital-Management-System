<?php

use App\Models\LaboratoryTest;
use App\Services\LaboratoryOrderService;
use App\Services\LaboratoryTestService;
use Illuminate\Validation\ValidationException;

test('creating a laboratory test normalizes the code to uppercase', function () {
    $admin = userWithRole('hospital-admin');
    $service = app(LaboratoryTestService::class);

    $test = $service->create(['code' => ' cbc ', 'name' => 'Complete Blood Count'], requestAs($admin));

    expect($test->code)->toBe('CBC');
    expect($test->status)->toBe(LaboratoryTest::STATUS_ACTIVE);
    expect($test->created_by)->toBe($admin->id);
});

test('creating a laboratory test records an audit event', function () {
    $admin = userWithRole('hospital-admin');
    $service = app(LaboratoryTestService::class);

    $test = $service->create(['code' => 'FBC', 'name' => 'Full Blood Count'], requestAs($admin));

    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_test_created', 'auditable_id' => $test->id]);
});

test('a laboratory test can be updated while unreferenced, including its code', function () {
    $admin = userWithRole('hospital-admin');
    $service = app(LaboratoryTestService::class);

    $test = $service->create(['code' => 'OLD', 'name' => 'Old Name'], requestAs($admin));
    $updated = $service->update($test, ['code' => 'NEW', 'name' => 'New Name'], requestAs($admin));

    expect($updated->code)->toBe('NEW');
    expect($updated->name)->toBe('New Name');
    expect($updated->updated_by)->toBe($admin->id);
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_test_updated', 'auditable_id' => $test->id]);
});

test('a laboratory test\'s code becomes immutable once referenced by an order', function () {
    $admin = userWithRole('hospital-admin');
    $service = app(LaboratoryTestService::class);
    $test = $service->create(['code' => 'LOCKED', 'name' => 'Locked Test'], requestAs($admin));

    $ctx = diagnosisReadyEncounter();
    app(LaboratoryOrderService::class)->create($ctx['encounter'], ['test_ids' => [$test->id]], requestAs($ctx['doctor']['user']));

    expect($test->isReferenced())->toBeTrue();

    expect(fn () => $service->update($test, ['code' => 'CHANGED'], requestAs($admin)))->toThrow(ValidationException::class);

    // Non-code fields remain editable even once referenced.
    $updated = $service->update($test, ['description' => 'Updated description'], requestAs($admin));
    expect($updated->description)->toBe('Updated description');
    expect($updated->code)->toBe('LOCKED');
});

test('updating configuration fields records a distinct configuration-changed audit event', function () {
    $admin = userWithRole('hospital-admin');
    $service = app(LaboratoryTestService::class);
    $test = $service->create(['code' => 'CFG', 'name' => 'Config Test'], requestAs($admin));

    $service->update($test, ['unit' => 'g/dL', 'reference_range' => '13.5-17.5'], requestAs($admin));

    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_test_configuration_changed', 'auditable_id' => $test->id]);
});

test('updating price or nhif_code records their own distinct audit events', function () {
    $admin = userWithRole('hospital-admin');
    $service = app(LaboratoryTestService::class);
    $test = $service->create(['code' => 'BILL', 'name' => 'Billable Test'], requestAs($admin));

    $service->update($test, ['price' => '5000.00'], requestAs($admin));
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_test_service_mapping_changed', 'auditable_id' => $test->id]);

    $service->update($test, ['nhif_code' => 'NHIF-001'], requestAs($admin));
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_test_nhif_mapping_changed', 'auditable_id' => $test->id]);
});

test('a laboratory test can be deactivated and reactivated', function () {
    $admin = userWithRole('hospital-admin');
    $service = app(LaboratoryTestService::class);
    $test = $service->create(['code' => 'TOGGLE', 'name' => 'Toggle Test'], requestAs($admin));

    $deactivated = $service->deactivate($test, requestAs($admin));
    expect($deactivated->status)->toBe(LaboratoryTest::STATUS_INACTIVE);
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_test_deactivated', 'auditable_id' => $test->id]);

    $reactivated = $service->activate($deactivated->fresh(), requestAs($admin));
    expect($reactivated->status)->toBe(LaboratoryTest::STATUS_ACTIVE);
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_test_activated', 'auditable_id' => $test->id]);
});

test('a deactivated test is excluded from new laboratory orders but a prior order referencing it still works', function () {
    $admin = userWithRole('hospital-admin');
    $service = app(LaboratoryTestService::class);
    $test = $service->create(['code' => 'HIST', 'name' => 'Historical Test'], requestAs($admin));

    $ctx = diagnosisReadyEncounter();
    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], ['test_ids' => [$test->id]], requestAs($ctx['doctor']['user']));

    $service->deactivate($test, requestAs($admin));

    // The existing order still resolves its test correctly.
    expect($order->fresh()->items->sole()->laboratoryTest->id)->toBe($test->id);

    // A new order cannot select the now-inactive test.
    $ctxB = diagnosisReadyEncounter();

    $this->actingAs($ctxB['doctor']['user'])->post(route('clinical-encounters.laboratory-orders.store', $ctxB['encounter']), [
        'test_ids' => [$test->id],
    ])->assertSessionHasErrors('test_ids.0');

    $this->assertDatabaseCount('laboratory_orders', 1);
});
