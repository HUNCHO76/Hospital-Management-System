<?php

namespace App\Services;

use App\Models\LaboratoryOrder;
use App\Models\LaboratoryReport;
use App\Models\LaboratoryResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Owns the laboratory report/version lifecycle (Phase 4, Step 35) — kept
 * deliberately separate from LaboratoryResultService (which owns the
 * LaboratoryResult row's own lifecycle) so neither service depends on the
 * other; LaboratoryResultController orchestrates calling both in sequence
 * (release() then generateForOrder(); amend() then supersedeActiveForOrder()
 * when the amendment came from a released result) — the same controller-
 * orchestration pattern Step 32 established for exactly this kind of
 * cross-cutting trigger between two independently-owned lifecycles.
 */
class LaboratoryReportService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Generate the next report version for an order, snapshotting every
     * currently-RELEASED result on it (spec Part 6/16). Never overwrites an
     * existing version — always inserts a new row and demotes whichever
     * version was previously active to superseded.
     */
    public function generateForOrder(LaboratoryOrder $order, Request $request): LaboratoryReport
    {
        $previousActive = null;

        $report = DB::transaction(function () use ($order, $request, &$previousActive) {
            $previousActive = LaboratoryReport::query()
                ->where('laboratory_order_id', $order->id)
                ->where('status', LaboratoryReport::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($previousActive) {
                $previousActive->update(['status' => LaboratoryReport::STATUS_SUPERSEDED]);
            }

            $lastVersion = LaboratoryReport::query()
                ->where('laboratory_order_id', $order->id)
                ->lockForUpdate()
                ->max('version') ?? 0;

            return LaboratoryReport::create([
                'laboratory_order_id' => $order->id,
                'version' => $lastVersion + 1,
                'status' => LaboratoryReport::STATUS_ACTIVE,
                'results_snapshot' => $this->buildSnapshot($order),
                'released_by' => $request->user()->id,
                'released_at' => now(),
            ]);
        });

        $this->auditLog->record(
            'laboratory_report_generated',
            $report,
            null,
            $report->only(['report_number', 'version', 'laboratory_order_id']),
            $request,
        );

        if ($previousActive) {
            $this->auditLog->record('laboratory_report_superseded', $previousActive, ['status' => LaboratoryReport::STATUS_ACTIVE], ['status' => LaboratoryReport::STATUS_SUPERSEDED], $request);
            $this->auditLog->record('laboratory_report_reissued', $report, null, ['supersedes' => $previousActive->report_number], $request);
        }

        return $report;
    }

    /**
     * Immediately invalidate an order's current report the moment one of
     * its released results is pulled back for amendment (spec Part 17) —
     * without waiting for a corrected value to actually be re-released, a
     * report known to contain a value under correction must never keep
     * presenting as current.
     */
    public function supersedeActiveForOrder(LaboratoryOrder $order, Request $request): void
    {
        DB::transaction(function () use ($order, $request) {
            $active = LaboratoryReport::query()
                ->where('laboratory_order_id', $order->id)
                ->where('status', LaboratoryReport::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if (! $active) {
                return;
            }

            $active->update(['status' => LaboratoryReport::STATUS_SUPERSEDED]);

            $this->auditLog->record(
                'laboratory_report_superseded',
                $active,
                ['status' => LaboratoryReport::STATUS_ACTIVE],
                ['status' => LaboratoryReport::STATUS_SUPERSEDED],
                $request,
            );
        });
    }

    /**
     * Record that a report was printed (spec Part 21) — the live column
     * always reflects only the most recent print; the full history remains
     * in the audit log, matching every other "most recent" actor field
     * already established on LaboratoryResult itself.
     */
    public function print(LaboratoryReport $report, Request $request): LaboratoryReport
    {
        $report->update(['printed_by' => $request->user()->id, 'printed_at' => now()]);

        $this->auditLog->record('laboratory_report_printed', $report, null, $report->only(['printed_by', 'printed_at']), $request);

        return $report;
    }

    /**
     * Record that a report was downloaded (spec Part 22).
     */
    public function download(LaboratoryReport $report, Request $request): LaboratoryReport
    {
        $report->update(['downloaded_by' => $request->user()->id, 'downloaded_at' => now()]);

        $this->auditLog->record('laboratory_report_downloaded', $report, null, $report->only(['downloaded_by', 'downloaded_at']), $request);

        return $report;
    }

    /**
     * Freeze exactly what should appear on this order's report right now —
     * every currently-RELEASED result, grouped implicitly by sample via
     * each entry's own sample_number (spec Part 13 — never mix results from
     * different samples). Only display-safe, human-readable values are
     * captured (names, not raw user/model IDs — spec Part 14) since this
     * becomes part of a printed clinical document. Patient/order/sample
     * header information is deliberately NOT duplicated here — it is read
     * live from the order's own relations when the report is rendered,
     * since (unlike a result's own catalog-derived interpretation fields)
     * it is not the kind of data that legitimately changes underneath a
     * historical document.
     *
     * @return list<array<string, mixed>>
     */
    protected function buildSnapshot(LaboratoryOrder $order): array
    {
        $results = LaboratoryResult::query()
            ->where('status', LaboratoryResult::STATUS_RELEASED)
            ->whereHas('item', fn ($q) => $q->where('laboratory_order_id', $order->id))
            ->with(['item', 'sample', 'enteredBy:id,UserName', 'verifiedBy:id,UserName', 'authorizedBy:id,UserName', 'releasedBy:id,UserName'])
            ->get();

        return $results->map(fn (LaboratoryResult $result) => [
            'laboratory_result_id' => $result->id,
            'sample_number' => $result->sample?->sample_number,
            'specimen_type' => $result->sample?->specimen_type,
            'test_code' => $result->item?->test_code,
            'test_name' => $result->item?->test_name,
            'result_type' => $result->result_type,
            'value_numeric' => $result->value_numeric,
            'value_text' => $result->value_text,
            'value_code' => $result->value_code,
            'unit' => $result->unit,
            'reference_range' => $result->reference_range,
            'abnormal_flag' => $result->abnormal_flag,
            'result_comment' => $result->result_comment,
            'entered_by' => $result->enteredBy?->UserName,
            'verified_by' => $result->verifiedBy?->UserName,
            'verified_at' => optional($result->verified_at)->toIso8601String(),
            'authorized_by' => $result->authorizedBy?->UserName,
            'authorized_at' => optional($result->authorized_at)->toIso8601String(),
            'released_by' => $result->releasedBy?->UserName,
            'released_at' => optional($result->released_at)->toIso8601String(),
        ])->values()->all();
    }
}
