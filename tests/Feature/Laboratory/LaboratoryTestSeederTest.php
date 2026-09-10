<?php

use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use App\Services\LaboratoryOrderService;
use Database\Seeders\LaboratoryTestSeeder;
use Illuminate\Validation\ValidationException;

test('the seeder populates the laboratory test catalog', function () {
    $this->seed(LaboratoryTestSeeder::class);

    expect(LaboratoryTestCategory::count())->toBeGreaterThanOrEqual(9);
    expect(LaboratoryTest::count())->toBeGreaterThanOrEqual(40);

    $this->assertDatabaseHas('laboratory_tests', ['code' => 'HB', 'status' => LaboratoryTest::STATUS_ACTIVE]);
    $this->assertDatabaseHas('laboratory_test_categories', ['code' => 'HEMATOLOGY']);
});

test('running the seeder twice is idempotent and creates no duplicates', function () {
    $this->seed(LaboratoryTestSeeder::class);
    $categoryCount = LaboratoryTestCategory::count();
    $testCount = LaboratoryTest::count();

    $this->seed(LaboratoryTestSeeder::class);

    expect(LaboratoryTestCategory::count())->toBe($categoryCount);
    expect(LaboratoryTest::count())->toBe($testCount);
});

test('every seeded category and test code is unique', function () {
    $this->seed(LaboratoryTestSeeder::class);

    expect(LaboratoryTestCategory::count())
        ->toBe(LaboratoryTestCategory::query()->distinct()->count('code'));

    expect(LaboratoryTest::count())
        ->toBe(LaboratoryTest::query()->distinct()->count('code'));
});

test('every seeded test has a valid category, specimen type, container and result type', function () {
    $this->seed(LaboratoryTestSeeder::class);

    $categoryIds = LaboratoryTestCategory::pluck('id');

    expect(LaboratoryTest::whereNotIn('category_id', $categoryIds)->exists())->toBeFalse();
    expect(LaboratoryTest::whereNotIn('specimen_type', LaboratoryTest::SPECIMEN_TYPES)->exists())->toBeFalse();
    expect(LaboratoryTest::whereNotIn('specimen_container', LaboratoryTest::SPECIMEN_CONTAINERS)->exists())->toBeFalse();
    expect(LaboratoryTest::whereNotIn('result_type', LaboratoryTest::RESULT_TYPES)->exists())->toBeFalse();
    expect(LaboratoryTest::whereNull('department_id')->count())->toBe(LaboratoryTest::count());
});

test('seeded tests carry the expected specimen and container mapping', function () {
    $this->seed(LaboratoryTestSeeder::class);

    $expectations = [
        'HB' => ['whole_blood', 'edta_tube'],
        'WBC' => ['whole_blood', 'edta_tube'],
        'ALT' => ['serum', 'plain_tube'],
        'CREAT' => ['serum', 'plain_tube'],
        'URINALYSIS' => ['urine', 'sterile_container'],
        'STOOL_EXAM' => ['stool', 'sterile_container'],
        'SPUTUM_AFB' => ['sputum', 'sterile_container'],
        'ABO' => ['whole_blood', 'edta_tube'],
    ];

    foreach ($expectations as $code => [$specimenType, $container]) {
        $test = LaboratoryTest::query()->where('code', $code)->firstOrFail();

        expect($test->specimen_type)->toBe($specimenType);
        expect($test->specimen_container)->toBe($container);
    }
});

test('a seeded active test can be ordered through the existing laboratory order workflow', function () {
    $this->seed(LaboratoryTestSeeder::class);
    $fbc = LaboratoryTest::query()->where('code', 'HB')->firstOrFail();

    $ctx = diagnosisReadyEncounter();
    $order = app(LaboratoryOrderService::class)->create(
        $ctx['encounter'],
        ['test_ids' => [$fbc->id]],
        requestAs($ctx['doctor']['user']),
    );

    expect($order->items()->where('laboratory_test_id', $fbc->id)->exists())->toBeTrue();
    expect($order->items()->first()->test_code)->toBe('HB');
});

test('an inactive test cannot be newly ordered even though it remains in the catalog', function () {
    $this->seed(LaboratoryTestSeeder::class);
    $test = LaboratoryTest::query()->where('code', 'HB')->firstOrFail();
    $test->update(['status' => LaboratoryTest::STATUS_INACTIVE]);

    $ctx = diagnosisReadyEncounter();

    expect(fn () => app(LaboratoryOrderService::class)->create(
        $ctx['encounter'],
        ['test_ids' => [$test->id]],
        requestAs($ctx['doctor']['user']),
    ))->toThrow(ValidationException::class);

    $this->assertDatabaseHas('laboratory_tests', ['code' => 'HB', 'status' => LaboratoryTest::STATUS_INACTIVE]);
});
