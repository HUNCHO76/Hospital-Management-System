# Laboratory Management (Phase 4, Steps 28-31)

## Existing functionality audit

No laboratory entity existed anywhere before this step — exhaustive search
(`Laboratory|LabTest|LabOrder|LabResult|LabSample|Specimen`) across
`app/`, `database/`, `routes/`, `tests/`, `resources/js/` returned nothing
but Step 27's own `CarePlanItem::TYPE_LABORATORY` constant. Two things
*did* already exist and are reused as-is:

- **The `LAB`/"Laboratory" department** — already seeded by
  `DepartmentSeeder` under the main hospital.
- **The `laboratory-technician` role** — already seeded by `RoleSeeder`,
  previously holding only `practitioners.view`/`patients.view`.

## The distinction: Test, Order, Sample, Result, Report

- **`LaboratoryTest`** — catalog master data ("what the lab can perform").
  Step 28 built a minimal stub; Step 29 turned it into the authoritative
  catalog with categories, specimen/result/reference-range metadata,
  turnaround time, default priority, and effective dating (see "The test
  catalog" below).
- **`LaboratoryOrder`** — a specific request for one or more tests against
  one patient's encounter ("this patient needs a CBC and a malaria test").
  Built Step 30.
- **`LaboratorySample`** — the physical specimen actually drawn ("this
  EDTA tube"), distinct from both the order and any single test since one
  sample may satisfy several tests. Built Step 31 — see "Sample /
  specimen management" below.
- **Result** — not built. A future `LaboratoryResult` model (Step 32) will
  reference a `LaboratorySample`.
- **Report** — not built (a later step), aggregates results for delivery.

## The LaboratoryOrder ↔ ClinicalEncounter relationship (Step 30 restructure)

Steps 28-29 originally modeled `LaboratoryOrder` as a one-to-one detail
record on a `laboratory`-type `CarePlanItem`, mirroring `Prescription`.
Step 30's spec was explicit and unambiguous that this was the wrong
long-term shape: *"Do NOT make Prescription → Laboratory... the correct
model is: Clinical Decision ├── Prescription ├── Laboratory ├──
Radiology..."* — Laboratory must sit beside Treatment, not inside it, and
one order must be able to hold many tests. This was a deliberate, audited
schema evolution (confirmed with the user before implementing), using the
same safe backfill-then-drop migration discipline established in Step 29's
`category` → `category_id` migration — no data was discarded.

```
ClinicalEncounter
  |
  +-- LaboratoryOrder (hasMany) -- its own sibling branch, alongside
  |      |                         Diagnosis/Treatment/Clinical Notes
  |      +-- LaboratoryOrderItem (hasMany) -- one row per requested test
  |             |
  |             +-- LaboratoryTest (belongsTo, the catalog entry)
```

`CarePlanItem::TYPE_LABORATORY` and its `laboratoryOrder()` relation were
removed entirely; `CarePlanService`/`CarePlanItemController`/
`CarePlanItemResource`/the CarePlan Form Requests lost every laboratory
branch. `LaboratoryOrderItem.test_code`/`.test_name` are snapshotted from
the catalog at order time (spec §44's historical-integrity requirement), so
a later rename/deactivation of the catalog test never makes an existing
order ambiguous — `LaboratoryTest::isReferenced()` (used to lock `code`
once clinically committed) now checks `orderItems()->exists()` rather than
a direct `LaboratoryOrder` relation, since the FK moved to the item.

**Duplicate tests within one order are rejected, not silently deduplicated**
— a documented choice (spec §20): `LaboratoryOrderService::create()`/
`update()` throw a validation error rather than quietly dropping a repeat
selection, so the clinician sees their mistake instead of an order missing
a test they thought they added. Two *separate* orders may legitimately
reference the same test later (re-testing), so there is no DB-level unique
constraint on `(laboratory_order_id, laboratory_test_id)`.

## Order lifecycle

```
ordered -> cancelled
```

Every other state (`draft`, `sample_pending`, `sample_collected`,
`received`, `in_progress`, `result_pending`, `completed`) is declared in
`LaboratoryOrder::STATUSES`/`LaboratoryOrderItem`'s own status constants so
the column and frontend labels already understand them, but
`TRANSITIONS` deliberately does not allow reaching them yet — Steps 31-34
will extend that constant as sample collection, processing, and result
entry are built. `draft` is declared for completeness but never persisted:
nothing in the order-creation workflow needs a saved multi-session draft,
so an order is created directly as `ordered`. `LaboratoryOrderItem` carries
its own status independently of the parent order's, because individual
tests within one order may progress through sample/processing states at
different rates once Steps 31-34 build that workflow.

Cancelling the whole order (`LaboratoryOrderService::cancel()`) cascades to
cancel every non-cancelled item with the same reason/actor/timestamp.
Cancelling a single test (`cancelItem()`) is only allowed while both the
parent order and the item itself are still `ordered` (spec §26 — only while
the order is in an editable state) — this is how a test is removed from an
order; `update()` never removes items, only adds new ones.

**No diagnosis requirement** (spec §17): `LaboratoryOrderService::create()`
asserts the encounter has an engaged consultation (`in_progress`/
`completed`, mirroring `DiagnosisService::assertConsultationEligible()`
exactly) but never checks for a diagnosis — urgent laboratory testing must
not be blocked simply because a final diagnosis has not yet been entered.

## Order number

`LAB-{year}-{sequence}`, generated in `LaboratoryOrder::booted()`'s
`creating` hook via the existing `GeneratesBusinessNumber` trait (already
used by `Patient`/`ClinicalEncounter`) — `lockForUpdate()` + last-value+1
inside a transaction, never `count()+1`.

## Priority

`routine` | `urgent` | `stat` — a distinct enum from `CarePlanItem::PRIORITIES`
(`low`/`medium`/`high`), since a laboratory priority has specific clinical
turnaround meaning the generic item priority doesn't carry.

## NHIF / billing integration boundary

`laboratory_tests.nhif_code` and `.price` exist so a future billing/claims
module can reference them without a schema change — nothing in this step
reads or writes either column. No claims, pricing, or authorization logic
is implemented.

## The test catalog (Step 29)

```
LaboratoryTestCategory  (new — Hematology, Clinical Chemistry, etc.)
  |
  +-- LaboratoryTest  (extended: short_name, description, category_id,
         |             department_id, specimen_type, specimen_container,
         |             collection_notes, result_type, unit, reference_range,
         |             turnaround_time_minutes, default_priority,
         |             effective_from/to, created_by/updated_by)
         |
         +-- LaboratoryOrderItem  (Step 30 — via orderItems(), not a direct
                                    LaboratoryOrder relation)
```

The Step 28 `category` free-text column was backfilled into real
`laboratory_test_categories` rows (one per distinct existing value) and then
dropped — a safe, non-destructive migration since the column had no
foreign-key semantics and nothing else referenced it directly.

**Specimen type/container and result type stay plain validated strings**
(model constants + `Rule::in()`), not separate lookup tables — a hospital's
specimen/result vocabulary is stable enough that a DB-configurable table
would be over-engineering for this step. **Reference range is a single
descriptive text field** (e.g. "13.5–17.5 g/dL (Adult Male)"), not a
structured per-sex/age table — extensible later if Step 33's result
interpretation actually needs structured ranges.

**Historical integrity**: rather than versioning or snapshotting a test's
definition onto each order, this catalog relies on three simpler
guarantees: (1) `code` becomes **immutable once any `LaboratoryOrder`
references the test** (`LaboratoryTestService::update()` rejects a code
change once `$test->isReferenced()`); (2) tests are **deactivated, never
deleted** (no destroy route on either `LaboratoryTest` or
`LaboratoryTestCategory` — an inactive test's FK from old orders never
breaks); (3) **every configuration change is fully audited** with an
old/new diff, so the change history is reconstructable without a dedicated
version table. Full snapshotting is deferred to Step 33, where a stored
result would actually need to be interpreted against a specific reference
range/unit — nothing today reads more than a test's identity from an order.

**Panels/profiles were not built** — no such architecture existed to reuse,
and spec's own instruction ("only introduce one if clearly justified... do
not build unnecessarily") argues against speculatively adding one; Step 30
(Laboratory Orders) is the more natural place to revisit if the real
order-entry workflow needs it.

**NHIF/billing mapping** stays exactly as Step 28 left it (`nhif_code`,
`price` on `laboratory_tests`) — no general `Service`/`PriceList` model
exists anywhere in this HMS to integrate against, so no fake
`hospital_service_id` relationship was invented.

## Permissions

`PermissionSeeder`: `laboratory.view` (the dashboard page); `laboratory-
orders.view/create/update/cancel`; `laboratory-tests.view/create/update/
activate/deactivate` (Step 29 replaced the Step 28 placeholder `.manage`
action with this granular set); `laboratory-categories.view/manage` (new,
Step 29 — one combined mutate permission, since categories change far less
often than tests).

`RoleSeeder`:
- `hospital-admin`: full set for all three permission groups.
- `doctor`: `laboratory-orders.create`/`.update`/`.cancel` +
  `laboratory-tests.view` (places, edits while still ordered, and can
  cancel their own orders, and views the catalog to pick tests; no
  catalog-curation rights — same precedent as `DiagnosisCode`/`Medication`,
  which no non-admin role manages either). Step 30 added `.update`, since
  Step 28 only allowed create/cancel and orders can now genuinely be edited
  (priority, indication, notes, adding tests) while still `ordered`.
- `laboratory-technician`: `laboratory.view`, `laboratory-orders.view`,
  `laboratory-tests.view` (sees the dashboard, every order, and the
  catalog; cannot create/update/deactivate tests or manage categories —
  catalog curation is an administrative function in this app).
- `nurse`/`receptionist`/`pharmacist`/`accountant`: none.

## Authorization

`LaboratoryOrderPolicy` (rewritten in Step 30 — a real improvement over
Step 28, which had no ownership scoping at all because it borrowed
`CarePlanItem`'s own): mirrors `EncounterDiagnosisPolicy` exactly, now that
`LaboratoryOrder` is a first-class encounter entity rather than a borrowed
detail record.
- `viewAny()`/`view()` — permission + hospital scope only, no ownership
  restriction (any authorized clinical/lab staff in the same hospital may
  need to read it for handoffs — the dashboard/worklist/detail pages need
  every order in their hospital, not just ones tied to them).
- `create(User, ClinicalEncounter)` — permission + `ownsOrSupervises()`
  (the encounter's consultation practitioner, or hospital-admin/
  super-admin).
- `update()`/`cancel()` — same permission + `ownsOrSupervises()` pattern
  against the order's own `clinicalEncounter`. Removing a single test from
  an order is authorized via the order's own `update` ability (modifying
  the order's content), not a separate item-level permission.

`LaboratoryTestPolicy` (Step 29): `viewAny`/`view` are permission-only
(`laboratory-tests.view`, mirroring `DiagnosisCodePolicy`); `create`/
`update`/`activate`/`deactivate` are also permission-only (no hospital
scoping — this catalog is hospital-wide master data, not per-hospital).
`LaboratoryTestCategoryPolicy` (new) follows the same permission-only shape,
with `create`/`update`/`activate`/`deactivate` all gated by the single
`laboratory-categories.manage` permission.

## IDOR protection

Orders reached through
`/clinical-encounters/{a}/laboratory-orders/{order}` (and its nested
`/items/{item}/cancel`) are guarded by `EncounterLaboratoryOrderController`'s
own `assertBelongsToEncounter()`/`assertItemBelongsToOrder()` checks — the
same pattern as `CarePlanItemController`/`EncounterDiagnosisController`. The
standalone `GET laboratory/orders/{laboratoryOrder}` page has no encounter
segment at all — it relies purely on `LaboratoryOrderPolicy::view()`'s
hospital scoping, proven by `LaboratoryOrderAuthorizationTest`: a
hospital-admin cannot open another hospital's order by guessing its id.

## Route architecture (web + Inertia only)

```
POST clinical-encounters/{clinicalEncounter}/laboratory-orders                          laboratory-orders.store
PUT  clinical-encounters/{clinicalEncounter}/laboratory-orders/{laboratoryOrder}          laboratory-orders.update
POST clinical-encounters/{clinicalEncounter}/laboratory-orders/{laboratoryOrder}/cancel   laboratory-orders.cancel
POST clinical-encounters/{clinicalEncounter}/laboratory-orders/{laboratoryOrder}/items/{item}/cancel  laboratory-orders.items.cancel

GET  laboratory                                    laboratory.index          dashboard: stat cards + recent orders
GET  laboratory/orders                             laboratory.orders.index   full paginated/filterable worklist (new, Step 30)
GET  laboratory/orders/{laboratoryOrder}            laboratory.orders.show   order detail
GET  laboratory-tests/search-json                   laboratory-tests.search-json

GET|POST    laboratory/tests[/create]                              laboratory-tests.index/create/store
GET|PUT     laboratory/tests/{laboratoryTest}[/edit]                laboratory-tests.show/edit/update
POST        laboratory/tests/{laboratoryTest}/activate              laboratory-tests.activate
POST        laboratory/tests/{laboratoryTest}/deactivate            laboratory-tests.deactivate

GET|POST    laboratory/categories[/create]                                    laboratory-categories.index/create/store
GET|PUT     laboratory/categories/{laboratoryTestCategory}/edit               laboratory-categories.edit/update
POST        laboratory/categories/{laboratoryTestCategory}/activate           laboratory-categories.activate
POST        laboratory/categories/{laboratoryTestCategory}/deactivate         laboratory-categories.deactivate
```

Order creation/editing/cancellation moved off the `care-plan.items.*`
family onto their own `EncounterLaboratoryOrderController`, since Laboratory
is its own branch off the encounter, not a `CarePlanItem`. The Laboratory
dashboard (`laboratory.index`) was slimmed to stat cards + quick links + a
short recent-orders preview; the full filterable worklist moved to its own
new page (`laboratory.orders.index`) — spec §33 wants Dashboard/Orders/Tests
as three distinct destinations rather than one collapsed page. No `destroy`
route on either catalog controller — deactivation only (spec §23).
`routes/api.php` is untouched (still only `GET /health`).

## Frontend

- `laboratory-orders-section.tsx` (new, Step 30) — embedded panel on
  `clinical-encounters/show.tsx`, mirroring `DiagnosisSection`/
  `CarePlanSection`: existing orders for this encounter (order number
  linking to the standalone detail page, per-item cancel, whole-order
  cancel dialog, priority/indication/notes) plus an inline "New Laboratory
  Order" form using `laboratory-order-test-selector.tsx` (a multi-select
  wrapper accumulating a local list on top of the existing single-select
  `laboratory-test-search.tsx`, Step 28, reused as-is). Requires the
  consultation to be `in_progress`/`completed`, same as Diagnosis, but
  deliberately has no diagnosis requirement.
- `laboratory/index.tsx` — dashboard: real status-distribution counts
  (`StatCard` per `LaboratoryOrder::STATUSES` value, sourced only from
  actual `laboratory_orders.status` rows — never fabricated), quick links,
  and a short recent-orders preview.
- `laboratory/orders/index.tsx` (new, Step 30) — the full paginated/
  filterable worklist: search, status/priority/department/practitioner
  filters, date range, and quick filter chips (Pending/Today/Urgent/STAT/
  Cancelled).
- `laboratory/show.tsx` — order detail: order number, patient, encounter
  (linked), priority, status, ordering user, ordered-at, clinical
  indication/notes, cancellation info, and (Step 30) the full `items[]`
  list with per-item status. No sample/result/verification/report fields —
  those are Steps 31-34's job, not stubbed here.
- `care-plan-section.tsx` — lost its Step 28 `laboratory_order` field group/
  display line entirely; Treatment's add-item form no longer offers a
  `laboratory` type at all, since Laboratory is its own sibling section now.
- Sidebar entry, `Laboratory`, gated by `laboratory.view` — unchanged by
  Step 30; the dashboard's own quick links reach Orders/Tests/Categories.
- `laboratory/tests/{index,create,edit,show}.tsx` + `laboratory-test-
  form.tsx` (Step 29) — full catalog CRUD, mirroring `DepartmentController`/
  `departments/index.tsx`'s create/edit-sharing convention (the closest
  small-admin-catalog precedent in this app, since `DiagnosisCode`/
  `Medication` never got a management UI at all). No destroy action —
  activate/deactivate buttons instead.
- `laboratory/categories/{index,create,edit}.tsx` + `laboratory-category-
  form.tsx` (Step 29) — same pattern, simpler field set, no show page.

## Testing

`tests/Feature/Laboratory/` (rewritten for Step 30's domain):
`LaboratoryOrderTest` (multi-test creation, no-diagnosis-required, snapshot
integrity, duplicate-test rejection, priority-change audited distinctly,
adding tests via update, cascading cancellation, item removal rules),
`LaboratoryOrderItemTest` (new — inactive/non-existent test rejection,
missing cancellation reason, item/order id mismatch IDOR, re-adding an
existing test is a no-op), `LaboratoryOrderAuthorizationTest` (role
separation including nurse/receptionist/laboratory-technician boundaries,
the standalone `laboratory-orders.create` gate proven with a doctor who
holds full `treatment.*` but not it, doctor-vs-doctor ownership, hospital-
admin scoping, encounter-id-mismatch IDOR, super-admin bypass),
`LaboratoryOrderConcurrencyTest` (order-number uniqueness under repeated
creation, single-winner cancellation), `LaboratoryDashboardTest` (dashboard
recent-orders/status counts, worklist listing/filtering, hospital scoping),
`LaboratoryOrderEndToEndTest` (the full Phase 3 → Laboratory handoff proving
spec §17's no-diagnosis exception, multi-test order, item removal then
order cancellation, with a medication order placed alongside to prove Step
27's parallel-orders boundary still holds). `LaboratoryTestCatalogTest`'s
two order-dependent tests (code-immutability-once-referenced, deactivated-
test exclusion) were updated to place orders through the new
`LaboratoryOrderService`/route instead of the retired
`care-plan.items.store` flow — a required adaptation, not a regression.
`OpdWorkflowEndToEndTest` (Step 27) was updated to place its laboratory
order through `clinical-encounters.laboratory-orders.store` instead of a
`laboratory`-type care-plan item. Full regression suite re-run in
memory-safe batches: zero failures across every Phase 1-4 module.

**Step 29 additions**: `LaboratoryTestCatalogTest` (code normalization,
code immutability once referenced, configuration/service-mapping/NHIF-
mapping audit events, activate/deactivate, deactivated-test exclusion from
new orders while a prior order keeps working), `LaboratoryTestCategoryTest`
(CRUD, activate/deactivate, tests keep referencing a deactivated category),
`LaboratoryCatalogAuthorizationTest` (guest redirect, doctor/laboratory-
technician/receptionist read-only or no access, hospital-admin full access,
IDOR — a user without the permission cannot reach an edit page regardless
of id, super-admin bypass), `LaboratoryCatalogValidationTest` (duplicate
code, missing name, invalid category/result-type, negative turnaround time,
invalid effective-date order, duplicate category code). 46/46 Laboratory
tests pass; full regression suite re-verified in batches with zero failures.

## Sample / specimen management (Step 31)

**Audit before building**: an exhaustive search (`Sample|Specimen|LabSample|
SampleCollection|SpecimenCollection|SampleType|SpecimenType`, plus every
table/route/component name variant the spec listed) found nothing — no
sample/specimen entity, table, controller, service, policy, route, or
frontend type existed anywhere before this step, confirmed by this same
doc's own prior "Sample — not built" line. What *did* already exist and
is reused as-is: `LaboratoryTest::SPECIMEN_TYPES`/`SPECIMEN_CONTAINERS`
(Step 29's catalog vocabulary — no duplicate specimen-type table was
created), the forward-declared but previously unreachable
`sample_pending`/`sample_collected`/`received` states on
`LaboratoryOrder`/`LaboratoryOrderItem`, and `GeneratesBusinessNumber`
(reused for the new `sample_number`, same `{PREFIX}-{year}-{sequence}`
pattern as `LAB-2026-000001`).

### The Sample entity and its relationships

```
LaboratoryOrder
  |
  +-- LaboratoryOrderItem (hasMany, Step 30 — one row per requested test)
  |
  +-- LaboratorySample (hasMany, Step 31 — one row per physical specimen)
         |
         +-- LaboratoryOrderItem (belongsToMany, via laboratory_sample_items)
```

`LaboratorySample` is a first-class entity, not nested under
`LaboratoryOrderItem` — because **one sample may satisfy several tests**
(spec §11-12: a single whole-blood/EDTA draw can cover CBC + Malaria +
Blood Group) and **one order may need several samples** (spec §13: CBC
needs EDTA whole blood, Creatinine needs serum — two incompatible
requirements, two samples). The `laboratory_sample_items` pivot table
models this many-to-many relationship; there is no `1 sample = 1 test`
constraint anywhere in the schema.

`clinical_encounter_id`/`patient_id` are denormalized onto `LaboratorySample`
directly from the order (mirroring `ClinicalNote`'s own `patient_id`
precedent) rather than reached through a `laboratory_order_id` join —
every sample list, IDOR check, and IDOR test queries them directly.
`specimen_type`/`container_type` live on the sample itself (validated
against `LaboratoryTest::SPECIMEN_TYPES`/`SPECIMEN_CONTAINERS` — no new
enum, no new lookup table), because the sample's own specimen identity is
the authoritative record of what was actually collected, not merely a
derived lookup through its tests.

**No separate accession number** (spec §8): `sample_number`
(`SMP-{year}-{sequence}`) is the single identifier from registration
through receipt. A dedicated accession number remains a candidate for
Step 32 if the lab's own bench/processing workflow ever needs a register
distinct from specimen identity — nothing in Steps 28-31 creates a real
domain distinction that would justify a second identifier today.

**"Sample Requirement" is computed, not persisted**: the spec's own
workflow diagram shows a "Sample Requirements" step between order items
and collection, but nothing reads or writes a dedicated requirements
table — a requirement is simply "an order item whose current status is
still `ordered`," combined with its catalog test's own
`specimen_type`/`specimen_container`. The collection screen computes this
live from `LaboratoryOrderItem` + its `laboratoryTest` relation.

### Specimen grouping and separation (spec §29)

`LaboratorySampleService::assertSpecimenMatchesCatalog()` validates every
selected item's live catalog `specimen_type`/`specimen_container` against
the sample's chosen values — driven entirely by catalog configuration,
never a hard-coded test name. The frontend's `RegisterSampleForm` goes
further: it only lets a user *select* items compatible with whatever is
already checked, so an incompatible combination is structurally
unreachable from the UI, not merely rejected after submission — the
backend validation is still the authoritative guard (an HTTP client could
still submit an incompatible set directly), proven by
`LaboratorySampleTest`'s "incompatible tests require separate specimens".

### Specimen lifecycle

```
PENDING_COLLECTION
        |
        v
    COLLECTED  -----> REJECTED
        |
        v
    LABELLED
        |
        v
    RECEIVED

PENDING_COLLECTION -----> CANCELLED
```

`LaboratorySample::TRANSITIONS` implements exactly the chain and branches
spec §20 lists — no invented extra edges. Rejection is reachable only from
`COLLECTED` (before labelling); `RECEIVED` requires `LABELLED` first (spec
§36 — "sample is correctly labeled" is a receipt precondition, so nothing
can be received straight off a bare collection). `PENDING_COLLECTION` is a
real, persisted state (not merely "no sample row yet") — registering a
sample reserves its identifier and item assignment before the physical
draw happens, which is what makes `PENDING_COLLECTION -> CANCELLED` (spec
§42) a meaningful, reachable action.

`LaboratoryOrderItem::status` is a **derived rollup**, recomputed by
`LaboratorySampleService::recomputeItemStatus()` from whichever of the
item's samples is currently active (its most recent non-cancelled one):
`PENDING_COLLECTION -> sample_pending`, `COLLECTED`/`LABELLED ->
sample_collected`, `RECEIVED -> received`; a `REJECTED` active sample is
treated as "no active sample" and the item reverts to plain `ordered`
(spec §33 — it simply needs a new specimen). This recompute deliberately
bypasses `LaboratoryOrderItem::canTransitionTo()` — a rejection must be
able to move the item backward, which the model's own forward-oriented
`TRANSITIONS` table cannot express as a single-direction edge, so the
service is the one place allowed to reassign status without going through
that gate; the model's `TRANSITIONS` still documents every edge that
*can* occur, for reference.

`LaboratoryOrder::status` is likewise a rollup —
`recomputeOrderStatus()` — computed from the aggregate of its own
non-cancelled items: `ordered` while none have started, `sample_pending`
once any item has (spec §39), `sample_collected` once every item has
reached at least `sample_collected`, `received` (this step's own "ready
for processing" boundary) only once **every** non-cancelled item has been
received — spec §40's explicit partial-collection requirement: an order
with two of three tests collected stays `sample_pending`, never jumps to
"fully collected." A rollup that actually changes the order's status is
audited as `laboratory_order_status_changed`.

### Labelling and reprinting (spec §22-24)

`LaboratorySampleService::label()` is a single endpoint that handles both
cases: the first call (status `COLLECTED`) transitions to `LABELLED` and
stamps `labelled_at`; every subsequent call (status already `LABELLED` or
`RECEIVED`) is a pure reprint — no status change, no new `sample_number`,
audited separately as `laboratory_sample_label_reprinted` rather than
`laboratory_sample_labelled`. `LaboratorySampleTest` proves a sample
reprinted after being received still reports its original number and
`labelled_at` timestamp unchanged.

**No barcode/QR symbol is rendered.** Both `composer.json` and
`package.json` were audited and contain no barcode/QR package of any kind
(`milon/barcode`, `simple-qrcode`, `picqer/php-barcode-generator`,
`jsbarcode`, `qrcode.react`, etc.), and adding one is a dependency change
outside this step's own authority (`CLAUDE.md`: "Do not change the
application's dependencies without approval"). The label page
(`laboratory/samples/label.tsx`) instead prints the `sample_number` as a
large, monospace, bordered block — spec §23's own "minimal
identifier/printing mechanism," reusing an identifier that is already
unique (DB constraint), concurrency-safe (`GeneratesBusinessNumber`), and
never client-editable (the label page only ever displays a
server-supplied prop; nothing on it is an input field). A real scannable
barcode/QR symbol remains a follow-up if the hospital's workflow later
needs physical scanning hardware integration — the identifier underneath
it would not need to change.

### Rejection and recollection (spec §31-34)

`LaboratorySample::REJECTION_REASONS` is a plain validated list (mirroring
the catalog's own specimen-vocabulary precedent) — insufficient sample,
wrong container, wrong specimen, hemolysed, clotted, leaking container,
unlabelled, mislabeled, contaminated, other (`other` requires
`rejection_notes`). A rejected sample's row is **never mutated after the
fact** beyond the rejection fields themselves, and never reused —
`LaboratorySampleService::recollect()` always creates a **new**
`LaboratorySample` row (a new `sample_number`, `PENDING_COLLECTION`),
linked back via `recollection_of_sample_id`, carrying over the same item
set (specimen/container may be overridden — a wrong-container rejection
typically needs a different tube). `LaboratorySampleTest` proves the
original rejected row's own `sample_number` and status are unchanged after
a recollection is created from it.

### Order/item status integration (spec §39-41)

A single order may register multiple samples (spec §41 — e.g. one
whole-blood/EDTA sample for CBC+Malaria, a separate urine sample for
Urinalysis), and the aggregate order status only ever reflects the true
combined state of every one of its non-cancelled items — proven by
`LaboratorySampleEndToEndTest`, which places a 3-test order, registers two
samples, and asserts the order stays `sample_pending` until every test's
own sample has been received.

## Permissions (Step 31)

`PermissionSeeder`: new `laboratory-samples.view/collect/label/receive/
reject/recollect/cancel` group.

`RoleSeeder`:
- `hospital-admin`: full set.
- `doctor`: `.view` only — doctors order tests and may check collection
  status, but do not perform collection themselves in this app's role
  model (same precedent as catalog curation being admin-only).
- `nurse`: `.view/.collect/.label/.recollect/.cancel` — nurses commonly
  perform bedside/OPD specimen collection, but receipt and rejection are a
  laboratory-reception function, not a collector's own call.
- `laboratory-technician`: the full operational set
  (`.view/.collect/.label/.receive/.reject/.recollect/.cancel`) — spans
  collection through receipt, matching real lab-technician scope in many
  hospitals and this role's existing broad view access across the whole
  laboratory domain.
- `receptionist`/`pharmacist`/`accountant`: none (spec §46 explicit:
  "Reception staff should not automatically receive unrestricted
  laboratory sample permissions").

## Authorization (Step 31)

`LaboratorySamplePolicy` mirrors the read side of `LaboratoryOrderPolicy`
exactly, but — unlike order creation — **no ability is restricted to the
encounter's own treating practitioner**: collection/receipt/rejection are
performed by whichever authorized nurse/lab staff is on duty, not
necessarily anyone tied to the order itself. Every ability is therefore
permission + hospital scope only (`belongsToUsersHospital()`, unrestricted
for any non-hospital-admin role). Removing/reprinting a label needs no
separate permission (bundled into `.label`); receiving a batch of
already-collected samples needs no separate "batch" permission — each
sample's own `receive` action is independently authorized.

## IDOR protection (Step 31)

`/laboratory/samples/{sample}`, `/laboratory/samples/{sample}/label`, and
every mutating sample route rely purely on `LaboratorySamplePolicy`'s
hospital scoping — proven by `LaboratorySampleIdorTest`: a hospital-admin
cannot open another hospital's sample, its label view, or its order's
collection page by guessing the id. `/laboratory/collection/{laboratoryOrder}/
samples` (registration) additionally verifies every submitted `item_ids`
value actually belongs to the route-bound order — `LaboratorySampleService::
create()` scopes its item lookup by `laboratory_order_id`, so a client
cannot register a sample against another order's item; a mismatch fails
validation with zero rows written (`LaboratorySampleIdorTest`).

## Audit logging (Step 31)

`laboratory_sample_created`, `laboratory_sample_collected`,
`laboratory_sample_labelled`, `laboratory_sample_label_reprinted`,
`laboratory_sample_received`, `laboratory_sample_rejected`,
`laboratory_sample_recollection_created`, `laboratory_sample_cancelled`,
and (on the order, when a sample event changes its aggregate status)
`laboratory_order_status_changed`. Every event goes through the existing
`AuditLogService`, which already strips sensitive keys automatically; no
clinical free text beyond the sample's own status/specimen metadata is
ever logged.

## Database integrity (Step 31)

Two new tables, `laboratory_samples` and `laboratory_sample_items`
(pivot). Foreign keys on every relationship (`restrictOnDelete` for the
main table, including the self-referencing `recollection_of_sample_id`;
`cascadeOnDelete` on the pivot rows only, since a sample/item is never
actually hard-deleted in practice); unique `sample_number`; indexes on
`laboratory_order_id`, `clinical_encounter_id`, `patient_id`, `status`,
`collected_at`, `received_at`, `specimen_type`; a composite unique index
on the pivot (`laboratory_sample_id`, `laboratory_order_item_id`) so the
same item can never be attached to the same sample twice. No samples are
ever physically deleted — rejection/cancellation are the only terminal
non-received states, and both are just another `status` value with a
full audit trail, never a row removal (spec §50).

## Concurrency (Step 31)

`LaboratorySampleService::create()` locks the parent order row and every
requested item row (`lockForUpdate()`) inside one transaction before
checking each item for an existing active sample — two simultaneous
registration attempts for the same test can never both succeed
(`LaboratorySampleConcurrencyTest`). `collect()`/`receive()`/`reject()`/
`cancel()`/`recollect()` each lock the sample row itself (and, for
`recollect()`, the underlying item rows too) before re-checking
`canTransitionTo()` inside the lock, so only one of several simultaneous
attempts to advance the same sample can win — proven for every one of
those five actions. `sample_number` uniqueness reuses
`GeneratesBusinessNumber`'s existing lockForUpdate()+last-value+1
transaction strategy, never `count()+1`.

## Frontend (Step 31)

- `laboratory/collection/index.tsx` (new) — the collection worklist (spec
  §25): every order still needing or mid-way through specimen collection,
  with search/status/priority/date filters and a "Required Specimens"
  column derived live from each item's catalog specimen type.
- `laboratory/collection/show.tsx` (new) — the collection detail (spec
  §26-27): patient identification block (name, patient number, DOB/age,
  sex, order number, priority — spec §15's minimum set, nothing more),
  the order's tests with their catalog specimen/container requirement and
  current status, every specimen registered so far with its own action
  buttons, and the specimen-registration form.
- `register-sample-form.tsx` (new) — checkbox-only specimen registration:
  selecting tests is the only interaction: specimen type/container are
  derived automatically from the checked items' shared catalog
  requirement (never manually typed), and an incompatible item is
  disabled rather than merely rejected after submission.
- `collect-sample-dialog.tsx`/`reject-sample-dialog.tsx`/
  `recollect-sample-dialog.tsx`/`cancel-sample-dialog.tsx` (new) — one
  small dialog per action, mirroring the existing
  `CancelLaboratoryOrderDialog` (Step 30) pattern exactly.
- `sample-timeline.tsx` (new) — the simple derived history view (spec
  §37-38): created/collected/labelled/received/rejected/cancelled, each
  with its own actor and timestamp where applicable, computed from the
  sample's own columns; the existing Audit Log viewer remains the
  authoritative forensic trail.
- `laboratory/samples/show.tsx` (new) — standalone specimen detail:
  specimen/container, every actor/timestamp, rejection detail and
  recollection links in both directions, patient/order context, and the
  timeline.
- `laboratory/samples/label.tsx` (new) — the printable label (spec §55),
  deliberately rendered **without** `AppLayout` (no sidebar/nav belongs on
  a physical label), using the existing `BrandMark`/`brand` (St. Joseph
  DMI logo and hospital name — the existing project branding, never
  reinvented) and a `print:hidden` Tailwind utility to hide the print
  button itself when actually printed.
- `laboratory/index.tsx` dashboard gained a "Sample Collection" quick
  link gated by `laboratory-samples.view`, alongside the existing
  Orders/Test Catalog/Categories links.

## Billing / NHIF compatibility (Step 31)

No billing or NHIF logic was added. The full chain Patient -> Encounter ->
LaboratoryOrder -> LaboratoryOrderItem -> LaboratorySample remains fully
traceable end to end, so a future billing/claims module can still
determine exactly which clinical services were requested and collected
without this step having guessed at that module's own shape.

## Testing (Step 31)

`tests/Feature/Laboratory/`: `LaboratorySampleTest` (multi-test grouping,
incompatible-specimen separation, catalog-driven validation, duplicate-
active-sample prevention, collect/label/reprint/receive/reject/recollect/
cancel, item and order status rollups including partial collection),
`LaboratorySampleAuthorizationTest` (guest redirect, receptionist zero
access, nurse collect-but-not-receive/reject, technician receive/reject,
hospital-admin scoping, super-admin bypass), `LaboratorySampleIdorTest`
(cross-hospital sample/label/collection-page access denied, foreign-order
item id rejected with zero rows written), `LaboratorySampleConcurrencyTest`
(sample-number uniqueness under repeated registration, single-winner
registration/collection/recollection races), `LaboratorySampleValidationTest`
(cancelled order/item, empty item list, invalid specimen type over HTTP,
missing/reason-conditional rejection fields, every invalid status
transition), `LaboratorySampleEndToEndTest` (the full reception -> triage
-> consultation -> diagnosis -> order -> two-sample collection -> label ->
receive -> "ready for processing" handoff, proving partial collection,
label reprint idempotency, and the full audit trail). A new
`laboratoryOrderReadyForSampling()`/`laboratorySamplingOrderFor()` pair of
shared test helpers were added to `tests/Pest.php` (the latter previously
lived duplicated across test files in this app's own established pattern,
but was placed in the shared file here since two Step 31 test files both
needed it). 95/95 Laboratory tests pass; full Phase 1-4 regression suite
re-run in memory-safe batches with zero failures.

## Laboratory processing (Step 32)

A specimen enters processing automatically the instant it reaches
`LaboratorySample::STATUS_RECEIVED` — one `LaboratoryProcessing` record is
created per (sample, item) pair (`LaboratoryProcessingService::
initializeForSample()`, idempotent via `firstOrCreate`). This is a
deliberate, minimal entity: it carries only what the processing phase
itself needs (status, bench, assignment, timestamps, notes) and never
duplicates patient/order/test identity, which is always reached through
`processing->item->laboratoryOrder` and `processing->sample`.

### Why accept/reject act on the whole specimen but assign/start/complete/transfer act per test

One sample can carry multiple tests (`laboratory_sample_id` +
`laboratory_order_item_id` is the natural key, enforced with a unique
composite index). Accepting or rejecting a tube is a single physical
decision about the specimen itself, so `acceptSample()`/`rejectSample()`
lock and transition every one of the sample's processing records together.
Assignment, starting, completing, and transferring are decisions about one
test's own bench work, so they operate on a single `LaboratoryProcessing`
row — this is what makes true partial processing possible: two tests
sharing one tube can sit at different stages (`IN_PROGRESS` and `RECEIVED`)
simultaneously.

### Status machine

```
RECEIVED -> ACCEPTED -> ASSIGNED -> IN_PROGRESS -> PROCESSING_COMPLETE -> RESULT_PENDING
RECEIVED -> REJECTED
ACCEPTED -> REJECTED
ACCEPTED -> IN_PROGRESS            (assignment is optional)
ASSIGNED -> REJECTED
ASSIGNED -> IN_PROGRESS -> REJECTED
```

`PROCESSING_COMPLETE` is real and audited (`laboratory_sample_processing_
completed`) but never rests: `complete()` advances the record to
`RESULT_PENDING` in the same call/transaction, audited separately as
`laboratory_processing_status_changed`. The dashboard's "Completed" count is
therefore derived from `completed_at IS NOT NULL` (a durable fact), not
from current status — this is why "Completed" and "Result Pending" can both
show non-zero counts even though the model never rests in
`PROCESSING_COMPLETE`. `RESULT_PENDING` and `REJECTED` are terminal for this
step; result entry (Step 33) owns whatever comes after `RESULT_PENDING`.
Arbitrary transitions (e.g. `RESULT_PENDING -> RECEIVED`) are rejected by
`LaboratoryProcessing::canTransitionTo()` regardless of what the frontend
sends.

### Rejection during processing reuses Step 31, it does not duplicate it

`rejectSample()` calls `LaboratorySampleService::reject()` — the exact same
rejection reasons, notes requirement, and recollection path Step 31 already
built — then cascades every non-terminal `LaboratoryProcessing` row for that
sample to `REJECTED`. `LaboratorySample::TRANSITIONS[STATUS_RECEIVED]` was
extended to include `STATUS_REJECTED` (previously empty) so a specimen can
be rejected after receipt as well as after collection; nothing about
Step 31's own rejection/recollection behavior changed.

### Benches / workstations

`LaboratoryBench` is a small, admin-managed catalog entity (name, code,
description, active/inactive) mirroring `LaboratoryTestCategory` exactly —
work areas are configurable, never hard-coded. Assignment and transfer both
require the bench to be `active`; assignment's own `bench_id` is optional
(spec's own "assignment is optional" allowance), transfer's is required
(there must be a destination to move to).

### Order/item status rollup shared across collection and processing

`LaboratoryOrder::statusFromItemProgress()` is a single, pure, static
function (a `PROGRESS` map plus a min-of-non-cancelled-items rule) that both
`LaboratorySampleService::recomputeOrderStatus()` (Step 31) and
`LaboratoryProcessingService::recomputeOrderStatus()` (Step 32) call — so
the two phases can never drift into inconsistent aggregate-status logic.
The rule: an order's status is always the *least-advanced* non-cancelled
item's stage, never implying more progress than the slowest test has
actually made. `LaboratoryProcessingService::recomputeItemStatus()` derives
an item's own status from its single most-recent `LaboratoryProcessing`
record (`IN_PROGRESS` -> item `in_progress`, `PROCESSING_COMPLETE`/
`RESULT_PENDING` -> item `result_pending`, otherwise item stays `received`)
and bypasses `LaboratoryOrderItem::canTransitionTo()` deliberately — this is
a derived read-model, not a user-facing transition.

### Concurrency

Every service method locks its row(s) with `lockForUpdate()` inside
`DB::transaction()` before checking the current status, so two concurrent
requests to start or complete the same processing record can never both
succeed — the loser hits the same `ValidationException` a stale-state retry
would produce. `acceptSample()` locks the sample and *all* its processing
records together, since acceptance must be all-or-nothing per specimen.

### Analyzer/device integration boundary

No analyzer/instrument/LIS integration exists anywhere in this codebase
(confirmed by audit before building this step). `LaboratoryBench` is the
only integration-ready seam this step establishes — a named, addressable
work area a future device-integration step could attach identifiers to —
no HL7/ASTM/device import was built or attempted.

## Permissions (Step 32)

`laboratory-processing`: `view`, `accept`, `assign`, `start`, `complete`,
`reject`, `transfer`. `laboratory-benches`: `view`, `manage`.
`hospital-admin` holds the full set of both; `doctor` holds only
`laboratory-processing.view` (ordering clinicians can see progress, never
act on it); `nurse` holds none (collection is the nurse's job, processing is
the technician's); `laboratory-technician` holds the full `laboratory-
processing.*` set plus `laboratory-benches.view`.

## Authorization (Step 32)

`LaboratoryProcessingPolicy` mirrors `LaboratorySamplePolicy` exactly:
every ability is permission + hospital scope only, never restricted to a
specific technician (a processing queue is shared work, not personally
owned) — matching how collection/receipt already work. Hospital scoping
itself only ever restricts `hospital-admin`; every other role holding the
permission is unrestricted, the same convention Step 31 established for
`LaboratorySamplePolicy`. `super-admin` bypasses every policy via
`Gate::before`.

## IDOR protection (Step 32)

`LaboratoryProcessingPolicy::view()`/`viewSample()`/every action method
enforce hospital scoping identically to Step 31 — proven by
`LaboratoryProcessingIdorTest`: a hospital-admin cannot open, accept,
assign, start, complete, or transfer another hospital's specimen/processing
record by guessing its id.

## Audit logging (Step 32)

`laboratory_sample_accepted`, `laboratory_sample_assigned`,
`laboratory_sample_processing_started`, `laboratory_sample_processing_
completed`, `laboratory_processing_status_changed` (the `PROCESSING_
COMPLETE -> RESULT_PENDING` auto-advance), `laboratory_sample_transferred`,
plus the existing `laboratory_sample_rejected`/`laboratory_order_status_
changed` actions reused as-is. `processing_notes` is never written into an
audit diff (only structured fields are), matching this app's existing
sensitive-free-text convention.

## Frontend (Step 32)

`laboratory/processing/index` — dashboard (real per-status counts from
`LaboratoryProcessing::STATUSES`, never fabricated) plus a filterable,
paginated worklist with one row per specimen (search, status, priority,
specimen type, bench, assigned/unassigned, date range); accept/reject act
inline from the row, "View" opens the detail page. `laboratory/processing/
show` — the per-specimen breakdown: one card per `LaboratoryProcessing`
record with its own bench/assignment/timing/notes, assign/start/complete/
transfer dialogs, and a derived timeline built from the record's own
timestamps (no separate history table). `laboratory/benches/{index,create,
edit}` — the bench catalog CRUD, mirroring the test-category pages exactly.

## Billing / NHIF compatibility (Step 32)

Unchanged from Step 31 — no billing/NHIF fields or logic were introduced or
touched by processing.

## Testing (Step 32)

`tests/Feature/Laboratory/`: `LaboratoryProcessingTest` (auto-
initialization on receive, whole-specimen accept and duplicate-accept
prevention, optional assignment, start/complete rollups, duplicate-
completion prevention, bench transfer without status change, partial
processing across two tests in one sample, independent progress across
multiple samples on one order), `LaboratoryProcessingAuthorizationTest`
(guest redirect, receptionist zero access, clinician view-only, technician
full lifecycle, nurse zero access, hospital-admin scoping, super-admin
bypass), `LaboratoryProcessingIdorTest` (cross-hospital processing-record
access denied for every action), `LaboratoryProcessingConcurrencyTest`
(single-winner accept/start/complete races, duplicate-processing-row
rejected at the database's own unique constraint),
`LaboratoryProcessingValidationTest` (missing/invalid assignee, inactive
bench, missing transfer fields, missing rejection reason, every invalid
status transition, accepting a non-received specimen),
`LaboratoryProcessingEndToEndTest` (the full reception -> triage ->
consultation -> diagnosis -> order -> collection -> receipt -> accept ->
assign -> start -> complete -> RESULT PENDING pathway for two tests sharing
one specimen, plus mid-processing rejection cascading to every open test).
`receivedSampleReadyForProcessing()`/`receivedSampleForHospital()` were
added to `tests/Pest.php` as shared helpers. Full Phase 1-4 regression
suite re-run in memory-safe batches after these additions.

## Laboratory result entry (Step 33)

A result is created automatically the moment a test's processing reaches
`RESULT_PENDING` (`LaboratoryResultService::initializeForItem()`, called
from `LaboratoryProcessingController::complete()` right after
`LaboratoryProcessingService::complete()` — the exact controller-
orchestrated pairing Step 32 established for auto-initialization, since a
downstream service must never depend on the service upstream of it). One
`LaboratoryResult` row exists per `LaboratoryOrderItem` — the Step 29
catalog is flat, with no panel/analyte/component structure for a single
test to decompose into (confirmed by audit before this step was built), so
"one test, one result" is the correct shape here, not a simplification of
something richer that already existed.

### Result type comes from the test's own catalog configuration

Step 29 already declared `LaboratoryTest::RESULT_TYPES` (`numeric`, `text`,
`boolean`, `positive_negative`, `qualitative`, `enum`) plus `unit`/
`reference_range` fields specifically "to help future result entry" — Step
33 reuses these exactly rather than inventing a second result-type system.
The one gap the audit found: `qualitative`/`enum` had no way to declare
*which* values are valid per test (e.g. a blood-group test's eight coded
values). A single nullable `result_options` JSON column was added to
`laboratory_tests` for this — the minimal structure needed, matching the
catalog's own specimen_type/specimen_container precedent of a plain
configured list rather than a full vocabulary table. `boolean`/
`positive_negative` fall back to an obvious default (`true`/`false`,
`positive`/`negative`) when a test hasn't configured its own options;
`qualitative`/`enum` with no configured options accept any bounded string
rather than blocking entry while the catalog is still being set up.

### Snapshotted, not live, configuration

`result_type`/`unit`/`reference_range`/`result_options` are copied onto the
`LaboratoryResult` row at creation time rather than read live from the
catalog. This matters because `LaboratoryTestService::update()` allows
those configuration fields to change even after a test has been referenced
by an order (only `code` locks) — without a snapshot, editing a test's
result type later could retroactively reinterpret an already-entered
historical result.

### Status machine

```
RESULT_PENDING -> DRAFT -> PENDING_VERIFICATION
```

`RESULT_PENDING` is the auto-created starting state (eligible, nothing
entered yet). A draft may be saved with a blank/incomplete value —
`submit()` is what actually requires a fully valid, present value. Once
`PENDING_VERIFICATION`, a value is never silently changed again: `amend()`
is the only path, requires a reason, and audits the old and new values
separately. Status never reverts casually (`PENDING_VERIFICATION ->
RESULT_PENDING` is not a reachable transition) — verification/release
(Step 34) can safely treat `PENDING_VERIFICATION` as controlled clinical
data once it exists.

### Raw value vs. interpretation

Exactly one of `value_numeric`/`value_text`/`value_code` is populated,
depending on the result's snapshotted `result_type` — never forced through
the wrong column. `abnormal_flag` (`normal`/`low`/`high`/`critical`/
`abnormal`) is a separate, staff-selected field, never encoded into the
value itself (a result is `8.2`/`g/dL`/flag `low`, never the string `"LOW
8.2"`). No age/sex-aware reference-range engine exists yet — `reference_range`
remains the same free-text field Step 29 already had, shown for the
technician's own comparison; the system does not calculate or claim to
calculate the flag automatically, since a naive parse of a text range like
"Adult male: 13.5-17.5; Adult female: 12.0-15.5" cannot be done safely.

### Eligibility is enforced by construction

A `LaboratoryResult` row only ever exists once its item's processing has
actually reached `RESULT_PENDING` (a terminal, unreachable-by-rejection
state per Step 32's own `LaboratoryProcessing::TRANSITIONS`), so there is
no separate runtime "is this eligible" check to bypass — the record simply
does not exist yet for `PENDING_COLLECTION`/`COLLECTED`/`RECEIVED`/
`REJECTED`/`CANCELLED` items, and every route 404s against a non-existent
result id.

### Concurrency

Every service method locks its row with `lockForUpdate()` inside
`DB::transaction()` before checking the current status, so two concurrent
submissions of the same result can never both succeed, and a stale
in-memory copy passed into `saveDraft()` can never reset `entered_by`/
`entered_at` back to whichever technician's request happened to load the
row first.

## Permissions (Step 33)

`laboratory-results`: `view`, `update`, `submit`, `amend`. No `create` or
`view_all` — results are never manually created by a controller action
(only auto-initialized, mirroring `laboratory-processing` having no
`.create` permission either for its own auto-created rows), and this app's
established convention already scopes "view" to everything within a user's
own hospital rather than a narrower personal-ownership subset (see
Authorization below). `hospital-admin` and `laboratory-technician` hold the
full set (no distinct "laboratory supervisor" role exists in this app, so
the technician role carries amendment authority too — the same precedent
Step 32 set for reject/transfer); `doctor` holds only `.view`; `nurse` and
`receptionist` hold none.

## Authorization (Step 33)

`LaboratoryResultPolicy` mirrors `LaboratoryProcessingPolicy` exactly:
every ability is permission + hospital scope only, never restricted to a
specific technician (a result worklist is shared work, not personally
owned). Hospital scoping itself only ever restricts `hospital-admin`;
every other role holding the permission is unrestricted, the same
convention Step 31/32 established. `super-admin` bypasses every policy via
`Gate::before`.

## IDOR protection (Step 33)

`LaboratoryResultPolicy::view()`/every action method enforce hospital
scoping identically to Step 32 — proven by `LaboratoryResultIdorTest`: a
hospital-admin cannot open, edit, submit, or amend another hospital's
result by guessing its id.

## Audit logging (Step 33)

`laboratory_result_created` (first draft save — not the silent auto-init),
`laboratory_result_updated` (subsequent draft saves),
`laboratory_result_submitted`, `laboratory_result_amended` (with the
reason), `laboratory_result_value_changed` (the old/new value diff during
an amendment), `laboratory_result_status_changed` (every real status
transition, audited as its own event alongside the action-specific one —
mirrors Step 32's dual-audit pattern for `complete()`).

## Frontend (Step 33)

`laboratory/results/index` — dashboard (real per-status counts plus a
"Completed Today" count derived from `submitted_at`, never fabricated) and
a filterable, paginated worklist, one row per test's own result.
`laboratory/results/show` — patient/order/specimen/test context, the
dynamic `LaboratoryResultValueInput` component (numeric input / textarea /
radio group / select, chosen entirely from the result's own snapshotted
`result_type`+`result_options` — never a hard-coded test name anywhere in
the frontend), draft saving, submission, and — once
`PENDING_VERIFICATION` — a locked, read-only view with an `AmendResultDialog`
requiring a reason. `laboratory-tests/{create,edit}` gained a "Result
Options" field (shown only for `qualitative`/`enum` result types).

## Billing / NHIF compatibility (Step 33)

Unchanged — no billing/NHIF fields or logic were introduced or touched by
result entry.

## Testing (Step 33)

`tests/Feature/Laboratory/`: `LaboratoryResultTest` (auto-initialization
with a snapshotted configuration, draft save/submit for every result type,
coded-value rejection outside configured options, boolean/positive_negative
defaults, amendment preserving the original value in the audit trail,
amendment blocked before submission, free-text fallback with no configured
result_type, no order/item status advancement in this step, duplicate-init
idempotency), `LaboratoryResultAuthorizationTest` (guest redirect,
receptionist/nurse zero access, clinician view-only, technician full
lifecycle, hospital-admin scoping, super-admin bypass),
`LaboratoryResultIdorTest` (cross-hospital result access denied for view/
update/submit/amend), `LaboratoryResultConcurrencyTest` (single-winner
submission races, stale-copy draft saves never corrupt entered_by/
entered_at, duplicate-result-row rejected at the database's own unique
constraint), `LaboratoryResultValidationTest` (non-numeric value, invalid
abnormal flag, coded value outside configured options, no result exists
until processing actually completes, double-submission rejected, amendment
without a reason, text length limit), `LaboratoryResultEndToEndTest` (the
full reception -> triage -> consultation -> diagnosis -> order ->
collection -> receipt -> processing -> RESULT PENDING -> worklist -> enter
-> validate -> submit -> PENDING VERIFICATION pathway, with full audit
trail and traceability assertions). `resultPendingForItem()`/
`resultPendingForHospital()` were added to `tests/Pest.php` as shared
helpers. Full Phase 1-4 regression suite re-run in memory-safe batches
after these additions.

## Laboratory result verification and authorization (Step 34)

No verification/authorization/release infrastructure existed anywhere
before this step (confirmed by audit) — every construct below is new,
built directly onto Step 33's `LaboratoryResult` rather than a second,
parallel entity.

### Status machine

```
RESULT_PENDING -> DRAFT -> PENDING_VERIFICATION -> UNDER_REVIEW -> VERIFIED -> AUTHORIZED
                                    ^                    |
                                    |                    v
                              (resubmit)      RETURNED_FOR_CORRECTION -> DRAFT
```

`AUTHORIZED` is the terminal state for this step — `LaboratoryResult::
isReadyForRelease()` is a purely derived reading of `status === AUTHORIZED`,
not a stored value, since nothing in this domain distinguishes a further
real-world state beyond authorization until release/reporting (Step 35).

A returned result re-enters *ordinary* draft editing
(`RETURNED_FOR_CORRECTION -> DRAFT`) and rejoins the exact same
`DRAFT -> PENDING_VERIFICATION` path every other draft uses via
`submit()` — there is no second, parallel resubmission mechanism.

Once `VERIFIED` or `AUTHORIZED`, `amend()` is the *only* way a value can
change (spec's "controlled amendment"): it forces the result all the way
back to `PENDING_VERIFICATION`, clearing `verified_by`/`verified_at`/
`verification_comment`/`authorized_by`/`authorized_at` from the live row —
full re-review, re-verification, and re-authorization is required. The
original verification is never deleted: it remains fully recoverable from
the audit log (`laboratory_result_verified`'s own `new_values`), matching
this app's established "audit log is the historical record" convention.

A subtle bug caught during implementation and fixed before release: `submit()`
originally used the generic `canTransitionTo(PENDING_VERIFICATION)` check,
but since `VERIFIED`/`AUTHORIZED` can *also* reach `PENDING_VERIFICATION`
(via `amend()`'s own reset), that made `submit()` wrongly reachable from
`AUTHORIZED` too. Fixed by checking the exact source status
(`status === DRAFT`) instead of the generic transition table for this one
action.

### Role separation

- **Result entry** (`laboratory-technician`, `laboratory-supervisor`,
  `hospital-admin`): enters and submits the value (`laboratory-results.update`/`.submit`).
- **Review and verification** (`laboratory-supervisor`, `hospital-admin`
  only — NOT `laboratory-technician`): `laboratory-results.review`/`.verify`/`.return`.
- **Authorization** (same roles as verification): `laboratory-results.authorize`
  — may be the *same person* who verified (spec only requires entry-vs-review
  separation, not verify-vs-authorize separation).
- **Controlled (post-verification) amendment** (`laboratory-supervisor`,
  `hospital-admin` only): `laboratory-results.amend_verified` — a
  deliberately stricter, separate permission from the existing
  pre-verification `laboratory-results.amend` (which every entry role still
  holds, unchanged from Step 33), since correcting already-verified/
  authorized clinical data is materially more sensitive than correcting an
  unreviewed draft.
- A brand-new `laboratory-supervisor` role was added — no distinct
  verifier/authorizer role existed before this step. It is a superset of
  `laboratory-technician`'s own capabilities (a supervisor may still enter
  results) plus the full review/verify/authorize/return/amend_verified set.

**Entry-vs-review separation is enforced by `LaboratoryResultPolicy`, not
the service** — `review()`/`verify()`/`returnForCorrection()`/`authorize()`
each additionally check `$result->entered_by !== $user->id`
(`LaboratoryResultPolicy::isNotTheEnteringUser()`), returning 403 Forbidden
regardless of whether the user otherwise holds every verification
permission (e.g. a supervisor who personally entered a value cannot review
their own entry — a different supervisor must). `LaboratoryResultService`
itself performs no such check; calling it directly bypasses the rule
entirely, matching this app's established convention that ownership/self-
action checks are a Policy responsibility, not a Service one.
`super-admin` bypasses this (and every other check) via the existing global
`Gate::before` — a known, accepted, pre-existing limitation of that
mechanism, not something new to this step.

### Verification eligibility

A result is only ever eligible for review once its underlying processing
has already reached `RESULT_PENDING` — enforced *by construction*
(`LaboratoryResult` rows only exist once created via
`LaboratoryResultService::initializeForItem()`), not by a separate runtime
check. Since the catalog has no panel/analyte structure (confirmed by
Step 33's own audit, still true), "all required components complete"
collapses to "this result's own single value is present and valid" —
already what `submit()`/`verifyResult()` both re-check.

### Critical results

No critical-result engine existed before this step, and none was built now
— per spec's own instruction to provide only "a safe foundation." The
existing `LaboratoryResult::FLAG_CRITICAL` abnormal flag (Step 33) is
reused as that foundation: a dedicated worklist filter, a dashboard count
("Critical Results Pending" — flagged critical and not yet authorized), and
a destructive-styled badge on both the worklist and review screen make a
critical result impossible to miss. No notification/acknowledgement
workflow was built; that remains explicitly out of scope.

## Permissions (Step 34)

`laboratory-results` gained: `review`, `verify`, `authorize`, `return`,
`amend_verified`. `hospital-admin` and the new `laboratory-supervisor` role
hold the full set; `laboratory-technician` gained nothing new (still
`.view`/`.update`/`.submit`/`.amend` only); `doctor` stays `.view`-only;
`nurse`/`receptionist` hold none.

## Authorization (Step 34)

`LaboratoryResultPolicy` mirrors its own Step 33 shape (permission +
hospital scope, hospital-admin the only role actually restricted by scope)
for every ability, plus the entry-vs-review self-check described above for
`review`/`verify`/`returnForCorrection`/`authorize`. `amendVerified` is a
distinct ability from `amend`, gated by the stricter permission.

## IDOR protection (Step 34)

Every new route enforces the identical hospital-scope chain
(`result -> item -> laboratoryOrder -> clinicalEncounter -> department ->
hospital_id`) already proven in Step 33 — a hospital-admin cannot review,
verify, return, authorize, or amend another hospital's result by guessing
its id.

## Audit logging (Step 34)

`laboratory_result_review_started`, `laboratory_result_verified`,
`laboratory_result_authorized`, `laboratory_result_returned_for_correction`,
`laboratory_result_amendment_requested` (only for a post-verification
amend), `laboratory_result_reverification_required` (only for a post-
verification amend), plus the existing `laboratory_result_status_changed`/
`laboratory_result_amended`/`laboratory_result_value_changed` events
reused for every transition in this step too.

## Concurrency (Step 34)

Every new service method (`startReview`/`verifyResult`/`authorizeResult`/
`returnForCorrection`, plus the extended `amend`) locks its row with
`lockForUpdate()` inside `DB::transaction()` before checking the current
status — proven by `LaboratoryResultVerificationConcurrencyTest`: two
racing review-starts, verifications, or authorizations on the same result
can never both succeed.

## Frontend (Step 34)

`laboratory/results/index` (extended) — new stat cards (Under Review,
Returned for Correction, Verified Today, Critical Results Pending) and an
abnormal/critical filter, alongside Step 33's existing ones.
`laboratory/results/show` (extended) — branches on status: the editable
draft form (Step 33, now also reachable from `RETURNED_FOR_CORRECTION`,
with a banner showing the return reason/notes) versus a read-only review
view with Start Review / Verify (`VerifyResultDialog`, an explicit
confirmation with patient/sample/test/result summary) / Return for
Correction (`ReturnForCorrectionDialog`, reason + conditional notes) /
Authorize (`AuthorizeResultDialog`, explicit confirmation) actions, plus a
"ready for release" banner once authorized. The existing `AmendResultDialog`
(Step 33) is reused as-is for the new controlled-amendment case — no new
component was needed since the route/action name and dialog UI are
identical; only the controller-side permission gate (`amend` vs
`amend_verified`) differs based on the result's current status.

## Billing / NHIF compatibility (Step 34)

Unchanged — no billing/NHIF fields or logic were introduced or touched.

## Testing (Step 34)

`tests/Feature/Laboratory/`: `LaboratoryResultVerificationTest` (full
review->verify->authorize sequence, each transition individually
unskippable, return-for-correction->correct->resubmit->re-verify cycle,
controlled amendment from both VERIFIED and AUTHORIZED clearing
verification metadata while preserving history in the audit log,
pre-verification amendment unaffected, immutability after authorization),
`LaboratoryResultVerificationAuthorizationTest` (guest redirect,
receptionist/nurse/technician zero access, clinician view-only, supervisor
full lifecycle, hospital-admin scoping, super-admin bypass),
`LaboratoryResultRoleSeparationTest` (a supervisor cannot review/verify/
return their own entered result; a different supervisor can; the same
supervisor may both verify and authorize; the guard lives in the policy,
not the service), `LaboratoryResultVerificationIdorTest` (cross-hospital
access denied for every verification action and for amending a verified
result), `LaboratoryResultVerificationConcurrencyTest` (single-winner
review-start/verify/authorize races), `LaboratoryResultImmutabilityTest`
(ordinary update/submit rejected once verified or authorized over HTTP; a
technician cannot amend a verified result even though they can amend a
pending_verification one), `LaboratoryResultVerificationValidationTest`
(missing/invalid return reason, "other" requiring notes, missing amendment
reason, invalid verification-comment length, every invalid status
transition individually), `LaboratoryResultVerificationEndToEndTest` (the
full reception -> ... -> processing -> result entry -> submit -> worklist
-> review -> verify -> authorize -> ready-for-release pathway, plus the
full correction branch: return -> correct -> resubmit -> re-verify ->
re-authorize). `pendingVerificationForItem()`/`pendingVerificationForHospital()`
were added to `tests/Pest.php` as shared helpers. Full Phase 1-4 regression
suite re-run in memory-safe batches after these additions.

## Future integration boundary

Result release, laboratory report generation, PDF reports, patient result
delivery, and external result publishing are Step 35. Full analyzer/LIS/
HL7/ASTM result import and full critical-result notification/acknowledgement
workflows remain deferred beyond that. Radiology, Pharmacy, Billing, NHIF
claims, admissions, discharge, and AI diagnosis/interpretation remain out
of scope entirely.

## Laboratory result release and reporting (Step 35)

No release/report/PDF/notification infrastructure existed anywhere before
this step (confirmed by audit — no `laboratory_reports`/`result_releases`
table, no PDF package in `composer.json`, no `app/Notifications` directory,
no patient-portal architecture). Everything below is new, built directly
onto Step 34's `LaboratoryResult` and its policy/service/controller — no
duplicate release/report/authorization system was created.

### Release: one more status on the same LaboratoryResult row

```
AUTHORIZED -> RELEASED -> (amendment) -> PENDING_VERIFICATION
```

`released_by`/`released_at`/`release_comment` live directly on
`laboratory_results`, mirroring `verified_by`/`authorized_by`'s own
precedent exactly — release is still a **per-test** action, the same
granularity every action has had since Step 33 (one specimen may carry
several independently-verified/authorized/released tests).

A real bug was caught and fixed during implementation, the same shape as
Step 34's own: `submit()` and `release()` must check the *exact* source
status (`DRAFT`, `AUTHORIZED`) rather than the generic `canTransitionTo()`
table, since `PENDING_VERIFICATION`/`RELEASED` are now each reachable from
more than one source status for different legitimate reasons (submission
vs. amendment-triggered reset).

### The report is per ORDER, not per result — a deliberate granularity split

This is the central design decision of this step. Verification/
authorization/release all stayed at the per-test granularity Steps 33-34
established. But the *document* a clinician actually wants is the whole
order's results together (spec Part 12-13's own "CBC + Blood Group on one
sample, Urinalysis on another — never mix results from different samples,
but do show every sample in one report" example). So:

- `LaboratoryResult.status` reaching `RELEASED` is a per-test fact.
- `LaboratoryReport` is a new table, one row per **released version of an
  order's report** — created/regenerated by `LaboratoryReportService::
  generateForOrder()` every time a release happens, snapshotting **every
  currently-RELEASED result on that order** (not just the one just
  released) into a `results_snapshot` JSON column, grouped by sample in the
  UI so results from different specimens are never mixed.

This is the **only new table** this step introduces (spec Part 40
explicitly warns against creating all three of `laboratory_result_releases`/
`laboratory_reports`/`laboratory_report_versions` reflexively) — report
versioning and the "release ledger" are the same concept here, so one table
serves both.

### Why the snapshot is JSON, not a second relational table

`results_snapshot` freezes exactly what was released — value, unit,
reference range, abnormal flag, comment, and the verifier/authorizer/
releaser's names — at the moment a report version was generated. A JSON
column was chosen over a `laboratory_report_results` join table because
this data is never queried relationally, only ever displayed as-is,
mirroring the catalog's own `result_options` and `AuditLog`'s own
`old_values`/`new_values` precedent. Patient/order/sample header
information is deliberately **not** snapshotted — it's read live via
Eloquent relations at render time, since (unlike a result's own
catalog-derived interpretation fields) demographic/order data isn't the
kind of thing that legitimately changes underneath a historical document.

### Report versioning and amendment-after-release

```
Report v1 (active) --release of a corrected result--> Report v2 (active)
                                                   \\-> v1 becomes superseded
```

Amending an **already-released** result (`LaboratoryResultService::amend()`,
extended once more) resets it fully back to `PENDING_VERIFICATION` —
clearing `released_by`/`released_at`/`release_comment` from the live row,
exactly as it already cleared verification/authorization metadata in Step
34 — and **immediately** marks the order's current report `superseded`
(`LaboratoryReportService::supersedeActiveForOrder()`, called by the
controller) rather than waiting for a new version to actually exist: a
report known to contain a value under correction must never keep
presenting as current. The superseded version's own snapshot is never
touched — it remains permanently viewable exactly as it was released,
satisfying "never silently overwritten" at both the result-status level
*and* the document level. Once the correction completes the full
re-verify/re-authorize/re-release cycle, a new report version is generated
and becomes active.

### Report numbering and version numbering

`report_number` (`LAB-RPT-2026-000001`) uses the exact same
`GeneratesBusinessNumber` trait every other business number in this app
uses — `lockForUpdate()` + last-value-plus-one inside a transaction, never
`count()+1` or `MAX(id)+1`. The per-order **version** number uses the same
discipline manually (`lockForUpdate()->max('version')`), backstopped by a
`unique(laboratory_order_id, version)` database constraint exactly like
every other concurrency-sensitive counter in this app.

### PDF/printing: no new dependency

No PDF library existed in this codebase, and none was added — the user
chose the browser print-to-PDF approach over installing a new Composer
dependency (`barryvdh/laravel-dompdf` or similar), consistent with this
project's "no dependency changes without approval" convention. One React
template (`laboratory/reports/show.tsx`) serves browser preview, printing,
and downloading alike — "Print" and "Download" both call `window.print()`
after a dedicated server-side round-trip stamps `printed_by`/`printed_at`
or `downloaded_by`/`downloaded_at` first, mirroring the exact pattern
`LaboratorySampleController::label()` already established for specimen
labels in Step 31. "Download" is the browser's own native "Save as PDF" in
that same print dialog — there is no server-generated PDF file. The report
page bypasses `AppLayout` entirely (no sidebar/nav on a clinical document),
also matching the label page's own precedent.

### Patient history integration — no duplicate table

Released results become visible on the patient's existing clinical
timeline (`ClinicalHistoryService`/`ClinicalHistoryEncounterResource`,
Step 26) via the encounter's own `laboratoryOrders.items.result` chain —
no new laboratory-history table was created. Only results whose `status`
is exactly `RELEASED` are ever surfaced there
(`ClinicalHistoryEncounterResource::releasedLaboratoryResults()`); a draft,
pending, under-review, verified-but-not-yet-authorized, or authorized-but-
not-yet-released result never appears as a "final result" on this timeline,
satisfying spec Part 23/29 precisely. Each entry links straight to its
order's active report.

### Clinician/patient access boundary

Authorized clinicians reach a released report through the existing
authentication + `LaboratoryReportPolicy` (permission + hospital scope) —
never an unrestricted URL. No patient portal exists in this app at all, so
none was built here either (explicitly out of scope per spec Part 25);
every report route already requires staff authentication, which is itself
the "not exposed publicly" boundary the future patient portal will need to
replace, not extend.

### Notifications: explicitly deferred

No notification infrastructure (`app/Notifications`, SMS/email/queue
integration) exists anywhere in this codebase. None was built for this
step either — per spec Part 26's own conditional instruction ("if an
existing system exists, integrate; do not introduce a new architecture"),
and the explicit Strict Scope Boundary excluding "Advanced SMS/WhatsApp
Result Delivery." A future step can safely add "Your laboratory report is
available" style notifications once a real notification channel exists.

## Permissions (Step 35)

`laboratory-results` gained `release`. A new `laboratory-reports` module
was added: `view`, `print`, `download` — deliberately its own permission
group (mirroring `laboratory-benches`/`laboratory-categories`'s own
precedent of small, adjacent-but-distinct permission groups) since a report
is a genuinely separate artifact/URL surface from the result it's derived
from. `hospital-admin` and `laboratory-supervisor` hold the full release +
report set; `doctor` gets `laboratory-reports.view/.print/.download` (a
clinician needs to reach and use released reports) but not `.release`;
`laboratory-technician` gets `laboratory-reports.view` only (traceability,
not action); `nurse`/`receptionist` hold neither.

## Authorization (Step 35)

`LaboratoryResultPolicy::release()` mirrors every other ability's shape
(permission + hospital scope). No entry-vs-release separation is required
by spec — only the entry-vs-review boundary from Step 34 is a hard rule.
`LaboratoryReportPolicy` (new) is the same permission + hospital-scope
shape, reached through `report->laboratoryOrder->clinicalEncounter->
department->hospital_id`.

## IDOR protection (Step 35)

Every report route enforces the identical hospital-scope chain already
proven throughout Steps 33-34 — a hospital-admin cannot view, print, or
download another hospital's report, nor release another hospital's result,
by guessing its id.

## Audit logging (Step 35)

`laboratory_result_released`, `laboratory_result_amended_after_release`,
`laboratory_report_generated`, `laboratory_report_reissued` (only when a
new version replaces an existing active one), `laboratory_report_superseded`,
`laboratory_report_printed`, `laboratory_report_downloaded`, plus the
existing `laboratory_result_status_changed`/`laboratory_result_amended`/
`laboratory_result_value_changed`/`laboratory_result_reverification_required`
events reused for release-triggered transitions too.

## Concurrency (Step 35)

`release()` locks the result row with `lockForUpdate()` inside
`DB::transaction()`; a second concurrent release attempt sees the
already-`RELEASED` status and receives the specific "This result has
already been released." message (spec Part 7's own literal example), never
a duplicate release. `generateForOrder()` locks both the previous-active
report row and the per-order version counter, backstopped by the
`unique(laboratory_order_id, version)` constraint.

## Frontend (Step 35)

`laboratory/results/index` (extended) — new stat cards (Released Today,
Reports Generated Today, Amended Reports), a "Report" link on released
rows. `laboratory/results/show` (extended) — a "Release Result" action
(`ReleaseResultDialog`, explicit confirmation with patient/sample/test/
result/authorizer summary) once authorized, a "Released" banner with a
"View Report" link once released; the existing `AmendResultDialog` is
reused as-is for a released result too (same route, same
`amend_verified` permission gate, no new component needed).
`laboratory/reports/show` (new) — the printable/downloadable report
template: hospital branding (via the existing centralized `brand.ts`/
`BrandMark`, never re-invented), patient/order/specimen information read
live, results grouped by sample, verification/authorization/release
metadata, a report-history section listing every version with links.
`patients/clinical-history` (extended) — a new "Laboratory Results" section
per encounter, released results only, each linking to its report.

## Billing / NHIF compatibility (Step 35)

Unchanged — no billing/NHIF fields, claims, or settlement logic were
introduced or touched. `routes/api.php` remains untouched; every new route
is in `routes/web.php`.

## Testing (Step 35)

`tests/Feature/Laboratory/`: `LaboratoryResultReleaseTest` (release stamps
fields and generates a report, release-not-authorized rejected,
double-release rejected, report version bumping and superseding,
two-samples-one-order correctly grouped, amendment-after-release clearing
metadata and superseding the report, full correct-and-re-release cycle
producing a new version, duplicate report row rejected by the database),
`LaboratoryResultReleaseAuthorizationTest` (guest redirect, receptionist/
nurse/technician cannot release, technician can view but not print/download
a report, doctor can view/print/download but not release, supervisor can
release, hospital-admin scoping, super-admin bypass),
`LaboratoryReportIdorTest` (cross-hospital report access denied for view/
print/download, cross-hospital release denied),
`LaboratoryResultReleaseConcurrencyTest` (single-winner release races,
concurrent report regeneration never produces two active versions),
`LaboratoryResultReleaseImmutabilityTest` (ordinary update/submit/verify/
authorize all rejected once released, a technician cannot amend a released
result, a superseded report's snapshot never changes),
`LaboratoryResultReleaseValidationTest` (every invalid release-source
status individually, release-comment length limit), `LaboratoryResultReleaseEndToEndTest`
(the full reception -> ... -> release -> report generation -> patient
history -> clinician view/print/download pathway, plus the full
amendment-after-release branch producing a new report version). Full
Phase 1-4 regression suite re-run in memory-safe batches after these
additions.

## Future integration boundary

Full patient portal result access, advanced SMS/WhatsApp result delivery,
external result publishing (HL7/ASTM), analyzer integration, NHIF claims,
and billing settlement remain out of scope entirely, per this step's own
explicit strict scope boundary.

**STEP 35 COMPLETE — laboratory workflow has reached: Laboratory Result ->
Verified -> Authorized -> Ready for Release -> Released -> Laboratory
Report -> Report Available -> Patient History -> Authorized Clinician
Access -> Print/Download.**

## Laboratory Test Catalog Seeder (Step 29A)

A retroactive seed-data step: populates the Step 29 catalog
(`laboratory_test_categories` and `laboratory_tests`) with a realistic
starter set of tests so the Step 30-35 order/sample/processing/result/report
workflow can be exercised and demonstrated without hand-creating catalog
rows first. No new tables were created and no existing catalog schema
changed — this step is data-only.

### Seeder

`database/seeders/LaboratoryTestSeeder.php`, registered in
`DatabaseSeeder::run()` after `MedicationSeeder`. Run it standalone with:

```
php artisan db:seed --class=LaboratoryTestSeeder
```

or as part of a full rebuild — **dev/test only**, since `migrate:fresh`
drops all data:

```
php artisan migrate:fresh --seed
```

### Idempotency and existing-data safety

Both categories and tests are written with
`updateOrCreate(['code' => ...], [...])`, keyed on each table's own unique
`code` column — never `count()+1` or a random code. Running the seeder
any number of times leaves the same 9 categories and 53 tests in place,
only re-applying the configured field values; it never truncates, deletes,
or touches `laboratory_orders`, `laboratory_order_items`,
`laboratory_samples`, `laboratory_processing`, `laboratory_results`, or
`laboratory_reports`. A test's `code` becomes immutable the moment any
order references it (`LaboratoryTest::isReferenced()`,
`LaboratoryTestService::update()`), so re-running this seeder against a
hospital with real order history is safe: it can only refresh the
non-code configuration of a test already in production use, and it will
never introduce a duplicate code. No audit log records are created for
seeded rows, matching the existing precedent set by `MedicationSeeder` and
`DiagnosisCodeSeeder`.

### Categories seeded

Hematology (`HEMATOLOGY`), Clinical Chemistry (`CHEMISTRY`), Microbiology
(`MICROBIOLOGY`), Parasitology (`PARASITOLOGY`), Serology (`SEROLOGY`),
Immunology (`IMMUNOLOGY`), Blood Bank (`BLOOD_BANK`), Urinalysis
(`URINALYSIS`), Stool Examination (`STOOL`).

### No panel/component modeling

The catalog remains flat (confirmed unchanged since Step 29/33/34/35 — no
`RESULT_TYPES` value for a panel/composite exists). A clinically-composite
test such as "Full Blood Count" is therefore seeded as its own independent
constituent analytes (`HB`, `PCV`, `WBC`, `RBC`, `PLT`, `MCV`, `MCH`,
`MCHC`, `ESR`, `PBF`) rather than one composite catalog row — a doctor
already orders several catalog tests together in one `LaboratoryOrder`
(Step 30's `test_ids: number[]`), so no standalone "FBC" code is needed.
The spec's own illustrative "Urine Routine Examination"/"Urine
Microscopy" and "Stool Routine Examination"/"Stool Microscopy" pairs are
each consolidated into one catalog entry (`URINALYSIS`, `STOOL_EXAM`)
rather than seeded as near-duplicate codes, since a real urinalysis/stool
exam is reported as one combined result in practice.

### Sample/specimen mapping

`specimen_type`/`specimen_container` reuse the existing plain validated
string columns on `laboratory_tests` (`LaboratoryTest::SPECIMEN_TYPES`/
`SPECIMEN_CONTAINERS`, Step 29's own deliberate design) — no new lookup
table was created. Representative mappings:

| Test group | Example codes | Specimen type | Container |
|---|---|---|---|
| Hematology analytes | `HB`, `PCV`, `WBC`, `RBC`, `PLT`, `MCV`, `MCH`, `MCHC`, `ESR`, `PBF` | `whole_blood` | `edta_tube` |
| Chemistry (glucose) | `RBG`, `FBG` | `plasma` | `fluoride_oxalate_tube` |
| Chemistry (HbA1c) | `HBA1C` | `whole_blood` | `edta_tube` |
| Chemistry (renal/electrolytes/LFT/lipids) | `UREA`, `CREAT`, `NA`, `K`, `CL`, `CA`, `TP`, `ALB`, `TBIL`, `DBIL`, `ALT`, `AST`, `ALP`, `GGT`, `CHOL`, `HDL`, `LDL`, `TG` | `serum` | `plain_tube` |
| Serology/Immunology | `HIV`, `HBSAG`, `HCV`, `VDRL`, `RPR`, `CRP`, `RF` | `serum` | `plain_tube` |
| Blood bank | `ABO`, `RH`, `BLOODGRP`, `XMATCH` | `whole_blood` | `edta_tube` |
| Malaria | `MALARIA` | `whole_blood` | `edta_tube` |
| Blood culture | `BLD_CULT` | `whole_blood` | `sterile_container` |
| Urine (culture/analysis/pregnancy) | `URINE_CULT`, `URINALYSIS`, `PREG_URINE` | `urine` | `sterile_container` |
| Stool | `STOOL_CULT`, `STOOL_EXAM`, `STOOL_OB` | `stool` | `sterile_container` |
| Sputum | `SPUTUM_EXAM`, `SPUTUM_AFB` | `sputum` | `sterile_container` |
| Swab microbiology | `GRAM_STAIN` | `swab` | `swab_transport_medium` |

This required-specimen metadata is what the doctor sees when selecting a
test on a laboratory order (Step 30); it is never confused with the
separate `laboratory_samples`/`laboratory_sample_items` actual-sample
record that laboratory/phlebotomy staff create during collection
(Step 31) — the catalog never stores a sample number, `collected_at`, or
`collected_by`.

### Result types

`positive_negative` (with the model's own hardcoded `['positive',
'negative']` default) is used for binary screening tests (`MALARIA`,
`SPUTUM_AFB`, `HIV`, `HBSAG`, `HCV`, `RF`, `PREG_URINE`, `STOOL_OB`, `RH`);
`qualitative` with explicit `result_options` is used for the
reactive/non-reactive syphilis screens (`VDRL`, `RPR`); `enum` with
explicit `result_options` is used for multi-value classifications (`ABO`:
A/B/AB/O, `BLOODGRP`: the 8 ABO+Rh combinations); `numeric` is used for
every measured analyte (with `unit`/`reference_range` populated); `text`
is used for narrative/descriptive results (culture and sensitivity
reports, Gram stain, sputum/stool/peripheral-blood-film examinations,
crossmatch, urinalysis, VDRL/RPR are the exception noted above). No result
entry, verification, or release logic was touched by this step.

### Hospital scoping

`laboratory_tests` has no `hospital_id` column — it is shared,
hospital-agnostic reference data. `department_id` is deliberately left
`null` on every seeded row: a "Laboratory" department only exists
per-hospital (`DepartmentSeeder` seeds it per `Hospital`), so linking
seeded master data to one specific hospital's department row would
misrepresent shared catalog data as belonging to only that hospital.

### Pricing / NHIF boundary

No prices or NHIF codes were seeded — `price` and `nhif_code` remain
`null` on every seeded row, preserving the existing separation from any
future Service Catalog/Price List/Billing/NHIF integration (Step 29's own
established boundary, untouched here).

### Testing

`tests/Feature/Laboratory/LaboratoryTestSeederTest.php`: seeder populates
the catalog, running it twice is idempotent (identical counts, no
duplicates), every category/test `code` is unique, every seeded test has a
valid `category_id`/`specimen_type`/`specimen_container`/`result_type` and
a `null` `department_id`, representative specimen/container mappings match
the table above, a seeded active test can be ordered through the existing
`LaboratoryOrderService` (Step 30) unchanged, and a seeded test switched to
`inactive` is correctly rejected by that same, pre-existing order-creation
validation. Full Phase 1-4 regression suite re-run to confirm the seeder
introduces no regressions.

**STEP 29A COMPLETE — the laboratory test catalog now ships with a
realistic starter set of categories and tests, ready for the existing
Step 30-35 laboratory workflow to consume unchanged.**

## End-to-End Workflow Audit (Phase 4)

A full audit of the Sample Collection -> Processing -> Result Entry ->
Verification -> Authorization -> Release chain (Steps 30-35) against a
fresh, independent specification of the same workflow. Every component
was found already implemented, architecturally sound, and covered by
existing tests: one order with many order items (Step 30), one sample
satisfying many compatible order items via `laboratory_sample_items`
(Step 31), one processing record per (sample, item) pair carrying
technician/bench/start/complete timestamps (Step 32), one result per item
with catalog-driven input types (Step 33), policy-layer entry/verification
role separation (Step 34), and per-order versioned report generation on
release (Step 35). No duplicate architecture, no internal API routes, and
no NHIF/billing scope creep were found.

A follow-up fix closed a genuine UX/integration gap surfaced by a
subsequent report: the Accept/Reject-during-processing actions
(`LaboratoryProcessingService::acceptSample()`/`rejectSample()`, already
fully implemented and tested since Step 32) were only ever surfaced on the
standalone `laboratory/processing` worklist — the Sample detail
(`laboratory/samples/show`) and Collection detail
(`laboratory/collection/show`) pages, where a RECEIVED specimen naturally
lands after Step 31's own workflow, showed no next action at all once
Receive was clicked, making the workflow look like it stopped at
"Register -> Label -> Reject" with no way forward. Fixed by (1) eager-
loading `processingRecords` in `LaboratorySampleController` so these two
pages can see the specimen's rolled-up processing status, and (2) adding
the same Accept/Reject-during-processing actions already used on the
Processing pages (reusing `RejectProcessingDialog` and the
`laboratory.processing.accept`/`.reject` routes as-is — no new routes,
controllers, or permissions). While fixing this, a second, previously
undetected defect was found and fixed: `canAcceptSpecimen()`/
`canRejectDuringProcessing()` gated only on the specimen's own status
(`received`), which never changes again after receipt — meaning "Accept"
stayed visible forever even after a specimen had already been accepted,
and "Reject" stayed visible even after every one of the specimen's tests
had reached RESULT_PENDING. Both helpers now also take the rolled-up
`processing_status`, and `LaboratoryProcessingService::rejectSample()`
gained a matching server-side guard (never trust the UI alone) blocking
rejection outright once any of the specimen's tests has already reached
result entry, with a proper row-locked transaction so this can't race
against a concurrent `completeProcessing()` call.

Two genuine test-coverage gaps named by the audit's own negative-test
checklist were also closed:
`LaboratorySampleAuthorizationTest` gained an explicit assertion that a
doctor cannot register or physically collect a specimen (the permission
already excluded doctors — `laboratory-samples.collect`/`.label` were
never granted to the `doctor` role — but no test asserted it directly),
and `LaboratorySampleValidationTest` gained an assertion that a sample's
`patient_id` is always derived server-side from its own order and can
never be supplied as client input, closing the "wrong patient's sample
attached to another patient's order" checklist item by construction. No
functional defects were found; no models, migrations, controllers,
policies, services, or frontend components required changes.
