<?php

use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Staff;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PatientAccountService;

/**
 * IDOR protection for invoices (Phase 7, Step 55, spec §35/§36): changing
 * the invoice/account id in the URL must never expose or allow acting on
 * another hospital's financial records for a hospital-admin — mirrors
 * BillingIdorTest's own precedent exactly.
 */
test('a hospital-admin cannot view or act on another hospital\'s invoice by changing the id in the URL', function () {
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $accountant = userWithRole('accountant');

    $chargeA = postedChargeFor($hospitalA, Patient::factory()->create());
    $accountA = app(PatientAccountService::class)->ensureAccountExists($hospitalA->id, $chargeA->patient_id, requestAs($accountant));
    $invoiceA = app(InvoiceService::class)->createDraft($accountA, [$chargeA->id], null, requestAs($accountant));

    $chargeB = postedChargeFor($hospitalB, Patient::factory()->create());
    $accountB = app(PatientAccountService::class)->ensureAccountExists($hospitalB->id, $chargeB->patient_id, requestAs($accountant));
    $invoiceB = app(InvoiceService::class)->createDraft($accountB, [$chargeB->id], null, requestAs($accountant));

    seedRolesAndPermissions();
    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->get(route('billing.invoices.show', $invoiceA))->assertOk();
    $this->actingAs($adminA)->get(route('billing.invoices.show', $invoiceB))->assertForbidden();

    $this->actingAs($adminA)->post(route('billing.invoices.post', $invoiceB))->assertForbidden();
    $this->actingAs($adminA)->post(route('billing.invoices.void', $invoiceB), ['reason' => 'x'])->assertForbidden();

    expect($invoiceB->fresh()->status)->toBe(Invoice::STATUS_DRAFT);
});

test('a hospital-admin cannot start a draft invoice against another hospital\'s account', function () {
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $accountant = userWithRole('accountant');

    $chargeB = postedChargeFor($hospitalB, Patient::factory()->create());
    $accountB = app(PatientAccountService::class)->ensureAccountExists($hospitalB->id, $chargeB->patient_id, requestAs($accountant));

    seedRolesAndPermissions();
    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);
    $adminA->givePermissionTo('billing-invoices.create');

    $this->actingAs($adminA)->get(route('billing.invoices.create', ['patient_account_id' => $accountB->id]))->assertForbidden();
    $this->actingAs($adminA)->post(route('billing.invoices.store'), [
        'patient_account_id' => $accountB->id,
        'charge_ids' => [$chargeB->id],
    ])->assertForbidden();

    expect(Invoice::query()->where('patient_account_id', $accountB->id)->exists())->toBeFalse();
});

test('the invoice list never leaks another hospital\'s invoices to a hospital-admin', function () {
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $accountant = userWithRole('accountant');

    $chargeA = postedChargeFor($hospitalA, Patient::factory()->create());
    $accountA = app(PatientAccountService::class)->ensureAccountExists($hospitalA->id, $chargeA->patient_id, requestAs($accountant));
    $invoiceA = app(InvoiceService::class)->createDraft($accountA, [$chargeA->id], null, requestAs($accountant));

    $chargeB = postedChargeFor($hospitalB, Patient::factory()->create());
    $accountB = app(PatientAccountService::class)->ensureAccountExists($hospitalB->id, $chargeB->patient_id, requestAs($accountant));
    app(InvoiceService::class)->createDraft($accountB, [$chargeB->id], null, requestAs($accountant));

    seedRolesAndPermissions();
    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->get(route('billing.invoices.index'))->assertInertia(fn ($page) => $page
        ->has('invoices.data', 1)
        ->where('invoices.data.0.id', $invoiceA->id));
});

test('an accountant (not hospital-admin) is unrestricted across hospitals, matching this app\'s existing systemic precedent', function () {
    $hospital = Hospital::factory()->create();
    $accountant = userWithRole('accountant');
    $charge = postedChargeFor($hospital, Patient::factory()->create());
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $charge->patient_id, requestAs($accountant));
    $invoice = app(InvoiceService::class)->createDraft($account, [$charge->id], null, requestAs($accountant));

    $this->actingAs($accountant)->get(route('billing.invoices.show', $invoice))->assertOk();
});
