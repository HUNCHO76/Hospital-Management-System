<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supporting documents attached to an NHIF claim (Phase 8, Steps 72–73).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_claim_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nhif_claim_id')->constrained()->restrictOnDelete();
            $table->foreignId('hospital_id')->constrained()->restrictOnDelete();
            $table->string('document_type', 40);
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->string('checksum', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('nhif_claim_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_claim_documents');
    }
};
