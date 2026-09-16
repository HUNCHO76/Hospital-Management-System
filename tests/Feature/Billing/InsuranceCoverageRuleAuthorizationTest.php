<?php

use App\Models\User;

/**
 * Authorization for the coverage rule admin surface (Phase 7, Step 56) —
 * global, no hospital scoping (InsuranceCoverageRulePolicy's own docblock).
 */
test('a guest is redirected to login for every coverage rule route', function () {
    $scheme = insuranceSchemeFor();
    $rule = coverageRuleFor($scheme);

    $this->get(route('billing-insurance-rules.index'))->assertRedirect(route('login'));
    $this->get(route('billing-insurance-rules.create'))->assertRedirect(route('login'));
    $this->post(route('billing-insurance-rules.store'))->assertRedirect(route('login'));
    $this->post(route('billing-insurance-rules.cancel', $rule))->assertRedirect(route('login'));
});

test('a permission-less user cannot view or manage coverage rules', function () {
    seedRolesAndPermissions();
    $scheme = insuranceSchemeFor();
    $rule = coverageRuleFor($scheme);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('billing-insurance-rules.index'))->assertForbidden();
    $this->actingAs($user)->get(route('billing-insurance-rules.create'))->assertForbidden();
    $this->actingAs($user)->post(route('billing-insurance-rules.cancel', $rule), ['reason' => 'x'])->assertForbidden();
});

test('a doctor and a pharmacist hold no coverage rule management permission', function () {
    $doctor = userWithRole('doctor');
    $pharmacist = userWithRole('pharmacist');

    $this->actingAs($doctor)->get(route('billing-insurance-rules.index'))->assertForbidden();
    $this->actingAs($pharmacist)->get(route('billing-insurance-rules.index'))->assertForbidden();
});

test('an accountant can view, create, and cancel coverage rules over HTTP', function () {
    $accountant = userWithRole('accountant');
    $scheme = insuranceSchemeFor();

    $this->actingAs($accountant)->get(route('billing-insurance-rules.index'))->assertOk();
    $this->actingAs($accountant)->get(route('billing-insurance-rules.create'))->assertOk();

    $this->actingAs($accountant)->post(route('billing-insurance-rules.store'), [
        'insurance_scheme_id' => $scheme->id,
        'coverage_type' => 'percentage',
        'coverage_percentage' => 75,
        'effective_from' => now()->toDateString(),
    ])->assertRedirect(route('billing-insurance-rules.index'));

    $rule = $scheme->coverageRules()->sole();

    $this->actingAs($accountant)->post(route('billing-insurance-rules.cancel', $rule), ['reason' => 'correction'])
        ->assertRedirect();

    expect($rule->fresh()->status)->toBe('cancelled');
});

test('a hospital-admin can manage coverage rules despite them being a global, non-facility resource', function () {
    $admin = userWithRole('hospital-admin');
    $scheme = insuranceSchemeFor();
    $rule = coverageRuleFor($scheme);

    $this->actingAs($admin)->get(route('billing-insurance-rules.index'))->assertOk();
    $this->actingAs($admin)->post(route('billing-insurance-rules.cancel', $rule), ['reason' => 'x'])->assertRedirect();
});

test('a super-admin bypasses coverage rule permission checks', function () {
    $user = userWithRole('super-admin');
    $scheme = insuranceSchemeFor();
    $rule = coverageRuleFor($scheme);

    $this->actingAs($user)->get(route('billing-insurance-rules.index'))->assertOk();
    $this->actingAs($user)->get(route('billing-insurance-rules.create'))->assertOk();
    $this->actingAs($user)->post(route('billing-insurance-rules.cancel', $rule), ['reason' => 'x'])->assertRedirect();
});
