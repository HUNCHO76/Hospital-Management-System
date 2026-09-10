# Diagnosis & Clinical Coding (Phase 3, Step 24)

## The DiagnosisCode and EncounterDiagnosis entities

`DiagnosisCode` is a reusable catalog entry (e.g. an ICD-10 code) — master
data, not a clinical record. `EncounterDiagnosis` is the clinician-confirmed
diagnosis recorded against an encounter, referencing a `DiagnosisCode` rather
than duplicating its code/description. This keeps "clinical diagnosis" (the
selection, per encounter) separate from "coding" (the catalog itself, spec
§9) — no diagnosis-related structure existed anywhere in the codebase before
this step (confirmed by inspection before implementing).

```
ClinicalEncounter
  |
  +-- consultation        (App\Models\Consultation, at most one — Step 23)
  |
  +-- diagnoses           (App\Models\EncounterDiagnosis, hasMany, oldest first)
         |
         +-- diagnosisCode  (App\Models\DiagnosisCode, belongsTo — the catalog entry selected)
```

`diagnosis_code_id` is required, not optional — nothing in this app has a
free-text diagnosis dictionary distinct from coding, and the workflow (spec
§10/§26) is exclusively search → select a coded diagnosis. "Clinical
diagnosis" and "code selection" are the same act here, which is also how spec
§15 ("never automatically diagnose") is satisfied — nothing is ever inferred
or free-typed into a diagnosis.

## No separate coding_systems table

`diagnosis_codes.coding_system` (default `icd-10`) carries the same future
extensibility a join table would (a new code can simply use a different
value, e.g. `icd-11`) without a table that would only ever hold a handful of
rows. `DiagnosisCodeSeeder` loads a representative ~50-code ICD-10 starter
set spanning common categories (infectious, respiratory, digestive,
circulatory, injury, symptoms/signs, endocrine, musculoskeletal) — **not** a
full ICD-10-CM import, which has tens of thousands of codes and isn't
available in this project. A production deployment would load the complete
official dataset into this same table.

## Multiple diagnoses, one active primary

Unlike `TriageAssessment`/`Consultation` (at most one per encounter),
multiple diagnoses are expected per encounter — no unique constraint on
`clinical_encounter_id`. The "one active primary" rule (spec §12) is enforced
in `DiagnosisService`, not a database constraint, since a portable partial
unique index isn't available across this project's supported databases:

- `create()` rejects a `primary` diagnosis outright if an active
  (`provisional`/`confirmed`) primary already exists on the encounter — the
  caller must add it as `secondary` first.
- `setPrimary()` is the only way a primary changes: it locks the encounter
  row (`lockForUpdate()`, same pattern as `TriageService`/`ConsultationService`),
  demotes any existing active primary to secondary, then promotes the
  target, all inside one transaction. Both changes are audited.

This means a primary is never silently replaced — the frontend's "Set as
Primary" action is a confirmation dialog, not a plain field edit (spec §26).

## Status lifecycle

`EncounterDiagnosis::TRANSITIONS` is the single source of truth for the
generic status-change path, enforced in `DiagnosisService::changeStatus()`:

```
provisional → confirmed | cancelled
confirmed   → resolved | cancelled
resolved, cancelled → (terminal)
```

Cancellation is deliberately excluded from `changeStatus()` and from the
`TRANSITIONS`-driven request (`ChangeDiagnosisStatusRequest` only accepts
`confirmed`/`resolved`) — it's its own action
(`DiagnosisService::cancel()`) requiring a mandatory reason, the same split
every other clinical module in this app uses for cancellation. This is also
why both `diagnosis_status_changed` and `diagnosis_cancelled` exist as
separate audit actions.

## Mutability & the correction workflow

Plain `update()` only works while `provisional` — a `confirmed`/`resolved`
diagnosis is a committed clinical record. Amending one requires
`DiagnosisService::correct()`, which stamps `corrected_at`/`corrected_by`/
`correction_reason` and relies on the audit log's old/new diff as the "what
changed" record (spec §22) — no separate correction-log table. A `cancelled`
diagnosis cannot be corrected (nothing to correct on a voided record) and a
still-`provisional` one doesn't need to be (use `update()` instead).

## Consultation dependency

`DiagnosisService::assertConsultationEligible()` requires the encounter's
`Consultation` to exist and be `in_progress` or `completed` (spec §11) —
diagnosis is part of documenting the visit, which may happen before or after
the consultation note is marked complete, but never before it has actually
been started. No emergency-bypass path was introduced, consistent with Step
23's own choice not to model one.

## Diagnosis-code search

The lightweight web JSON endpoint `/diagnosis-codes/search-json`
(2-character minimum, capped at 10 results) goes through
`DiagnosisCodeSearchService`, mirroring `PatientSearchService`'s shape
exactly — no versioned API (see `docs/api-standards.md`). The full catalog
is never loaded client-side (spec §8/§34) — `DiagnosisCodeSearch` (a
combobox copying `PatientSelector`'s structure) queries the search-json
endpoint with a 300 ms debounce and a request-id guard against stale
responses.

## Authorization & ownership

Permissions (`database/seeders/PermissionSeeder.php`): `diagnosis.view`,
`.create`, `.update`, `.primary`, `.status`, `.cancel`, `.correct`;
`diagnosis-codes.view` for the catalog. `hospital-admin` and `doctor` hold
the full set; `nurse`/`receptionist` hold neither — diagnosis is a
doctor-scope action, the same split Step 23 uses for `consultation.*`.

`EncounterDiagnosisPolicy::ownsOrSupervises()` anchors ownership to **the
encounter's own consultation practitioner**, not to `diagnosed_by` on the
individual diagnosis row — the doctor who owns the encounter's consultation
owns all of its diagnoses, matching `ConsultationPolicy`'s equivalent method.
Unlike `Consultation`/`TriageAssessment` (which have no prior owner to check
at creation time), `create()` here receives the route-bound
`ClinicalEncounter` as extra policy context (`Gate::authorize('create',
[EncounterDiagnosis::class, $clinicalEncounter])`) so ownership is enforced
even for the very first diagnosis on an encounter, since the consultation
(with its assigned practitioner) already exists by that point.

## IDOR protection

Diagnoses need a real `{diagnosis}` route segment (unlike triage/
consultation's singleton pattern). Every action verifies
`$diagnosis->clinical_encounter_id === $clinicalEncounter->id` via an
`assertBelongsToEncounter()` guard (copied from `VitalSignController`) before
authorizing or touching the model — substituting another encounter's id in
the URL returns 404, never another patient's diagnosis.

## Web routes (Inertia)

Diagnoses are **not** a standalone page — rendered inline on the existing
`clinical-encounters/show` screen via `DiagnosisSection`, the same pattern as
`TriagePanel`/`ConsultationPanel`. The web routes exist only as POST/PUT
action targets that redirect back to that same page:

```
POST {clinicalEncounter}/diagnoses
PUT  {clinicalEncounter}/diagnoses/{diagnosis}
POST {clinicalEncounter}/diagnoses/{diagnosis}/primary
POST {clinicalEncounter}/diagnoses/{diagnosis}/status
POST {clinicalEncounter}/diagnoses/{diagnosis}/cancel
POST {clinicalEncounter}/diagnoses/{diagnosis}/correct

GET  diagnosis-codes/search-json
```

No new sidebar entry was added, per the same spec guidance Step 22/23 used.

*A parallel `/api/v1/clinical-encounters/{clinicalEncounter}/diagnoses` and
`/api/v1/diagnosis-codes` versioned API existed alongside these routes and
was removed in the HMS Route Architecture Migration (no consumer, no
external-integration need — see `docs/api-standards.md`); this web surface
already covered every action it offered.*

## Consultation → Diagnosis integration

`ClinicalEncounterController::show()` eager-loads the encounter's diagnoses
(with their diagnosis code, `diagnosedBy`, `correctedBy`) and passes them as
a `diagnoses` prop alongside the existing `triage`/`consultation` props.
`DiagnosisSection` shows a locked state until the consultation is
`in_progress`/`completed`, then a list of existing diagnoses (primary/
secondary + status badges, per-row actions gated by permission) plus an
inline "Add Diagnosis" form (code search → Primary/Secondary radio, disabled
on Primary when one is already active → optional clinical note).

## Patient History integration

`PatientHistoryService::timeline()` was extended to include
`EncounterDiagnosis` audit-log entries, scoped through the patient's own
encounter ids — the same audit-log-only pattern already used for triage/
vital-signs/appointments. Only the action name, timestamp, and user surface
there, never the diagnosis, code, or clinical-note content (spec §29). Note:
`Consultation` (Step 23) was not previously wired into this timeline; that
pre-existing gap was left as-is per this step's "implement only Step 24"
scope rather than bundled into this change.

## Testing

`tests/Feature/Diagnosis/`: creation (valid/invalid codes, inactive-code
rejection, consultation-dependency gate, encounter-status gate), the full
provisional → confirmed → resolved lifecycle, the dedicated cancel path,
primary/secondary classification including the create-time duplicate-primary
rejection, `setPrimary()`'s promote-and-demote behavior, the correction
workflow (confirmed/resolved only, audit trail), role-separation and
hospital-scoped IDOR authorization, ownership (one doctor cannot touch
another's consultation's diagnoses), mass-assignment protection, and the
concurrency invariant for simultaneous primary promotions. A full HTTP
end-to-end test verifies triage → consultation → primary + secondary
diagnosis → confirm → resolve, every audit event, and patient-timeline
surfacing. (The former `tests/Feature/Api/V1/DiagnosisApiTest.php`/
`DiagnosisCodeApiTest.php` covered the same surface through the now-removed
JSON API — see `docs/api-standards.md`.)

## Future integration boundary

Diagnosis coding for insurance/NHIF claims, treatment/care-plan, prescription,
lab, pharmacy, billing, and admission/discharge are explicitly out of scope.
No NHIF/insurance structure currently references a diagnosis code (confirmed
by inspection before implementing) — a future claims module can consume
`EncounterDiagnosis`/`DiagnosisCode` by id without any new integration
surface.
