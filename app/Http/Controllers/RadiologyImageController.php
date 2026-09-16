<?php

namespace App\Http\Controllers;

use App\Http\Requests\RadiologyImage\UploadRadiologyImageRequest;
use App\Http\Resources\RadiologyImageResource;
use App\Models\RadiologyAppointment;
use App\Models\RadiologyImage;
use App\Services\RadiologyImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Radiology examination imaging: attach/remove/view X-Ray images against
 * one examination. Kept as its own controller, mirroring this module's
 * established one-controller-per-workflow-surface convention. `show()` is
 * the one route in this whole module that isn't an Inertia page — it
 * streams raw image bytes for `<img>` tags, exactly like any other
 * authenticated-asset route; the path on disk is never sent to the browser
 * (RadiologyImageResource never includes it).
 */
class RadiologyImageController extends Controller
{
    public function store(UploadRadiologyImageRequest $request, RadiologyAppointment $radiologyAppointment, RadiologyImageService $service): RedirectResponse
    {
        $this->authorize('manageImages', $radiologyAppointment);

        $service->upload($radiologyAppointment, $request->file('images'), $request);

        return back()->with('success', 'Images uploaded.');
    }

    public function destroy(Request $request, RadiologyAppointment $radiologyAppointment, RadiologyImage $radiologyImage, RadiologyImageService $service): RedirectResponse
    {
        $this->authorize('manageImages', $radiologyAppointment);
        $this->assertBelongsToAppointment($radiologyAppointment, $radiologyImage);

        $service->delete($radiologyImage, $request);

        return back()->with('success', 'Image removed.');
    }

    /**
     * Stream the image file back, after re-authorizing on this request —
     * never a public URL, never a raw filesystem path handed to React.
     */
    public function show(RadiologyAppointment $radiologyAppointment, RadiologyImage $radiologyImage): StreamedResponse
    {
        $this->authorize('viewImages', $radiologyAppointment);
        $this->assertBelongsToAppointment($radiologyAppointment, $radiologyImage);

        abort_unless(Storage::disk('local')->exists($radiologyImage->path), 404);

        return Storage::disk('local')->response(
            $radiologyImage->path,
            $radiologyImage->original_filename,
            ['Content-Type' => $radiologyImage->mime_type],
        );
    }

    /**
     * Reject (404) an image id that does not actually belong to the
     * appointment named in the URL — the same IDOR guard every nested
     * Radiology route in this app already applies (mirrors
     * EncounterRadiologyOrderController::assertItemBelongsToOrder()).
     */
    protected function assertBelongsToAppointment(RadiologyAppointment $appointment, RadiologyImage $image): void
    {
        abort_unless($image->radiology_appointment_id === $appointment->id, 404);
    }
}
