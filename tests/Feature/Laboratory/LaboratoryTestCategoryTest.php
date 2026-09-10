<?php

use App\Models\LaboratoryTestCategory;
use App\Services\LaboratoryTestCategoryService;

test('creating a category records an audit event', function () {
    $admin = userWithRole('hospital-admin');
    $service = app(LaboratoryTestCategoryService::class);

    $category = $service->create(['name' => 'Hematology', 'code' => 'HEMA'], requestAs($admin));

    expect($category->status)->toBe(LaboratoryTestCategory::STATUS_ACTIVE);
    expect($category->created_by)->toBe($admin->id);
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_category_created', 'auditable_id' => $category->id]);
});

test('a category can be updated', function () {
    $admin = userWithRole('hospital-admin');
    $service = app(LaboratoryTestCategoryService::class);
    $category = $service->create(['name' => 'Old Name', 'code' => 'OLD'], requestAs($admin));

    $updated = $service->update($category, ['name' => 'New Name'], requestAs($admin));

    expect($updated->name)->toBe('New Name');
    expect($updated->updated_by)->toBe($admin->id);
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_category_updated', 'auditable_id' => $category->id]);
});

test('a category can be deactivated and reactivated, and tests already assigned keep referencing it', function () {
    $admin = userWithRole('hospital-admin');
    $service = app(LaboratoryTestCategoryService::class);
    $category = $service->create(['name' => 'Microbiology', 'code' => 'MICRO'], requestAs($admin));
    $test = activeLaboratoryTest(['category_id' => $category->id]);

    $deactivated = $service->deactivate($category, requestAs($admin));
    expect($deactivated->status)->toBe(LaboratoryTestCategory::STATUS_INACTIVE);
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_category_deactivated', 'auditable_id' => $category->id]);

    expect($test->fresh()->category->id)->toBe($category->id);

    $reactivated = $service->activate($deactivated->fresh(), requestAs($admin));
    expect($reactivated->status)->toBe(LaboratoryTestCategory::STATUS_ACTIVE);
});
