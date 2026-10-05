<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discharge_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('document_number')->unique();
            $table->foreignId('admission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('version')->default(1);

            // active = current; superseded = replaced by a newer version.
            $table->string('status')->default('active');

            // Full clinical and financial snapshot at generation time, ensuring
            // the document remains historically reproducible after record updates.
            $table->json('data_snapshot');

            $table->foreignId('generated_by')->constrained('users');
            $table->timestamp('generated_at');

            // Amendment chain — each amendment creates a new version (never
            // silently overwrites the previous one).
            $table->text('amendment_reason')->nullable();
            $table->foreignId('amended_by')->nullable()->constrained('users');
            $table->timestamp('amended_at')->nullable();

            $table->timestamps();

            $table->index(['admission_id', 'version']);
            $table->index('hospital_id');
            $table->index('patient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discharge_documents');
    }
};
