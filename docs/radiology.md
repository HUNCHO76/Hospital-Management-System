# Radiology Management Foundation (Phase 5, Step 36)

## Existing functionality audit

No radiology entity existed anywhere before this step — exhaustive search
(`radiology|imaging|xray|x_ray|ultrasound|\bct\b|mri|mammograph|fluoroscop
|scan|sonograph|dicom|medical_imaging`) across `app/`, `database/`,
`routes/`, `tests/`, `resources/js/` returned nothing but:

- **`CarePlanItem::TYPE_RADIOLOGY`** — a `radiology`-typed generic
  treatment item (Step 27), carrying no structured procedure/priority/
  clinical-indication fields of its own, the exact same "clinical handoff
  boundary only" shape Laboratory had before its own Step 30 restructure.
  This step applies the identical correction Laboratory already went
  through: `TYPE_RADIOLOGY` is removed from `CarePlanItem::TYPES` entirely
  (and every corresponding frontend option), and Radiology becomes its own
  first-class `RadiologyOrder` belonging directly to the
  `ClinicalEncounter` — never nested under a `CarePlanItem`.
- **No generic `clinical_orders`/`service_orders` architecture** exists
  anywhere in the codebase to extend — Laboratory itself is the only
  precedent, implemented as its own dedicated domain (`LaboratoryOrder`/
  `LaboratoryOrderItem`/`LaboratoryTest`), not a generic order system. This
  step therefore mirrors Laboratory's own architecture directly rather
  than inventing a shared abstraction neither module asked for.

Three things *did* already exist and are reused as-is:

- **The `RADIOLOGY` department** — already seeded by `DepartmentSeeder`
  under the main hospital.
- **The `radiology-technician` practitioner type** — already recognized by
  `Practitioner::TYPES`/`CLINICAL_JOB_TITLES`, though it had no role or
  permissions of its own until this step.
- **The entire Laboratory Order architecture (Steps 28-30)** as the
  structural template: `LaboratoryOrder`/`LaboratoryOrderItem`/
  `LaboratoryOrderService`/`LaboratoryOrderPolicy`/the encounter-nested +
  standalone controller split/the search-json catalog-picker pattern are
  all mirrored directly for Radiology, field-for-field and rule-for-rule
  where the two domains are genuinely the same shape.

## The distinction: Procedure, Order, Order Item

- **`RadiologyProcedure`** — catalog master data ("what radiology can
  perform"): X-Ray Chest, Ultrasound Abdomen, CT Brain, MRI Spine,
  Mammography, Fluoroscopy, etc. Deliberately minimal this step (code,
  name, modality, department, nhif_code/price placeholders, status) —
  mirrors exactly how `laboratory_tests` started in Step 28 before Step 29
  grew it into a full configurable catalog with categories and
  activation workflows. Full catalog management is Step 37's job.
- **`RadiologyOrder`** — a specific request for one or more procedures
  against one patient's encounter ("this patient needs a chest X-ray and
  an abdominal ultrasound"). Belongs directly to `ClinicalEncounter`, its
  own sibling branch alongside Prescription/Laboratory/Diagnosis/Clinical
  Notes.
- **`RadiologyOrderItem`** — one requested procedure within an order,
  snapshotting `procedure_code`/`procedure_name` at order time.
- **Examination / Findings / Report** — not built. A future
  `RadiologyExamination`/`RadiologyReport` (Steps 41-43) will reference a
  `RadiologyOrderItem`, mirroring how `LaboratoryResult` references a
  `LaboratoryOrderItem`.

## Domain model

```
ClinicalEncounter
  |
  +-- LaboratoryOrder (hasMany)   -- Phase 4, unaffected sibling branch
  |
  +-- RadiologyOrder (hasMany)    -- its own sibling branch, alongside
  |      |                           Prescription/Laboratory/Diagnosis/
  |      |                           Clinical Notes/Treatment
  |      +-- RadiologyOrderItem (hasMany) -- one row per requested procedure
  |             |
  |             +-- RadiologyProcedure (belongsTo, the catalog entry)
```

Radiology is explicitly **not** modeled as a continuation of Laboratory
(`Laboratory -> Radiology`) — the two are independent diagnostic services,
each its own direct child of the encounter:

```
Clinical Decision
 +-- Prescription
 +-- Laboratory
 +-- Radiology
 +-- Procedure
 +-- Referral
 +-- Follow-up
```

**Duplicate procedures within one order are rejected, not silently
deduplicated** — the same documented choice Laboratory made:
`RadiologyOrderService::create()`/`update()` throw a validation error
rather than quietly dropping a repeat selection. Two *separate* orders may
legitimately reference the same procedure later (a follow-up scan), so
there is no DB-level unique constraint on
`(radiology_order_id, radiology_procedure_id)`.

**No automatic duplicate-order blocking** — the spec explicitly warns
against blindly preventing duplicates "if the clinical workflow
legitimately requires repeated examinations" (e.g. a follow-up chest X-ray
after treatment). No such check was added; this mirrors Laboratory's own
precedent of allowing re-testing without restriction.

## Order lifecycle

```
ordered -> cancelled
```

Only the foundation status set (`draft`/`ordered`/`cancelled`) is
implemented — `draft` is declared for completeness but never persisted (an
order is created directly as `ordered`, mirroring `LaboratoryOrder`'s own
precedent). Scheduling/patient-arrival/preparation/in-progress/completed/
result-pending/verification/release states are **not** forward-declared,
unlike Laboratory's own Step 30 (which did forward-declare its full future
state list) — this step's own spec explicitly instructs against
introducing later-step workflow states "unless the existing architecture
already requires them," which it does not yet for Radiology. Steps 38-44
will extend `RadiologyOrder::STATUSES`/`TRANSITIONS` and
`RadiologyOrderItem::STATUSES`/`TRANSITIONS` as those workflows are built.

Order numbers use the existing `GeneratesBusinessNumber` trait (never
`count()+1`/`MAX(id)+1`): `RAD-{year}-{sequence}`, e.g. `RAD-2026-000001`
— concurrency-proven the same way `LAB-`/patient/encounter numbers already
are (lock-for-update + last-value + 1, inside a transaction).

## Creating an order

`RadiologyOrderService::create()` mirrors `LaboratoryOrderService::create()`
exactly:

1. Locks the encounter row (serializes concurrent order creation).
2. Asserts the encounter is in an active status
   (`ClinicalEncounter::ACTIVE_STATUSES`).
3. Asserts the encounter has a consultation the doctor has actually
   engaged with (`in_progress`/`completed`) — **deliberately does not
   require a diagnosis**, the same explicit exception Laboratory has:
   urgent imaging must never be blocked on a final diagnosis not yet being
   entered.
4. Validates every `procedure_id` is distinct and resolves to an
   **active** `RadiologyProcedure` — an inactive or non-existent procedure
   id is rejected, never silently dropped.
5. Creates the order (`status = ordered`, `ordered_by`/`ordered_at` from
   the authenticated user/now — never trusted from the request body) and
   one `RadiologyOrderItem` per procedure, snapshotting
   `procedure_code`/`procedure_name` from the live catalog row.
6. Records `radiology_order_created` plus one `radiology_order_item_added`
   per item.

The whole operation runs inside one `DB::transaction()` — no partially
created order can ever persist.

**Patient/encounter consistency is enforced by construction, not by a
separate check**: `create()` only ever accepts a `ClinicalEncounter`
instance and procedure ids — there is no `patient_id` input path at all.
Every order's patient is reached exclusively through
`clinicalEncounter->patient_id`, so an order can never be created for one
patient using another patient's encounter regardless of what a caller
submits.

**Ordering practitioner is never trusted from the client**: `ordered_by`
is always the authenticated user (`$request->user()->id`), and
`RadiologyOrderPolicy::create()` additionally requires the acting user to
be the encounter's own treating practitioner (or a hospital-admin/
super-admin) — a doctor cannot place a radiology order against another
doctor's consultation.

## Updating and cancelling

`update()` only allowed while still `ordered`; may add new procedures
(never removes existing ones — that is `cancelItem()`'s job) and audits a
priority change (`radiology_order_priority_changed`) distinctly from other
field edits (`radiology_order_updated`). `cancel()` requires a reason,
cascades to cancel every non-cancelled item, and is never reachable twice.
`cancelItem()` removes a single procedure while both the parent order and
the item itself are still `ordered`. Nothing is ever hard-deleted — a
cancelled order/item remains fully queryable and historically traceable.

## Permissions

Reuses the exact naming convention Laboratory established:

```
radiology              view
radiology-orders       view, create, update, cancel
radiology-procedures   view
```

Only the abilities the actual Step 36 workflow needs — no
`radiology-orders.view_all`/`view_own_department` (department scoping is
handled by `RadiologyOrderPolicy`'s existing hospital-admin check, the
same mechanism Laboratory uses), no catalog `create`/`update`/`activate`/
`deactivate` permissions (Step 37's job).

## Role separation

```
doctor               radiology-orders.{create,update,cancel}, radiology-procedures.view
radiology-technician  radiology.view, radiology-orders.view, radiology-procedures.view  (new role)
hospital-admin        full set, scoped to their own hospital
super-admin           unrestricted (Gate::before bypass)
```

A doctor cannot view the standalone Radiology dashboard/worklist (no
`.view` grant, mirroring Laboratory's identical doctor permission split —
the doctor only ever encounters radiology orders inline on the clinical
encounter they are treating). `radiology-technician` is intentionally
view-only this step — scheduling/examination/reporting abilities belong to
Steps 39-43, the same way `laboratory-technician` grew its permissions
incrementally across Steps 28-35.

## IDOR protection

- `RadiologyOrderPolicy::view()`/`update()`/`cancel()` all check hospital
  ownership (for `hospital-admin`) via the encounter's department, and
  `create()`/`update()`/`cancel()` additionally require the acting doctor
  to actually be the encounter's own treating practitioner (or an
  unrestricted hospital-admin/super-admin) — proven by test: a second
  doctor with a completely different consultation on the same encounter
  cannot create or cancel its radiology orders.
- `EncounterRadiologyOrderController::assertBelongsToEncounter()`/
  `assertItemBelongsToOrder()` reject (404) any order/item id that does
  not actually belong to the encounter/order named in the URL — a client
  cannot substitute a different encounter or order id to reach another
  patient's order.
- Cross-hospital access is denied end-to-end: a hospital-admin scoped to
  hospital A cannot view or cancel hospital B's radiology order.

## Audit logging

Reuses the existing `AuditLogService` — no second audit system. Events
implemented this step (mirroring Laboratory's own event names exactly):

```
radiology_order_created
radiology_order_updated
radiology_order_cancelled
radiology_order_item_added
radiology_order_item_removed
radiology_order_priority_changed
```

## Backend architecture

```
routes/web.php
  -> EncounterRadiologyOrderController   (store/update/cancel/cancelItem,
  |                                        nested under clinical-encounters,
  |                                        redirects back to the encounter
  |                                        page — never its own Inertia page)
  -> RadiologyOrderController            (index/orders/show — the standalone,
  |                                        cross-encounter dashboard/worklist/
  |                                        detail surface)
  -> RadiologyProcedureController        (searchJson only — the lightweight
                                           catalog picker; full catalog
                                           management is Step 37)
       |
       v
  Form Requests (Store/Update/CancelRadiologyOrderRequest,
                 CancelRadiologyOrderItemRequest)
       |
       v
  RadiologyOrderPolicy
       |
       v
  RadiologyOrderService / RadiologyOrderSearchService /
  RadiologyProcedureSearchService
       |
       v
  RadiologyOrder / RadiologyOrderItem / RadiologyProcedure (Eloquent)
       |
       v
  MySQL: radiology_orders, radiology_order_items, radiology_procedures
```

No internal `/api/v1/radiology/...` routes exist — every internal route is
under `routes/web.php`, Inertia-rendered. The one JSON-returning route
(`radiology-procedures/search-json`) is the same established pattern
`laboratory-tests/search-json`/`medications/search-json`/
`diagnosis-codes/search-json` already use — a lightweight web-route JSON
endpoint for a combobox, not a REST API layer.

## Frontend

- `resources/js/components/clinical-encounters/radiology-orders-section.tsx`
  — embedded on the clinical encounter screen (mirrors
  `laboratory-orders-section.tsx` exactly), listing existing orders with
  inline cancel/remove-procedure actions and an inline "New Radiology
  Order" form. The patient/encounter/ordering-practitioner context is
  entirely inherited from the page — never re-entered by the doctor.
- `resources/js/components/radiology/radiology-procedure-search.tsx` /
  `radiology-order-procedure-selector.tsx` — the search-and-accumulate
  procedure picker, mirroring `LaboratoryTestSearch`/
  `LaboratoryOrderTestSelector`.
- `resources/js/pages/radiology/index.tsx` — the dashboard (real
  status-distribution counts, recent orders).
- `resources/js/pages/radiology/orders/index.tsx` — the full paginated,
  server-side-filtered worklist (search, status, priority, department,
  practitioner, date range, plus Pending/Today/Urgent/STAT/Cancelled quick
  filters).
- `resources/js/pages/radiology/show.tsx` — order detail. Examination/
  findings/report fields are deliberately absent — no fake result data is
  fabricated; those are Steps 41-43's job.
- Sidebar: a new "Radiology" entry, gated on `radiology.view`, alongside
  the existing "Laboratory" entry.

All pages use `Inertia::render()`/the Inertia router and forms exclusively
— no Axios/fetch-based internal API communication (the one `fetch()` call,
in the procedure-search combobox, targets the same-origin web route
described above, identical to every other catalog-search combobox already
in this codebase).

## Billing / NHIF integration boundary

`RadiologyProcedure.nhif_code`/`.price` exist as nullable placeholder
columns, exactly mirroring `LaboratoryTest`'s own precedent — neither is
read or written by any logic in this step. No billing, payment, or NHIF
claims logic was implemented or touched; the existing NHIF integration
remains entirely server-side, with no credentials, tokens, or endpoints
exposed to React. The target future relationship
(`RadiologyProcedure -> Service/Catalog -> Billing Price -> Patient Charge
-> Insurance/NHIF Mapping`) is preserved but not built.

## Testing

`tests/Feature/Radiology/`: `RadiologyOrderTest` (order/item creation,
order-number format, snapshotting, duplicate rejection, priority default
and distinct audit, update/cancel/cancelItem lifecycle and their guards),
`RadiologyOrderItemTest` (inactive/non-existent procedure rejection, item
cancellation validation, cross-order item IDOR, re-adding an existing
procedure is a no-op), `RadiologyOrderValidationTest` (consultation not
yet engaged, non-active encounter, invalid priority, empty procedure list,
patient/encounter consistency by construction),
`RadiologyOrderAuthorizationTest` (guest, nurse, doctor-owns-vs-another-
doctor's-consultation, permission-less doctor, radiology-technician
view-only, receptionist forbidden, hospital-admin cross-hospital IDOR,
mismatched-encounter-in-URL IDOR, super-admin bypass),
`RadiologyOrderConcurrencyTest` (unique order numbers under repeated
creation, single-winner cancellation race), `RadiologyOrderEndToEndTest`
(the full reception -> encounter -> triage -> consultation -> clinical
decision -> radiology order with multiple procedures -> encounter page ->
worklist -> detail -> item removal -> order cancellation -> full audit
trail pathway, ending at RADIOLOGY ORDERED). Full Phase 1-5 regression
suite re-run in memory-safe batches after these additions — 846 tests
passing.

## Future integration boundary

Radiology Test Catalog Management (Step 37), Scheduling & Appointment
(Step 39), Patient Preparation & Reception (Step 40), Examination &
Procedure (Step 41), Findings & Results (Step 42), Verification &
Reporting (Step 43), Result Release (Step 44), Billing Integration
(Step 45), NHIF Integration (Step 46), and full workflow integration
testing (Step 47) all remain entirely out of scope, per this step's own
explicit strict scope boundary.

**STEP 36 COMPLETE — the radiology workflow has reached: Doctor
Consultation -> Clinical Decision -> Radiology -> Procedure Selected ->
Radiology Order Created -> Radiology Worklist -> RADIOLOGY ORDERED.**

# Radiology Test/Procedure Catalog & Configuration (Phase 5, Step 37)

## Existing architecture audit

Searched for `radiology_tests|radiology_services|imaging_tests|
\bservices\b|service_catalog|medical_services|clinical_services|
procedure_catalog` across `app/`, `database/`, `routes/`. No generic
service/billing catalog architecture exists anywhere in the codebase — the
only match was the unrelated `HospitalService` business-logic class (not a
catalog entity). This settles Part 3 definitively: there is nothing to
reuse from a generic catalog, so `RadiologyProcedure` (Step 36) remains the
sole catalog entity and is extended directly, mirroring exactly how
`LaboratoryTest` was grown in Step 29 rather than replaced by a generic
`Service` abstraction.

## Decision

**Extend `RadiologyProcedure` in place — no new tables, no generic Service
model, no per-facility pivot table.** Specifically:

- **Category vs. Modality (Parts 6-7)**: both parts list the identical
  value set (X-Ray/Ultrasound/CT/MRI/Mammography/Fluoroscopy/Other). Adding
  a separate `category` column would duplicate the exact same fact the
  existing `modality` column already stores. Consolidated into the single
  `modality` column — applying Part 3's "no duplicate architecture"
  principle one level deeper (no duplicate *columns*, not just no
  duplicate tables).
- **Body site (Part 8)**: a procedure may span multiple regions (e.g. a
  combined CT chest/abdomen). Used a JSON array column (`body_sites`, cast
  to `array`) rather than a new pivot table, directly reusing the existing
  `laboratory_tests.result_options` precedent for "one-to-many-without-a-
  join-table" catalog data.
- **Laterality (Part 9)**: the spec itself flags the ambiguity of whether
  laterality belongs on the catalog or the order. Kept `laterality` on the
  catalog row as a descriptive default/applicability marker only (e.g. "US
  Kidney is typically bilateral"); the actual per-patient-order laterality
  selection is explicitly deferred to a future step — no column was added
  to `radiology_order_items` in this step, keeping the change strictly in
  scope.
- **Facility-specific availability (Part 21)**: declined to build a
  facility-enablement pivot table. The existing Laboratory catalog is
  itself global/hospital-agnostic, and nothing in this codebase's
  architecture requires per-facility catalog scoping today — a
  considered-and-declined decision per the spec's own explicit escape
  hatch ("unless the existing architecture requires that").
- **Versioning (Part 15)**: no effective-dating/versioning architecture
  exists anywhere in the codebase to extend. Historical integrity is
  preserved the same way Laboratory already does it — `RadiologyOrderItem`
  snapshots `procedure_code`/`procedure_name` at order time (Step 36,
  unchanged), so a later catalog edit or deactivation never mutates a
  historical order's displayed values.
- **Permissions (Part 28)**: reused/extended the existing
  `radiology-procedures.*` permission group from Step 36 rather than the
  spec's own illustrative `radiology.catalog.*` dotted naming — the spec's
  own instruction is to "use the project's actual naming convention," and
  `radiology-procedures.*` is that convention (mirrors `laboratory-tests.*`).
- **Equipment/rooms/technician scheduling**: not built, per Part 22 — the
  catalog's `turnaround_time_minutes` field is an operational *target*
  only, no equipment/room/technician entity was created or referenced.

## Changes made

**Models**: `RadiologyProcedure` fully rewritten — new consts
`MODALITIES`, `BODY_SITES`, `LATERALITIES`, `CONTRAST_REQUIREMENTS`,
`PREPARATION_TYPES`; new fillable fields `body_sites` (array cast),
`laterality`, `contrast_requirement`, `contrast_type`, `preparation_type`,
`preparation_instructions`, `turnaround_time_minutes`, `sort_order`,
`created_by`, `updated_by`; new `createdBy()`/`updatedBy()` belongsTo
relations; `isReferenced()` (checks `RadiologyOrderItem` existence, mirrors
`LaboratoryTest::isReferenced()`).

**Migrations**: one additive migration
(`add_configuration_fields_to_radiology_procedures_table`) — all new
columns nullable or defaulted, no data loss, no column drops.

**Services**: new `RadiologyProcedureService` (create/update/activate/
deactivate), mirroring `LaboratoryTestService` exactly: code normalized to
uppercase/trimmed, uniqueness enforced, code becomes immutable once
`isReferenced()` is true (non-code fields remain editable), every mutation
wrapped in `DB::transaction()`, distinct audit events per logical change
type (identity vs. configuration vs. billing vs. NHIF).

**Controllers**: `RadiologyProcedureController` fully rewritten —
`authorizeResource()` + index/create/store/show/edit/update/activate/
deactivate, plus the existing `searchJson()` now filters to
`status = active` only and supports modality/body_site search filters.
Thin controller — all mutation logic lives in `RadiologyProcedureService`.

**Form Requests**: new `StoreRadiologyProcedureRequest`/
`UpdateRadiologyProcedureRequest` — server-side `Rule::in()` validation
against every enum list, uniqueness on `code`, `department_id` existence,
non-negative `turnaround_time_minutes`. React never enforces these rules
alone.

**Policies**: `RadiologyProcedurePolicy` extended to full viewAny/view/
create/update/activate/deactivate, stateless (catalog is not
hospital-scoped, matching `LaboratoryTestPolicy`'s own precedent).

**Search**: `RadiologyProcedureSearchService` extended with modality and
body_site (`whereJsonContains`) filters, alongside the existing
code/name/category/active-status filters — server-side paginated, never
loads the full catalog to the browser.

**Routes** (`routes/web.php`, Inertia only, no new API routes):

```
GET    radiology/catalog                          radiology-catalog.index
GET    radiology/catalog/create                    radiology-catalog.create
POST   radiology/catalog                            radiology-catalog.store
GET    radiology/catalog/{radiologyProcedure}       radiology-catalog.show
GET    radiology/catalog/{radiologyProcedure}/edit  radiology-catalog.edit
PUT    radiology/catalog/{radiologyProcedure}       radiology-catalog.update
POST   radiology/catalog/{radiologyProcedure}/activate    radiology-catalog.activate
POST   radiology/catalog/{radiologyProcedure}/deactivate  radiology-catalog.deactivate
```

No `destroy` route — catalog entries are never hard-deleted, only
deactivated (Part 13).

**React**: `resources/js/types/radiology.ts` fully rewritten with all new
enum types plus `RadiologyProcedure`/`RadiologyProcedureFormData`/
`RadiologyProcedureFilters`; `lib/radiology-status.ts` gained label/variant
maps for every new enum (modality, body site, laterality, contrast
requirement, contrast type, preparation type); new shared
`radiology-procedure-form.tsx` (create/edit); new pages
`radiology/catalog/{index,create,edit,show}.tsx` (server-side paginated
list with search/modality/status filters); `radiology-procedure-search.tsx`
(the order-time combobox) updated to display modality + preparation info;
`radiology/index.tsx` gained a "Catalog" quick-link.

**Seeders**: `RadiologyProcedureSeeder` fully rewritten — idempotent
`updateOrCreate()` keyed by `code`, seeding the canonical 36-procedure list
(X-Ray x12, Ultrasound x8, CT x6, MRI x6, Mammography x3, plus one
Fluoroscopy example). Before seeding, safely removes Step 36's 12 old
placeholder codes (`XRAY_CHEST` etc.) — verified via query that zero
`RadiologyOrderItem` rows referenced any of them, so
`whereDoesntHave('orderItems')->delete()` is provably non-destructive.
Verified idempotent by running twice (36 procedures both times, no
duplicates, no errors).

**Permissions/Roles**: `radiology-procedures` group extended to
`['view', 'create', 'update', 'activate', 'deactivate']`; `hospital-admin`
granted the full set. `doctor` and `radiology-technician` keep
`radiology-procedures.view` only (Step 36, unchanged) — catalog management
stays admin-restricted.

**Audit events** (reusing `AuditLogService`, no second audit system):

```
radiology_procedure_created
radiology_procedure_updated
radiology_procedure_configuration_changed   (modality/body_sites/laterality/
                                              contrast/preparation/turnaround)
radiology_procedure_service_mapping_changed (price)
radiology_procedure_nhif_mapping_changed    (nhif_code)
radiology_procedure_activated
radiology_procedure_deactivated
```

**Tests**: `RadiologyProcedureCatalogTest` (9), `RadiologyProcedureAuthorizationTest`
(7), `RadiologyProcedureValidationTest` (9), `RadiologyCatalogEndToEndTest` (1,
28 assertions) — all new, all in `tests/Feature/Radiology/`.

## Database changes

One additive migration on `radiology_procedures`: `body_sites` (json,
nullable), `laterality` (string, default `not_applicable`),
`contrast_requirement` (string, default `no_contrast`), `contrast_type`
(string, nullable), `preparation_type` (string, default `none`),
`preparation_instructions` (text, nullable), `turnaround_time_minutes`
(unsigned int, nullable), `sort_order` (unsigned int, default 0),
`created_by`/`updated_by` (nullable FK `users`, `restrictOnDelete`), plus
indexes on `modality`/`laterality`/`sort_order`. No columns dropped, no
data loss.

## Catalog

36 procedures seeded (idempotent, code-keyed): X-Ray (12: XR-CHEST,
XR-ABDOMEN, XR-PELVIS, XR-SKULL, XR-SPINE-C, XR-SPINE-L, XR-SPINE-T,
XR-HAND, XR-FOOT, XR-KNEE, XR-SHOULDER, XR-WRIST), Ultrasound (8: US-ABD,
US-PELVIS, US-KUB, US-OBSTETRIC, US-THYROID, US-BREAST, US-SCROTAL,
US-RENAL), CT (6: CT-BRAIN, CT-CHEST, CT-ABDOMEN, CT-PELVIS, CT-SPINE,
CT-KUB), MRI (6: MRI-BRAIN, MRI-SPINE, MRI-KNEE, MRI-ABDOMEN, MRI-PELVIS,
MRI-SHOULDER), Mammography (3: MG-BREAST, MG-SCREENING, MG-DIAGNOSTIC),
Fluoroscopy (1 example: FL-GENERAL). Step 36's 12 old placeholder codes
were safely removed (unreferenced by any order).

## Integration

`RadiologyProcedureController::searchJson()` (consumed by
`radiology-procedure-search.tsx` on the clinical encounter's radiology
order form) now filters to `status = active` only — an inactive procedure
is unreachable from the order-creation combobox. `RadiologyOrderService`
already validated `status = active` at creation time (Step 36); this step
adds coverage from the catalog-management side (deactivating a procedure
while orders exist) rather than changing that guard. A `RadiologyOrderItem`
created before deactivation continues to resolve its `radiologyProcedure`
relation and display `procedure_code`/`procedure_name` correctly forever,
since those are snapshotted at order time, not read live from the catalog.

## Tests

- Unit/Service: PASS — create/update/activate/deactivate, code
  normalization, code-immutability-once-referenced, distinct audit events
  per change type.
- Feature (catalog CRUD): PASS — `RadiologyProcedureCatalogTest` (9/9).
- Authorization: PASS — `RadiologyProcedureAuthorizationTest` (7/7): guest
  redirected, doctor view-only, radiology-technician view-only,
  receptionist fully forbidden, hospital-admin full management,
  permission-less user forbidden regardless of procedure id, super-admin
  bypass.
- IDOR: PASS — covered within the authorization suite (id-substitution
  attempts on edit/update/activate/deactivate all rejected server-side,
  never relying on React to hide the actions).
- Validation: PASS — `RadiologyProcedureValidationTest` (9/9): duplicate
  code, missing name, invalid modality/body_site/laterality/
  contrast_requirement/preparation_type, negative turnaround time, invalid
  department.
- Ordering integration: PASS — active procedure appears in search-json and
  is orderable; inactive procedure is excluded from search and rejected at
  order-creation with a validation error; historical order created before
  deactivation still displays its procedure correctly.
- Regression: PASS — full Phase 1-5 suite re-run in memory-safe batches:
  Laboratory/CarePlan/OpdWorkflow/ClinicalEncounters/Consultation/Diagnosis
  (366 passed), Reception/Triage/ClinicalNotes/Patients/Appointments/
  Insurance (246 passed), Hospitals/Departments/Staff/Practitioners/Auth/
  Authorization/Audit/Security/Settings/Integration/Database (198 passed),
  plus the full Radiology directory (62 passed) — 872 tests, zero
  failures.
- E2E: PASS — `RadiologyCatalogEndToEndTest`: admin creates and activates
  `XR-CHEST-E2E` -> doctor searches "Chest" -> selects it -> creates a
  radiology order -> `RAD-2026-XXXXXX` -> `ORDERED`; admin deactivates the
  procedure -> a new order can no longer select it and it drops out of
  search -> the original historical order still displays
  `procedure_code`/`procedure_name` correctly.
- `vendor/bin/pint --format agent`: passed, no changes needed.
- `npx tsc --noEmit`: passed, zero errors.
- `npx eslint` (radiology files): passed, zero warnings.
- `npm run build`: succeeded.

## Remaining issues

None blocking. Explicitly out of scope per this step's own boundary (Part
39): scheduling/appointment booking, patient preparation workflow,
radiology reception, examination workflow, technician workflow,
equipment/room management, PACS/DICOM/RIS integration, findings,
radiologist reporting, verification, authorization, result release,
billing settlement, NHIF claims — all remain future steps.

**STEP 37 COMPLETE — the pathway Radiology Procedure Catalog -> Active
Procedure -> Doctor Clinical Encounter -> Clinical Decision -> Radiology ->
Procedure Selector -> Radiology Order -> ORDERED works correctly end to
end.**

# Radiology Orders (Phase 5, Step 38)

Step 38's own audit found that Step 36 had already built essentially the
entire order-management workflow the spec asked for (create/review/view/
update/cancel, multi-procedure orders, active-only catalog integration,
IDOR/facility isolation, audit logging, encounter + standalone UI). No
duplicate architecture was created. The genuine gaps closed this step:

- **Historical snapshot completeness**: `radiology_order_items` gained
  `modality_snapshot`/`body_site_snapshot` (Step 37 added `modality`/
  `body_sites` to the catalog *after* order items already existed, so
  neither had ever been captured at order time). Populated at item-creation
  time, immune to later catalog edits.
- **Worklist modality filter**: added to `RadiologyOrderSearchService`,
  the controller, and `radiology/orders/index.tsx`.
- **Order detail timeline**: a minimal Created -> Ordered(-> Cancelled)
  card added to `radiology/show.tsx`.
- **Patient Clinical History integration**: radiology orders (order
  number, status, procedures) now appear on `patients/clinical-history`,
  mirroring — but distinct from — Laboratory's released-results-only
  section, since Radiology has no result concept yet.

Full report delivered in-conversation; 872 tests passing (regression +
Radiology suite) at completion.

# Radiology Scheduling & Worklist Management (Phase 5, Step 39)

## Existing architecture audit

Searched for `RadiologyAppointment|RadiologySchedule|RadiologyWorklist|
ImagingAppointment|ImagingSchedule|Room|Facility` and inspected the Phase 2
`Appointment` module in full (model/migration/service/policy/controller/
routes/permissions). Findings:

- **`Appointment` (Phase 2) exists and is mature** — `GeneratesBusinessNumber`
  prefix `APT`, `patient_id`/`department_id`/`practitioner_id`,
  `scheduled_start`/`scheduled_end` datetimes, a 7-state lifecycle
  (`scheduled/confirmed/checked_in/in_progress/completed/cancelled/
  no_show`), and `AppointmentService`'s proven concurrency pattern: lock
  the scheduled resource's row (`Practitioner`), run an interval-overlap
  conflict check, insert inside one transaction.
- **Its scheduling axis is a mandatory, non-nullable `practitioner_id`** —
  a person's slot. There is no room/resource/equipment column anywhere on
  it, and no `Room`/`Equipment`/`facility_id` concept exists *anywhere* in
  this codebase (confirmed by an exhaustive grep of `app/Models` and every
  migration).
- **No generic clinical-order scheduling architecture** exists to extend
  either — `Appointment` is itself domain-specific to practitioner
  consultations, not a generic scheduling engine.

## Decision

**A dedicated `RadiologyAppointment` model/table — not a reuse of
`appointments`.** Weakening `Appointment.practitioner_id` to nullable to
shoehorn radiology in would touch an invariant every existing OPD
appointment relies on, for a domain (room/machine booking) that column was
never designed for — the "genuine domain problem" the spec's own
reuse-first instruction explicitly excepts (Part 33). What *is* reused
deliberately: the exact transaction + row-lock + interval-overlap-check
concurrency pattern, the audited-transition-helper shape, the
required-cancellation-reason discipline, and `AppointmentSearchService`'s
query-builder style — all copied as *patterns*, not as shared code, since
the underlying resource differs.

No `Room`/`Equipment` model was created (explicitly out of scope, Part 8).
`resource_name` is a plain required string label (e.g. "X-Ray Room 1") —
the minimum structure Part 6/9's own escape hatch allows absent a real
equipment registry. Modality compatibility is enforced the only way that's
honest without inventing a fragile room registry: the appointment's
`modality` is always copied from the order item's own `modality_snapshot`,
never user-editable, and displayed prominently next to the resource field
so staff can visually verify the pairing themselves.

Scheduling is **order-item-level** (Part 3): `radiology_appointments`
references `radiology_order_items`, not `radiology_orders` — one order's
two procedures can be booked at different times/resources. The parent
order's own `status` gained a **derived** `SCHEDULED` rollup (set once
every non-cancelled item has an active appointment, reverted the instant
that stops being true) rather than a manually-driven transition — mirrors
Laboratory's own `laboratory_order_status_changed` derived-rollup
precedent.

## Database changes

New table `radiology_appointments`: `radiology_order_item_id` (FK,
restrictOnDelete), `modality` (snapshot, nullable), `resource_name`
(required), `scheduled_start`/`scheduled_end` (datetime), `status`
(default `scheduled`), `notes`, `scheduled_by` (FK users), `confirmed_at`/
`confirmed_by`, `rescheduled_at`/`reschedule_reason`, `cancelled_at`/
`cancelled_by`/`cancellation_reason`, timestamps. A generated
`active_slot_key` column (`CONCAT`/`||` of `resource_name` and
`scheduled_start`, NULL unless `status` is `scheduled`/`confirmed`) carries
a **unique index** — the database-level double-booking backstop for the
exact-same-resource-and-start-time race, including the "first booking of
the day for this resource" case a plain lock-existing-rows check cannot
catch (nothing yet exists to lock). NULL rows (cancelled/no-show) never
collide, so a freed slot can always be rebooked. Concatenation syntax is
driver-detected (MySQL `CONCAT()` vs SQLite `||`, since the test suite runs
on in-memory SQLite) — both support indexable generated columns.

`radiology_orders.STATUSES` gained `SCHEDULED`; `TRANSITIONS` updated to
`ordered <-> scheduled`, both `-> cancelled`.

## Models/relationships

`RadiologyAppointment` (new): `STATUSES`
(`scheduled/confirmed/cancelled/no_show` — Step 40 owns
`arrived/in_progress/completed`, not forward-declared), `ACTIVE_STATUSES`
(`scheduled/confirmed` — the set that occupies the schedule, blocks
re-scheduling the same item, and counts toward the order rollup),
`TRANSITIONS`, `canTransitionTo()`. `RadiologyOrderItem` gained
`radiologyAppointments()` (hasMany — full history, since a cancelled
appointment is never deleted and an item may be scheduled, cancelled, and
rescheduled from scratch more than once) and `activeRadiologyAppointment()`
(hasOne-of-many, scoped to `ACTIVE_STATUSES`).

## Scheduling service

`RadiologySchedulingService`: `schedule()` (locks the item row, validates
item/order eligibility, checks for a resource/time conflict, creates the
appointment, recomputes the order rollup), `reschedule()` (re-checks
conflict under the same lock, records old/new time + reason, never
overwrites history — the audit log carries the full old/new pair),
`confirm()`, `cancel()` (requires a reason, recomputes the order rollup),
`markNoShow()` (also recomputes the rollup — a no-show item is not
"handled," so the order reverts to ORDERED), `cancelActiveAppointmentForItem()`/
`cancelActiveAppointmentsForOrder()` (cascade helpers invoked by
`EncounterRadiologyOrderController` so an appointment can never outlive the
order/item it was scheduled for), `findConflict()` (the resource-scoped,
row-locking interval-overlap query), and the private `recomputeOrderStatus()`
rollup (lock-then-recompute, never touches a CANCELLED order).

`RadiologySchedulingSearchService`: `unscheduledItemsQuery()` (ORDERED
items on a non-cancelled order with no active appointment) and
`worklistQuery()` (appointments, defaulting to active-only unless an
explicit status filter is given). Both always sort STAT-first,
URGENT-next, ROUTINE-last, then by time (Part 16) — never a
user-toggleable sort — via a portable `CASE WHEN` expression (not MySQL's
`FIELD()`, which SQLite's test connection doesn't support).

## Policies & permissions

`RadiologyAppointmentPolicy` mirrors `AppointmentPolicy`'s shape, scoped
through `item -> radiologyOrder -> clinicalEncounter -> department ->
hospital_id` (the same chain every other Radiology policy already uses).
New permission group `radiology-scheduling` =
`[view, create, reschedule, confirm, cancel, no_show]` — no separate
`radiology-worklist.*` group: viewing the worklist and viewing the
scheduling dashboard are the same capability over the same data (Part 26's
own "do not duplicate permissions" instruction, applied). Granted in full
to `radiology-technician` (this role's own doc-comment always anticipated
this incremental growth) and `hospital-admin`; doctors do not receive it
— ordering and scheduling are deliberately separate responsibilities.

## Routes (web.php/Inertia only)

```
GET  radiology/scheduling                                  radiology-scheduling.index
POST radiology/scheduling                                   radiology-scheduling.store
POST radiology/scheduling/{radiologyAppointment}/reschedule radiology-scheduling.reschedule
POST radiology/scheduling/{radiologyAppointment}/confirm    radiology-scheduling.confirm
POST radiology/scheduling/{radiologyAppointment}/cancel     radiology-scheduling.cancel
POST radiology/scheduling/{radiologyAppointment}/no-show    radiology-scheduling.no-show
GET  radiology/worklist                                     radiology-worklist.index
```

No `destroy` route — an appointment is cancelled, never deleted.

## Frontend

`radiology/scheduling/index.tsx` — the "awaiting scheduling" queue
(paginated, filterable by search/modality/priority) plus the selected
day/resource's existing bookings, so staff see what's already taken before
picking a time. **No synthetic slot grid is generated** (documented
limitation, Part 6): no working-hours/slot-duration/room-registry
configuration exists anywhere in this codebase, so availability is shown
as "what's already booked" and enforced authoritatively server-side at
submit time, rather than fabricated as a pre-computed grid. `radiology/
worklist/index.tsx` — the operational view, with Today/Tomorrow/Upcoming/
Overdue/Cancelled quick filters and explicit text priority badges (STAT/
URGENT/ROUTINE — never color alone, Part 17). New dialogs:
`schedule-radiology-item-dialog.tsx`, `reschedule-radiology-appointment-dialog.tsx`,
`cancel-radiology-appointment-dialog.tsx`.

## Audit events

`radiology_appointment_scheduled`, `radiology_appointment_rescheduled`,
`radiology_appointment_confirmed`, `radiology_appointment_cancelled`,
`radiology_appointment_no_show`, `radiology_order_status_changed` (the
order-level rollup, reusing Laboratory's exact naming convention for this
kind of derived multi-item status change).

## Tests

`tests/Feature/Radiology/`: `RadiologySchedulingTest` (core service
behavior — schedule/reschedule/confirm/cancel/no-show, order rollup in
both directions, cascade cancellation, re-scheduling after cancellation),
`RadiologySchedulingConcurrencyTest` (mandatory — identical
resource+exact-time back-to-back attempts only let one succeed;
overlapping-but-different times also rejected; non-overlapping and
different-resource bookings both succeed), `RadiologySchedulingValidationTest`,
`RadiologySchedulingAuthorizationTest` (guest, doctor forbidden from all
scheduling actions, receptionist forbidden, radiology-technician full
access, hospital-admin cross-hospital IDOR, super-admin bypass),
`RadiologyWorklistTest` (visibility, cancelled-appointment exclusion,
modality/priority/resource filtering, priority-first sort), and
`RadiologySchedulingEndToEndTest` (the complete Part 38 scenario: order ->
queue -> schedule -> worklist -> conflicting-booking rejection ->
reschedule -> cancel -> history). 102 Radiology tests passing; full
regression suite (811 tests across Laboratory/CarePlan/OpdWorkflow/
ClinicalEncounters/Consultation/Diagnosis/Reception/Triage/ClinicalNotes/
Patients/Appointments/Insurance/Hospitals/Departments/Staff/Practitioners/
Auth/Authorization/Audit/Security/Settings/Integration/Database) unchanged
at baseline — 913 tests total, zero failures.

## Remaining issues

None blocking. Explicitly out of scope per this step's own boundary (Part
42): patient preparation, radiology reception, MRI safety screening,
consent workflow, examination workflow, DICOM/PACS/RIS, findings,
reporting, verification, release, billing, NHIF claims — all remain
future steps (Step 40+).

**STEP 39 COMPLETE — the pathway Radiology Order (ORDERED) -> Scheduler ->
Unscheduled Orders -> Select Procedure -> Select Date/Time -> Assign
Resource -> Confirm -> SCHEDULED -> Radiology Worklist -> Patient Arrives
works correctly end to end.**

# Radiology Patient Preparation & Reception (Phase 5, Step 40)

## Existing architecture audit

Searched for `RadiologyReception|RadiologyPreparation|PatientArrival|
PatientCheckIn|Consent` and inspected Phase 2's `ReceptionVisit`/
`ReceptionVisitService` (the hospital's front-desk check-in) and Laboratory's
specimen-reception workflow in full. Findings:

- **`ReceptionVisit` is the wrong entity to reuse.** It represents a
  patient's front-desk arrival at the *hospital* (before triage/
  consultation even exist) and is keyed to the generic `Appointment` model
  (mandatory `practitioner_id`). Radiology "patient arrival" is a
  department-level event that happens *after* an order and a
  `RadiologyAppointment` already exist — a different moment in the journey
  entirely, structurally closer to Laboratory's own specimen-reception step
  than to front-desk check-in.
- **No `Consent` model or architecture exists anywhere** in this codebase
  (confirmed by an exhaustive grep) — consent status is recorded directly
  on the appointment rather than inventing a generic consent subsystem no
  other module has ever needed.
- **Step 37's catalog already carries every preparation fact needed**
  (`preparation_type`, `preparation_instructions`, `contrast_requirement`,
  `contrast_type`) — Part 8 explicitly forbids duplicating this
  configuration, so reception reads it live through the order item's
  `radiologyProcedure` relation, never snapshots or re-enters it.

## Decision

**Extend the same `RadiologyAppointment` row from Step 39 — no new
entity.** Arrival, identity/order verification, preparation, MRI
screening, contrast review, consent, and readiness are all further
states/attributes of the one appointment already anchored to a single
`RadiologyOrderItem`. This directly delivers Part 25's "prefer readiness at
order-item level": each procedure on a multi-procedure order carries its
own independent arrival/preparation/ready progress, proven by test (one
procedure can reach READY while a sibling procedure on the same order is
still PREPARATION_PENDING).

**Wrong-patient protection is structural, not computed** (Part 7): an
appointment resolves to exactly one order item, one order, one encounter,
one patient — there is no input path anywhere in this service that accepts
a patient/order/procedure id from the caller, so a mix-up is impossible by
construction, exactly like every prior Radiology write path (Steps 38-39).
"Verification" is therefore a deliberate staff confirmation checkpoint
(Part 36's two checkboxes are client-side UX only), not a validation that
could actually fail.

## Database changes

One additive migration on `radiology_appointments`: `arrived_at`/
`arrived_by`, `verified_at`/`verified_by`, `instructions_given_at`/
`instructions_given_by`, `preparation_completed_at`/`_by`/
`preparation_notes`, `preparation_waived_at`/`_by`/`_reason`,
`mri_screening_completed_at`/`_by`/`mri_screening_flagged`/`_notes`,
`contrast_reviewed_at`/`_by`, `consent_status`/`consent_recorded_at`/`_by`,
`not_ready_reason`, `ready_at`/`ready_by`. `RadiologyAppointment::STATUSES`
gained `ARRIVED`/`PREPARATION_PENDING`/`PREPARATION_IN_PROGRESS`/`READY`/
`NOT_READY`; `ACTIVE_STATUSES` was broadened to include all of them (an
arrived/preparing/ready patient still occupies their slot); a new
`RESCHEDULABLE_STATUSES` constant (`SCHEDULED`/`CONFIRMED` only) keeps
Step 39's reschedule action from applying once a patient has physically
arrived.

## The arrival -> ready pipeline

```
SCHEDULED/CONFIRMED
       |  markArrived()
       v
    ARRIVED  <-------------------------------+
       |  verify()                            |
       |  (no prep required: stays ARRIVED)   |  resume()
       v  (prep required: routes onward)      |
PREPARATION_PENDING -> PREPARATION_IN_PROGRESS |
       |                        |              |
       |                        v              |
       +------------------>  READY        NOT_READY
                                |
                          (Step 41 examination)
```

`verify()` auto-routes the appointment: a procedure whose
`preparation_type` is `none` stays at ARRIVED (immediately eligible for
`markReady()`), satisfying Part 9/24's "procedures without preparation
proceed directly to readiness" — never forcing an unnecessary preparation
step.

## The readiness gate

`RadiologyReceptionService::markReady()` refuses to transition unless
`readinessBlockers()` is empty. Checked, in order: identity/order
verification recorded; preparation completed *or* waived (only when the
procedure actually requires it); for `mri_safety_screening` procedures,
screening completed and not flagged (a flagged screening blocks readiness
until cleared by a fresh, unflagged screening record — Part 12's "never
automatically declare a patient safe"); for `contrast_requirement =
required` procedures, the contrast checkpoint reviewed (Part 13 — a review
checkpoint only, never dose/allergy/medication logic); consent not
`pending`/`declined`. `readinessBlockers()` is exposed separately from
`markReady()` so the reception UI can show staff exactly what remains
instead of just a rejected submission.

## Not ready, never cancelled

`markNotReady()` requires one of Part 15's exact reasons
(`preparation_incomplete`/`identity_issue`/`consent_pending`/
`clinical_review_required`/`equipment_unavailable`/
`patient_condition_changed`/`other`) and never touches the underlying
`RadiologyOrder` — cancellation remains Step 38/39's own explicit action.
`resume()` returns a not-ready appointment to ARRIVED once the blocking
issue is resolved.

## Consent

No generic consent architecture was built (none exists anywhere to
extend, and none was requested). `consent_status`
(`not_required`/`pending`/`obtained`/`declined`) is a plain per-appointment
field staff set directly — deliberately not derived from a nonexistent
Step 37 catalog flag, since assuming a uniform consent process per
procedure would contradict Part 14's own "do not assume every procedure
requires the same consent process."

## Permissions

New group `radiology-reception` = `[view, manage]` — every reception/
preparation/readiness mutation (arrive, verify, start/complete/waive
preparation, record screening/contrast/consent, mark ready/not-ready,
resume) shares the single `manage` ability rather than fragmenting one
continuous staff workflow into many permissions (Part 27's "do not
duplicate permissions," applied one level further). Granted to
`radiology-technician` and `hospital-admin`; doctors and receptionists do
not receive it — reception/preparation is radiology department staff's
job, not the ordering doctor's or the front-desk receptionist's.

## Routes (web.php/Inertia only)

```
GET  radiology/reception                                    radiology-reception.index
POST radiology/reception/{radiologyAppointment}/arrive               .arrive
POST radiology/reception/{radiologyAppointment}/verify                .verify
POST radiology/reception/{radiologyAppointment}/start-preparation     .start-preparation
POST radiology/reception/{radiologyAppointment}/complete-preparation  .complete-preparation
POST radiology/reception/{radiologyAppointment}/waive-preparation     .waive-preparation
POST radiology/reception/{radiologyAppointment}/screening             .screening
POST radiology/reception/{radiologyAppointment}/review-contrast       .review-contrast
POST radiology/reception/{radiologyAppointment}/consent               .consent
POST radiology/reception/{radiologyAppointment}/ready                 .ready
POST radiology/reception/{radiologyAppointment}/not-ready             .not-ready
POST radiology/reception/{radiologyAppointment}/resume                .resume
```

No `destroy` route — nothing here is ever deleted, only transitioned.

## Frontend

`radiology/reception/index.tsx` — today's radiology patients (defaults to
today, every status except cancelled), searchable by patient number/name/
order number/phone, with Not Arrived/Arrived/Preparation Pending/Ready/Not
Ready quick filters and a late-arrival indicator. `radiology-reception-
dialog.tsx` — the single contextual panel per patient consolidating
verification, the preparation checklist (with live-read instructions),
MRI screening, contrast review, consent, and the final ready/not-ready
decision, showing the server-computed readiness blockers rather than
re-deriving them client-side.

## Audit logging

`radiology_patient_arrived`, `radiology_patient_verified`,
`radiology_order_verified`, `radiology_preparation_started`,
`radiology_preparation_completed`, `radiology_preparation_waived`,
`radiology_safety_screening_recorded`, `radiology_contrast_reviewed`,
`radiology_consent_recorded`, `radiology_patient_marked_ready`,
`radiology_patient_marked_not_ready`.

## Tests

`tests/Feature/Radiology/`: `RadiologyReceptionTest` (arrival, verify's
auto-routing in both directions, every readiness-gate check individually,
not-ready/resume, two procedures on one order progressing independently),
`RadiologyReceptionAuthorizationTest` (guest, doctor fully forbidden,
receptionist forbidden, radiology-technician full workflow, hospital-admin
cross-hospital IDOR, super-admin bypass), `RadiologyReceptionValidationTest`
(invalid/missing not-ready reason, invalid consent status, wrong-status
transitions), `RadiologyReceptionEndToEndTest` (no-preparation procedure
straight to ready, preparation-required procedure through the full
checklist, multi-procedure independence, a wrong-status failure case). 132
Radiology tests passing; full regression suite (811 tests) unchanged at
baseline.

## Remaining issues

None blocking. Explicitly out of scope per this step's own boundary (Part
44): image capture/acquisition for any modality, DICOM/PACS/RIS, image
upload/viewing, findings, radiologist reporting, result verification/
authorization/release, billing, payments, NHIF claims — all remain future
steps (Step 41+).

**STEP 40 COMPLETE — the pathway Scheduled Appointment -> Patient Arrives
-> Identity Verification -> Order Verification -> Procedure Verification ->
Preparation/Screening/Consent where applicable -> READY FOR EXAMINATION
works correctly end to end.**

# Radiology Examination / Imaging (Phase 5, Step 41)

## Existing architecture audit

Inspected the full Steps 36-40 implementation before writing anything.
Findings that shaped every decision below:

- **`RadiologyAppointment` (Step 39) already IS the per-order-item
  examination record's natural home** — Step 40 had already extended the
  exact same row with arrival/verification/preparation/screening/consent
  fields rather than a new entity, explicitly reasoning that this "keeps
  each procedure's own progress independent of every other procedure on
  the same order." That reasoning applies identically one stage further:
  the actual examination (READY -> IN_PROGRESS -> COMPLETED/ABORTED) is
  just the next leg of the same per-item journey. No
  `radiology_examinations` table was created — extending
  `RadiologyAppointment` a second time was the only choice consistent with
  the precedent Steps 39-40 already set.
- **No `Room`/`Equipment` model exists anywhere** (re-confirmed) —
  `resource_name` (Step 39) is reused as-is for the examination's actual
  resource; a technician may correct it at start time, audited as its own
  `radiology_examination_resource_changed` event, rather than a second
  "actual resource" column.
- **`RadiologyOrderItem`/`RadiologyOrder` need no new statuses** — both
  stayed at `ordered`/`scheduled` throughout scheduling and reception
  (Steps 39-40); the examination's own progress lives entirely on the
  appointment, so this step follows the same pattern rather than growing
  either model's status set.
- **Permissions**: reused the `radiology-<workflow>.<ability>` naming
  convention exactly (`radiology-scheduling.*`, `radiology-reception.*`),
  adding `radiology-examination` = `[view, start, update, complete,
  abort]`. Granular per-action abilities (mirroring scheduling's
  create/reschedule/confirm/cancel/no_show split) were chosen over
  reception's single `manage` ability, because start/complete/abort are
  meaningfully distinct, higher-stakes actions — not one continuous
  checklist workflow the way reception's arrival-to-readiness steps are.
- **Policy**: extended the existing `RadiologyAppointmentPolicy` (which
  already grew twice, for scheduling then reception) with
  `viewExaminationWorklist`/`viewExamination`/`start`/`updateExamination`/
  `completeExamination`/`abortExamination`, rather than the spec's own
  illustrative `RadiologyExaminationPolicy` — one appointment, one policy
  class owning its whole lifecycle is the actual established convention
  here, and the spec's own instruction is to follow the project's real
  naming convention over its own illustrative names.
- **Role**: `radiology-technician` (Step 36) already anticipated this
  incremental growth in its own doc-comment; no new role was created.

## Decision: extend RadiologyAppointment, not a new table

`radiology_appointments` gains (all additive, nullable): `performed_by`,
`started_at`, `completed_at`, `completed_by`, `technical_notes`,
`technical_details` (json), `aborted_at`, `aborted_by`, `abort_reason`.

`technical_details` is a single JSON bag rather than one sparse column per
modality-specific field (view/projection, body_part, laterality for X-Ray;
protocol/contrast for CT; coil for MRI; etc.) — mirrors the
`radiology_procedures.body_sites`/`radiology_order_items.body_site_snapshot`
precedent of JSON for catalog-shaped data that varies by row, rather than a
wide table mostly full of nulls. The allowed keys per modality
(`RadiologyAppointment::TECHNICAL_FIELDS_BY_MODALITY`) are enforced
server-side by `RadiologyExaminationService`, never trusted unchecked from
the client — an unrecognized key for the appointment's own modality is
rejected outright, not silently dropped.

## Examination lifecycle

```
READY -> IN_PROGRESS -> COMPLETED
READY -> IN_PROGRESS -> ABORTED
```

`RadiologyAppointment::TRANSITIONS` updated: `READY` now allows
`IN_PROGRESS` (alongside its existing `CANCELLED`); `IN_PROGRESS` allows
only `COMPLETED`/`ABORTED` — never a plain `CANCELLED`, and never back to
`READY`; `COMPLETED`/`ABORTED` are both terminal (no reopening capability
was built this step, per the spec's own "must be separately authorized"
caveat).

`ACTIVE_STATUSES` (the set that blocks a fresh scheduling attempt on the
same item and counts toward double-booking/order-rollup checks) gained
`IN_PROGRESS` and `COMPLETED` — an in-progress or genuinely finished
examination must never be double-scheduled or silently reopened for
re-booking. `ABORTED` was deliberately **excluded** — an aborted
examination's order item must remain freely re-schedulable for a fresh
attempt (Part 14), the same way a `NO_SHOW` appointment already is; this is
proven by test (`an aborted examination leaves its order item freely
re-schedulable, but a completed one does not`).

## Starting an examination

`RadiologyExaminationService::start()`: locks the appointment row inside a
transaction, re-checks `canTransitionTo(IN_PROGRESS)` under that lock (the
same lock-then-check idiom every other Radiology write path already uses),
defensively re-verifies the order/item are not cancelled (structurally
redundant in the ordinary flow — cancelling an order/item already cascades
to cancel any active appointment, so a `READY` appointment cannot belong to
a cancelled order/item — but cheap insurance, and explicitly required by
this step's own spec), sets `performed_by` to the authenticated user
(never client-supplied) and `started_at` to now, and optionally corrects
`resource_name` if staff supply a different one — audited as its own
`radiology_examination_resource_changed` event distinct from
`radiology_examination_started`.

Two technicians racing to start the same appointment: only the first
transaction's `lockForUpdate()` + status re-check wins; the second sees a
status that is no longer `READY` and is rejected with a validation error.
Proven by `RadiologyExaminationConcurrencyTest` (sequential back-to-back
attempts against the same row, the codebase's established way of proving a
serialized invariant without spinning up genuinely parallel PHP processes)
and exercised end-to-end in `RadiologyExaminationEndToEndTest`.

## Technical information

`RadiologyExaminationService::recordTechnicalData()` — only reachable while
`IN_PROGRESS` — validates `technical_notes` (length-bounded free text) and
whitelists `technical_details` keys against the appointment's own modality.
`complete()` accepts the same optional payload so a technician may finalize
technical information in the same action that completes the examination,
but never accepts or finalizes any clinical interpretation — that remains
Step 42's job entirely.

## Completing / aborting

`complete()`: `IN_PROGRESS` only, records `completed_at`/`completed_by`,
audits `radiology_examination_completed`. This is the Step 42 handoff
point — a completed examination is queryable as
`RadiologyAppointment::where('status', 'completed')`, fully traceable back
through `radiologyOrderItem -> radiologyOrder -> clinicalEncounter ->
patient`, with no separate "ready for findings" status needed (`COMPLETED`
already means exactly that).

`abort()`: `IN_PROGRESS` only, requires a reason from a controlled list
(`RadiologyAppointment::ABORT_REASONS` — patient unable to tolerate,
equipment problem, patient condition changed, patient refused, incorrect
preparation, safety concern, technical failure, other), records
`aborted_at`/`aborted_by`/`abort_reason`, audits
`radiology_examination_aborted`. Never hard-deletes; the order item is
never itself cancelled, so it remains freely re-schedulable for a fresh
examination attempt while the original aborted record stays fully
historically traceable.

## Backend architecture

```
routes/web.php
  -> RadiologyExaminationController   (index/show/start/update/complete/
       |                                abort — thin, all logic in the
       |                                service)
       v
  Form Requests (Start/Update/Complete/AbortRadiologyExaminationRequest)
       |
       v
  RadiologyAppointmentPolicy (extended: viewExaminationWorklist/
  viewExamination/start/updateExamination/completeExamination/
  abortExamination)
       |
       v
  RadiologyExaminationService / RadiologySchedulingSearchService
  (extended: examinationWorklistQuery())
       |
       v
  RadiologyAppointment (Eloquent, extended a second time)
       |
       v
  MySQL: radiology_appointments (additive migration only)
```

Routes (`routes/web.php`, Inertia only, no new API routes):

```
GET  radiology/examinations                                 radiology-examinations.index
GET  radiology/examinations/{radiologyAppointment}           radiology-examinations.show
POST radiology/examinations/{radiologyAppointment}/start     radiology-examinations.start
PUT  radiology/examinations/{radiologyAppointment}           radiology-examinations.update
POST radiology/examinations/{radiologyAppointment}/complete  radiology-examinations.complete
POST radiology/examinations/{radiologyAppointment}/abort     radiology-examinations.abort
```

No destroy route — an aborted examination is never deleted, only
transitioned.

## Frontend

`resources/js/pages/radiology/examinations/index.tsx` — the worklist,
defaulting to READY/IN_PROGRESS with an explicit status filter to widen to
COMPLETED/ABORTED history; STAT/URGENT always sorted first server-side.
`resources/js/pages/radiology/examinations/show.tsx` — the detail page:
patient/encounter/order/procedure context, a read-only preparation/
readiness recap (Step 40's own fields, read live, never re-entered here),
the examination itself (performer, started/completed timestamps, technical
information), and a timeline. `radiology-examination-start-dialog.tsx`
displays the Part 18 patient-safety panel (name, patient number, DOB, sex,
procedure, order number) before confirming start — the backend
independently re-validates every relationship regardless of what this
dialog shows. `radiology-examination-technical-form.tsx` renders only the
fields recognized for the appointment's own modality (server-supplied
`technicalFields`, never hard-coded twice). `radiology-examination-
complete-dialog.tsx` / `radiology-examination-abort-dialog.tsx` are
confirmation dialogs; abort always requires a reason from the controlled
list. The Radiology dashboard gained an "Examinations" quick link gated on
`radiology-examination.view`.

## Audit events

```
radiology_examination_started
radiology_examination_completed
radiology_examination_aborted
radiology_examination_technical_data_recorded
radiology_examination_resource_changed
```

## Security

- **Authorization**: every mutating action re-checked server-side via
  `RadiologyAppointmentPolicy`; a doctor/receptionist/permission-less user
  is forbidden from every examination action (proven by
  `RadiologyExaminationAuthorizationTest`).
- **Facility isolation / IDOR**: a hospital-admin scoped to hospital A
  cannot view or start hospital B's examination
  (`belongsToUsersHospital()`, the same mechanism every other Radiology
  policy already uses).
- **Concurrency**: row-locked transaction + status re-check prevents two
  technicians from starting the same appointment
  (`RadiologyExaminationConcurrencyTest`).
- **No client-controlled performer/facility**: `performed_by` is always
  `$request->user()->id`; there is no `patient_id`/`order_id`/
  `facility_id` input path anywhere in `RadiologyExaminationService` — every
  relationship is reached transitively through the appointment itself.
- **No hard deletion**: aborted examinations remain fully queryable and
  historically traceable; no destroy route exists.

## Testing

`tests/Feature/Radiology/`: `RadiologyExaminationTest` (core service
behavior — start/complete/abort, resource correction, technical data
whitelist validation, invalid-transition guards, aborted-vs-completed
re-schedulability), `RadiologyExaminationValidationTest` (missing/invalid
abort reason, technical field length/modality-key validation, invalid
transitions), `RadiologyExaminationAuthorizationTest` (guest, doctor,
receptionist, permission-less user, radiology-technician, hospital-admin
cross-hospital IDOR, super-admin bypass), `RadiologyExaminationConcurrencyTest`
(two and three technicians racing to start the same appointment — exactly
one winner), `RadiologyExaminationWorklistTest` (visibility/filtering,
default READY/IN_PROGRESS view vs. explicit COMPLETED/ABORTED filter),
`RadiologyExaminationEndToEndTest` (the complete Part 29 pathway: doctor
order -> schedule -> reception -> READY -> worklist -> start -> technical
data -> complete -> COMPLETED/queryable-for-Step-42, plus the
ready-start-abort branch and the two-technicians-race branch). Full
Phase 1-5 regression suite re-run afterward.

## Remaining issues / Step 42 boundary

None blocking. Explicitly out of scope per this step's own boundary (Part
33): radiologist findings, clinical interpretation, final report,
verification, authorization, result release, PACS/DICOM/RIS, billing,
NHIF claims. A completed examination
(`RadiologyAppointment::where('status', 'completed')`, joined through to
its order item/order/encounter/patient) is exactly the structured
handoff Step 42 (Radiology Findings & Results) will build on.

**STEP 41 COMPLETE — the pathway Radiology Appointment (READY) ->
Examination Worklist -> Start Examination -> Technical Information ->
Complete Examination -> COMPLETED (ready for findings) works correctly end
to end, alongside the READY -> Start -> Abort branch and the
two-technicians-race protection.**

# Radiology Findings & Results (Phase 5, Step 42)

## Existing architecture audit

Searched for `radiology_results|radiology_findings|radiology_reports|
imaging_results|imaging_reports|radiology_exam_results|diagnostic_results`
and inspected the full Laboratory result/report architecture (Steps 33-35)
in detail before writing anything. Findings:

- **No radiology result/report entity exists anywhere** — Steps 36-41 built
  the order/catalog/scheduling/reception/examination pipeline but
  deliberately stopped at `COMPLETED`, exactly as documented in each of
  those steps' own scope boundaries.
- **`LaboratoryResult` is the closer precedent, not `LaboratoryReport`** —
  `LaboratoryResult` carries its own DRAFT-onward lifecycle directly on one
  row per test (exactly the shape this step's own spec asks for:
  `status`/`findings`-equivalent/`impression`-equivalent fields together on
  one entity). `LaboratoryReport` is a *different* granularity: an
  order-level, versioned *document snapshot*, generated only once results
  are RELEASED (Step 35) — a concept that doesn't exist yet for Radiology
  and isn't needed until a future release step does. Building a
  `LaboratoryReport`-style version table now, before any
  verification/release step exists to make versioning meaningful, would be
  exactly the kind of premature architecture Part 2's "create new
  structures only when genuinely required" warns against.
- **No `radiologist` practitioner type or role existed** — only
  `radiology-technician` (Step 36, operational/technical work). Interpreting
  a completed examination is a distinct clinical function from performing
  it, mirroring Laboratory's own `laboratory-technician` vs
  `laboratory-supervisor` separation.
- **The existing `AuditLogService`/`AuditLog` table already answers Part
  18's "who changed this, when, what"** for the draft-editing phase — no
  second versioning system was built (see the migration's own doc-comment
  for the full reasoning).

## Decision: one new entity, `RadiologyReport`, keyed to the examination

`radiology_reports` — one row per `RadiologyAppointment` (unique
`radiology_appointment_id`), never a duplicated `radiology_order_id`/
`radiology_order_item_id`/`patient_id`/`encounter_id`/`facility_id` — every
one of those is reached transitively through the appointment's own chain,
exactly the "no duplicated identity columns" convention every Radiology
table since Step 39 already follows. **`clinical_indication` is
deliberately NOT a column here** (Part 11) — the report always reads the
order's own live `clinical_indication`; there is no input path anywhere in
`RadiologyReportService`/its Form Requests that could change it, so
correcting it can only ever go through the existing order-amendment
workflow, never silently through a report edit.

Fields: `reporting_practitioner_id` (Practitioner FK, always resolved
server-side from the authenticated user's own practitioner profile — never
client-supplied, Part 14), `report_number` (business number,
`GeneratesBusinessNumber`, prefix `RAD-RPT`), `status`, `technique`,
`findings`, `impression`, `recommendations` (all plain `text`, not
rich/HTML — Part 22's own "plain text is preferable unless rich formatting
is genuinely required," which sidesteps the sanitization/stored-XSS surface
entirely rather than building and then defending a rich-text editor),
`updated_by`/`submitted_by`/`submitted_at`.

## Reporting lifecycle

```
DRAFT -> SUBMITTED
```

`RadiologyReport::TRANSITIONS` also declares `SUBMITTED -> RETURNED` and
`RETURNED -> DRAFT` (Part 17's explicit "make the architecture ready for
it") — `STATUS_RETURNED` is a real constant and a real transition target,
but no code path anywhere in this step ever produces or consumes it; it
exists purely so Step 43's own verifier "return for correction" action can
call `$report->canTransitionTo(RETURNED)` without needing to touch this
model again. No `returned_by`/`returned_at`/`return_reason` columns were
added yet — those belong to the migration Step 43 actually needs them in,
mirroring how Step 40 added its own new columns via a fresh additive
migration rather than Step 39 pre-guessing them.

## Creating and editing a draft

`RadiologyReportService::createDraft()`: locks the appointment row inside a
transaction, verifies it is `COMPLETED` (Part 6 — `SCHEDULED`/`ARRIVED`/
`READY`/`IN_PROGRESS`/`ABORTED`/`CANCELLED` are all rejected, proven by
test for every one of those statuses), verifies no report already exists
for it (a unique DB index is the backstop, the lock-then-check is the
concurrency guard — two radiologists racing to start a report on the same
examination: only the first succeeds, proven by
`RadiologyReportConcurrencyTest`), resolves `reporting_practitioner_id`
from `$request->user()->staff?->practitioner?->id` (rejecting outright if
the acting user has no practitioner profile — a hospital-admin exercising
this ability still needs a genuine clinical identity of their own; the
*permission* to act and the *domain requirement* of a real clinical author
are deliberately two separate checks).

`updateDraft()`: only reachable while `DRAFT`; re-validates length limits;
audits `radiology_report_updated` with the full old/new field values (this
step's own "history" mechanism, per Part 18's own reasoning above).

## Submitting

`submit()`: re-locks the report row, re-checks `canTransitionTo(SUBMITTED)`,
requires both `findings` and `impression` to be present (Part 13/27's own
"at minimum: findings, impression... if mandatory by hospital policy" —
baked in as this step's default policy), re-verifies the underlying
examination is still `COMPLETED` (defense-in-depth, mirroring
`RadiologyExaminationService`'s own re-verification pattern), then sets
`submitted_by`/`submitted_at` and audits `radiology_report_submitted`. Once
`SUBMITTED`, `updateDraft()`/`submit()` both reject any further call (Part
17 — never freely editable once submitted).

## Permissions & roles

New permission group `radiology-results` = `[view, create, update,
submit]` — matches this app's own established 1:1 convention between a
permission group's name and its route prefix (`radiology/results`),
exactly like `radiology-scheduling`/`radiology-reception`/
`radiology-examination` already do. New role `radiologist` (practitioner
type `radiologist` also added to `Practitioner::TYPES`/
`CLINICAL_JOB_TITLES`) holds the full set; `hospital-admin` gains it too.
`radiology-technician` and `doctor` do **not** — ordering, performing, and
interpreting are three deliberately separate responsibilities (mirrors
Laboratory's own `doctor` / `laboratory-technician` / `laboratory-supervisor`
three-way split).

## Policy

`RadiologyReportPolicy` (new, mirrors `RadiologyAppointmentPolicy`'s
shape): `viewAny`/`view` are open to any hospital-scoped permission holder
(no ownership restriction — any radiologist may need to read a colleague's
report for handoffs, mirroring `RadiologyOrderPolicy::view()`'s identical
reasoning); `create` checks hospital scope against the target appointment
only (no report exists yet to own); `update`/`submit` additionally require
`ownsReport()` — only the report's own `reporting_practitioner`, or an
unrestricted `hospital-admin`, may modify or submit it (mirrors
`RadiologyOrderPolicy::ownsOrSupervises()`'s identical "only the owner or
an admin" shape). Proven by test: a second radiologist can view but not
edit or submit a colleague's draft.

## Backend architecture

```
routes/web.php
  -> RadiologyReportController   (index/create/store/show/update/submit/
       |                          history — thin, all logic in the service)
       v
  Form Requests (Create/Update/SubmitRadiologyReportRequest)
       |
       v
  RadiologyReportPolicy
       |
       v
  RadiologyReportService / RadiologyReportSearchService
       |
       v
  RadiologyReport (Eloquent, new) -> RadiologyAppointment (existing chain)
       |
       v
  MySQL: radiology_reports (new table, additive only elsewhere)
```

Routes (`routes/web.php`, Inertia only, no new API routes):

```
GET  radiology/results                          radiology-results.index
GET  radiology/results/create/{radiologyAppointment}  radiology-results.create
POST radiology/results                           radiology-results.store
GET  radiology/results/{radiologyReport}         radiology-results.show
PUT  radiology/results/{radiologyReport}         radiology-results.update
POST radiology/results/{radiologyReport}/submit  radiology-results.submit
GET  radiology/results/{radiologyReport}/history radiology-results.history
```

`create` is a deliberate GET-only preview (patient/order/procedure/
examination summary, no mutation) distinct from `store` (the actual draft
creation) — the same patient-safety-style confirmation pattern
`RadiologyExaminationStartDialog` already established in Step 41. No
destroy route — a report is never deleted, only transitioned.

## Frontend

`radiology/results/index.tsx` — the reporting worklist: every `COMPLETED`
examination, whether or not it has a report yet, with an explicit
Not-Started/Draft/Submitted status column; STAT/URGENT always sorted first
server-side. `radiology/results/create.tsx` — the read-only start-report
preview. `radiology/results/show.tsx` — the full report page (Part 21):
patient/encounter/order/procedure context, `RadiologyExaminationSummary`
(read-only review of Step 41's own technical information — never
duplicated onto the report), the clinical indication read live from the
order, and either the editable `RadiologyReportForm` (only when
`canUpdate` — computed server-side from the policy — and still `DRAFT`) or
a read-only display once submitted. `radiology-submit-report-dialog.tsx`
is a confirmation dialog. `radiology/results/history.tsx` renders the
report's own audit trail. Every findings/impression/technique/
recommendations field is a plain `<Textarea>` bound via controlled
`value=`, and the read-only view interpolates the same strings as plain
text nodes — no component anywhere in the reporting UI uses
`dangerouslySetInnerHTML`, proven by test (`RadiologyReportSecurityTest`).
The Radiology dashboard gained a "Results" quick link gated on
`radiology-results.view`.

## Audit events

```
radiology_report_created
radiology_report_updated
radiology_report_submitted
```

(`radiology_report_returned` is not emitted by any code this step — it
belongs to Step 43's own verifier action.)

## Security

- **Authorization**: every mutating action re-checked server-side via
  `RadiologyReportPolicy`; a doctor/radiology-technician/permission-less
  user is forbidden from every reporting action.
- **Facility isolation / IDOR**: a hospital-admin scoped to hospital A
  cannot create a report against, or view, hospital B's examination/report;
  changing a report id in the URL to another hospital's report returns 403.
- **Concurrency**: row-locked transaction + unique-index backstop prevents
  two radiologists from both creating a report for the same examination;
  sequential draft edits and submit attempts are proven not to corrupt or
  double-submit.
- **XSS**: findings/impression/technique/recommendations are plain text
  end-to-end — stored verbatim (proven by test with a `<script>`/`onerror`
  payload), never rendered as HTML anywhere in the reporting UI.
- **Mass assignment**: `status`, `reporting_practitioner_id`, and
  `clinical_indication` cannot be set by the client through create/update —
  proven by test that submitting them in the request body has no effect.
- **Audit logging**: every create/update/submit call records the acting
  user, old/new values, and timestamp via the existing `AuditLogService`,
  and doubles as this step's report-history mechanism (Part 18).

## Testing

`tests/Feature/Radiology/`: `RadiologyReportTest` (core service behavior —
draft creation/practitioner resolution, duplicate-report rejection, no-
practitioner-profile rejection, draft editing, required-findings/impression
submit gate, submit locking, traceability), `RadiologyReportValidationTest`
(every non-COMPLETED examination status rejected, non-existent appointment
id, split findings/impression validation errors, clinical indication
immutability), `RadiologyReportAuthorizationTest` (guest, doctor,
radiology-technician, permission-less user, owning vs. non-owning
radiologist, hospital-admin cross-hospital IDOR, super-admin bypass),
`RadiologyReportConcurrencyTest` (two and three radiologists racing to
create a report; sequential draft edits; double-submit prevention),
`RadiologyReportWorklistTest` (not-started/draft/submitted visibility,
`report_status=none` filter, non-completed exclusion, modality/priority
filters), `RadiologyReportSecurityTest` (XSS-payload-stored-verbatim,
status/practitioner mass-assignment protection, source-level
`dangerouslySetInnerHTML` absence check), `RadiologyReportEndToEndTest`
(the complete Part 35 pathway: order -> schedule -> reception -> exam
start/complete -> worklist -> create preview -> draft -> save -> reopen ->
update -> submit -> SUBMITTED/queryable-for-Step-43, plus the
aborted-examination-cannot-be-reported branch). 43 new Radiology Report
tests passing; full Phase 1-5 regression suite re-run afterward.

## Remaining issues / Step 43 boundary

None blocking. Explicitly out of scope per this step's own boundary (Part
38): report verification, authorization, electronic final approval, result
release, doctor/patient notification, PACS/DICOM/RIS, billing, NHIF claims.
A submitted report (`RadiologyReport::where('status', 'submitted')`, joined
through to its examination/order item/order/encounter/patient) is exactly
the structured handoff Step 43 (Radiology Verification & Reporting) will
build on; `STATUS_RETURNED` and its transitions are already in place for
that step to use without a further model change.

**STEP 42 COMPLETE — the pathway Completed Examination -> Reporting
Worklist -> Start Report -> Technique/Findings/Impression -> Save Draft ->
Reopen -> Update -> Submit -> SUBMITTED (ready for verification) works
correctly end to end, alongside the two-radiologists-race protection and
the aborted-examination-cannot-be-reported guard.**

# Radiology Report Verification & Authorization (Phase 5, Step 43)

## Existing architecture audited

Searched for `radiology_verifications`/`radiology_authorizations`/
`radiology_report_reviews`/`result_verifications`/
`clinical_result_verifications` — none exist. `RadiologyReport` (Step 42)
already declares `STATUS_RETURNED` and a `SUBMITTED -> RETURNED -> DRAFT`
transition, unreachable through any Step 42 code path, explicitly left for
this step to build on.

Laboratory's own verification workflow (`LaboratoryResultService`, Phase 4
Steps 33-35) was inspected in full: `startReview()`/`verifyResult()`/
`returnForCorrection()`/`authorizeResult()`, the `entered_by`-vs-reviewer
role-separation rule (`LaboratoryResultPolicy::isNotTheEnteringUser()`), and
the `LaboratoryResult\ReturnForCorrectionRequest`'s reason-enum +
comments-required-only-for-"other" pattern. These patterns were reused
directly rather than re-invented.

## Decision: extend `RadiologyReport`, no new entity

Per Part 16's own "do NOT automatically create this table" instruction: a
report is a single mutable row, and its full change history (who wrote it,
who changed it, who returned it, who verified it, and when) is already
fully answerable by the existing `AuditLogService` trail, the same way
Laboratory's own draft-editing phase (Steps 33-34) relied on the audit log
alone before a dedicated version-snapshot table (`LaboratoryReport`) was
introduced at *release* (Step 35) — a materially different need (freezing a
printable document) that Step 43 does not have. So no
`radiology_report_verifications` table was created; `radiology_reports`
gained columns instead.

No persisted `UNDER_REVIEW` status was introduced either (Part 9's explicit
"do not create it unnecessarily"). Conflicting concurrent verification is
prevented purely by row-locking plus the status check inside
`verify()`/`returnForCorrection()` — exactly the same guard every other
Radiology service in this app already uses for its own race conditions
(scheduling double-booking, two-radiologists-racing-to-report, etc.). A
"review started" audit event is still recorded (satisfying Part 22's
`RADIOLOGY_REPORT_REVIEW_STARTED` requirement) — it just never gates
anything by itself.

`RadiologyVerificationPolicy` was not created as a separate class; the new
`review`/`verify`/`returnForCorrection` abilities were added directly to the
existing `RadiologyReportPolicy` (one policy per model is this app's
established convention — `LaboratoryResultPolicy` alone covers its entire
create-through-release lifecycle the same way).

## Database

One additive migration on `radiology_reports`:

```
radiology_reports
  + verifying_practitioner_id (FK -> practitioners, nullable, restrictOnDelete)
      mirrors reporting_practitioner_id exactly — a Practitioner, not a
      User, so the self-verification rule compares like with like.
  + verified_by (FK -> users, nullable, restrictOnDelete)
  + verified_at (nullable timestamp)
  + verification_notes (nullable text — administrative only, never clinical content)
  + returned_by (FK -> users, nullable, restrictOnDelete)
  + returned_at (nullable timestamp)
  + return_reason (nullable string, enum-validated)
  + return_comments (nullable text)
```

`return_reason`/`return_comments`/`returned_by`/`returned_at` are never
cleared once set, even after the report is corrected and resubmitted (Part
12's "must clearly show Returned/Returned By/Returned At/Return Reason"
remains true as history, not just while the status is still RETURNED).

## Model: `RadiologyReport`

Final implemented state machine:

```
DRAFT -> SUBMITTED -> VERIFIED               (terminal, immutable)
SUBMITTED -> RETURNED -> DRAFT -> SUBMITTED  (repeatable correction cycle)
```

```php
TRANSITIONS = [
    DRAFT     => [SUBMITTED],
    SUBMITTED => [VERIFIED, RETURNED],
    RETURNED  => [DRAFT],
    VERIFIED  => [],
];
```

New `RETURN_REASONS` constant (Part 11): `incomplete_findings`,
`unclear_impression`, `incorrect_technique`, `missing_recommendation`,
`clinical_inconsistency`, `wrong_procedure_information`,
`typographical_error`, `additional_review_required`, `other`. New relations:
`verifyingPractitioner()`, `verifiedBy()`, `returnedBy()`.

## Service: `RadiologyReportService` (extended, not duplicated)

- `updateDraft()` now accepts `RETURNED` as well as `DRAFT` (mirroring
  `LaboratoryResultService::saveDraft()`'s identical `editableFrom` shape) —
  correcting a returned report normalizes its status back to `DRAFT`,
  auditing the `RETURNED -> DRAFT` transition distinctly.
- `submit()` additionally fires `radiology_report_resubmitted` (on top of
  the existing `radiology_report_submitted`) whenever `returned_at` is
  already set — a resubmission, never a new report.
- `recordReviewStarted()` — audit-only, fires
  `radiology_report_review_started` only while the report is still
  `SUBMITTED` (re-opening an already-decided report for reference does not
  spam the log).
- `verify()` — locks the report row, re-checks `SUBMITTED`, re-verifies the
  underlying examination is still `COMPLETED` (defense in depth), enforces
  the self-verification rule server-side a second time (the policy already
  checked it — this is not a single point of failure), then sets `VERIFIED`
  + `verifying_practitioner_id`/`verified_by`/`verified_at`/
  `verification_notes`.
- `returnForCorrection()` — locks the row, re-checks `SUBMITTED`, enforces
  the same self-verification rule, records `return_reason`/`return_comments`/
  `returned_by`/`returned_at`. The report's clinical content is never
  touched here.

## Self-verification protection (Part 5)

```
reporting_practitioner_id != verifying_practitioner_id
```

enforced in two independent places — `RadiologyReportPolicy::
independentOfAuthor()` (so the Verify/Return buttons never even render for
the report's own author) and again inside
`RadiologyReportService::verify()`/`returnForCorrection()` (so the rule
holds even if a request reaches the service directly). Both read a new
`config('radiology.allow_self_verification')` flag
(`config/radiology.php`, `RADIOLOGY_ALLOW_SELF_VERIFICATION` env var,
defaults to `false`) rather than hard-coding independence — Part 5's own
explicit "make that behavior configurable" instruction.

## Permissions & policy

Extended the existing `radiology-results` permission group (Part 17's "do
not create redundant permissions") rather than a new `radiology-verification`
namespace:

```
radiology-results: view, create, update, submit, review, verify, return
```

Granted to `hospital-admin` and `radiologist` (the same clinical-reporting
role from Step 42 — any radiologist may verify a colleague's report, just
never their own). Deliberately **not** granted to `radiology-technician`
(Part 4's clinical separation — a technician has zero `radiology-results.*`
permissions at all, so this holds structurally, not just by convention).

`RadiologyReportPolicy` gained `review()`/`verify()`/`returnForCorrection()`,
each: permission check -> hospital scope (`belongsToUsersHospital()`,
reused as-is) -> `independentOfAuthor()`.

## Routes (web + Inertia only)

```
GET  radiology/verification                       radiology-verification.index
GET  radiology/verification/{radiologyReport}      radiology-verification.review
POST radiology/verification/{radiologyReport}/verify   radiology-verification.verify
POST radiology/verification/{radiologyReport}/return   radiology-verification.return
```

No history route — `radiology-results.history` (Step 42) is reused as-is;
every role holding verification permissions already has `radiology-results.view`.

## Form Requests

`VerifyRadiologyReportRequest` (`verification_notes` nullable, max 1000),
`ReturnRadiologyReportRequest` (`return_reason` required enum,
`return_comments` required only when `return_reason === 'other'`, mirroring
`LaboratoryResult\ReturnForCorrectionRequest` exactly). Neither accepts a
client-supplied `verifying_practitioner_id`/`facility_id`/`patient_id` —
proven by a dedicated test.

## Frontend

`radiology/verification/index.tsx` — worklist defaulting to the actionable
`SUBMITTED` queue (priority-first, then submission time — never a
user-toggleable sort), with a status filter to browse VERIFIED/RETURNED
history. `radiology/verification/show.tsx` — full read-only patient/
encounter/order/procedure/examination/report context (reuses
`RadiologyExaminationSummary` from Step 42 as-is), a Returned-history panel
and a Verified panel shown conditionally, and `RadiologyVerifyDialog`/
`RadiologyReturnDialog` (both require explicit confirmation, mirroring
Laboratory's `VerifyResultDialog`/`ReturnForCorrectionDialog`). A
"Verification" quick link was added to the Radiology dashboard, gated on
`radiology-results.review`.

## Audit events

`radiology_report_review_started`, `radiology_report_verified`,
`radiology_report_returned`, `radiology_report_resubmitted`,
`radiology_verification_note_added` (fired only when verification_notes is
non-empty), plus the existing `radiology_report_updated`/
`radiology_report_status_changed` events for the return-correct cycle. No
credentials, tokens, or unnecessary clinical content logged.

## Security

- Facility/hospital isolation: `belongsToUsersHospital()` (reused) plus a
  dedicated `scopeReportsToHospital()` on the worklist query.
- IDOR: a hospital-admin cannot open, verify, or return another hospital's
  report by changing the id in the URL, and the worklist itself never lists
  it — proven by `RadiologyVerificationIdorTest`.
- Self-verification: blocked at both policy and service layers, proven with
  and without the `allow_self_verification` override.
- Concurrency: two verifiers racing to verify the same report — one
  succeeds, the second gets a `ValidationException`; verifying while another
  attempts to return is likewise serialized to exactly one winner.
- Status manipulation: verifying/returning a DRAFT, or acting twice on an
  already-VERIFIED report, is rejected server-side regardless of what the
  UI would show.

## Tests

`RadiologyVerificationTest` (8, service-level: verify, self-verification
block + config override, return, the full return-correct-resubmit-verify
cycle, verified-report immutability, review-started audit gating),
`RadiologyVerificationAuthorizationTest` (8: guest/doctor/technician
forbidden, author-cannot-verify-or-return-own-report, independent
radiologist succeeds, permission-less user forbidden, hospital-admin
scoped, super-admin bypass), `RadiologyVerificationIdorTest` (3),
`RadiologyVerificationValidationTest` (9), `RadiologyVerificationConcurrencyTest`
(3), `RadiologyVerificationEndToEndTest` (2, including the full Part 34
scenario with a return-correct-resubmit cycle). All 33 pass.

Regression: all pre-existing Radiology tests (Steps 36-42, 212 tests across
33 files) re-run and pass unchanged, including `RadiologyReportTest`'s own
`TRANSITIONS` assertion (updated for the new VERIFIED/RETURNED shape — the
only pre-existing test that needed a change, since it asserted the old,
narrower transition table by value). `tests/Feature/OpdWorkflow` (10 tests)
also re-run and pass; no Laboratory/Patient/ClinicalEncounter files were
touched by this step, so a full run of those suites was not repeated beyond
this cross-module smoke check.

## Remaining issues

None blocking. Explicitly out of scope per this step's own boundary (Part
37): final result release, patient portal release, SMS/email delivery,
billing, payment, NHIF claims, PACS/DICOM, advanced image viewer,
admission/discharge, and any post-verification amendment workflow (the
architecture is left ready for one, per Part 15, but none is built). A
`VERIFIED` report (`RadiologyReport::where('status', 'verified')`) is
exactly the structured handoff Step 44 (Radiology Result Release) will
build on.

**STEP 43 COMPLETE — the pathway Submitted Report -> Verification Worklist
-> Review -> Verify (or Return -> Correct -> Resubmit -> Review again ->
Verify) -> VERIFIED works correctly end to end, alongside self-verification
protection, facility isolation, IDOR protection, and concurrent-verification
protection.**

# Radiology Result Release (Phase 5, Step 44)

## Existing architecture audited

Searched for `radiology_releases`/`result_release`/`result_releases`/
`clinical_results`/`diagnostic_results`/`laboratory_releases`/
`released_results` — none exist beyond `laboratory_results` itself, which
already implements exactly this pattern for Laboratory (Phase 4, Step 35):
`released_by`/`released_at`/`release_comment` columns living directly on
the same result row, no separate release table, `STATUS_RELEASED` as a
terminal state reachable only from `AUTHORIZED`. That precedent — plus
`ClinicalHistoryEncounterResource::releasedLaboratoryResults()` (filters to
`STATUS_RELEASED` only when surfacing results on the patient's timeline)
and `ClinicalEncounterController::show()`'s existing `laboratoryOrders`
eager-load pattern — was reused directly rather than re-invented.

One real gap was found: **radiology results were not shown on the clinical
encounter page at all** — `radiologyOrders` there only ever carried plain
order/item status tracking (Step 38), never examination or report content.
This step adds that missing integration.

## Decision: extend `RadiologyReport` again, no new entity

Per Part 13's own "do not store unnecessary duplicate report content"
instruction and mirroring the Step 43 migration's reasoning: release
metadata lives directly on the same `radiology_reports` row
(`released_by`/`released_at`/`release_notes`), not a separate release/
history table. Radiology has no `AUTHORIZED` intermediate status (unlike
Laboratory's), and Part 3 explicitly says not to invent one — so the
implemented transition is the minimum valid one: `VERIFIED -> RELEASED`
(terminal).

## Database

One additive migration on `radiology_reports`:

```
radiology_reports
  + released_by (FK -> users, nullable, restrictOnDelete)
  + released_at (nullable timestamp)
  + release_notes (nullable text)
```

## Model: `RadiologyReport`

```
VERIFIED -> RELEASED   (terminal)
```

```php
TRANSITIONS = [
    ...
    VERIFIED  => [RELEASED],
    RELEASED  => [],
];
```

New `releasedBy()` relation.

## Service: `RadiologyReportService::release()` (extended, not a new class)

Continues this Radiology module's own established pattern (all of Steps
42-44's create/update/submit/review/verify/return/release logic lives in
one `RadiologyReportService`) rather than the spec's own suggested
`RadiologyResultReleaseService` name — mirroring Laboratory's own
`LaboratoryResultService::release()`, which likewise lives in the same
single service that owns that entity's entire lifecycle, not a separate
release-only class.

`release()`: locks the report row, re-checks `canTransitionTo(RELEASED)`
(only from `VERIFIED`), re-verifies the underlying examination is still
`COMPLETED` (defense in depth, mirroring `submit()`/`verify()`'s own
re-verification pattern), then sets `RELEASED` + `released_by`/
`released_at`/`release_notes`. Report content (`technique`/`findings`/
`impression`/`recommendations`) is never touched — release changes
workflow state, not clinical interpretation (Part 34/38).

## Policy: `RadiologyReportPolicy::release()` (extended, not a new class)

Permission + hospital scope only — no independence-from-author/verifier
restriction (Part 9-11 impose none here, unlike Step 43's self-verification
rule). A reporting practitioner or the report's own verifier may release
it; only permission and facility scope gate the action.

## Controller: `RadiologyResultReleaseController` (new)

Kept as its own controller — continuing this module's own established
convention (a dedicated controller per distinct worklist stage since Step
39) rather than folding release into `RadiologyVerificationController` or
`RadiologyReportController`, even though Laboratory's own precedent keeps
verify/authorize/release together on one controller. `index()`/`show()`/
`release()`, thin, delegating every rule to
`RadiologyReportPolicy`/`RadiologyReportService`.

## Permissions & policy

Extended the existing `radiology-results` permission group (Part 9's own
"reuse existing permission naming conventions") rather than a new
`radiology-release` or `radiology.release.*` namespace:

```
radiology-results: view, create, update, submit, review, verify, return, release
```

Granted to `hospital-admin` and `radiologist` — the same role already
verifying reports, since no separate "senior radiologist" role exists in
this app's Spatie configuration and none was invented for this step (Part
9's own "do not hard-code role names if the existing permission
architecture already controls this").

## Routes (web + Inertia only)

```
GET  radiology/release                       radiology-release.index
GET  radiology/release/{radiologyReport}     radiology-release.show
POST radiology/release/{radiologyReport}     radiology-release.release
```

No history route — `radiology-results.history` (Step 42) is reused as-is.

## Form Request

`ReleaseRadiologyResultRequest` — `release_notes` nullable, max 1000. Never
accepts `released_by`/`facility_id`/`patient_id`/`status` — proven by a
dedicated test.

## Clinical encounter & patient history integration (Part 15-18)

New `RadiologyResultResource` — a lightweight, purpose-built resource
(distinct from `RadiologyReportResource`, which re-derives the full
patient/encounter/order chain upward from a report for the standalone
report/verification/release pages) for the two places where the caller
already has patient/encounter/order/item in scope, walking *down* to the
report instead:

- `ClinicalEncounterController::show()` — a new `radiologyResults` prop,
  computed by walking `radiologyOrders.items.radiologyAppointments.report`
  and filtering to `RadiologyReport::STATUS_RELEASED` only. Rendered by a
  new `RadiologyResultsSection` component on the encounter page, right
  after the existing `RadiologyOrdersSection` (which is unchanged and
  keeps showing plain order/item status tracking for every status,
  regardless of release).
- `ClinicalHistoryService::timeline()` — extended eager-loads mirroring
  Laboratory's own; `ClinicalHistoryEncounterResource` gained a new
  `radiology_results` field via `releasedRadiologyResults()`, mirroring
  `releasedLaboratoryResults()`'s exact filter-then-map shape (reusing
  `RadiologyResultResource` rather than hand-rolling the array twice). The
  existing `radiology_orders` field is untouched; the patient
  clinical-history page's "Radiology" heading was renamed "Radiology
  Orders" and a new "Radiology Results" list (mirroring "Laboratory
  Results") was added above it.

The visibility boundary (Part 18) is enforced entirely server-side by this
filter — a VERIFIED-but-not-yet-released report is structurally excluded
from both props; there is no client-side hiding to bypass.

## Audit events

`radiology_result_released`, recording facility/patient/encounter/order/
order-item/examination/report/released_by/timestamp via the existing
`AuditLogService` — no report content logged. `RADIOLOGY_RESULT_VIEWED` was
deliberately **not** added: Laboratory's own release step (Step 35) never
added a per-result "viewed" audit event either (only the broader,
already-existing `clinical_history_viewed` at the whole-timeline level), so
per Part 21's own "only add VIEWED if the existing architecture already
tracks it," none was introduced here.

## Notifications

No result-released notification integration was built. This app has no
existing notification-delivery architecture to integrate with (no queued
jobs, mailers, or in-app notification table were found anywhere in the
codebase) — per Part 20's own "however, do not create a new notification
system," none was invented. The doctor's own encounter page and patient
history already surface the released result automatically (Part 15-16),
which is this step's actual delivery mechanism.

## Security

- Facility/hospital isolation: `belongsToUsersHospital()` (reused) plus
  `scopeReportsToHospital()` (reused from Step 43) applied to the release
  worklist query.
- IDOR: a hospital-admin cannot view or release another hospital's result
  by changing the id in the URL, and the release worklist itself never
  lists it — proven by `RadiologyReleaseIdorTest`.
- Concurrency: two users racing to release the same result — one succeeds,
  the second gets a `ValidationException` naming the current status.
- Result immutability: `updateDraft()`'s `editableFrom` list never includes
  `RELEASED`, so the normal report-edit route is blocked server-side
  regardless of what the UI shows.
- Release protection: only `VERIFIED` reports satisfy
  `canTransitionTo(RELEASED)` — DRAFT/SUBMITTED/RETURNED are all rejected,
  and an ABORTED examination never even produces a report to begin with
  (Step 42's own eligibility gate).
- Clinical privacy: released-result visibility on the encounter/history
  pages is gated the same way every other clinical section on those same
  pages already is (the encounter's own `view` policy, the patient's
  `viewClinicalHistory` policy) — no separate, weaker access path was
  introduced.

## Tests

`RadiologyReleaseTest` (6, service-level: release, metadata, re-release
protection, content preservation, immutability, transitions),
`RadiologyReleaseValidationTest` (7: DRAFT/SUBMITTED/RETURNED/aborted
cannot release, release_notes length, re-release via HTTP, client-supplied
identity fields ignored), `RadiologyReleaseAuthorizationTest` (8: guest/
doctor/technician forbidden, permission-less forbidden, authorized
radiologist succeeds, no independence restriction, hospital-admin scoped,
super-admin bypass), `RadiologyReleaseIdorTest` (2),
`RadiologyReleaseConcurrencyTest` (2), `RadiologyReleaseVisibilityTest` (5:
before/after release on both the encounter page and patient history, plus
the order-remains-visible-regardless check), `RadiologyReleaseEndToEndTest`
(1, the full Part 37 pathway from order through release to encounter/
history visibility, plus the post-release edit-blocked and cannot-
re-release checks). All 31 pass.

Regression: all pre-existing Radiology tests (Steps 36-43, 213 tests across
39 files) re-run and pass unchanged, including `RadiologyReportTest`'s and
`RadiologyReleaseTest`'s own `TRANSITIONS` assertions (updated for the new
RELEASED shape). `tests/Feature/ClinicalEncounters` (36 tests),
`tests/Feature/ClinicalNotes/ClinicalHistoryTest` (part of that same 36),
and `tests/Feature/OpdWorkflow` (10 tests) also re-run and pass — both
touched by this step's `ClinicalEncounterController`/`ClinicalHistoryService`
changes.

## Remaining issues

None blocking. Explicitly out of scope per this step's own boundary (Part
35): billing, payment, insurance claims, NHIF claim submission,
preauthorization, and claim adjudication. A `RELEASED` report
(`RadiologyReport::where('status', 'released')`) is exactly the structured,
traceable handoff Step 45 (Radiology Billing Integration) will build on.

**STEP 44 COMPLETE — the pathway Verified Report -> Release Worklist ->
Release -> RELEASED -> Doctor's Clinical Encounter -> Patient Medical
History works correctly end to end, alongside re-release protection,
result immutability, facility isolation, IDOR protection, and
concurrent-release protection.**

# Radiology UI Completion Pass — Audit, Imaging, and Bug Fixes

A full UI audit was run across the entire Radiology module (order ->
scheduling -> arrival -> verification -> preparation -> examination ->
imaging -> reporting -> verification -> release -> doctor result -> patient
history), using the status matrix below. Steps 36-44 were found essentially
complete and well-tested already; the two genuine gaps closed here are
X-Ray/imaging support (nothing existed anywhere in the codebase for it) and
two real frontend bugs found during the audit.

## Status matrix (final)

| UI Area | Status |
| --- | --- |
| Radiology dashboard | IMPLEMENTED |
| Radiology order list | IMPLEMENTED |
| Radiology order details | IMPLEMENTED (fixed — see below) |
| Radiology order creation | IMPLEMENTED |
| Procedure selector | IMPLEMENTED |
| Order status display | IMPLEMENTED (fixed — see below) |
| Scheduling UI | IMPLEMENTED |
| Radiology worklist | IMPLEMENTED |
| Unscheduled orders queue | IMPLEMENTED |
| Appointment/schedule view | IMPLEMENTED |
| Patient arrival | IMPLEMENTED (fixed — see below) |
| Order verification | IMPLEMENTED |
| Preparation status | IMPLEMENTED |
| READY state | IMPLEMENTED |
| Examination worklist | IMPLEMENTED |
| Start examination | IMPLEMENTED |
| Examination details | IMPLEMENTED |
| Technical information form | IMPLEMENTED |
| X-Ray/image upload | **WAS MISSING — now IMPLEMENTED** |
| Multiple image support | **WAS MISSING — now IMPLEMENTED** |
| Image preview/gallery | **WAS MISSING — now IMPLEMENTED** |
| Image secure viewing | **WAS MISSING — now IMPLEMENTED** |
| Examination completion | IMPLEMENTED |
| Abort examination | IMPLEMENTED |
| Findings worklist | IMPLEMENTED |
| Radiology report form | IMPLEMENTED |
| Findings field | IMPLEMENTED |
| Impression field | IMPLEMENTED |
| Recommendations | IMPLEMENTED |
| Save draft | IMPLEMENTED |
| Submit report | IMPLEMENTED |
| Report history/version display | IMPLEMENTED (audit-log based) |
| Verification worklist | IMPLEMENTED |
| Report verification page | IMPLEMENTED |
| Verify result | IMPLEMENTED |
| Return report | IMPLEMENTED |
| Return reason | IMPLEMENTED |
| Doctor radiology results | IMPLEMENTED |
| Patient history radiology results | IMPLEMENTED |
| View radiology images from result | **WAS MISSING — now IMPLEMENTED** |
| Loading states | IMPLEMENTED |
| Empty states | IMPLEMENTED |
| Error states | IMPLEMENTED (Inertia default + field-level validation) |
| Permission-based UI | IMPLEMENTED |
| Mobile/responsive behavior | IMPLEMENTED (existing Tailwind conventions) |

## Audit method

Searched the entire frontend (`resources/js/pages`, `resources/js/
components`, `resources/js/types`) and backend (`routes/web.php`, every
Radiology controller/service/policy/resource) for every workflow area
above, then cross-checked each Inertia page's props against its
controller. Also grepped the whole codebase (not just Radiology) for any
existing file/media/document storage abstraction to reuse — none exists
anywhere (Laboratory, patients, insurance all store zero uploaded files),
confirming imaging genuinely needed new, purpose-built infrastructure
rather than an extension of something already there.

## Gap 1: X-Ray / imaging (new)

No image/media capability existed anywhere in this application. Built the
minimum needed, explicitly not a PACS/DICOM/RIS:

- **`radiology_images` table** (new) — one row per file, keyed to
  `radiology_appointment_id` alone (the same "no duplicated identity
  columns" convention every Radiology table already follows). `path` is a
  private-disk storage key, never sent to the browser.
- **`RadiologyImage` model** (new), `RadiologyAppointment::images()`
  relation (new).
- **`RadiologyImageService`** (new) — upload/delete, both restricted to an
  `IN_PROGRESS` examination (mirrors `RadiologyExaminationService::
  recordTechnicalData()`'s identical gate), content-sniffed MIME
  validation (JPG/PNG only), 10MB/file, 10 files/upload.
- **`RadiologyImageController`** (new) — `store`/`destroy` (Inertia
  redirects) and `show` (streams the file after re-authorizing on every
  request — the one route in this module that isn't an Inertia page,
  exactly like any other authenticated-asset route). Files live on the
  private `local` disk (`storage/app/private`), never the `public` disk;
  there is no public URL or symlink to them at any point.
- **`RadiologyAppointmentPolicy`** extended with `manageImages` (reuses
  `radiology-examination.update` — no new permission) and `viewImages`
  (reuses `radiology-examination.view` or `radiology-results.view`).
- **Routes** (`routes/web.php`, all under `auth`):
  `POST/GET/DELETE radiology/examinations/{radiologyAppointment}/images[/{radiologyImage}]`
  as `radiology-images.{store,show,destroy}`.
- **`RadiologyImageResource`** (new) — metadata only, never `path`.
- **Frontend**: `radiology-image-gallery.tsx` (upload input + progress +
  thumbnail grid + delete, gated by `canManage`) and `radiology-image-
  viewer.tsx` (open/zoom/navigate/close modal, shared by every surface).
  Wired into: the examination page (Step 41, upload-capable while
  `IN_PROGRESS`); `RadiologyExaminationSummary` (read-only — automatically
  reaches the report/verification/release pages, since all three already
  reuse that one component); the doctor's `RadiologyResultsSection` on the
  clinical encounter page (a "View X-Ray Images" action, only shown when a
  released result actually has images); and patient clinical history
  transitively through the same released-result resource.
- **Eager-loading** added only where images are actually displayed (each
  detail/show page), never on list/worklist pages, to avoid unnecessary
  N+1/payload cost.

Explicitly not built (per the task's own stop condition): DICOM support,
a PACS viewer, thumbnail generation via GD/Imagick (thumbnails are the
same full image, CSS-scaled), or annotation/measurement tools.

## Gap 2: two real frontend bugs found and fixed during the audit

1. **"Patient Arrived" missing after confirming a booking** —
   `radiology-reception-dialog.tsx` only rendered the button when
   `appointment.status === 'scheduled'`, even though the backend
   (`RadiologyAppointment::TRANSITIONS`) has always allowed arrival from
   both `scheduled` and `confirmed`. A technician who confirmed a booking
   before the patient arrived (the documented, expected order of
   operations) hit a dead end on the Reception screen. Fixed to check
   `['scheduled', 'confirmed'].includes(appointment.status)`.
2. **Order-level status hid procedure-level progress** — the radiology
   order's own `status` is a deliberate coarse rollup (`scheduled` only
   ever means "every item has an active appointment"), but the order
   detail page and the dashboard's Recent Orders table displayed *only*
   that rollup, so a procedure whose appointment was actually `confirmed`
   (or further along) looked stuck at "Scheduled." Fixed by eager-loading
   each item's `activeRadiologyAppointment` on both pages and rendering
   its real status alongside the item — the order-level badge is
   unchanged (still correct), the per-item status is now also visible.
   Covered by `RadiologyOrderAppointmentStatusTest`.

## Testing

New: `RadiologyImageTest` (upload/delete, IN_PROGRESS-only gate, MIME/
size/count validation, storage-path-never-exposed), `RadiologyImage
AuthorizationTest` (guest, doctor, permission-less, radiologist
view-only, technician full access, hospital-admin IDOR, super-admin
bypass, secure content-type serving, no public-disk exposure),
`RadiologyOrderAppointmentStatusTest` (order/dashboard per-item status
display). All new tests pass; the full pre-existing Radiology suite
(Steps 36-44) continues to pass unchanged.

## Remaining work

None blocking within this module's own scope. Explicitly deferred, as
already documented by Steps 42/45: full report version/snapshot history
beyond the audit log (would follow `LaboratoryReport`'s own snapshot
pattern if a future step needs it), billing/NHIF integration, and any
DICOM/PACS/RIS capability.

# Verification/release role split: `radiology-supervisor` (post Step 44)

## Change

Step 43 originally granted `review`/`verify`/`return`/`release` to the
same `radiologist` role used for authoring reports (Step 42), relying
solely on `independentOfAuthor()` to stop a radiologist verifying their
own report. Per explicit user decision, this was replaced with a
distinct-role design mirroring Laboratory's existing
`laboratory-technician` / `laboratory-supervisor` split
(`database/seeders/RoleSeeder.php`):

```
radiologist            : radiology-results.view, create, update, submit
radiology-supervisor   : radiology-results.view, create, update, submit,
                          review, verify, return, release
```

`radiologist` no longer carries `review`/`verify`/`return`/`release` at
all — a plain radiologist cannot reach those actions on *any* report,
not even a colleague's, regardless of the self-verification identity
check. `radiology-supervisor` is a new role added to `RoleSeeder`
alongside `hospital-admin` as the only roles holding those four
permissions. No new permission names were introduced (Part 17's
"extend, don't duplicate" rule held) — only the role→permission mapping
changed; `RadiologyReportPolicy` (`review()`/`verify()`/
`returnForCorrection()`/`release()`) is unchanged, since it already
checked permissions rather than role names directly.
`independentOfAuthor()` still applies on top, unchanged, so a
`radiology-supervisor` still cannot verify/return their own report
unless `RADIOLOGY_ALLOW_SELF_VERIFICATION` is set.

## Why

Before this change, "any radiologist can verify any other radiologist's
report" was enforced purely by the identity check. The user asked who
is responsible for verification and, after the trade-off was explained
(Laboratory already uses an explicit supervisor role rather than
peer-review), confirmed they wanted the same distinct-role model for
Radiology rather than continuing to rely on self-verification
avoidance alone.

## Test/seed impact

- `RoleSeeder` role count: 11 -> 12 (`RolePermissionTest`).
- `tests/Pest.php`: `radiologistWithPractitioner()` gained an optional
  `$role` parameter; new `radiologySupervisorWithPractitioner()` helper
  wraps it with `'radiology-supervisor'`. `verifiedRadiologyReportFor()`
  now defaults its verifier through the new helper.
- Every existing Verification/Release test's verify/return/release
  actor was moved to `radiologySupervisorWithPractitioner()`; actors
  that only author/submit reports were left on plain
  `radiologistWithPractitioner()`, since that permission set is
  unchanged.
- Added coverage
  (`RadiologyVerificationAuthorizationTest`): a plain radiologist who is
  not the report's author is still forbidden from review/verify/return,
  proving the block is now permission-based, not merely identity-based.
  The worklist index itself stays reachable for a plain radiologist
  (`radiology-verification.index` is gated by the same broad
  `radiology-results.view` shared with the authoring worklist); only the
  per-report review/verify/return/release actions are restricted to
  `radiology-supervisor`.

## Remaining work

Real (non-test) database: `RoleSeeder` must be re-run once against the
live dev database for the `radiology-supervisor` role to exist there
(seeders are idempotent — safe to re-run). One of the two demo
radiologist accounts should be reassigned to `radiology-supervisor` so
manual testing can exercise both the authoring and verification/release
sides of the workflow.
