<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NHIF folio number sequences per hospital/year/month (Phase 8, Steps 72–73).
 * Provides monotonically-increasing folio numbers for claims in the same period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_folio_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('folio_year');
            $table->unsignedTinyInteger('folio_month');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['hospital_id', 'folio_year', 'folio_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_folio_sequences');
    }
};
