<?php

namespace App\Http\Controllers;

use App\Http\Requests\LaboratoryReport\DownloadLaboratoryReportRequest;
use App\Http\Requests\LaboratoryReport\PrintLaboratoryReportRequest;
use App\Http\Resources\LaboratoryReportResource;
use App\Models\LaboratoryReport;
use App\Services\LaboratoryReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The laboratory report viewer (Phase 4, Step 35) — one printable/
 * downloadable document per released report version. Every route is
 * protected by LaboratoryReportPolicy (permission + hospital scope), so a
 * report can never be reached by guessing its id (spec Part 22/24/32/39).
 */
class LaboratoryReportController extends Controller
{
    protected function eagerLoads(): array
    {
        return [
            'laboratoryOrder.clinicalEncounter.patient',
            'laboratoryOrder.clinicalEncounter.department.hospital',
            'laboratoryOrder.orderedBy:id,UserName',
            'releasedBy:id,UserName',
            'printedBy:id,UserName',
            'downloadedBy:id,UserName',
        ];
    }

    /**
     * View a single report version. The full version history for its order
     * is listed alongside it (spec Part 37) rather than living on a
     * separate route — every version is already a `LaboratoryReport` row
     * for the same order.
     */
    public function show(Request $request, LaboratoryReport $report): Response
    {
        $this->authorize('view', $report);

        $report->load($this->eagerLoads());

        $versions = LaboratoryReport::query()
            ->where('laboratory_order_id', $report->laboratory_order_id)
            ->orderByDesc('version')
            ->get(['id', 'version', 'status', 'released_at']);

        return Inertia::render('laboratory/reports/show', [
            'report' => (new LaboratoryReportResource($report))->resolve($request),
            'versions' => $versions->map(fn (LaboratoryReport $version) => [
                'id' => $version->id,
                'version' => $version->version,
                'status' => $version->status,
                'released_at' => optional($version->released_at)->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Stamp printed_by/printed_at before the browser's own print dialog
     * opens client-side (spec Part 21) — this app has no PDF-generation
     * dependency, so "print" and "download" both use the browser's native
     * print-to-PDF capability against the same report template, mirroring
     * the exact pattern already established by LaboratorySampleController::
     * label().
     */
    public function print(PrintLaboratoryReportRequest $request, LaboratoryReport $report, LaboratoryReportService $service): RedirectResponse
    {
        $this->authorize('print', $report);

        $service->print($report, $request);

        return back();
    }

    /**
     * Stamp downloaded_by/downloaded_at before the browser's own
     * print-to-PDF/save dialog opens client-side (spec Part 22).
     */
    public function download(DownloadLaboratoryReportRequest $request, LaboratoryReport $report, LaboratoryReportService $service): RedirectResponse
    {
        $this->authorize('download', $report);

        $service->download($report, $request);

        return back();
    }
}
