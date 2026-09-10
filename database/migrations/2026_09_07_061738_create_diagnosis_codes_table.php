<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('diagnosis_codes', function (Blueprint $table) {
            $table->id();
            // No separate coding_systems table: with exactly one coding
            // system in use today, this column carries the same future
            // extensibility (a new code can simply use a different value,
            // e.g. 'icd-11') without a join table that would only ever hold
            // a handful of rows.
            $table->string('coding_system')->default('icd-10')->index();
            $table->string('code')->index();
            $table->string('description', 500);
            $table->string('category')->nullable()->index();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['coding_system', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('diagnosis_codes');
    }
};
