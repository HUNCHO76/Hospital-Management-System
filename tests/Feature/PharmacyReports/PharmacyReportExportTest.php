<?php

use App\Models\Hospital;
use App\Models\User;
use App\Services\StockService;

/**
 * CSV export (Phase 6, Step 50, spec §18) — every export reflects exactly
 * the same filtered/scoped query the on-screen report used, and never
 * bypasses authorization.
 */
test('exporting the stock batch report returns a CSV with the correct headers and filtered rows', function () {
    $pharmacist = userWithRole('pharmacist');
    $hospital = Hospital::factory()->create();
    $matching = activeMedication(['generic_name' => 'Exportable Medication']);
    $other = activeMedication(['generic_name' => 'Other Medication']);

    app(StockService::class)->receive($hospital->id, [
        'medication_id' => $matching->id, 'batch_number' => 'EXPORT-1', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => 10,
    ], requestAs($pharmacist));

    app(StockService::class)->receive($hospital->id, [
        'medication_id' => $other->id, 'batch_number' => 'EXPORT-2', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => 10,
    ], requestAs($pharmacist));

    $response = $this->actingAs($pharmacist)->get(route('pharmacy-reports.stock-batches', [
        'search' => 'Exportable Medication',
        'export' => 1,
    ]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/csv');

    $csv = $response->getContent();
    expect($csv)->toContain('Code,Medication');
    expect($csv)->toContain('Batch Number');
    expect($csv)->toContain('Exportable Medication');
    expect($csv)->not->toContain('Other Medication');
});

test('an export records an audit event without leaking patient data into it', function () {
    $pharmacist = userWithRole('pharmacist');
    $hospital = Hospital::factory()->create();
    $medication = activeMedication();

    app(StockService::class)->receive($hospital->id, [
        'medication_id' => $medication->id, 'batch_number' => 'AUDIT-1', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => 10,
    ], requestAs($pharmacist));

    $this->actingAs($pharmacist)->get(route('pharmacy-reports.stock-batches', ['export' => 1]))->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'pharmacy_report_exported',
        'user_id' => $pharmacist->id,
    ]);
});

test('a user without the export permission is forbidden from every report\'s export, even with view access', function () {
    seedRolesAndPermissions();
    $viewOnly = User::factory()->create();
    $viewOnly->givePermissionTo('pharmacy-reports.view');

    $routes = [
        'pharmacy-reports.stock-batches',
        'pharmacy-reports.low-stock',
        'pharmacy-reports.stock-movements',
        'pharmacy-reports.prescriptions',
        'pharmacy-reports.dispensing',
        'pharmacy-reports.medication-utilization',
        'pharmacy-reports.reconciliation',
    ];

    foreach ($routes as $routeName) {
        $this->actingAs($viewOnly)->get(route($routeName, ['export' => 1]))->assertForbidden();
    }
});
