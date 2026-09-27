<?php

namespace App\Services;

use App\Models\LaboratoryResult;
use App\Models\LaboratoryResultDocument;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Machine/analyzer document upload/delete for a single LaboratoryResult
 * (Phase 4 Machine Document Upload). Files are stored on the private `local`
 * disk (never `public`) and served only through the authenticated
 * LaboratoryResultDocumentController::show() action. Mirrors
 * RadiologyImageService exactly.
 */
class LaboratoryResultDocumentService
{
    /**
     * Content-sniffed MIME types accepted — PDF and plain image formats from
     * lab analyzers.
     *
     * @var list<string>
     */
    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];

    public const MAX_FILE_SIZE_KB = 20480; // 20 MB per file.

    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Attach a machine/analyzer document to an editable result.
     *
     * @throws ValidationException
     */
    public function upload(LaboratoryResult $result, UploadedFile $file, Request $request): LaboratoryResultDocument
    {
        $this->assertEditable($result);

        $path = $file->store("laboratory-result-documents/{$result->id}", 'local');

        $document = LaboratoryResultDocument::create([
            'laboratory_result_id' => $result->id,
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        // Mark that this result has machine documentation — stays once set
        // even if all documents are later deleted.
        if ($result->result_source !== LaboratoryResult::RESULT_SOURCE_MACHINE_DOCUMENT) {
            $result->update(['result_source' => LaboratoryResult::RESULT_SOURCE_MACHINE_DOCUMENT]);
        }

        $this->auditLog->record(
            'laboratory_result_document_uploaded',
            $document,
            null,
            $document->only(['id', 'laboratory_result_id', 'original_filename', 'mime_type', 'size_bytes']),
            $request,
        );

        return $document;
    }

    /**
     * Permanently remove a document — both the database row and the
     * underlying file.
     *
     * @throws ValidationException
     */
    public function delete(LaboratoryResultDocument $document, Request $request): void
    {
        $result = $document->laboratoryResult;

        $this->assertEditable($result);

        $this->auditLog->record(
            'laboratory_result_document_deleted',
            $document,
            $document->only(['id', 'laboratory_result_id', 'original_filename']),
            null,
            $request,
        );

        Storage::disk('local')->delete($document->path);
        $document->delete();
    }

    /**
     * @throws ValidationException
     */
    protected function assertEditable(LaboratoryResult $result): void
    {
        $editableStatuses = [
            LaboratoryResult::STATUS_RESULT_PENDING,
            LaboratoryResult::STATUS_DRAFT,
            LaboratoryResult::STATUS_RETURNED_FOR_CORRECTION,
        ];

        if (! in_array($result->status, $editableStatuses, true)) {
            throw ValidationException::withMessages([
                'status' => "Cannot manage documents for a result with status \"{$result->status}\".",
            ]);
        }
    }
}
