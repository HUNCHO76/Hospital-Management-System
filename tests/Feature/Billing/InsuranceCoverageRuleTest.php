<?php

use App\Models\InsuranceCoverageRule;
use App\Services\InsuranceCoverageRuleService;
use Illuminate\Validation\ValidationException;

/**
 * Phase 7, Step 56 — coverage rule configuration: creation, effective-dated
 * versioning/overlap prevention (mirrors ServicePricingService's own
 * algorithm), validation of each coverage type's required shape, and
 * cancellation.
 */
test('a percentage rule can be created for a scheme\'s default (no service or category)', function () {
    $scheme = insuranceSchemeFor();

    $rule = coverageRuleFor($scheme, ['coverage_type' => 'percentage', 'coverage_percentage' => 80]);

    expect($rule->insurance_scheme_id)->toBe($scheme->id);
    expect($rule->service_id)->toBeNull();
    expect($rule->category)->toBeNull();
    expect((float) $rule->coverage_percentage)->toBe(80.0);
    expect($rule->status)->toBe(InsuranceCoverageRule::STATUS_ACTIVE);
});

test('a fixed copay rule can be created for a specific service', function () {
    $scheme = insuranceSchemeFor();
    $service = serviceFor();

    $rule = coverageRuleFor($scheme, [
        'service_id' => $service->id,
        'coverage_type' => 'fixed_copay',
        'coverage_percentage' => null,
        'fixed_copay_amount' => 2000,
    ]);

    expect($rule->service_id)->toBe($service->id);
    expect((float) $rule->fixed_copay_amount)->toBe(2000.0);
});

test('a rule can target a whole category', function () {
    $scheme = insuranceSchemeFor();

    $rule = coverageRuleFor($scheme, ['category' => 'pharmacy']);

    expect($rule->category)->toBe('pharmacy');
    expect($rule->service_id)->toBeNull();
});

test('a rule cannot target both a specific service and a category at once', function () {
    $scheme = insuranceSchemeFor();
    $service = serviceFor();

    expect(fn () => coverageRuleFor($scheme, ['service_id' => $service->id, 'category' => 'pharmacy']))
        ->toThrow(ValidationException::class);
});

test('an invalid category is rejected', function () {
    $scheme = insuranceSchemeFor();

    expect(fn () => coverageRuleFor($scheme, ['category' => 'not-a-real-category']))
        ->toThrow(ValidationException::class);
});

test('a percentage rule requires a percentage between 0 and 100', function () {
    $scheme = insuranceSchemeFor();

    expect(fn () => coverageRuleFor($scheme, ['coverage_type' => 'percentage', 'coverage_percentage' => 150]))
        ->toThrow(ValidationException::class);

    expect(fn () => coverageRuleFor($scheme, ['coverage_type' => 'percentage', 'coverage_percentage' => -5]))
        ->toThrow(ValidationException::class);

    expect(fn () => coverageRuleFor($scheme, ['coverage_type' => 'percentage', 'coverage_percentage' => null]))
        ->toThrow(ValidationException::class);
});

test('a fixed copay rule requires a non-negative amount', function () {
    $scheme = insuranceSchemeFor();

    expect(fn () => coverageRuleFor($scheme, ['coverage_type' => 'fixed_copay', 'coverage_percentage' => null, 'fixed_copay_amount' => -100]))
        ->toThrow(ValidationException::class);

    expect(fn () => coverageRuleFor($scheme, ['coverage_type' => 'fixed_copay', 'coverage_percentage' => null, 'fixed_copay_amount' => null]))
        ->toThrow(ValidationException::class);
});

test('full and not_covered rules require no percentage or fixed amount at all', function () {
    $scheme = insuranceSchemeFor();

    $full = coverageRuleFor($scheme, ['coverage_type' => 'full', 'coverage_percentage' => null]);
    $notCovered = coverageRuleFor($scheme, ['category' => 'dental', 'coverage_type' => 'not_covered', 'coverage_percentage' => null]);

    expect($full->coverage_percentage)->toBeNull();
    expect($full->fixed_copay_amount)->toBeNull();
    expect($notCovered->coverage_percentage)->toBeNull();
});

test('an invalid coverage type is rejected', function () {
    $scheme = insuranceSchemeFor();

    expect(fn () => coverageRuleFor($scheme, ['coverage_type' => 'bogus', 'coverage_percentage' => null]))
        ->toThrow(ValidationException::class);
});

test('a new open-ended rule for the same target automatically closes out the previous one', function () {
    $scheme = insuranceSchemeFor();

    $old = coverageRuleFor($scheme, ['effective_from' => now()->subYear()->toDateString()]);
    $new = coverageRuleFor($scheme, ['coverage_percentage' => 90, 'effective_from' => now()->toDateString()]);

    expect($old->fresh()->effective_to?->toDateString())->toBe(now()->subDay()->toDateString());
    expect($new->effective_to)->toBeNull();
});

test('an overlapping bounded rule period for the same target is rejected outright', function () {
    $scheme = insuranceSchemeFor();

    coverageRuleFor($scheme, [
        'effective_from' => now()->subMonths(2)->toDateString(),
        'effective_to' => now()->addMonths(2)->toDateString(),
    ]);

    expect(fn () => coverageRuleFor($scheme, ['effective_from' => now()->toDateString()]))
        ->toThrow(ValidationException::class);
});

test('two different targets for the same scheme never conflict with each other', function () {
    $scheme = insuranceSchemeFor();

    $default = coverageRuleFor($scheme);
    $pharmacy = coverageRuleFor($scheme, ['category' => 'pharmacy']);

    expect($default->id)->not->toBe($pharmacy->id);
    expect($default->fresh()->status)->toBe(InsuranceCoverageRule::STATUS_ACTIVE);
    expect($pharmacy->fresh()->status)->toBe(InsuranceCoverageRule::STATUS_ACTIVE);
});

test('a rule can be cancelled with a reason, never deleted', function () {
    $scheme = insuranceSchemeFor();
    $rule = coverageRuleFor($scheme);
    $actor = userWithRole('accountant');

    app(InsuranceCoverageRuleService::class)->cancel($rule, 'entered by mistake', requestAs($actor));

    $rule->refresh();
    expect($rule->status)->toBe(InsuranceCoverageRule::STATUS_CANCELLED);
    expect($rule->cancellation_reason)->toBe('entered by mistake');
});

test('cancelling an already-cancelled rule is rejected', function () {
    $scheme = insuranceSchemeFor();
    $rule = coverageRuleFor($scheme);
    $actor = userWithRole('accountant');

    app(InsuranceCoverageRuleService::class)->cancel($rule, 'first cancel', requestAs($actor));

    expect(fn () => app(InsuranceCoverageRuleService::class)->cancel($rule, 'second cancel', requestAs($actor)))
        ->toThrow(ValidationException::class);
});

test('cancelling without a reason is rejected', function () {
    $scheme = insuranceSchemeFor();
    $rule = coverageRuleFor($scheme);
    $actor = userWithRole('accountant');

    expect(fn () => app(InsuranceCoverageRuleService::class)->cancel($rule, '', requestAs($actor)))
        ->toThrow(ValidationException::class);
});
