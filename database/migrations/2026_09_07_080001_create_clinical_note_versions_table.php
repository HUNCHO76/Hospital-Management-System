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
        Schema::create('clinical_note_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinical_note_id')->constrained()->restrictOnDelete();
            // Assigned under a row lock on the parent clinical_notes record
            // (see ClinicalNoteService::sign()/amend()) — never a bare
            // count()+1 without that lock, which would race under
            // concurrent amendments.
            $table->unsignedInteger('version_number');
            $table->string('note_type');
            $table->text('note_content');
            // What produced this version: 'signed' (the first, frozen draft
            // content) or 'amended' (a subsequent correction).
            $table->string('action');
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            // Null only for the 'signed' version — an amendment always
            // requires a reason.
            $table->text('reason')->nullable();
            $table->timestamp('created_at');

            $table->unique(['clinical_note_id', 'version_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clinical_note_versions');
    }
};
