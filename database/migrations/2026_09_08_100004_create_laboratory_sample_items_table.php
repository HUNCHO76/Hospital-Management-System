<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot table supporting the many-to-many relationship between a
 * LaboratorySample and the LaboratoryOrderItems it satisfies (Phase 4, Step
 * 31, spec §11-13): a single specimen may cover several compatible tests,
 * and a single order may require several separate specimens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_sample_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_sample_id')->constrained()->cascadeOnDelete();
            $table->foreignId('laboratory_order_item_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['laboratory_sample_id', 'laboratory_order_item_id'], 'lab_sample_item_unique');
            $table->index('laboratory_order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_sample_items');
    }
};
