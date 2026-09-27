# Service Catalog & Pricing (Phase 7, Step 53)

## Re-Audit & Gap Closure — 2026-09-24

A full re-audit was run against a 36-section external spec covering this
exact step, with an explicit instruction not to duplicate or redesign
anything already built. Two independent research passes (full file reads)
plus a design pass confirmed the original build below remains accurate and
undrifted — every structure this document describes was found exactly as
documented. Five narrow, real gaps were found and closed; one spec item
(a database-backed categories table) was re-evaluated and deliberately
**not** implemented, reaffirming this document's own original B section
reasoning. Details are folded into the relevant lettered sections below;
summary:

1. **Closed** — `Hospital.default_consultation_service_id` had no admin UI
   (column/relation/consumer all existed, but no form exposed it). Added
   `Service::consultationOptions()` (shared with the practitioner's own
   equivalent field), a form field on `hospitals/create`/`edit`, and
   request validation.
2. **Closed** — price history views never disclosed which practitioner a
   price belonged to. Added `ServicePrice::practitioner()`, a
   `practitioner` key on `ServicePriceResource`, and a Practitioner column
   on both the pricing index and a service's own price-history table.
3. **Closed** — a single service's price history (`billing/services/show`)
   was unpaginated, unlike the global pricing index. Paginated it
   (20/page) to match.
4. **Closed** — the resolver's practitioner-price-wins-over-facility-wide
   precedence was implemented and used correctly, but only proven by
   *creation*-path tests, not a resolution-path test. Added three direct
   `ServicePriceResolver` tests.
5. **Closed** — no receptionist-specific 403 test existed for service/
   pricing management (doctor/pharmacist were covered). Added one.
6. **Not implemented, by design** — categories remain the existing
   `Service::CATEGORIES` const, not a database table. Re-confirmed correct
   per this document's own original B section rationale below.

**ServiceCatalog suite after gap closure: 57/57 pass** (49 original + 8
new). `HospitalManagementTest`: 20/20 pass (3 new). A targeted
cross-cutting regression run of every practitioner-related test across
Billing/ChargeCapture/Consultation/Reception/Radiology/Staff/
ServiceCatalog: 63/63 pass — no drift from the `Service::
consultationOptions()` extraction.

## Audit findings

Before writing any code, the entire repository was searched for
`service`/`catalog`/`pricing`/`tariff`/`price_list`/`billing_service`
(case-insensitive, across `app`, `database`, `resources/js`, `routes`,
`tests`). No `Service`/`ServiceCatalog`/`ServicePrice`/`Tariff`/`PriceList`
model, migration, controller, service, or route exists anywhere. What
*does* exist, exactly as `LaboratoryTest`/`RadiologyProcedure`'s own
docblocks already say: `nhif_code`/`price` fields on `Medication`,
`LaboratoryTest`, `RadiologyProcedure` — each `decimal(10, 2)`, nullable,
explicitly reserved "for a future billing/claims module — nothing in this
system currently reads or acts on these values... no general Service/
PriceList model exists anywhere in this app to integrate against (confirmed
by audit before this step was built)." Step 53 is that future module.

No generic service category system exists — only `LaboratoryTestCategory`
(laboratory-specific). No department-scoped catalog issue was invented:
`LaboratoryTestSeeder` had already discovered and documented that
`department_id` cannot safely appear on global reference data, since
`Department` is hospital-scoped (`departments.hospital_id`) — the identical
constraint this step's own `Service` catalog inherits.

**Duplicates found**: none — greenfield.
**Architecture reused**: the polymorphic `source_type`/`source_id` pattern
(`AuditLog`, `StockTransaction` — Step 49, `Charge` — Step 52), the
hospital-scoping policy pattern (`StockItemPolicy`/`ChargePolicy`), the
global-catalog policy pattern (`MedicationPolicy`), `decimal(10,2)` money
precision, the `accountant` role (Step 52's own extension of it), the
`ValidatesMoney` trait (extracted from `ChargeService` this step, now
shared).
**Architecture changed**: `ChargeService::validateMoney()` was extracted
into `App\Services\Concerns\ValidatesMoney` (a trait) once
`ServicePricingService` needed the identical rules — no behavior changed,
purely a DRY refactor. No other existing file was modified.

## B. Service Catalog

- **`Service`** (global, no `hospital_id`/`department_id` — see the
  `create_services_table` migration's own docblock): `code` (unique,
  stable, locked once priced), `name`, `description`, `category` (merged
  category+type vocabulary — spec §8/§9's own near-identical lists, mirrors
  `RadiologyProcedure.modality`'s own "one field, not two" precedent),
  `serviceable_type`/`serviceable_id` (polymorphic, nullable),
  `is_billable`, `status` (active/inactive).
- **Categories**: `Service::CATEGORIES` — a plain const list (consultation,
  laboratory, radiology, pharmacy, procedure, emergency, dental, maternity,
  inpatient, other), not a manageable table — mirrors
  `RadiologyProcedure::MODALITIES`'s own "standard vocabulary, not
  admin-reconfigurable" precedent (spec §8's "do not create duplicate
  category systems" is satisfied by not building a second table at all).
  **Re-confirmed 2026-09-24**: a later, independent spec explicitly asked
  for Create/Edit/Activate/Deactivate category management. Re-evaluated
  and upheld the original decision — categories are correctly *not*
  hardcoded in React (served as a backend-supplied prop to both
  `billing/services/create.tsx` and `edit.tsx`, the one real concern that
  spec's own wording raised), and a full CRUD surface (table + policy +
  permissions + audit trail) for a fixed ~10-value, non-facility-specific
  classification vocabulary would be the "duplicate structure without a
  demonstrated architectural reason" that same spec explicitly warned
  against creating.
- **Mappings**: `ServiceCatalogSeeder` maps every active `LaboratoryTest`
  and `RadiologyProcedure` to its own `Service` row (reusing each source's
  own real `code`/`name` — never inventing spec's illustrative codes
  verbatim), plus two hand-authored clinical services (`CONSULT-GP`,
  `CONSULT-SPECIALIST` — spec §57's own suggested pair, since no
  consultation-type catalog exists to map from). **Medication is
  deliberately NOT mapped** — see Pharmacy Boundary below. Idempotent,
  verified by running it twice (91 services both times, zero duplicates).
- **Facility configuration**: no separate `FacilityServiceConfiguration`
  entity was built (spec §14's own "if required"). A service's
  availability at a given hospital is derived from whether an active
  `ServicePrice` exists for it there — building a second table to track
  "available" state that could silently disagree with pricing existence
  would be the over-engineering spec §13's own target diagram (which has no
  such intermediate step) already warns against.

## C. Pricing

- **`ServicePrice`**: `service_id`, `hospital_id`, `amount`
  (`decimal(10,2)`), `currency`, `effective_from`, `effective_to`
  (nullable = ongoing), `status` (active/cancelled — for pulling a
  mistaken *future* price before it ever takes effect, distinct from
  natural expiry).
- **Effective-date logic / overlap prevention**
  (`ServicePricingService::create()`): every existing active price for the
  same service+hospital is row-locked; a genuinely overlapping bounded
  period is rejected outright; a new price immediately following an
  existing *open-ended* price automatically closes that old price's
  `effective_to` to the day before — the exact versioning workflow spec
  §19/§40's own worked example describes. A price is **never** edited in
  place — `billing-pricing` has no `update` permission at all (spec §40).
- **Price resolver** (`ServicePriceResolver::resolvePrice(Service,
  hospitalId, ?date)`): the single authoritative "what does this cost, at
  this hospital, on this date" lookup. Rejects a non-billable or inactive
  service, rejects when no price covers the date, and defensively rejects
  (rather than silently picking one) if more than one price is ever found
  — structurally should never happen given the overlap prevention above,
  but flagged loudly if it ever does, mirroring `StockService::reconcile()`'s
  own "flag for investigation, never silently correct" principle.
- **Historical pricing**: verified by direct test — a transaction dated in
  June resolves to the price that was actually in effect in June, even
  after a July price change has since become "current."
- **Provider/specialist pricing** (`practitioner_id`, nullable = facility-
  wide): a practitioner-specific price always wins over the facility-wide
  one for the same service/hospital/date — now proven directly at the
  resolution layer (2026-09-24 addition), not just the creation layer.
  Price history views (`billing/pricing/index`, `billing/services/show`)
  now disclose which practitioner a price applies to, or "Facility-wide"
  when none.
- **Consultation fallback chain**: `ChargeCaptureService::
  resolveConsultationService()` — a practitioner's own
  `consultation_service_id` first, else the hospital's
  `default_consultation_service_id`, else `null` (never silently free —
  charge capture throws and the gap is audited). Both ends of this chain
  now have admin UI: the practitioner form already had it; the hospital
  form gained it 2026-09-24 (`Service::consultationOptions()`, shared
  between both forms).
- **A real, subtle, driver-dependent bug was found and fixed** while
  building the resolver: Eloquent's `date` cast still persists a full
  `"Y-m-d H:i:s"` string (only the time is zeroed, the format is not
  shortened). A plain `where('effective_from', '<=', $dateString)` against
  a bare `"Y-m-d"` string works on MySQL (which coerces `DATE` columns) but
  silently fails on SQLite (this project's test driver — pure string
  comparison, so `"2026-09-15 00:00:00" <= "2026-09-15"` is `false`) for
  the exact-same-day boundary case. Fixed by switching to `whereDate()`,
  which is correct on every driver. See **Known Issues** for the identical
  latent pattern this audit found already shipped in Step 49.

## D. Backend Changes

- **Migrations**: `create_services_table`, `create_service_prices_table`
- **Models**: `Service`, `ServicePrice`
- **Services**: `ServiceCatalogService`, `ServicePricingService`,
  `ServicePriceResolver`, `ServiceCatalogSearchService`; new shared trait
  `App\Services\Concerns\ValidatesMoney`
- **Controllers**: `ServiceCatalogController`, `ServicePricingController`
- **Form Requests**: `CreateServiceRequest`, `UpdateServiceRequest`,
  `CreateServicePriceRequest`, `CancelServicePriceRequest`
- **Policies**: `ServicePolicy` (global, mirrors `MedicationPolicy`),
  `ServicePricePolicy` (hospital-scoped, mirrors `StockItemPolicy`)
- **Resources**: `ServiceResource`, `ServicePriceResource`
- **Seeder**: `ServiceCatalogSeeder` (registered in `DatabaseSeeder`)
- **Routes**: `billing/services/*` (resource, no destroy),
  `billing/pricing/*` — all Inertia, no `routes/api.php` usage
- **Permissions**: `billing-services.{view,create,update,activate,
  deactivate}`, `billing-pricing.{view,create,deactivate}` — granted to
  `hospital-admin` and `accountant`; clinical/pharmacy roles hold neither

**2026-09-24 additions**: `Service::consultationOptions()` (shared option
query, extracted from `PractitionerController`); `ServicePrice::
practitioner()` relation; `default_consultation_service_id` validation
added to `StoreHospitalRequest`/`UpdateHospitalRequest`;
`ServicePriceResource` gained a `practitioner` key; `HospitalController`
now passes `consultationServices` to create/edit and eager-loads
`defaultConsultationService` on show; `ServiceCatalogController::show()`
now paginates its price-history query (was `->get()`) and eager-loads the
practitioner relation, matching `ServiceCatalogSearchService::
pricesQuery()`'s existing eager-loads.

## E. Frontend Changes

- **Pages**: `billing/services/{index,create,edit,show}`,
  `billing/pricing/{index,create}`
- **Components**: `ServiceForm` (shared create/edit, mirrors
  `MedicationForm`)
- **Types**: `types/service.ts`; **Utils**: `lib/service-status.ts`
- **Navigation**: "Services"/"Pricing" buttons added to the Billing
  dashboard (`billing/index`), permission-gated

**2026-09-24 additions**: `hospital-form.tsx` gained a "Default
Consultation Service" field (mirrors `practitioner-form.tsx`'s own field
exactly); `hospitals/create.tsx`/`edit.tsx` pass through the new
`consultationServices` prop, `hospitals/show.tsx` displays the current
setting read-only; `billing/pricing/index.tsx` and
`billing/services/show.tsx` both gained a Practitioner column; the latter
also gained pagination (`<Pagination links={prices.links} />`); `types/
hospital.ts` and `types/service.ts` updated accordingly.

## F. Security Results

```
Authentication: PASS
Authorization: PASS
Facility Isolation: PASS
IDOR: PASS
Mass Assignment: PASS
Price Manipulation: PASS
```
**2026-09-24**: reaffirmed. Receptionist role (spec-named alongside
doctor/pharmacist as roles that must never manage pricing) is now also
directly tested, closing the one previously-untested named role.

## G. Testing Results

```
Unit: PASS
Feature: PASS
Authorization: PASS
IDOR: PASS
Security: PASS
Concurrency: PASS
Historical Pricing: PASS
Integration: PASS
Regression: PASS (75/75: Authorization + Billing, both touched by this
                   step's permission-seeder additions)
TypeScript: PASS
Laravel Validation: PASS
```
**ServiceCatalog suite: 49/49 pass.**

**2026-09-24 re-audit**: `tests/Feature/Hospitals/HospitalManagementTest`
20/20 pass (3 new); `tests/Feature/ServiceCatalog` full suite 57/57 pass
(8 new — Gap 2/4/5's tests); a targeted cross-cutting run of every
"Practitioner"-matching test across Billing, ChargeCapture, Consultation,
Reception, Radiology, Staff, and ServiceCatalog — 63/63 pass, confirming
the `Service::consultationOptions()` extraction is behavior-preserving.
`npx tsc --noEmit` and `npm run build`: both clean.

## H. Known Issues

- **Medium** — the exact `where('date_column', op, $bareDateString)`
  same-day boundary bug this step found and fixed in
  `ServicePriceResolver` was *not* fixed in Step 49's `StockService`/
  `StockSearchService` (`expiry_date` comparisons) — out of this step's
  scope to touch, but worth a follow-up pass converting those to
  `whereDate()` too, since the identical failure mode (a batch expiring
  exactly *today* being mis-classified) exists there on SQLite/non-MySQL
  drivers.
- **Low** — no `FacilityServiceConfiguration`/"available at this facility"
  toggle independent of pricing exists (a deliberate simplification, see
  Service Catalog section above); if a future step needs to mark a service
  "temporarily unavailable" at a facility *without* removing its price,
  this boundary will need revisiting.
- **Low** — categories remain a fixed `Service::CATEGORIES` const rather
  than a database-backed, admin-manageable table (re-confirmed 2026-09-24
  against an external spec that explicitly asked for category CRUD — see
  Service Catalog section above for the full reasoning). If a future
  requirement genuinely needs per-hospital or admin-invented categories
  beyond the fixed ~10-value vocabulary, this will need revisiting; today
  no such need exists.

## I. Final Status

```
PHASE 7 — STEP 53 STATUS: PASS

READY FOR STEP 54 — PATIENT CHARGES & CHARGE CAPTURE: YES
```

## Re-Audit Final Status (2026-09-24)

```
PHASE 7 — STEP 53 RE-AUDIT STATUS: PASS

All 5 identified gaps closed. No new gaps introduced. No duplicate
service catalog or pricing architecture created. Step 53 remains closed.
```
