<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Radiology examination imaging (Phase 5, Radiology UI completion pass) —
 * X-Ray/imaging files attached to one examination. Audited before writing:
 * no file/media/document storage abstraction exists anywhere else in this
 * application (Laboratory, patients, insurance — none of them store
 * uploaded files), so there was nothing to reuse; this is a small, purpose-
 * built table rather than a generic "documents" system, matching the
 * spec's own "do not build full PACS/DICOM infrastructure" boundary.
 *
 * Keyed to `radiology_appointment_id` alone — no duplicated patient_id/
 * encounter_id/order_id column, the same "reach everything transitively
 * through the appointment" convention every other Radiology table in this
 * app already follows. `path` is a private-disk storage key, never a
 * public URL and never sent to the browser directly — images are only ever
 * served through the authorized `radiology-images.show` route
 * (RadiologyImageController), which streams the file after policy
 * authorization, exactly like the spec's own conceptual
 * `GET /radiology/examinations/{examination}/images/{image}` route.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radiology_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('radiology_appointment_id')->constrained()->restrictOnDelete();
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('radiology_appointment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radiology_images');
    }
};
