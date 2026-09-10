# Clinical Notes & Encounter History (Phase 3, Step 26)

## Existing functionality audit

No `ClinicalNote`/`MedicalNote`/`ProgressNote`/versioning structure existed
anywhere before this step. The only near-hit was `consultations.clinical_notes`
— a single nullable text column that is just one field of `Consultation`'s
own SOAP documentation (alongside `chief_complaint`, `assessment_summary`,
`plan_summary`, etc.), mutable only in that consultation's own edit window
and correctable via `Consultation::correct()`. It is not a multi-entry,
multi-author, typed, signed, versioned note system, and this step leaves it
completely untouched — `ClinicalNote` is a genuinely new, distinct entity for
documentation that spans the encounter (nursing notes, progress notes,
follow-ups, observations) rather than one consultation's own record.

`PatientHistoryService`/`patients/history.tsx` (Step 16) is deliberately
non-clinical — its own doc-comment states *"No diagnosis, treatment, or other
clinical detail ever appears here"* — and is reachable via `patients.view`, a
permission held by receptionist, pharmacist, and lab technician too. Folding
clinical content into it would be a privacy regression. Per the anti-
duplication requirement for patient history, the resolution was to reuse the
same `PatientController` and add one sibling action (`clinicalHistory()`)
rather than a second `PatientHistoryController` — the existing `history()`
action/page/service is unmodified.

## The ClinicalNote and ClinicalNoteVersion entities

```
ClinicalEncounter
  |
  +-- clinicalNotes   (App\Models\ClinicalNote, hasMany — many per encounter)
         |
         +-- versions  (App\Models\ClinicalNoteVersion, hasMany)
```

`ClinicalNote` holds the **current/live state** of a note (mirroring how
`Consultation`/`CarePlan` keep their live state on the row itself and stamp
`corrected_*` on correction). `ClinicalNoteVersion` is the append-only,
immutable history table this step specifically requires for notes — a
genuine architectural first in this app, since every other clinical module's
correction workflow mutates the row in place with only a single set of
`corrected_at`/`corrected_by`/`correction_reason` columns and no full version
table.

`note_type` is one of six categories justified by the existing clinical
workflow: `progress`, `follow_up`, `nursing`, `clinical_observation`,
`procedure`, `other`. `patient_id` is denormalized from the encounter and set
by `ClinicalNoteService` from `$encounter->patient_id` — never trusted from
the request — so a note can never be attached to a patient other than the
encounter's own.

## Note lifecycle

```
draft   -> signed | cancelled
signed  -> amended
amended -> amended
```

- **Draft**: freely editable by its author. Cancellable with a mandatory
  reason.
- **Sign**: freezes the draft content as version 1 in `clinical_note_versions`
  and locks the note against plain edits. Lock-guarded
  (`lockForUpdate()` on the note row inside `DB::transaction()`) so two
  simultaneous sign attempts on the same note can never both succeed.
- **Amend**: only from `signed`/`amended`, requires a reason, and is the
  *only* way to change a finalized note's content. The pre-amendment content
  is written to `clinical_note_versions` first, then the note's own live
  content is updated — so the original is never lost. A signed note is
  **never cancelled**, only amended, preserving its history permanently.

## Versioning

`version_number` is assigned as `$note->versions()->max('version_number') + 1`
**while the note row is locked** — the same "lock the parent, then act"
pattern `CarePlanService`/`DiagnosisService` already use for their own
create-time invariants. This is never a bare `count()+1` without the lock,
which would race under concurrent amendments. `clinical_note_versions` has a
`unique(clinical_note_id, version_number)` database constraint as a second
line of defense. `ClinicalNoteConcurrencyTest` proves both invariants: only
one of several back-to-back sign attempts succeeds, and several back-to-back
amendments never produce a duplicate version number.

## Permissions

`PermissionSeeder`: `clinical-notes.view`, `.create`, `.update`, `.sign`,
`.amend`, `.cancel`; `clinical-history.view` (gates the patient-level
clinical timeline page only — the single-encounter "Encounter History"
section on `clinical-encounters/show` needs no new permission, since it's
just that page displaying data each already gated by its own domain
permission, the same conditional-section pattern the page already uses for
triage/consultation/diagnosis/treatment).

`RoleSeeder`: `hospital-admin` and `doctor` hold the full `clinical-notes.*`
set; `nurse` holds the full set too (a nurse authors and finalizes their own
nursing documentation); `receptionist`/`pharmacist`/`laboratory-technician`/
`accountant` hold none — an explicit non-clinical boundary, matching
`clinical-encounters.*` today.

## Authorization & ownership

`ClinicalNotePolicy::ownsOrSupervises()` anchors ownership directly to the
note's own `author_id` (not a practitioner record, since a note's author is
whichever clinical user — doctor or nurse — created it) plus a
hospital-admin/super-admin override, mirroring `ConsultationPolicy`'s shape.
`view()` has no ownership restriction — any authorized clinical staff in the
same hospital may read a note for handoffs. `update()`/`sign()`/`amend()`/
`cancel()` are all author-scoped: a doctor cannot amend or sign a nurse's
note, and vice versa, except for a hospital-admin acting within their own
hospital.

`PatientPolicy::viewClinicalHistory()` (added to the existing policy rather
than a new one, since a second policy class cannot be auto-discovered for
the same `Patient` model) checks the separate `clinical-history.view`
permission — distinct from `PatientPolicy::view()`'s much more broadly held
`patients.view`.

## IDOR protection

`ClinicalNoteController::assertBelongsToEncounter()` verifies
`$note->clinical_encounter_id === $clinicalEncounter->id` before every
`{clinicalNote}` action, copying `EncounterDiagnosisController`'s guard
exactly. The patient clinical-history route is resolved from `{patient}`
directly, so no cross-patient leakage is possible via a mismatched id;
`ClinicalHistoryTest` proves requesting one patient's timeline never
surfaces another patient's encounters.

## Query strategy — ClinicalHistoryService (aggregation only, no new storage)

`ClinicalHistoryService::timeline()` queries `ClinicalEncounter::where('patient_id', ...)`,
eager-loading `department`, `practitioner.staff`, `triageAssessment`,
`consultation`, `diagnoses.diagnosisCode`, `carePlan.items`,
`clinicalNotes.author` in one pass, and paginates **by encounter** (10 per
page) rather than flattening every child record — avoiding an unbounded load
of a patient's entire lifetime record. Filters (date range, department,
practitioner, encounter type, status, diagnosis, note type) apply to this
same query via `where`/`whereHas`, all on already-indexed columns. No
unrestricted full-text search over note content is exposed — search support
is limited to encounter number and diagnosis code/description, per the
privacy/performance requirement to avoid indexing sensitive note text for
free-text search.

This file is entirely separate from `PatientHistoryService`, which continues
unchanged: one non-clinical (Step 16), one clinical (this step), gated by
different permissions, both reading from the same authoritative tables
without duplicating any of their data into new storage.

## Route architecture (web + Inertia only — nothing in `routes/api.php`)

Clinical notes are **not** a standalone page — rendered inline on the
existing `clinical-encounters/show` screen via `ClinicalNotesSection`, the
sixth stacked panel after `CarePlanSection`, the same pattern every prior
clinical module in this app uses:

```
POST clinical-encounters/{clinicalEncounter}/clinical-notes                        clinical-notes.store
PUT  clinical-encounters/{clinicalEncounter}/clinical-notes/{clinicalNote}          clinical-notes.update
POST clinical-encounters/{clinicalEncounter}/clinical-notes/{clinicalNote}/sign     clinical-notes.sign
POST clinical-encounters/{clinicalEncounter}/clinical-notes/{clinicalNote}/amend    clinical-notes.amend
POST clinical-encounters/{clinicalEncounter}/clinical-notes/{clinicalNote}/cancel   clinical-notes.cancel

GET  patients/{patient}/clinical-history                                            patients.clinical-history
```

No `ClinicalNoteVersionController`, `ClinicalHistoryController`, or
`EncounterHistoryController` was created: version history ships as a nested
`versions` array inside `ClinicalNoteResource` (avoiding a redundant
read-only surface), the single-encounter history section is satisfied by
`ClinicalEncounterController::show()`'s existing props, and the patient
timeline is satisfied by one new action on the existing `PatientController`.
No route was added to `routes/api.php` — this step follows the HMS Route
Architecture Migration (see `docs/api-standards.md`). No sidebar entry was
added, per the same convention every prior clinical module used — reached
via the existing "Clinical Encounters" nav item or a new "Clinical History"
button on the patient profile.

## Security

XSS: note content is always rendered as plain React text (default escaping),
never `dangerouslySetInnerHTML`. Mass assignment: every write goes through a
Form Request with an explicit field list (`note_type`/`note_content`/
`amendment_reason`/`cancellation_reason` only) — `status`, `author_id`, and
`patient_id` can never be set from the request, proven by
`ClinicalNoteAuthorizationTest`. Sensitive logging: every
`AuditLogService::record()` call for notes passes only metadata
(`note_type`, `status`, timestamps) — never `note_content` — proven by
`ClinicalNoteTest`.

## Testing

`tests/Feature/ClinicalNotes/`: `ClinicalNoteTest` (draft → signed → amended
lifecycle, draft-only mutability and cancellation, sequential versioning,
audit content-stripping), `ClinicalNoteAuthorizationTest` (role separation
including the receptionist boundary, author-only update/sign/amend,
hospital-admin override, hospital-scoped IDOR, mass-assignment protection,
super-admin bypass), `ClinicalNoteConcurrencyTest` (double-sign and duplicate
version-number invariants), `ClinicalHistoryTest` (permission gate, patient
data isolation, filtering, audit-on-view), `ClinicalNotesEndToEndTest` (the
full HTTP chain: triage → nursing note → consultation → diagnosis →
treatment → progress note → sign → amend → verify the original version
survives → view patient clinical history → every audit event present). New
`tests/Pest.php` helper: `clinicalNoteReadyEncounter()` (a non-cancelled
encounter with a doctor and a nurse — no consultation/diagnosis prerequisite,
since a nursing note may be recorded before a doctor is ever involved).

## Future integration boundary

Pharmacy dispensing, laboratory, radiology, billing, NHIF claims,
admissions, discharge, surgery, and any AI-assisted documentation or
diagnosis are explicitly out of scope for this step.
