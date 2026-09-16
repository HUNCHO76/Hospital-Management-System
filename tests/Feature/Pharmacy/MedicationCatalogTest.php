<?php

use App\Models\Medication;
use App\Services\MedicationService;
use Illuminate\Validation\ValidationException;

test('creating a medication normalizes the code to uppercase', function () {
    $admin = userWithRole('hospital-admin');
    $service = app(MedicationService::class);

    $medication = $service->create(['code' => ' pcm-500-tab ', 'generic_name' => 'Paracetamol'], requestAs($admin));

    expect($medication->code)->toBe('PCM-500-TAB');
    expect($medication->status)->toBe(Medication::STATUS_ACTIVE);
    expect($medication->created_by)->toBe($admin->id);
});

test('creating a medication records an audit event', function () {
    $admin = userWithRole('hospital-admin');
    $service = app(MedicationService::class);

    $medication = $service->create(['code' => 'PCM-TEST', 'generic_name' => 'Test Medication'], requestAs($admin));

    $this->assertDatabaseHas('audit_logs', ['action' => 'medication_created', 'auditable_id' => $medication->id]);
});

test('a medication can be updated while unreferenced, including its code', function () {
    $admin = userWithRole('hospital-admin');
    $service = app(MedicationService::class);

    $medication = $service->create(['code' => 'OLD-CODE', 'generic_name' => 'Old Name'], requestAs($admin));
    $updated = $service->update($medication, ['code' => 'NEW-CODE', 'generic_name' => 'New Name'], requestAs($admin));

    expect($updated->code)->toBe('NEW-CODE');
    expect($updated->generic_name)->toBe('New Name');
    expect($updated->updated_by)->toBe($admin->id);
    $this->assertDatabaseHas('audit_logs', ['action' => 'medication_updated', 'auditable_id' => $medication->id]);
});

test('a medication\'s code becomes immutable once referenced by a prescription', function () {
    $admin = userWithRole('hospital-admin');
    $service = app(MedicationService::class);
    $medication = $service->create(['code' => 'LOCKED', 'generic_name' => 'Locked Medication'], requestAs($admin));

    prescriptionFor(null, ['prescription' => ['medication_id' => $medication->id]]);

    expect($medication->isReferenced())->toBeTrue();

    expect(fn () => $service->update($medication, ['code' => 'CHANGED'], requestAs($admin)))->toThrow(ValidationException::class);

    // Non-code fields remain editable even once referenced.
    $updated = $service->update($medication, ['description' => 'Updated description'], requestAs($admin));
    expect($updated->description)->toBe('Updated description');
    expect($updated->code)->toBe('LOCKED');
});

test('updating configuration fields records a distinct configuration-changed audit event', function () {
    $admin = userWithRole('hospital-admin');
    $service = app(MedicationService::class);
    $medication = $service->create(['code' => 'CFG', 'generic_name' => 'Config Medication'], requestAs($admin));

    $service->update($medication, ['form' => 'tablet', 'strength' => '500 mg'], requestAs($admin));

    $this->assertDatabaseHas('audit_logs', ['action' => 'medication_configuration_changed', 'auditable_id' => $medication->id]);
});

test('updating price or nhif_code records their own distinct audit events', function () {
    $admin = userWithRole('hospital-admin');
    $service = app(MedicationService::class);
    $medication = $service->create(['code' => 'BILL', 'generic_name' => 'Billable Medication'], requestAs($admin));

    $service->update($medication, ['price' => '250.00'], requestAs($admin));
    $this->assertDatabaseHas('audit_logs', ['action' => 'medication_service_mapping_changed', 'auditable_id' => $medication->id]);

    $service->update($medication, ['nhif_code' => 'NHIF-MED-001'], requestAs($admin));
    $this->assertDatabaseHas('audit_logs', ['action' => 'medication_nhif_mapping_changed', 'auditable_id' => $medication->id]);
});

test('a medication can be deactivated and reactivated', function () {
    $admin = userWithRole('hospital-admin');
    $service = app(MedicationService::class);
    $medication = $service->create(['code' => 'TOGGLE', 'generic_name' => 'Toggle Medication'], requestAs($admin));

    $deactivated = $service->deactivate($medication, requestAs($admin));
    expect($deactivated->status)->toBe(Medication::STATUS_INACTIVE);
    $this->assertDatabaseHas('audit_logs', ['action' => 'medication_deactivated', 'auditable_id' => $medication->id]);

    $reactivated = $service->activate($deactivated->fresh(), requestAs($admin));
    expect($reactivated->status)->toBe(Medication::STATUS_ACTIVE);
    $this->assertDatabaseHas('audit_logs', ['action' => 'medication_activated', 'auditable_id' => $medication->id]);
});

test('a deactivated medication is excluded from new prescriptions search but a prior prescription referencing it still works', function () {
    $admin = userWithRole('hospital-admin');
    $service = app(MedicationService::class);
    $medication = $service->create(['code' => 'HIST', 'generic_name' => 'Historical Medication'], requestAs($admin));

    $prescription = prescriptionFor(null, ['prescription' => ['medication_id' => $medication->id]]);

    $service->deactivate($medication, requestAs($admin));

    // The existing prescription still resolves its medication correctly.
    expect($prescription->fresh()->medication->id)->toBe($medication->id);

    // A now-inactive medication no longer appears in the order-time search.
    $doctor = userWithRole('doctor');
    $response = $this->actingAs($doctor)->getJson(route('medications.search-json', ['search' => 'Historical']));
    expect(collect($response->json('data'))->pluck('code'))->not->toContain('HIST');
});

test('an active medication appears in the order-time search-json endpoint but an inactive one does not', function () {
    $doctor = userWithRole('doctor');
    $active = activeMedication(['code' => 'ZZ-ACTIVE', 'generic_name' => 'Zebra Active Medication']);
    $inactive = activeMedication(['code' => 'ZZ-INACTIVE', 'generic_name' => 'Zebra Inactive Medication', 'status' => 'inactive']);

    $response = $this->actingAs($doctor)->getJson(route('medications.search-json', ['search' => 'Zebra']));
    $response->assertOk();

    $codes = collect($response->json('data'))->pluck('code');
    expect($codes)->toContain('ZZ-ACTIVE');
    expect($codes)->not->toContain('ZZ-INACTIVE');
});
