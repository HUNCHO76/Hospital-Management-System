<?php

namespace App\Services;

use App\Models\ClinicalEncounter;
use App\Models\ClinicalNote;
use App\Models\ClinicalNoteVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClinicalNoteService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Record a new draft clinical note against an encounter. patient_id is
     * always derived from the encounter, never trusted from the request, so
     * a note can never be attached to a patient other than the encounter's
     * own.
     *
     * @param  array{note_type: string, note_content: string}  $data
     *
     * @throws ValidationException
     */
    public function create(ClinicalEncounter $encounter, array $data, Request $request): ClinicalNote
    {
        $this->assertEncounterEligible($encounter);

        $note = ClinicalNote::create([
            'clinical_encounter_id' => $encounter->id,
            'patient_id' => $encounter->patient_id,
            'author_id' => $request->user()->id,
            'note_type' => $data['note_type'],
            'note_content' => $data['note_content'],
            'status' => ClinicalNote::STATUS_DRAFT,
            'note_category' => $data['note_category'] ?? null,
        ]);

        // Never log the note's own content — only metadata (spec's audit
        // requirement to avoid persisting sensitive clinical text in logs).
        $this->auditLog->record(
            'clinical_note_created',
            $note,
            null,
            $note->only(['clinical_encounter_id', 'patient_id', 'note_type', 'note_category', 'status']),
            $request,
        );

        return $note;
    }

    /**
     * Update a draft note's editable fields. Once signed, content is
     * immutable — only amend() can change it.
     *
     * @param  array{note_type?: string, note_content?: string}  $data
     *
     * @throws ValidationException
     */
    public function update(ClinicalNote $note, array $data, Request $request): ClinicalNote
    {
        if ($note->status !== ClinicalNote::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => "Cannot modify a clinical note with status \"{$note->status}\". Use the amend action instead.",
            ]);
        }

        $note->update($data);

        $this->auditLog->record('clinical_note_updated', $note, null, $note->only(['note_type', 'status']), $request);

        return $note;
    }

    /**
     * Sign a draft note, freezing its content as the note's first version.
     * Lock-guarded so two simultaneous sign attempts on the same note can
     * never both succeed.
     *
     * @throws ValidationException
     */
    public function sign(ClinicalNote $note, Request $request): ClinicalNote
    {
        return DB::transaction(function () use ($note, $request) {
            $note = ClinicalNote::query()->whereKey($note->id)->lockForUpdate()->firstOrFail();

            if (! $note->canTransitionTo(ClinicalNote::STATUS_SIGNED)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot sign a clinical note with status \"{$note->status}\".",
                ]);
            }

            $note->update([
                'status' => ClinicalNote::STATUS_SIGNED,
                'signed_at' => now(),
                'signed_by' => $request->user()->id,
            ]);

            $version = ClinicalNoteVersion::create([
                'clinical_note_id' => $note->id,
                'version_number' => 1,
                'note_type' => $note->note_type,
                'note_content' => $note->note_content,
                'action' => 'signed',
                'author_id' => $request->user()->id,
                'reason' => null,
            ]);

            $this->auditLog->record('clinical_note_signed', $note, null, $note->only(['status', 'signed_at', 'signed_by']), $request);
            $this->auditLog->record('clinical_note_version_created', $version, null, ['version_number' => 1, 'action' => 'signed'], $request);

            return $note;
        });
    }

    /**
     * Amend a signed/amended note: the pre-amendment content is preserved as
     * its own version row before the note's live content is updated to the
     * new text, so the original is never lost. Lock-guarded so concurrent
     * amendments never produce a duplicate version_number (never a bare
     * count()+1 without the lock).
     *
     * @param  array{note_type?: string, note_content?: string}  $data
     *
     * @throws ValidationException
     */
    public function amend(ClinicalNote $note, array $data, string $reason, Request $request): ClinicalNote
    {
        return DB::transaction(function () use ($note, $data, $reason, $request) {
            $note = ClinicalNote::query()->whereKey($note->id)->lockForUpdate()->firstOrFail();

            if (! $note->canTransitionTo(ClinicalNote::STATUS_AMENDED)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot amend a clinical note with status \"{$note->status}\".",
                ]);
            }

            $nextVersionNumber = (int) $note->versions()->max('version_number') + 1;

            $version = ClinicalNoteVersion::create([
                'clinical_note_id' => $note->id,
                'version_number' => $nextVersionNumber,
                'note_type' => $data['note_type'] ?? $note->note_type,
                'note_content' => $data['note_content'] ?? $note->note_content,
                'action' => 'amended',
                'author_id' => $request->user()->id,
                'reason' => $reason,
            ]);

            $note->update([
                ...$data,
                'status' => ClinicalNote::STATUS_AMENDED,
                'corrected_at' => now(),
                'corrected_by' => $request->user()->id,
                'correction_reason' => $reason,
            ]);

            $this->auditLog->record(
                'clinical_note_amended',
                $note,
                null,
                $note->only(['status', 'corrected_at', 'corrected_by', 'correction_reason']),
                $request,
            );
            $this->auditLog->record(
                'clinical_note_version_created',
                $version,
                null,
                ['version_number' => $nextVersionNumber, 'action' => 'amended'],
                $request,
            );

            return $note;
        });
    }

    /**
     * Cancel a draft note, recording who/when/why. Only reachable from
     * draft — a signed note is amended, never cancelled.
     *
     * @throws ValidationException
     */
    public function cancel(ClinicalNote $note, string $reason, Request $request): ClinicalNote
    {
        if (! $note->canTransitionTo(ClinicalNote::STATUS_CANCELLED)) {
            throw ValidationException::withMessages([
                'status' => "Cannot cancel a clinical note with status \"{$note->status}\".",
            ]);
        }

        $note->update([
            'status' => ClinicalNote::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        $this->auditLog->record('clinical_note_cancelled', $note, null, $note->only(['status', 'cancelled_at']), $request);

        return $note;
    }

    /**
     * @throws ValidationException
     */
    protected function assertEncounterEligible(ClinicalEncounter $encounter): void
    {
        if ($encounter->status === ClinicalEncounter::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'clinical_encounter_id' => 'Cannot record a clinical note on a cancelled encounter.',
            ]);
        }
    }
}
