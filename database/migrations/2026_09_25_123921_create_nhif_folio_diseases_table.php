<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_folio_diseases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nhif_claim_id')->constrained()->cascadeOnDelete();
            $table->string('disease_code');
            $table->string('status')->nullable();
            $table->string('remarks')->nullable();
            $table->boolean('is_main')->default(false);
            $table->timestamps();

            $table->index('nhif_claim_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_folio_diseases');
    }
};
