<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_diseases', function (Blueprint $table) {
            $table->id();
            $table->string('disease_code', 20)->index();
            $table->string('disease_name');
            $table->string('icd_version_code', 10)->index();
            $table->timestamp('synced_at')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->unique(['disease_code', 'icd_version_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_diseases');
    }
};
