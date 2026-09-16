# Service Catalog & Pricing (Phase 7, Step 53)

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

## E. Frontend Changes

- **Pages**: `billing/services/{index,create,edit,show}`,
  `billing/pricing/{index,create}`
- **Components**: `ServiceForm` (shared create/edit, mirrors
  `MedicationForm`)
- **Types**: `types/service.ts`; **Utils**: `lib/service-status.ts`
- **Navigation**: "Services"/"Pricing" buttons added to the Billing
  dashboard (`billing/index`), permission-gated

## F. Security Results

```
Authentication: PASS
Authorization: PASS
Facility Isolation: PASS
IDOR: PASS
Mass Assignment: PASS
Price Manipulation: PASS
```

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

## I. Final Status

```
PHASE 7 — STEP 53 STATUS: PASS

READY FOR STEP 54 — PATIENT CHARGES & CHARGE CAPTURE: YES
```
