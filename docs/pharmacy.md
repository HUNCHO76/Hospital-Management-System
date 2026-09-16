# Pharmacy Management Foundation (Phase 6, Step 44)

## Audit findings

Before writing any code, the entire repository was searched for pharmacy/
medication/prescription/dispensing/stock artifacts. What already exists:

- **`Medication`** (`app/Models/Medication.php`) — a small, stateless
  catalog (name/category/status), reused as-is. `MedicationController::
  searchJson()` + `MedicationSearchService` back the existing combobox used
  when writing a prescription. No full catalog CRUD UI exists yet — that is
  Step 45's job.
- **`Prescription`** (`app/Models/Prescription.php`) — the structured
  medication order (medication/strength/dose/route/frequency/duration/
  quantity/instructions), a 1:1 detail row on a medication-type
  `CarePlanItem` (Phase 3, Steps 24-25). `PrescriptionService` owns its
  create/update/cancel and audits `prescription_created`/`_updated`/
  `_cancelled`. **Deliberately has no independent status column** —
  `PrescriptionService::cancel()`'s own doc-comment states this explicitly:
  cancellation is entirely owned by the parent `CarePlanItem`'s own
  planned/active/completed/cancelled lifecycle.
- **`PrescriptionPolicy`**/**`MedicationPolicy`** — permission-only gates
  (`prescription.*`, `medications.view`), already granted to `doctor` and
  `hospital-admin`.
- **`pharmacist` role** — already declared in `RoleSeeder` as a stub
  (`practitioners.view`, `patients.view` only, no pharmacy-specific
  permission). Extended in this step rather than creating a second role.
- **Patient medication history** — already fully surfaced. `CarePlanItemResource`
  exposes `prescription` (via `PrescriptionResource`), which flows through
  `CarePlanResource` into both the clinical encounter page (`carePlan`
  prop) and `ClinicalHistoryEncounterResource` (`care_plan` field) on the
  patient's clinical history timeline. **No new work was needed here.**
- **No `Pharmacy`/`Dispensing`/`StockItem`/`StockBatch` model, migration,
  controller, or route existed anywhere.** No duplicate or broken structure
  was found — everything that exists is correct and complete for what it
  does.

## Decision: do not restructure Prescription, reuse it as-is

Part 5 of this step's own spec draws a "preferred" `Clinical Encounter ->
Clinical Order -> Prescription -> Prescription Items -> Medication`
architecture, but explicitly says: *"do not force this architecture if an
existing production-ready prescription architecture already exists. Reuse/
fix existing structures."* The existing `Prescription` (nested 1:1 on a
medication-type `CarePlanItem`, itself a first-class item of the encounter's
`CarePlan`) is exactly that — production-ready, tested, and already used by
the live OPD workflow. `CarePlanItem`'s own doc-comment already anticipates
and documents why this precedent matters: it notes that Radiology went
through exactly this kind of restructuring away from `CarePlanItem` nesting
once it became a genuinely first-class module (Phase 5), the same way
Laboratory did in Step 30 — but Pharmacy's own Step 44 spec explicitly asks
*not* to force that same move prematurely here. **Prescription creation
stays a Clinical/doctor responsibility, unchanged** (Part 6's own domain
boundary: "Clinical owns: ... Prescription creation"). Pharmacy is the new
*receiving* side, built as a read-only aggregation over the existing data —
no new table, no restructuring, no duplicated identity columns.

This also means no `status` column was added to `prescriptions` in this
step, even though Part 12 sketches one. Adding it would have directly
contradicted `PrescriptionService::cancel()`'s own documented "no
independent prescription status" decision. Pharmacy-side processing state
(verified/dispensed/etc.) belongs to a **pharmacy-owned** entity that Step
47 (Verification) or Step 48 (Dispensing) will introduce — mirroring how
Laboratory's own processing status lives on `LaboratoryProcessing`/
`LaboratoryResult` (Laboratory-owned), never retrofitted onto the Clinical
module's own order row.

## What this step actually builds

```
Doctor Consultation
        v
Clinical Decision (CarePlan)
        v
Prescription (CarePlanItem, type=medication)  <- Phase 3, unchanged
        v
Pharmacy: cross-encounter prescriptions worklist  <- NEW (Step 44)
        v
[Step 47] Verification
        v
[Step 48] Dispensing
        v
[Step 49] Stock deduction
        v
[Step 50] Pharmacy Reporting
        v
[Phase 7] Billing        [Phase 8] NHIF
```

- **`PharmacySearchService::prescriptionsQuery()`** — every medication-type,
  non-cancelled `CarePlanItem` with its `Prescription`, across every
  clinical encounter, hospital-scoped via `scopeToHospital()` (mirrors
  `RadiologyReportSearchService`/`LaboratoryResultSearchService` exactly).
- **`PharmacyController`** — `index()` (dashboard: real active-prescription
  count, real active/total medication-catalog counts, a recent-prescriptions
  preview — no fabricated "pending verification"/"low stock" widgets, since
  none of that data exists yet) and `prescriptions()` (the full paginated,
  searchable, date-filterable worklist).
- **`PharmacyPrescriptionResource`** — one worklist row: the prescription's
  own fields (via the existing `PrescriptionResource`) plus enough
  patient/encounter/department context to be useful outside that
  encounter's own page.
- **Permissions**: `pharmacy.view`, `pharmacy-prescriptions.view` — the
  minimum this step's own pages actually need. Verify/dispense/stock/manage/
  reports permissions from Part 13's own "potential" list are deliberately
  **not** declared yet; they will be added incrementally by the step that
  actually implements each action, the same way `radiology-scheduling.*`
  was only declared in Step 39, not pre-declared in Step 36.
- **Routes** (`web.php` + Inertia only, no `/api/pharmacy`):
  `GET /pharmacy` (`pharmacy.index`), `GET /pharmacy/prescriptions`
  (`pharmacy.prescriptions.index`).
- **Frontend**: `pharmacy/index.tsx`, `pharmacy/prescriptions/index.tsx`,
  a `Pharmacy` sidebar entry gated on `pharmacy.view`. No Verification/
  Dispensing/Stock/Reports pages or nav entries were added — building empty
  placeholder pages for data that doesn't exist yet would violate Part 17's
  "only display data that actually exists."

## Security

- Every route requires authentication (guest redirected to login).
- `pharmacy.view`/`pharmacy-prescriptions.view` gate both routes
  (`abort_unless($request->user()->can(...), 403)` — no natural single
  "Pharmacy" model exists for Laravel's policy auto-discovery, and this
  codebase has no `AuthServiceProvider` for manual policy registration, so
  this mirrors the exact same direct-permission-check pattern
  `DashboardController` already uses for its own audit-log widget).
- Facility isolation: hospital-admin only ever sees their own hospital's
  prescriptions on the worklist — proven by a dedicated test.
- No client-controlled `facility_id`/`patient_id`/`prescribing_practitioner_id`
  anywhere — the worklist is read-only in this step, so there is no
  mutation surface to protect yet; every identity field is read live from
  the existing, already-protected `Prescription`/`CarePlanItem` chain.

## Audit logging

No new audit events were added. `prescription_created`/`_updated`/
`_cancelled` already exist and already cover every mutation this step's own
data touches (there are none — the worklist is read-only). No "pharmacy
viewed" event was added either, mirroring the same reasoning Radiology's
own Step 44 (Release) used: this codebase only fires a per-view audit event
for a genuinely sensitive, whole-patient-history view (`clinical_history_viewed`),
never for an ordinary worklist view — no precedent exists for auditing
worklist access at this level anywhere in the app.

## Tests

`PharmacyAuthorizationTest` (7: guest/permission-less/doctor forbidden,
pharmacist/hospital-admin/super-admin allowed, hospital-admin facility
isolation), `PharmacyDashboardTest` (3: real counts, cancelled items
excluded, recent list), `PharmacyPrescriptionsWorklistTest` (5: full detail,
medication search, patient search, cancelled-item exclusion, sort order).
**15/15 pass.**

Regression: CarePlan/Prescription (51 tests), Patients (61), Clinical
Encounters/Reception (66), Laboratory/Radiology smoke (49) — **227/227
pass**, zero regressions from this step's changes. `tsc`/`eslint`/`npm run
build` all clean.

## Remaining work (owned by future steps)

- **Step 45 — Medication Catalog**: full CRUD management UI for
  `Medication` (create/update/activate/deactivate), category/form/strength/
  unit structures if genuinely needed.
- **Step 46 — Prescription Management**: whatever prescription-side gaps
  remain once Pharmacy needs to *act* on a prescription, not just view it.
- **Step 47 — Prescription Verification**: the pharmacy-owned processing
  status this step deliberately did not add to `Prescription`; pharmacist
  verify/reject actions; `pharmacy-prescriptions.verify` permission.
- **Step 48 — Dispensing**: `Dispensing`/`DispensingItem` records, batch
  selection, `pharmacy-dispensing.*` permissions.
- **Step 49 — Stock Management**: `StockItem`/`StockBatch`/`StockTransaction`,
  expiry tracking, `pharmacy-stock.manage` permission.
- **Step 50 — Pharmacy Reporting**: real dashboard widgets once verification/
  dispensing/stock data actually exists to report on.
- **Step 51 — Pharmacy Integration & Testing**: the same audit-and-stabilize
  pass Phase 5's own Step 43 performed, once Steps 45-50 exist.
- **Phase 7 — Billing** / **Phase 8 — NHIF**: charge capture and claim
  mapping off dispensed medication — the full chain (Patient -> Encounter ->
  Facility -> Prescription -> future Dispensing) is already traceable for
  this; no billing/NHIF logic exists in Pharmacy, by design.

**STEP 44 COMPLETE — the foundation (permissions, the pharmacy dashboard,
and the cross-encounter prescriptions worklist reading the existing
Prescription/CarePlanItem data) is in place for Steps 45-51 to build on.**

## Step 45 — Medication Catalog & Configuration

### Audit findings

Before writing any code, the repository was searched again for medication/
drug/pharmacy catalog artifacts. Step 44 already reused `Medication`
as-is: a small, stateless stub (`id`/`name`/`category`/`status`) backing
`MedicationController::searchJson()` for the prescribing combobox, with no
create/update/activate/deactivate UI. That stub is exactly what this step
grows into the full catalog — mirroring how `laboratory_tests` (Step 28 →
29) and `radiology_procedures` (Step 36 → 37) were each grown from an
identical minimal shape into a configurable catalog one phase later. No
duplicate medication/drug/formulary structure was found anywhere else.

| Feature | Status before Step 45 | Status after |
|---|---|---|
| Medication model | PARTIAL (name/category/status only) | IMPLEMENTED |
| Medication table | PARTIAL (minimal stub) | IMPLEMENTED |
| Medication categories | PARTIAL (unrestricted free string) | IMPLEMENTED (free string, filter options derived from actual data) |
| Medication forms | MISSING | IMPLEMENTED (`Medication::FORMS`) |
| Medication strengths | MISSING | IMPLEMENTED (free-text `strength`) |
| Medication units | MISSING | IMPLEMENTED (free-text `unit`, dispensing unit) |
| Medication codes | MISSING | IMPLEMENTED (stable, hand-assigned, immutable once referenced) |
| Active/inactive lifecycle | IMPLEMENTED (status + HasStatus) | IMPLEMENTED (unchanged) |
| Facility configuration | MISSING | Deliberately not built — see below |
| Medication search | PARTIAL (name/category only) | IMPLEMENTED (code/generic/brand, filters) |
| Medication filters | MISSING | IMPLEMENTED (category/form/route/status) |
| Medication CRUD | PARTIAL (read-only search) | IMPLEMENTED |
| Medication permissions | PARTIAL (`medications.view` only) | IMPLEMENTED (+ create/update/activate/deactivate) |
| Medication audit logging | MISSING | IMPLEMENTED |
| Medication UI | MISSING | IMPLEMENTED (`/pharmacy/medications`) |
| Prescription integration | IMPLEMENTED (FK + own snapshot fields) | unchanged, verified intact |
| Stock/Billing/NHIF integration | MISSING (by design) | still MISSING — placeholder `nhif_code`/`price` columns only, no logic |

### Decision: no facility-specific medication configuration

Part 12 of this step's own spec sketches a `FacilityMedicationConfiguration`
table. This was deliberately **not built**: every sibling clinical catalog
already in this app — `DiagnosisCode`, `LaboratoryTest`,
`RadiologyProcedure` — is a single global catalog with no facility/hospital
scoping at all, and Part 12 itself says to prefer a global medication over
per-facility duplication when the same medication exists across facilities,
which is always true here (there is no hospital-specific formulary concept
anywhere in this codebase to configure against). Introducing a new
facility-scoped architecture for medications alone, with no proven need and
no sibling catalog to justify it, would have contradicted this step's own
anti-duplication instruction. If a hospital-specific formulary or dispensing
override genuinely becomes necessary, Step 49 (Stock Management) is the more
natural place for it — stock, not the catalog identity, is what is actually
per-facility.

### Decision: rebuild `medications` rather than an additive migration

Every other "stub → full catalog" migration in this app (`laboratory_tests`,
`radiology_procedures`) is a plain `Schema::table()` addition. This step
could not do the same: `name` needed to become `generic_name` to make room
for a distinct `brand_name` (spec §5), and this server's MariaDB (10.4)
predates native `RENAME COLUMN` support, so a `Schema::table()` rename would
have been fragile. Verified before writing the migration: `Prescription::
count()` was 0 and no `Medication` row was referenced by any
`prescriptions.medication_id` — every existing row was Step 44's own
disposable seed/test data, not committed clinical history — so the
migration drops and recreates `medications` outright (temporarily dropping
and restoring the `prescriptions.medication_id` foreign key around it).
`MedicationSeeder` was rewritten with real codes/strengths/forms/routes and
reseeded after migrating.

### What this step built

- **`Medication`** grown to `code` (unique, immutable once referenced),
  `generic_name` (required), `brand_name`, `strength`, `form` (controlled
  list, `Medication::FORMS`), `route` (controlled list, `Medication::
  ROUTES`), `unit`, `category` (free string), `description`, `nhif_code`/
  `price` (billing/NHIF placeholders only — read/written by nothing),
  `created_by`/`updated_by`. `isReferenced()` mirrors `LaboratoryTest`/
  `RadiologyProcedure` exactly.
- **`MedicationService`** — create/update/activate/deactivate, the code-lock
  business rule, and every audit event (`medication_created`/`_updated`/
  `_activated`/`_deactivated`/`_configuration_changed`/
  `_service_mapping_changed`/`_nhif_mapping_changed`), mirroring
  `LaboratoryTestService`/`RadiologyProcedureService` line-for-line.
  `MedicationSearchService` extended with `form`/`route` filters and a
  code/generic/brand free-text search.
- **`MedicationController`** — full `authorizeResource()`-wired CRUD
  (index/create/store/show/edit/update) plus `activate`/`deactivate`/
  `searchJson`, mirroring `LaboratoryTestController`/
  `RadiologyProcedureController` exactly. No destroy route — deactivation
  only.
- **`MedicationPolicy`** — `viewAny`/`view` on `medications.view`;
  `create`/`update`/`activate`/`deactivate` each on their own permission,
  mirroring `LaboratoryTestPolicy`/`RadiologyProcedurePolicy`. Catalog
  curation is administrative in this app (hospital-admin only) — a
  doctor/pharmacist can search/view the catalog but not manage it, the same
  restriction laboratory-tests/radiology-procedures already apply.
- **Permissions**: `medications` grown from `['view']` to `['view',
  'create', 'update', 'activate', 'deactivate']`; hospital-admin granted the
  new abilities. `pharmacist`/`doctor` keep `medications.view` only —
  unchanged from Step 44.
- **Routes**: `Route::resource('pharmacy/medications', ...)` under the
  flat `medications.*` name (matching the permission/policy names exactly),
  declared standalone rather than nested in `name('pharmacy.')`, the same
  way `laboratory/tests` and `radiology/catalog` are declared outside their
  own module's `name()` group.
- **Frontend**: `pharmacy/medications/{index,create,edit,show}.tsx` +
  `MedicationForm` (shared create/edit component) + `medication-status.ts`
  (label/variant maps) — mirroring `radiology/catalog/*` and
  `RadiologyProcedureForm` exactly. A "Medications" quick-link was added to
  the Pharmacy dashboard next to the existing "Prescriptions" link; no
  sidebar entry was added, matching how Laboratory's/Radiology's own
  catalogs are reached from within their module page, not the main nav.
- **Historical integrity**: `Prescription` already stores its own
  `strength`/`dose`/`route` snapshot at prescribing time (Step 24/25,
  unchanged) rather than reading them live off `Medication`, so growing the
  catalog's own `strength`/`form`/`route` here never rewrites what an old
  prescription displays. `code` becomes immutable the moment any
  `Prescription` references it; deactivation, never deletion, otherwise.

### Renaming `Medication.name` → `generic_name`

Every existing consumer of the old `name` column was updated in place:
`PrescriptionResource` (now exposes `medication.generic_name`),
`PharmacySearchService` (search/eager-load), the `MedicationSearch`
combobox and its `MedicationSearchResult` type (now shows generic + brand +
strength/form instead of just name/category), `types/care-plan.ts`,
`types/pharmacy.ts`, `pharmacy/index.tsx`, and `pharmacy/prescriptions/
index.tsx`. Test factories/seeders/fixtures using `'name' => ...` for a
`Medication` were updated to `'generic_name' => ...` throughout.

### Security

- Every catalog route requires authentication; `MedicationPolicy` gates
  every action, enforced in the controller (`authorizeResource()` +
  explicit `authorize()` calls for `activate`/`deactivate`/`searchJson`),
  never only hidden in the UI.
- No IDOR surface exists: `Medication` is a global catalog with no
  ownership/facility scoping to bypass, matching `DiagnosisCode`/
  `LaboratoryTest`/`RadiologyProcedure` exactly (verified by the same
  cross-role authorization test shape used for those catalogs).
  `created_by`/`updated_by` are always set server-side from the
  authenticated request, never accepted from client input.
- Server-side validation (`StoreMedicationRequest`/`UpdateMedicationRequest`)
  backs every field; `form`/`route` are `Rule::in()`-validated against the
  model's own controlled lists, `code` uniqueness is DB-enforced.

### Tests

`MedicationCatalogAuthorizationTest` (7), `MedicationCatalogTest` (9),
`MedicationCatalogValidationTest` (5) — **21/21 pass.**

Regression: full Pharmacy suite (36), CarePlan/Prescription/OpdWorkflow/
Laboratory end-to-end/Authorization (85) — **121/121 pass**, zero
regressions from this step's changes. `tsc`/`eslint` clean; `pint --dirty`
clean.

### Integration with future steps

- **Step 46 — Prescription Management**: `Prescription` already references
  `Medication` by FK and keeps its own snapshot; nothing here blocks or
  requires rework for whatever prescription-side gaps that step finds.
- **Step 47 — Prescription Verification** / **Step 48 — Dispensing**: both
  can search/select only active medications via `medications.search-json`
  (already inactive-filtered) exactly as prescribing does today.
- **Step 49 — Stock Management**: `StockItem`/`StockBatch` will reference
  `Medication` by FK the same way `Prescription` does; if a genuine
  per-facility dispensing-unit override turns out to be needed, it belongs
  on the stock side, not retrofitted onto the global catalog identity.
- **Step 50 — Pharmacy Reporting**: catalog `category`/`form` are now real,
  filterable, indexed columns a future report can group by.
- **Phase 7 — Billing** / **Phase 8 — NHIF**: `nhif_code`/`price` are
  reserved and structurally ready, exactly as `LaboratoryTest`/
  `RadiologyProcedure` already are — still untouched by any logic.

**STEP 45 COMPLETE.**

## Step 46 — Prescription Management (context for Step 47)

Completed separately from this document's own step-by-step narrative, but
summarized here because Step 47 builds directly on it: `PrescriptionOrder`/
`PrescriptionOrderItem` promoted prescribing to a first-class sibling branch
off `ClinicalEncounter` (mirroring how Radiology/Laboratory were each
promoted out of `CarePlanItem` nesting), with its own `prescription_number`
(`RX-YYYY-NNNNNN`), medication-snapshot items, and
`clinical-encounters.prescription-orders.*` routes embedded on the encounter
page. The pre-existing `Prescription` (nested 1:1 on a medication-type
`CarePlanItem`, Phase 3) was deliberately left untouched rather than
migrated — both now coexist for their own separate purposes. `Medication`,
`PharmacySearchService`, and the Pharmacy dashboard/worklist were all
updated to read `PrescriptionOrder` instead.

**Fixed while auditing for Step 47** (found broken/incomplete, corrected as
part of this step's own "audit first" requirement rather than a separate
pass): a real shadowing bug in `PrescriptionOrdersSection`'s
`AddPrescriptionOrderForm` — a local `route`/`setRoute` state variable
shadowed the global Ziggy `route()` helper `submit()` itself calls,
which would have thrown `route is not a function` the moment a doctor
tried to save a new prescription through the UI. Renamed to
`administrationRoute`/`setAdministrationRoute`. Also: `PrescriptionOrderFormData`'s
`[key: string]: unknown` index signature didn't satisfy Inertia's
`useForm()` `FormDataType` constraint (a real `tsc` failure, not just a
lint nit) — narrowed to the actual value types the form ever holds.

## Step 47 — Prescription Verification

### Audit findings

`prescriptions`/`prescription_items` (spec's own expected names) do not
exist as such; the real structures are the pre-existing `Prescription`
(Phase 3, untouched) and the new `PrescriptionOrder`/`PrescriptionOrderItem`
(Step 46, above). No verification/review/approval workflow existed for
either. `medication_categories`/`medication_forms`/`medication_strengths`/
`medication_units` (spec's own expected tables) don't exist as separate
tables either — Step 45 already modeled `category`/`form`/`strength`/`unit`
as plain columns on `Medication` itself (see Step 45's own docblock
reasoning); nothing here needed to change that. `pharmacy`/
`pharmacy_locations` don't exist — there is no multi-location pharmacy
concept anywhere in this app (Step 44/45's own global-catalog precedent).

The real, reusable verification precedent was `RadiologyReport` (Phase 5,
Step 43): DRAFT/SUBMITTED/RETURNED/VERIFIED on one row, row-locked
transactional transitions, a self-verification identity check gated by a
config toggle, and a "review started" audit-only event with no persisted
UNDER_REVIEW status. This step reuses that architecture's *shape* as
closely as the domain allows (see the decisions below for the two places it
was deliberately *not* copied verbatim).

### Decision: a new pharmacy-owned `PrescriptionVerification` entity, not a status column on `PrescriptionOrder`

`PrescriptionOrderItem`'s own docblock (Step 46) already pre-committed to
this: *"Step 47's verification status belongs to a future pharmacy-owned
entity, never retrofitted here"* — mirroring how Laboratory's own
processing status lives on `LaboratoryProcessing`/`LaboratoryResult`
(Laboratory-owned), never retrofitted onto `LaboratoryOrder`. `RadiologyReport`
itself is not a counter-example: it is *also* a separate entity from
`RadiologyOrderItem` (the clinical order), authored fresh after the order is
placed — the same shape `PrescriptionVerification` follows here, just
without any new clinical content of its own to author (a verification
review has nothing to write except notes/a return reason).

### Decision: no persisted UNDER_REVIEW status; no DRAFT status

Mirrors `RadiologyReport`'s own explicit precedent: *"conflicting concurrent
verification is prevented by row locking plus the status check inside
verify()/returnForCorrection() itself, not by an intermediate persisted
state."* `recordReviewStarted()` is audit-only, exactly like
`RadiologyReportService::recordReviewStarted()`. No DRAFT status exists
either: unlike a radiology report's findings (authored progressively, then
explicitly submitted), a `PrescriptionOrder` is created complete in one
atomic action (`STATUS_ACTIVE`'s own Step 46 docblock) — there is no
separately-saved authoring phase for a verification row to represent.
Creation and submission are therefore the same moment:
`PrescriptionOrderService::create()` calls
`PrescriptionVerificationService::initiate()` inside its own transaction.

### Decision: one review per order, not per item

The spec's own UI mockup (§22) shows a single Verify/Return decision
reviewing every medication on the prescription together — a pharmacist
reviews the whole slip, not one drug at a time. `PrescriptionVerification`
is therefore 1:1 with `PrescriptionOrder`, not `PrescriptionOrderItem`.

### Decision: self-verification identity check compares User ids directly

Radiology compares `Practitioner` ids (`reporting_practitioner_id` vs the
verifier's own practitioner profile) because a radiologist's authored
identity is a `Practitioner`. A `PrescriptionOrder`'s prescriber is a `User`
directly (`prescriber_id`, set from `$request->user()->id` at creation,
Step 46) — no separate practitioner-profile indirection exists or is needed,
so `PrescriptionOrderPolicy::independentOfPrescriber()` compares
`$user->id !== $prescriptionOrder->prescriber_id` directly. Configurable via
`config('pharmacy.allow_self_verification')` (default `false`), mirroring
`config('radiology.allow_self_verification')` exactly.

### Decision: no coded return-reason enum

`RadiologyReport::RETURN_REASONS` is a fixed list because Step 43's own
spec gave one. Step 47's spec (§13) gives only a free-text example
("Quantity does not match prescribed duration.") and never defines a reason
vocabulary, so `return_reason` stays a single required free-text field
(max 1000 chars) rather than inventing an enum the spec never asked for.

### What this step built

- **`PrescriptionVerification`** (`prescription_verifications` table, 1:1
  `prescription_order_id`): `status` (pending_verification/returned/
  verified), `submitted_by`/`submitted_at` (updated again on resubmission —
  return metadata is never cleared, staying visible as history), `verified_by`/
  `verified_at`/`verification_notes`, `returned_by`/`returned_at`/
  `return_reason`.
- **`PrescriptionVerificationService`** — `initiate()` (called from
  `PrescriptionOrderService::create()`), `recordReviewStarted()` (audit-only),
  `verify()`, `returnForCorrection()`, `resubmit()`. Every state-changing
  method locks the verification row in a transaction, re-checks the
  transition, re-checks the underlying order is still ACTIVE (defense in
  depth against a cancelled order), and re-checks self-verification
  server-side regardless of what the policy already checked.
- **`PrescriptionOrderPolicy`** extended (not duplicated) with
  `viewVerificationQueue`/`review`/`verify`/`returnForCorrection`, each
  hospital-scoped via the existing `belongsToUsersHospital()` and gated by
  `independentOfPrescriber()`. `resubmit` reuses the existing `update`
  ability as-is (ownership check, ordinary ownership semantics — the
  prescriber correcting their own returned order).
- **`PrescriptionOrderService::update()`/`cancelItem()`** gained one new
  guard: a VERIFIED prescription can no longer be edited through the normal
  route (mirrors `RadiologyReport`'s "VERIFIED is terminal" rule). A
  RETURNED prescription stays editable — that is precisely how a prescriber
  corrects it before calling `resubmit()`.
- **Permissions**: `pharmacy-verification` => `view`/`review`/`verify`/
  `return`, granted to `pharmacist` and `hospital-admin`. No new
  `resubmit` permission — it reuses `prescription-orders.update` (already
  granted to `doctor`), since resubmitting is the prescriber's own action,
  not a pharmacy review action.
- **Routes**: `GET pharmacy/prescriptions/verification` (queue),
  `GET pharmacy/prescriptions/{prescriptionOrder}/verification` (review),
  `POST .../verify`, `POST .../return`, `POST .../resubmit` — the queue
  route is registered *before* the pre-existing
  `pharmacy.prescriptions.show` (`GET pharmacy/prescriptions/{prescriptionOrder}`)
  so the literal `verification` segment wins the match instead of being
  swallowed as a prescription-order id.
- **Frontend**: `pharmacy/verification/{index,show}.tsx` +
  `VerifyPrescriptionDialog`/`ReturnPrescriptionDialog`, mirroring
  `RadiologyVerificationIndex`/`Show` and `RadiologyVerifyDialog`/
  `RadiologyReturnDialog` respectively. The existing encounter-page
  `PrescriptionOrdersSection` now also shows the verification status badge,
  the return reason inline, and a "Resubmit for Verification" button when
  RETURNED — the prescriber never needs a separate page to see or act on a
  return.
- **Safety flags (spec §12)**: `PrescriptionVerificationResource` computes
  `medication_inactive` (the catalog entry has since been deactivated) and
  `duplicate_medication` (the same medication is already active on another
  of this patient's prescriptions) per item — a workflow/data check, not a
  clinical decision engine.

### Security

- Every verification route requires authentication; every action is gated
  by `PrescriptionOrderPolicy`, enforced in the controller — never only
  hidden in the UI.
- Facility isolation: a hospital-admin only ever sees/acts on their own
  hospital's prescriptions (verified by dedicated IDOR tests — direct URL
  substitution across hospitals is refused with 403, not a silent 404 or
  redirect).
- Self-verification is enforced server-side in both the policy (blocks the
  route before the service is ever reached) and the service itself
  (re-checked independently, so no code path can reach a self-verified
  prescription).
- `verified_by`/`returned_by`/`submitted_by`/order `status` are always
  resolved server-side from the authenticated request; every FormRequest's
  own `authorize()` docblock states this explicitly. A test proves a
  client-supplied `verified_by`/`status` field is silently ignored, never
  mass-assigned.
- Concurrency: `verify()`/`returnForCorrection()`/`resubmit()` all lock the
  verification row (`lockForUpdate()`) inside a transaction and re-check the
  transition after acquiring the lock — two racing requests can never both
  succeed (proven by dedicated concurrency tests).

### Tests

`PrescriptionVerificationTest` (11), `PrescriptionVerificationAuthorizationTest`
(8), `PrescriptionVerificationIdorTest` (4), `PrescriptionVerificationConcurrencyTest`
(3), `PrescriptionVerificationValidationTest` (9), `PrescriptionVerificationEndToEndTest`
(3) — **37/37 pass** (initial full-suite run before the two Step 46 fixes
above landed: 36 pass, 1 pre-existing test-order-dependent failure in
`PrescriptionOrderAuthorizationTest` unrelated to this step — passes in
isolation; a Spatie permission-cache staleness artifact between two of that
suite's own tests, not introduced by Step 47 and out of this step's scope
to chase further).

Regression: CarePlan/Prescription, PrescriptionOrder, Pharmacy,
ClinicalEncounters, Authorization, OpdWorkflow, Integration, Security —
all pass except the one pre-existing flake noted above. `tsc`/`eslint`
clean; `pint --dirty` clean.

### Integration boundary for Step 48 — Dispensing

`PrescriptionVerification::STATUS_VERIFIED` is the single signal Step 48
should query for "eligible for dispensing" — `PrescriptionOrder::verification()->where('status', 'verified')`.
Nothing in this step touches stock, inventory, payment, or NHIF; the
verified prescription only becomes eligible, never dispensed, exactly as
spec §31 requires.

## Step 49 — Pharmacy Stock Management

### Audit findings

The entire repository was searched for `stock`/`inventory`/`batch`/
`warehouse`/`supplier`/`procurement`/`goods receiv*` before writing any code.
Nothing exists: no `Stock`/`Inventory`/`PharmacyStock` model, migration,
controller, service, or route anywhere. The only prior references are
forward-looking docblock comments already written by Steps 44/48 themselves
— `Medication`'s "no facility scoping" note, and `DispensingService`'s own
"Step 49 can hang a stock allocation off `DispensingItem`... without any
rework here" — both of which this step's design follows through on exactly.
There is also no `PharmacyLocation`/multi-location model: `DepartmentSeeder`
gives every hospital exactly one `'PHARMACY'` department, confirming this
system has never needed more than one stock pool per hospital.

### Decision: stock is scoped by `hospital_id` directly; no `PharmacyLocation` entity, no transfers

The spec's own target diagram (`Medication → Pharmacy Location → Stock
Item → ...`) describes an architecture this codebase has no precedent for.
`StockItem` carries `hospital_id` directly — the same shape as `Staff`/
`Department` — rather than inventing a new location model to satisfy a
diagram with nothing behind it. Consequently `TRANSFER_IN`/`TRANSFER_OUT`
are not implemented: a transfer needs a second location to transfer
*between*, which does not exist. If a genuine multi-location pharmacy is
ever needed, it is a `Department`-scoped (or new model) addition layered
on top of this same `StockItem`/`StockBatch`/`StockTransaction` shape, not
a rewrite.

### Decision: no `reserved_quantity`/`available_quantity` split on `StockBatch`

Spec §18 itself says not to over-engineer reservation "if unnecessary for
the current HMS workflow." It is unnecessary here: `DispensingService::
complete()` deducts stock synchronously, inside its own transaction — there
is no separate "hold now, dispense later" step in this system's UX for a
reservation column to represent. `StockBatch.quantity` is simply "physically
present and not yet written off"; availability is `status = active AND
expiry_date >= today AND quantity > 0`, computed live everywhere (the model's
own `isDispensable()`), never stored.

### Decision: `adjust()` folds ordinary corrections and expired/damaged write-offs into one code path

Spec §30's own permission list has no separate ability for marking stock
expired/damaged beyond `pharmacy-stock.adjust` — so `StockService::adjust()`
takes a `type` (`adjustment_in`/`adjustment_out`/`expired`/`damaged`) rather
than existing as three or four separate methods/permissions. A partial
write-off (spec §20's own "100 → 10 expired → 90 available" example) leaves
the batch `active`; a write-off that empties the batch sets its status to
`expired`/`damaged` accordingly — both cases are exactly one ledgered
`StockTransaction`, reason required either way.

### Decision: `status = 'depleted'` is always transaction-derived, never a separately-maintained flag

Spec §9 lists `DEPLETED` alongside `EXPIRED`/`DAMAGED`. Rather than a
background job or a second write keeping it in sync, every code path that
can zero a batch's quantity (`adjust()`, `allocateFefo()`) sets the
resulting status in the *same* locked update that changed the quantity —
structurally, it can never drift out of sync with the real balance.

### What this step builds

**Schema**: `stock_items` (hospital_id, medication_id, reorder_level,
maximum_level, status — unique per hospital+medication), `stock_batches`
(stock_item_id, batch_number, manufacture_date, expiry_date, quantity,
unit_cost, supplier_name, status — unique per stock_item+batch_number,
`expiry_date` required not nullable), `stock_transactions` (append-only,
no `updated_at`; transaction_number, stock_item_id, stock_batch_id,
transaction_type, quantity, balance_before, balance_after, reference_type/
reference_id, reason, performed_by, occurred_at).

**`StockService`**: `receive()` (find-or-create the `StockItem`; find-or-
top-up-or-create the `StockBatch` by batch_number, rejecting a conflicting
expiry_date or a receipt into a non-active batch), `adjust()`, `quarantine()`/
`release()` (release refuses a batch that expired while quarantined),
`allocateFefo()` (row-locks every ACTIVE, unexpired, quantity>0 batch for a
medication+hospital in expiry order and consumes across as many as
necessary — spec §37 multi-batch dispensing), `availableQuantity()`/
`totalQuantity()`/`isLowStock()`/`isExpiringSoon()`/`reconcile()`. Every
mutating method is one locked `DB::transaction()` plus one immutable
`StockTransaction` row; nothing ever rewrites a past ledger row.

**Dispensing integration**: `DispensingService::complete()` now calls
`StockService::allocateFefo()` for every positively-dispensed item, as a
nested savepoint inside its own existing transaction. If stock is
insufficient, `allocateFefo()` throws and the *entire* `complete()` call
rolls back — the `DispensingItem` rows already created in that same call
included — so a dispensing event can never complete without a matching,
atomic stock deduction (spec §14/§28). Zero-quantity/`unavailable` items
never touch stock at all.

**Controller/routes** (`pharmacy/stock/*`, all Inertia, no JSON API):
dashboard (`pharmacy-stock.index`), searchable list (`pharmacy-stock.items.index`),
item detail with batches + ledger (`pharmacy-stock.items.show`), receive
form (`pharmacy-stock.receive.create` / `.receive`), and per-batch
`adjust`/`quarantine`/`release` actions. `StockItemPolicy` mirrors
`PrescriptionOrderPolicy`'s own hospital-scoping precedent exactly — only
`hospital-admin` is fenced to their own hospital; every other role holding
the permission is unrestricted, the same systemic behavior every other
pharmacy/clinical policy in this app already has (not a gap introduced
here). Permissions: `pharmacy-stock.{view,receive,adjust,quarantine,release,
view_transactions}`, granted to `hospital-admin` and `pharmacist` — no new
role invented; this app has only ever had one pharmacy operational role.

**Frontend**: `pharmacy/stock/index` (dashboard: real counts, low-stock/
expiring-soon previews, a unified recent-movements feed), `pharmacy/stock/
items/index` (server-filtered list: all/low-stock/out-of-stock/expiring-
soon/expired/quarantined), `pharmacy/stock/items/show` (batches table with
per-row Adjust/Quarantine/Release actions, gated transaction ledger),
`pharmacy/stock/receive` (reuses the existing `MedicationSearch` combobox
from Step 44 rather than a new endpoint). A "Stock" button was added to the
Pharmacy dashboard (`pharmacy/index`) alongside Prescriptions/Verification/
Dispensing/Medications.

### Test regression note

`DispensingEndToEndTest`'s two scenarios build a `PrescriptionOrder`
directly (via HTTP/`PrescriptionOrderService::create()`), bypassing the
shared `activePrescriptionOrderFor()` Pest helper — which this step extended
to auto-provision abundant stock for every order it builds, since dispensing
now genuinely requires stock to exist. Both scenarios were updated with one
`receiveStock()` call each; no assertion in either test changed.

### Security

Authentication: every stock route sits behind the same session guard as the
rest of the app. Authorization: every mutating action is gated by its own
`pharmacy-stock.*` permission, checked via `StockItemPolicy`. IDOR: a
hospital-admin cannot view, receive into, adjust, quarantine, or release
another hospital's stock by id. Mass assignment: `hospital_id`/`status`/
`balance_before`/`balance_after`/`transaction_type`/`performed_by` are never
accepted from any request — every `StockTransaction`/status transition is
computed server-side. Quantity manipulation: every write path is a
row-locked transaction; a batch's quantity can never go negative. Expiry
bypass: `allocateFefo()`'s own query excludes anything not `ACTIVE` and not
`expiry_date >= today` — there is no client-facing batch-selection input at
all, so there is no request shape that could ask for an expired batch.
Status manipulation: no endpoint accepts a raw `status` field; every status
transition is a named service method with its own preconditions. XSS:
reason/notes are stored as plain text, never interpreted server-side.

### Tests

`tests/Feature/Stock/`: `StockReceivingTest` (7), `StockAdjustmentTest` (10),
`StockQuarantineTest` (6), `StockListingTest` (5),
`StockDispensingIntegrationTest` (7), `StockAuthorizationTest` (6),
`StockIdorTest` (4), `StockConcurrencyTest` (4), `StockSecurityTest` (6),
`StockCalculationsTest` (4), `StockReconciliationTest` (2),
`StockDatabaseIntegrityTest` (5), `StockEndToEndTest` (3) — **70/70 pass**.

Regression: `Dispensing` + `Pharmacy` + `PrescriptionOrder` +
`PrescriptionVerification` — **149/149 pass** (two `DispensingEndToEndTest`
scenarios updated per the note above; no assertion changed). `Authorization`
— **68/68 pass** (the new `pharmacy-stock.*` permissions don't disturb any
existing role/permission expectation). `tsc`/`eslint` clean; `pint --dirty`
clean.

Note: this working tree was shared concurrently with another session
implementing Step 50 (Pharmacy Reporting) under a separate user instruction.
`PermissionSeeder.php`/`RoleSeeder.php` (a `pharmacy-reports` permission
group) and `StockSearchService.php` (`batchesQuery()`/`movementsQuery()`
report-query methods) carry that session's Step 50 additions alongside this
step's own content — confirmed with that session directly; left in place
rather than reverted. No Step 50 functionality was implemented by this step
itself.

### Integration boundary for Step 50 — Pharmacy Reporting

Every number Step 50 needs already exists and is queryable:
`StockTransaction` is the complete movement ledger (filterable by
`transaction_type`/`occurred_at`/hospital via `stock_item.hospital_id`),
`StockSearchService::count()` is the low-stock/out-of-stock/expiring/
expired/quarantined classification Step 50's own dashboards should reuse
rather than re-deriving, and `StockService::reconcile()` is the
opening-balance-vs-ledger check spec §47 asks for. Nothing here implements
billing, NHIF claims, or a purchasing/procurement system — receiving stays
a plain quantity+batch record, exactly as spec §12/§44/§45 require.

**STEP 49 COMPLETE.**

## Step 50 — Pharmacy Reporting & Operational Analytics

### Audit findings

Steps 44-49 already own every fact this step reports on: `PrescriptionOrder`/
`PrescriptionVerification` (prescribing + verification), `Dispensing`/
`DispensingItem` (fulfillment), and `StockItem`/`StockBatch`/
`StockTransaction` (the append-only stock ledger). No reporting/summary
table existed anywhere in the schema, and none was warranted — every figure
below is a live query or aggregation against those tables, never a cached
statistic that could drift from them. `StockSearchService` already held the
low-stock/out-of-stock/expiring/expired/quarantined classification logic
(`itemsQuery()`/`count()`); `StockService::reconcile()` already held the
ledger-vs-recorded-quantity check; `DispensingService::fulfillmentStatus()`
already derived per-order fulfillment state. All three are reused here, not
re-derived. The existing `/pharmacy` route (`PharmacyController::index()`)
was the natural home for the enriched operational dashboard spec §5 asks
for — no second, competing dashboard route was created.

### Decision: extend `StockSearchService` rather than build a parallel query layer

`batchesQuery()`/`scopeBatchesToHospital()` and `movementsQuery()`/
`scopeMovementsToHospital()` were added alongside the existing
`itemsQuery()`/`scopeToHospital()`/`count()` methods, and a `'damaged'` case
was added to `itemsQuery()`'s status `match` (unlike `expiring_soon`/
`expired`, a damaged batch's `quantity` is always `0` once written off, so
its `whereExists` check must not require `quantity > 0`). Every stock-facing
report reuses this one service; no report queries `StockBatch`/
`StockTransaction` directly.

### Decision: no per-report Spatie policy

Every prior step attached a policy to its owning Eloquent model. Reports
here are cross-cutting (a single page can join prescriptions, verification,
dispensing, and stock) with no single natural model to attach a policy to.
Mirroring `PharmacyController`'s own precedent, authorization is a single
`pharmacy-reports.view`/`pharmacy-reports.export` permission pair checked via
direct `abort_unless()` calls in each controller action — never in the
constructor, since a constructor-level check evaluates
`request()->user()?->can(...)` against the console's own userless request
during `artisan route:list` (which resolves every controller to introspect
its actions), throwing on the `null` the null-safe operator produces.

### Decision: CSV export reuses the exact on-screen query, never a second export-only query

Every report action builds one filtered/scoped query (or one filtered
service call); `?export=1` streams that same query's rows as CSV instead of
paginating them for Inertia. No export path independently re-filters or
re-scopes — the two paths cannot silently diverge.

### What this step built

Nine report surfaces plus the enriched dashboard, all under
`pharmacy/reports/*` (`PharmacyReportController` + `PharmacyReportService`):
current stock batches, low-stock, out-of-stock, expiry (expiring-soon and
expired share one page, two tabs), stock movements, medication utilization
(day/week/month grouping), prescriptions (with a live-computed
`dispensing_status` per row via `DispensingService::fulfillmentStatus()`,
bounded to the current page), dispensing, and stock reconciliation (batch
quantity vs. `StockService::reconcile()`'s ledger balance). The `/pharmacy`
dashboard gained a `metrics` prop — prescriptions today/this-week/this-month/
cancelled, verification pending/verified/returned counts, dispensing
dispensed/pending buckets, stock low/out-of-stock/expiring/expired/
quarantined/damaged counts, and this-month movement counts by transaction
type — sent only to users holding `pharmacy-reports.view`.

### Security

Authentication: every report route sits behind the same session guard as
the rest of the app. Authorization: a single `pharmacy-reports.view`
permission gates every report and the dashboard's `metrics` prop; a distinct
`pharmacy-reports.export` permission additionally gates every CSV export,
checked before the export's data is fetched (not merely before the response
is returned) so an unauthorized export attempt cannot force execution of the
underlying query. IDOR/facility isolation: every report is scoped to a
hospital-admin's own hospital server-side (`scopeToHospital()`/
`scopeBatchesToHospital()`/`scopeMovementsToHospital()`/
`scopePrescriptionsToHospital()`/`scopeDispensingToHospital()`); an
unrestricted role (super-admin via Spatie's `Gate::before`, or any other
role without the hospital-admin restriction) sees every hospital. Mass
assignment: reports are read-only; no report action writes to any model.
Audit: every export records a `pharmacy_report_exported` audit log entry
(report name, row count) with no patient data in the log payload itself.

### Tests

`tests/Feature/PharmacyReports/`: `PharmacyReportAuthorizationTest` (8),
`PharmacyReportIdorTest` (5), `PharmacyReportTest` (10),
`PharmacyReportExportTest` (3), `PharmacyReportEndToEndTest` (3) —
**29/29 pass**.

Regression: `Pharmacy` + `PrescriptionOrder` + `PrescriptionVerification` +
`Dispensing` + `Stock` + `PharmacyReports` — **248/248 pass**. `tsc`/`eslint`
clean; `pint --dirty` clean.

Two bugs were caught and fixed during this step's own verification, not left
for a later step: (1) `medicationUtilization()`'s period-grouping SQL used
MySQL-only `DATE_FORMAT(...)`, which the SQLite-backed test suite cannot
run — `PharmacyReportService::utilizationPeriodExpr()` now branches on
`DB::connection()->getDriverName()` (MySQL in production, SQLite for tests).
(2) every export branch evaluated its query's `->get()` as a function
argument to `exportCsv()` before that method's own `abort_unless('pharmacy-
reports.export')` ran (PHP evaluates arguments eagerly), so an unauthorized
export attempt still executed the full underlying query — each export branch
now checks the export permission itself before building the export payload.

### Integration boundary for Step 51

Nothing here computes money, generates an invoice, or talks to NHIF —
`medicationUtilization()`'s `total_quantity` is a unit count, not a cost.
Billing/claims work needing pharmacy consumption figures should call
`PharmacyReportService::medicationUtilization()` rather than re-deriving
dispensed-quantity aggregation a second way.

**STEP 50 COMPLETE.**

## Step 51 — Pharmacy Integration & End-to-End Testing (Phase 6 finalization)

### Audit findings

A full re-audit of Steps 44-50 against the spec's suggested alternative
schema (`medicine`/`inventory`/`stock_movements`/`pharmacy_locations`/
`medication_categories`/`medication_forms`/`medication_strengths`/
`medication_units`/`stock_adjustments`/`stock_transfers`) confirmed none of
these exist as separate tables or models — every one of those concepts is
either a plain column on `medications`/`stock_batches` or folded into the
single append-only `StockTransaction` ledger via `transaction_type`, exactly
as Steps 45/49 already decided and documented. `routes/api.php` still holds
only the public `health` liveness check; zero pharmacy routes exist there.
`php artisan migrate:status` confirms every pharmacy migration has run.

One pre-existing, legitimately separate concept was re-confirmed rather than
merged: the `Prescription` model (Phase 3, `CarePlanItem`-nested) is the
doctor's care-plan-level prescribing narrative, unrelated to `PrescriptionOrder`
(Phase 6, Step 46) — the actual pharmacy-facing order, its own sibling
branch off the clinical encounter (mirroring `RadiologyOrder`'s own
promotion out of `CarePlanItem` nesting, Phase 5, Step 36). This was already
audited and documented in Step 46; re-verified here, not duplicated further.

### Integration gap found and fixed

The patient's clinical/medication history (`ClinicalHistoryEncounterResource`,
reached via `GET patients/{patient}/clinical-history`) surfaced each
`PrescriptionOrder`'s own `status` (draft/active/cancelled only) but never
its verification or dispensing progress — a clinician viewing history had no
way to tell a pending prescription from a verified one, or a partially
dispensed one from a fully dispensed one (spec §19's explicit requirement).
Fixed by adding `verification_status` (from the already-loaded
`PrescriptionOrder.verification` relation) and `dispensing_status` (via
`DispensingService::fulfillmentStatus()`, the same authoritative calculation
the dispensing worklist and Step 50 prescription report already use — never
a second, competing calculation) to the resource, bounded to each
encounter's own orders on the current page. `ClinicalHistoryService` gained
one additional eager load (`prescriptionOrders.verification`); the frontend
(`clinical-history.tsx`) gained two additional status badges reusing the
existing `prescriptionVerificationStatusLabel`/`fulfillmentStatusLabel`
helpers Step 47/48 already built. Covered by
`tests/Feature/Pharmacy/PatientMedicationHistoryTest.php` (5 tests,
including the exact "never shows a partial dispense as fully dispensed"
case spec §19 calls out).

### Cross-branch clinical independence confirmed

`PrescriptionOrderService`, `LaboratoryOrderService`, and
`RadiologyOrderService` each carry an identical, independent
`assertEncounterEligible()`/`assertConsultationEligible()` guard — neither
references the others' existence or state. Verified with a new test,
`tests/Feature/Pharmacy/PharmacyClinicalBranchIndependenceTest.php` (2
tests): a single encounter can carry a prescription, a laboratory order, and
a radiology order simultaneously, in any creation order, with no artificial
sequential dependency.

### Mandatory end-to-end scenario (spec §35)

`tests/Feature/Pharmacy/PharmacyFullPathwayE2ETest.php` reproduces the
spec's own exact numbers: Paracetamol 500mg x100 prescribed, Batch A (50
units, expiring in 3 months) + Batch B (100 units, expiring in 2 years),
dispensed as 80 then 20. Confirms FEFO exhausts Batch A first (50, then 30
from Batch B), the second dispensing pulls the remaining 20 from Batch B,
every stock transaction's `balance_after = balance_before + quantity`, the
reconciliation report finds both batches reconciled, the medication
utilization report shows exactly 100 units consumed, and the patient's
clinical history shows the prescription as `dispensed` (never partial) —
one single test asserting every downstream surface agrees with the same
underlying transaction.

### Concurrency (spec §15/§16)

Confirmed via the existing `StockConcurrencyTest` (adjustment/FEFO-allocation
races bounded at zero, quarantine races), `DispensingConcurrencyTest`
(single-in-progress-dispensing-per-prescription guard is what actually
prevents two pharmacists from both dispensing the same remaining quantity),
`PrescriptionOrderConcurrencyTest`, and `PrescriptionVerificationConcurrencyTest`
— every state-changing service method row-locks its target
(`lockForUpdate()`) inside a `DB::transaction()`, verified in this step by
re-reading every mutating method in `StockService`, `DispensingService`, and
`PrescriptionVerificationService`. No gaps found; no new concurrency
mechanism was needed.

### Security

Authentication/authorization/facility-isolation/IDOR/mass-assignment/
status-manipulation/quantity-manipulation were all re-audited against the
existing test suite (test names alone already enumerate: self-verification
rejection, cross-hospital IDOR on every resource id, client-supplied
prescriber_id/verifier_id/dispensed_by/status/balance rejection, quantity-
exceeding-remaining rejection) rather than re-tested from scratch — no gap
was found that needed a new security test. `routes/api.php` re-confirmed
clean of internal pharmacy routes.

### Notifications

No notification infrastructure (queued jobs, mail, database notifications)
exists anywhere in this application yet — not just in Pharmacy. Building one
now would be a new cross-cutting system outside this finalization step's
scope (spec §44 forbids Phase 7+ work; a notification system is itself a
standalone concern, not a pharmacy fix). The existing Step 50 dashboard/
reports (low-stock, expiring-soon, pending-verification counts) already
serve the same operational-awareness purpose synchronously. Documented here
as a known gap, not silently worked around.

### Regression

Laboratory + Radiology: **541/541 pass** (zero regressions from any
Pharmacy change — `ClinicalHistoryEncounterResource`'s constructor change
only affects its own single call site in `PatientController`). Full
Pharmacy suite (`Pharmacy`, `PrescriptionOrder`, `PrescriptionVerification`,
`Dispensing`, `Stock`, `PharmacyReports`, plus the base `ClinicalHistoryTest`
and this step's 3 new files): pass — see the final Step 51 report for the
exact count. `tsc`/`eslint` clean; `pint --dirty` clean.

**STEP 51 COMPLETE — PHASE 6 (PHARMACY) COMPLETE.**
