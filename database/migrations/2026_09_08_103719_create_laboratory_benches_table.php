<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The laboratory's configurable work areas (Phase 4, Step 32, spec §11) —
 * Hematology, Chemistry, Microbiology, etc., or whatever a hospital
 * actually configures. Mirrors LaboratoryTestCategory's exact shape
 * (Step 29): a small admin-managed catalog, deactivated rather than
 * deleted, never a hard-coded enum — a hospital's own work-area vocabulary
 * varies, unlike the clinically stable specimen_type/specimen_container
 * enums.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_benches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_benches');
    }
};
