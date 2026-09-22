<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supporting documents attached to an NHIF preauthorization request (Phase 8, Step 69).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_preauthorization_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nhif_preauthorization_id')->constrained()->restrictOnDelete();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('nhif_preauthorization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_preauthorization_documents');
    }
};
