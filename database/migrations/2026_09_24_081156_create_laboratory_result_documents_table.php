<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Machine/analyzer result documents (PDF, JPEG, PNG) uploaded by lab
     * staff to support a LaboratoryResult (Phase 4 Machine Document Upload).
     * `path` is a private `local`-disk storage key — never exposed to the
     * browser and never used to build a public URL; every read goes through
     * LaboratoryResultDocumentController::show(), which re-authorizes on
     * every request before streaming the file.
     */
    public function up(): void
    {
        Schema::create('laboratory_result_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_result_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_result_documents');
    }
};
