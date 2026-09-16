<?php

namespace App\Services;

use App\Models\RadiologyAppointment;
use App\Models\RadiologyImage;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Radiology examination imaging: attach/remove X-Ray images against one
 * IN_PROGRESS examination. Files are stored on the private `local` disk
 * (never `public` — see the migration's own doc-comment) and are only ever
 * readable back through RadiologyImageController::show(), which
 * re-authorizes on every request. Upload/delete are both restricted to
 * IN_PROGRESS, mirroring RadiologyExaminationService::recordTechnicalData()'s
 * identical "only while the exam is actually under way" gate — an
 * examination's imaging is part of the same technical work as its
 * technical_details, not a separately-timed action.
 */
class RadiologyImageService
{
    /**
     * Content-sniffed MIME types accepted for upload — deliberately narrow
     * (plain image formats only); this is not a DICOM/PACS ingestion point.
     *
     * @var list<string>
     */
    public const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png'];

    public const MAX_FILE_SIZE_KB = 10240; // 10MB per file.

    public const MAX_FILES_PER_UPLOAD = 10;

    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Store one or more images against an examination.
     *
     * @param  list<UploadedFile>  $files
     * @return Collection<int, RadiologyImage>
     *
     * @throws ValidationException
     */
    public function upload(RadiologyAppointment $appointment, array $files, Request $request): Collection
    {
        $this->assertManageable($appointment);

        return DB::transaction(function () use ($appointment, $files, $request) {
            $images = collect();

            foreach ($files as $file) {
                $path = $file->store("radiology-images/{$appointment->id}", 'local');

                $image = RadiologyImage::create([
                    'radiology_appointment_id' => $appointment->id,
                    'path' => $path,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'uploaded_by' => $request->user()->id,
                ]);

                $images->push($image);

                $this->auditLog->record(
                    'radiology_examination_image_uploaded',
                    $image,
                    null,
                    $image->only(['id', 'radiology_appointment_id', 'original_filename', 'mime_type', 'size_bytes']),
                    $request,
                );
            }

            return $images;
        });
    }

    /**
     * Permanently remove an image — both the database row and the
     * underlying file.
     *
     * @throws ValidationException
     */
    public function delete(RadiologyImage $image, Request $request): void
    {
        $this->assertManageable($image->radiologyAppointment);

        $this->auditLog->record(
            'radiology_examination_image_deleted',
            $image,
            $image->only(['id', 'radiology_appointment_id', 'original_filename']),
            null,
            $request,
        );

        Storage::disk('local')->delete($image->path);
        $image->delete();
    }

    /**
     * @throws ValidationException
     */
    protected function assertManageable(RadiologyAppointment $appointment): void
    {
        if ($appointment->status !== RadiologyAppointment::STATUS_IN_PROGRESS) {
            throw ValidationException::withMessages([
                'status' => "Cannot manage images for an examination with status \"{$appointment->status}\". Images can only be attached while the examination is in progress.",
            ]);
        }
    }
}
