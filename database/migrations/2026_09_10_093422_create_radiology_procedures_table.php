<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The minimal authoritative radiology procedure/test catalog needed so a
 * RadiologyOrderItem can reference a real, validated service rather than
 * free text (Phase 5, Step 36, Part 16) — mirrors the exact minimal shape
 * `laboratory_tests` started at in Step 28, before Step 29 grew it into a
 * full configurable catalog. Full catalog management (categories,
 * modality-specific configuration, activation workflows) is Step 37's job;
 * this table only needs to exist and be selectable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radiology_procedures', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('modality')->nullable();
            $table->text('description')->nullable();
            // Reuses the existing department structure (Part 6) rather than
            // a separate radiology_departments table — nullable since the
            // catalog itself is hospital-agnostic (mirrors
            // laboratory_tests.department_id's own precedent).
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            // Billing/NHIF integration boundary only (Part 17/18) — neither
            // column is read or written by any logic in this step.
            $table->string('nhif_code')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index('department_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radiology_procedures');
    }
};
