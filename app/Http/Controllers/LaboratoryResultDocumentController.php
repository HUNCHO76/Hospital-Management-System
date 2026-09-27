<?php

namespace App\Http\Controllers;

use App\Http\Requests\LaboratoryResultDocument\UploadLaboratoryResultDocumentRequest;
use App\Models\LaboratoryResult;
use App\Models\LaboratoryResultDocument;
use App\Services\LaboratoryResultDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Machine/analyzer result document upload, streaming, and deletion (Phase 4
 * Machine Document Upload). Mirrors RadiologyImageController exactly:
 * `show()` streams raw bytes for `<a href>` download, the private disk path
 * is never sent to the browser, and every request re-authorizes via the
 * parent LaboratoryResult's policy.
 */
class LaboratoryResultDocumentController extends Controller
{
    public function store(UploadLaboratoryResultDocumentRequest $request, LaboratoryResult $result, LaboratoryResultDocumentService $service): RedirectResponse
    {
        $this->authorize('uploadDocument', $result);

        $service->upload($result, $request->file('document'), $request);

        return back()->with('success', 'Document uploaded.');
    }

    /**
     * Stream the document file back, after re-authorizing on this request —
     * never a public URL, never a raw filesystem path handed to React.
     */
    public function show(LaboratoryResult $result, LaboratoryResultDocument $document): StreamedResponse
    {
        $this->authorize('viewDocument', $result);
        $this->assertBelongsToResult($result, $document);

        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->response(
            $document->path,
            $document->original_filename,
            ['Content-Type' => $document->mime_type],
        );
    }

    public function destroy(Request $request, LaboratoryResult $result, LaboratoryResultDocument $document, LaboratoryResultDocumentService $service): RedirectResponse
    {
        $this->authorize('deleteDocument', $result);
        $this->assertBelongsToResult($result, $document);

        $service->delete($document, $request);

        return back()->with('success', 'Document removed.');
    }

    /**
     * Reject (404) a document that does not belong to the result in the URL —
     * the IDOR guard mirroring RadiologyImageController::assertBelongsToAppointment().
     */
    protected function assertBelongsToResult(LaboratoryResult $result, LaboratoryResultDocument $document): void
    {
        abort_unless($document->laboratory_result_id === $result->id, 404);
    }
}
