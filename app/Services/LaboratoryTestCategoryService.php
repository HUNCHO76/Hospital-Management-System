<?php

namespace App\Services;

use App\Models\LaboratoryTestCategory;
use Illuminate\Http\Request;

/**
 * Mirrors DepartmentService's shape. No delete() — categories are
 * deactivated, never deleted, the same no-hard-delete precedent every other
 * clinical catalog in this app follows (spec §23/§36).
 */
class LaboratoryTestCategoryService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, Request $request): LaboratoryTestCategory
    {
        $category = LaboratoryTestCategory::create([
            'status' => LaboratoryTestCategory::STATUS_ACTIVE,
            ...$data,
            'created_by' => $request->user()->id,
        ]);

        $this->auditLog->record('laboratory_category_created', $category, null, $category->only(array_keys($data)), $request);

        return $category;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(LaboratoryTestCategory $category, array $data, Request $request): LaboratoryTestCategory
    {
        $oldValues = $category->only(array_keys($data));

        $data['updated_by'] = $request->user()->id;
        $category->update($data);

        $this->auditLog->record('laboratory_category_updated', $category, $oldValues, $category->only(array_keys($data)), $request);

        return $category;
    }

    public function activate(LaboratoryTestCategory $category, Request $request): LaboratoryTestCategory
    {
        $oldValues = $category->only(['status']);

        $category->update(['status' => LaboratoryTestCategory::STATUS_ACTIVE, 'updated_by' => $request->user()->id]);

        $this->auditLog->record('laboratory_category_activated', $category, $oldValues, $category->only(['status']), $request);

        return $category;
    }

    public function deactivate(LaboratoryTestCategory $category, Request $request): LaboratoryTestCategory
    {
        $oldValues = $category->only(['status']);

        $category->update(['status' => LaboratoryTestCategory::STATUS_INACTIVE, 'updated_by' => $request->user()->id]);

        $this->auditLog->record('laboratory_category_deactivated', $category, $oldValues, $category->only(['status']), $request);

        return $category;
    }
}
