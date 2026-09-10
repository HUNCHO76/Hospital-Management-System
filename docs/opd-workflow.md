# OPD Workflow Integration & Testing (Phase 3, Step 27)

## The master workflow

```
Reception Check-in
      |
Clinical Encounter (OPEN)
      |
Triage --------------------------+
      |                          |
Vital Signs                      |  TriageAssessment::CATEGORY_EMERGENCY
      |                          |  flags acuity for worklist sorting —
Consultation (requires           |  no bypass of the gate below is
 triage.status = completed) <----+  implemented (see "Emergency path").
      |
Diagnosis (requires an engaged consultation)
      |
Orders / Treatment (CarePlan + CarePlanItem, one plan per encounter,
      |                          many items — see "Order architecture")
      |
Clinical Notes (many per encounter, independent of the items above)
      |
Disposition + Complete (ClinicalEncounter.disposition)
      |
Patient Clinical History (ClinicalHistoryService — aggregation only)
```

Every arrow above already existed after Steps 21-26; Step 27 added the
`disposition` outcome, the `laboratory`/`radiology` order categories, and a
read-only workflow-status view — see "What Step 27 actually changed" below.

## Clinical encounter lifecycle vs. disposition

`ClinicalEncounter.status` (`open` → `in_progress` → `completed`/`cancelled`)
is a **lifecycle** state — it says nothing about *why* the encounter ended.
`ClinicalEncounter.disposition` (added this step) is the **outcome**,
recorded only at completion:

| Disposition | Handoff |
|---|---|
| `completed` (default) | None — the encounter is simply closed. |
| `follow_up` | The encounter page offers a "Schedule Follow-up Appointment" link straight into the existing `appointments.create` form, pre-filled with the patient (and department, if known) via `AppointmentController::create()`'s optional `patient_id`/`department_id` query params. No second scheduling system. |
| `referred` | Pairs with a `CarePlanItem::TYPE_REFERRAL` item (department/practitioner referral, already built in Step 25) recording *where* the patient was referred; disposition records that the encounter itself concluded in a referral. |
| `admitted` | Recorded only — a future admission module reads encounters where `disposition = 'admitted'` (with `patient`, `diagnoses`, `carePlan`, and `clinicalNotes` all reachable from the encounter) to build an admission record. No inpatient workflow exists yet. |
| `transferred` | Recorded only, same handoff shape as `admitted`, for a future inter-facility transfer workflow. |

`disposition` is **optional** — `ClinicalEncounterService::complete()` defaults
it to `completed` when omitted, so a simple encounter is never forced to
declare an outcome it doesn't have (spec §15). This also means every
pre-existing call site that completed an encounter with no body (e.g.
`ClinicalEncounterEndToEndTest`) kept working unchanged.

## Order architecture: how Prescription, Laboratory, Radiology, Procedure, and Referral connect

```
                    Diagnosis (EncounterDiagnosis)
                            |
                     Clinical Decision
                            |
              CarePlan (one per encounter)
                            |
              CarePlanItem (many, one `type` each)
        ┌───────┬──────────┬───────────┬────────────┬─────────┐
        ↓       ↓          ↓           ↓            ↓         ↓
   medication procedure clinical_   referral    laboratory  radiology
        |    monitoring intervention   |             |          |
        ↓    follow_up               (existing    (Step 27,  (Step 27,
  Prescription                      dept/pract-   new type,  new type,
  (own service,                     itioner        no new     no new
   own audit                        fields)        columns)   columns)
   vocabulary)
```

Every item type is a sibling under the same `CarePlanItem` row — **not** a
sequential chain (`Prescription → Lab → Radiology` never happens). The
doctor may order any combination, independently, in one plan. This was
already true for `medication`/`procedure`/`referral`/`follow_up`; Step 27
extends the same `type` enum with `laboratory` and `radiology` — no new
table, no new migration (`care_plan_items.type` is a plain indexed string
with no DB-level enum), no processing service. A `laboratory`/`radiology`
item is recorded with only the fields every other generic item already has
(`description`, `priority`, `instructions`) and stays in `planned` status
indefinitely — the encounter is never blocked waiting for it (proven by
`OpdWorkflowEndToEndTest`, which completes an encounter with a lab order
still `planned`). A future Laboratory/Radiology module reads these items via
`CarePlanItem::where('type', 'laboratory')->with('carePlan.clinicalEncounter.patient')`
— the existing FK chain already gives full traceability without a generic
`orders` table replacing any of this.

`PrescriptionService` remains the one item type with its own processing
service (structured dose/route/frequency + its own audit vocabulary),
exactly as Step 25 built it — Step 27 does not touch pharmacy dispensing.

## Emergency path (deliberately not built)

`TriageAssessment::CATEGORY_EMERGENCY` (alongside `very_urgent`/`urgent`/
`non_urgent`) already existed before this step and satisfies "a controlled
emergency/urgent flag where the existing architecture supports it." What is
**not** built: an authorized override that lets a doctor start a
consultation before triage is completed. `ConsultationService::create()`
still unconditionally requires `triage.status === 'completed'`. Building a
bypass-with-mandatory-audit pathway is a real new authorization surface, and
the spec itself only asks for it conditionally ("if emergency workflow
permits...") while explicitly forbidding a full Emergency Department module.
The safer choice — and the one taken here — is no bypass at all, so there is
no risk of a silent one; a future, explicitly-scoped emergency module is the
right place to design that override properly.

## Workflow-status UI (read-only, backend-driven)

`WorkflowStatus` (`resources/js/components/clinical-encounters/workflow-status.tsx`)
renders a ✓/○ checklist — Triage Complete, Consultation Complete, Diagnosis
Recorded, Treatment Recorded, Clinical Note Signed, Disposition Set — computed
entirely from the same `triage`/`consultation`/`diagnoses`/`carePlan`/
`clinicalNotes`/`encounter` props `clinical-encounters/show.tsx` already
receives. There is no new backend endpoint and no frontend-invented state; an
unchecked step is never treated as an error (spec §15/26) — a simple
encounter may never need most of these.

`ClinicalEncounterService::complete()` deliberately has **no** blocking
clinical-completeness validation — see the audit note in the Step 27 plan:
`ClinicalEncounterEndToEndTest` (regression-critical, still passing)
completes a walk-in encounter with no triage/consultation/diagnosis/treatment
at all, a legitimate flow the spec itself protects.

## Doctor worklist / dashboard

The existing `clinical-encounters/index.tsx` + `ClinicalEncounterController::index()`
+ `ClinicalEncounterSearchService` already filtered by department,
practitioner, encounter type, status, and date range, with a "My Encounters"
toggle scoped to the acting practitioner — this already was the doctor
worklist spec §23/24 asks for; building a second page would have duplicated
it. Step 27 adds one thing: four **quick-filter buttons** (Waiting for
Triage / Ready for Consultation / In Consultation / Completed) backed by a
single new `workflow_stage` query parameter
(`ClinicalEncounterSearchService::WORKFLOW_STAGES`), since those groupings
depend on the triage/consultation relations, not a plain `status` equality:

- `waiting_for_triage` — encounter still active, no completed triage.
- `ready_for_consultation` — triage completed, no consultation started yet.
- `in_consultation` — consultation `in_progress`.
- `completed` — encounter `status = completed`.

## Permissions

No new permissions were added in this step — every ability spec §29 lists
already exists from Steps 21-26 (`clinical-encounters.*`, `triage.*`,
`consultation.*`, `diagnosis.*`, `treatment.*`, `clinical-notes.*`,
`clinical-history.view`). Every workflow *transition*, not just page access,
is independently gated: e.g. `clinical-notes.view` never implies
`clinical-notes.amend`, `clinical-encounters.view` never implies `.complete`
— see each module's own Policy (`ClinicalNotePolicy`, `ClinicalEncounterPolicy`,
etc.), all already built and already tested per-module.

## IDOR & failure-scenario testing

`tests/Feature/OpdWorkflow/OpdWorkflowIdorTest.php` is a single consolidated
reference proving Patient A's authorized doctor can never reach Patient B's
diagnosis/treatment-item/clinical-note by substituting `{clinicalEncounter}`
in the URL (404, via each controller's existing `assertBelongsToEncounter()`
guard), and that a hospital-admin cannot cross into another hospital's
encounter (403, via `ClinicalEncounterPolicy`). Viewing another encounter
directly (`GET .../show`) is **not** ownership-scoped by design — any
authorized clinical staff may need to read it for handoffs, matching
`ConsultationPolicy`'s own documented philosophy — the boundary that matters
is that no *action* on another encounter's records is reachable.
`tests/Feature/OpdWorkflow/OpdWorkflowFailureScenariosTest.php` asserts the
spec §40 list together (diagnosis without consultation, treatment against
another patient's encounter, editing a signed note directly, duplicate
active encounter, signing/amending without permission) by reusing each
module's own existing Pest helpers — none of it is new coverage, it is the
Step 27 "regression contract" record.

This app has no `DELETE`/`PATCH` route for any clinical entity ("no destroy
route" is a standing convention throughout `routes/web.php`), so the full
verb surface actually in use is GET/POST/PUT, all covered.

## What Step 27 actually changed

- `clinical_encounters` gained `disposition`/`disposition_notes` columns
  (migration `2026_09_07_090000_add_disposition_to_clinical_encounters_table.php`).
- `CarePlanItem::TYPES` gained `laboratory`/`radiology` (no migration).
- `ClinicalEncounterService::complete()` accepts an optional
  `disposition`/`disposition_notes` payload via a new
  `CompleteClinicalEncounterRequest`.
- `AppointmentController::create()` accepts optional `patient_id`/
  `department_id` query params (`preselectedPatient`/`preselectedDepartmentId`
  props) so the encounter disposition dialog's follow-up link genuinely
  pre-fills the existing appointment form, rather than linking to a page
  that silently ignores the params.
- `ClinicalEncounterSearchService` gained a `workflow_stage` filter for the
  worklist quick-filter buttons.
- New frontend: `CompleteEncounterDialog`, `WorkflowStatus`, quick-filter row
  on `clinical-encounters/index.tsx`.
- New tests: `tests/Feature/OpdWorkflow/{OpdWorkflowEndToEndTest,OpdWorkflowIdorTest,OpdWorkflowFailureScenariosTest}.php`.

## Non-goals

Laboratory/Radiology/Pharmacy processing, billing/payments/NHIF claims,
admission/inpatient/discharge management, an Emergency Department module,
and AI-assisted diagnosis/treatment/prescription remain unimplemented, per
spec §45. A full authorized triage-completion bypass is deferred (see
"Emergency path" above) rather than built under this step.
