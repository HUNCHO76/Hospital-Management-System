# Patient Charges & Charge Capture (Phase 7, Step 54)

## Audit findings

Before writing any code, the entire repository was searched for
`charge`/`billing`/`capture`/`invoice` across `app`, `database`,
`resources/js`, `routes`, `tests`. `Charge`/`ChargeService` (Step 52) and
`Service`/`ServicePrice`/`ServicePriceResolver` (Step 53) already exist and
are fully authoritative — this step **extends** both, never introduces a
`NewCharge`/`BillingCharge`/parallel capture system (spec §1's own explicit
instruction). No `charge_id`/`billing_status`/`is_billed` field exists
anywhere on `LaboratoryOrderItem`, `RadiologyOrderItem`, `DispensingItem`, or
`PrescriptionOrderItem` — clean to design against, no risk of duplicating an
existing signal.

**Duplicates found**: none — greenfield integration work.
**Architecture reused**: `ChargeService::create()`/`post()`/`void()`/
`cancel()` (Step 52, unchanged in shape, extended to accept
`service_id`/`service_code_snapshot`/`notes`), `ServicePriceResolver::
resolvePrice()` (Step 53, called unmodified), the `(source_type, source_id)`
polymorphic pointer (Step 52), the `GeneratesBusinessNumber` trait (now also
powering `Charge.charge_number`), the snapshot-at-creation-time pattern
(`LaboratoryOrderItem.test_code`/`test_name`,
`PrescriptionOrderItem.medication_code_snapshot`/`medication_name_snapshot`).
**Architecture changed**: `ChargeService::create()`'s accepted array grew
three new optional keys; no existing behavior for a caller that omits them
changed at all (verified by Step 52's own regression suite passing
unmodified).

## Decision: a single `ChargeCaptureService`, not per-module ad-hoc calls

Spec §23 requires "a centralized `ChargeCaptureService`... no controller or
service should create Charges by hand outside this service." Every capture
path — Laboratory, Radiology, Pharmacy (once unblocked), and manual — funnels
through one of `ChargeCaptureService`'s public methods, which all share one
private write path (`createFromResolvedPrice()`) that does the actual
duplicate-check, row-lock, and `ChargeService::create()` call. A price is
never computed or a `Charge` array never built anywhere else.

## Decision: charge at order time, reuse existing cancellation for reversal

Spec §10 asks the trigger point to follow "the existing domain rules" rather
than an arbitrary new workflow step. Auditing `LaboratoryOrderService`/
`RadiologyOrderService` (Step 30/36) found each already creates its
`*OrderItem` row ACTIVE/ORDERED synchronously with order placement — the
moment lab/imaging resources are actually committed — inside
`addItem()`, called from both `create()` and `addNewItems()`. Charge capture
hooks into that single `addItem()` method in each service, so both order
creation and later item-addition are covered by one integration point.
Reversal hooks into the two cancellation methods each service *already
had* — `cancel()` (order-level cascade) and `cancelItem()` (single item) —
via `ChargeCaptureService::voidForSource()`, which voids a still-PENDING
charge or cancels an already-POSTED one, and is a silent no-op if no charge
was ever captured (a missing price must never make a cancellation fail).

## Decision: a billing-configuration gap never blocks a clinical order

Every automated capture method (`captureFromLaboratoryOrderItem()`,
`captureFromRadiologyOrderItem()`, `captureFromDispensingItem()`) catches its
own `ValidationException` — no `Service` mapped yet, no price configured for
this hospital/date, no `Medication.price` set — and returns `null` instead of
throwing, recording a `charge_capture_skipped` audit entry so the gap is
still traceable and never silent. This mirrors this codebase's own repeated
"urgent care is never blocked" precedent (`LaboratoryOrderService`/
`RadiologyOrderService` never require a diagnosis first, for the identical
reason). `captureManual()` is the deliberate exception — a human directly
initiating a billing action gets the real, synchronous error immediately
(spec §7's "return a clear business error").

## Decision: duplicate protection is idempotent by design, not just checked

Spec §16/§25 requires a source event can never produce two charges. The
`(source_type, source_id)` column pair already carried a plain index (Step
52); this step replaces it with a UNIQUE constraint (migration
`add_charge_capture_fields_to_charges_table`). Every source-based capture
looks up an existing charge for that pair under `lockForUpdate()` first and
returns it unchanged if found; if a concurrent request still races past that
check, the database's own constraint rejects the loser's insert, which is
caught (portable `SQLSTATE '23000'` check, not a driver-specific message
match) and treated as success — returning the winner's charge — never
surfaced as an error to the caller. Verified directly by test
(`ChargeCaptureTest`: "a source item can never produce two charges").

## Decision: Pharmacy prices from `Medication.price`, never the Service catalog

Reaffirms Step 53's own boundary decision. `captureFromDispensingItem()`
resolves its price from `Medication.price` directly (rejecting, not
capturing, if null) and leaves `service_id` null on the resulting charge —
`service_code_snapshot`/`description` reuse the already-snapshotted
`PrescriptionOrderItem.medication_code_snapshot`/`medication_name_snapshot`
(Step 46) rather than re-reading the live catalog row. One `DispensingItem`
row is always exactly one fulfillment event (Step 48's own architecture), so
charging per `DispensingItem` id is inherently the correct granularity for
partial dispensing — no separate "remaining quantity" bookkeeping is needed.

This integration point was briefly held off (not designed around) at a peer
session's explicit request — a session concurrently working in this
repository on Step 51 (Pharmacy Reporting) asked to wait on editing
`app/Services/DispensingService.php` until their own regression suite, which
asserts on `DispensingService::complete()`'s exact current behavior, had
finished running against it. That session confirmed clearance once its own
work completed; `captureFromDispensingItem()` is now wired into
`DispensingService::complete()` exactly like the Laboratory/Radiology hooks,
verified by the full Dispensing/Stock regression suite (42/42) plus two new
end-to-end tests (priced medication captures a charge; unpriced medication
dispenses normally with a `charge_capture_skipped` audit entry, never a
blocked dispensing event).

## Decision: manual capture requires a catalog service, never a free-typed price

Spec §26-27: "must require: patient, service, quantity, reason/reference"
and must not let a user type a `unit_price` directly. This **upgrades**
Step 52's existing manual-charge flow (`ChargeController`/
`CreateChargeRequest`/`billing/charges/create.tsx`) rather than duplicating
it: `unit_price`/`discount_amount` are no longer accepted fields at all;
`service_id` (required, resolved via `ServicePriceResolver`, exactly like an
automated capture) replaces them. `description` is repurposed to always hold
the resolved service's own name (auto-set, never client-supplied); a new
`notes` field carries the now-required reason/reference text spec §26 asks
for. No `source_type`/`source_id` is ever recorded for a manual charge — it
has no source event to be idempotent against.

## Decision: no new `source_item_id` field

Spec's illustrative charge-field list suggests one alongside
`source_type`/`source_id`. The existing two-field pointer, kept at
item-level granularity (e.g. `source_type = LaboratoryOrderItem::class`),
already fully captures item-level traceability — a third field would be a
redundant near-duplicate, the same pattern this whole build has repeatedly
avoided (`service_code_snapshot` instead of a redundant
`service_name_snapshot`, no second `FacilityServiceConfiguration` table in
Step 53, etc).

## What this step builds

```
LaboratoryOrderService::addItem()   -\
RadiologyOrderService::addItem()     |-> ChargeCaptureService -> ChargeService::create() -> Charge (PENDING)
DispensingService::complete()        |
ChargeController::store() (manual) -/

LaboratoryOrderService::cancel()/cancelItem()  -\
RadiologyOrderService::cancel()/cancelItem()    |-> ChargeCaptureService::voidForSource() -> Charge (VOIDED/CANCELLED)
```

- **Migration**: `add_charge_capture_fields_to_charges_table` — adds
  `charge_number` (unique, nullable at the DB level only), `service_id`
  (nullable FK, `restrictOnDelete`), `service_code_snapshot`, `notes`;
  replaces the plain `(source_type, source_id)` index with a UNIQUE
  constraint.
- **`Charge`**: gains `GeneratesBusinessNumber` (`CHG-YYYY-######`, a
  `creating` hook, auto-generated unconditionally), a `service()` relation.
  No other model changed.
- **`ChargeService::create()`**: accepts three new optional keys
  (`service_id`, `service_code_snapshot`, `notes`); every existing caller
  that omits them is unaffected (they persist as `null`, matching prior
  behavior exactly).
- **`ChargeCaptureService`** (new): `captureFromLaboratoryOrderItem()`,
  `captureFromRadiologyOrderItem()`, `captureFromDispensingItem()`,
  `captureManual()`, `voidForSource()`.
- **`LaboratoryOrderService`/`RadiologyOrderService`**: `addItem()` now also
  calls the matching capture method; `cancel()`/`cancelItem()` now also call
  `voidForSource()`. `cancelItem()` on both is now wrapped in its own
  `DB::transaction()` (it previously issued a single bare `update()`) so the
  item cancellation and its charge reversal commit or roll back together.
- **`DispensingService::complete()`**: after each positively-dispensed
  item's `StockService::allocateFefo()` call, inside the same outer
  transaction, now also calls `captureFromDispensingItem()` with the
  already row-locked `PrescriptionOrderItem` (threaded through
  `validatedItems()`'s return shape as a new `order_item` key, avoiding a
  redundant re-query). A missing `Medication.price` is skipped/audited, not
  thrown — it never rolls back the dispensing transaction itself, only a
  genuine stock shortfall does that (unchanged Step 49 behavior).
- **`ChargeController`/`CreateChargeRequest`**: manual creation now routes
  through `ChargeCaptureService::captureManual()`; the request requires
  `service_id`+`notes`, no longer `description`/`unit_price`/
  `discount_amount`.
- **`ChargeResource`**: exposes `charge_number`, `service`,
  `service_code_snapshot`, `notes`.
- **Frontend**: `billing/charges/create.tsx` now selects a Service (reusing
  the Step 53 `Service` type) instead of free-typing a description/price;
  `billing/accounts/show.tsx`'s charge timeline shows the charge number, an
  Auto/Manual source badge, and notes. No new list/detail routes were added —
  the existing account-scoped financial timeline (`billing/accounts/show`,
  Step 52) already serves as the per-account charge list/detail surface
  spec §44 asks for; a second, redundant `/billing/charges` index was judged
  an unnecessary duplicate of it.
- **Permissions**: none added — `billing-charges.create` (already granted to
  `hospital-admin`/`accountant`) continues to gate every capture path,
  automated or manual, per spec's own "reuse existing permissions" guidance.
- **Test helpers** (`tests/Pest.php`): `billableLaboratoryTest()`,
  `billableRadiologyProcedure()` — create a catalog test/procedure already
  mapped and priced, mirroring `ServiceCatalogSeeder`'s own mapping shape.

## Security

Authentication/authorization: unchanged surface, same permissions, same
policies (`ChargePolicy`) — automated capture never runs through HTTP at all
(services never gate on permissions themselves, this app's own established
precedent), so no new attack surface there. Facility isolation: every
captured charge's `hospital_id` is always derived server-side from
`$encounter->department->hospital_id` (this codebase's own established
pattern, e.g. `DispensingService`), never from client input. Mass
assignment / price manipulation: `unit_price` is not even an accepted field
on the manual-charge request any more; every amount, on every path, comes
from `ServicePriceResolver` or `Medication.price`, never the client.
Duplicate capture: database-enforced (UNIQUE constraint), not just
application-checked. Financial immutability: `voidForSource()` only ever
transitions a charge's status (void/cancel) — never edits or deletes a row.

## Tests

`ChargeCaptureTest` — 16/16 pass: automated capture (Laboratory, Radiology,
Pharmacy), skip-not-block on missing mapping/price (all three modules),
reversal on order cancel and single item removal, idempotent duplicate
capture, manual capture (success, missing reason, unpriced service,
encounter/patient mismatch). Existing Step 52/53 suites (`ChargeTest`,
`BillingAuthorizationTest`, `BillingIdorTest`, `BillingSecurityTest`,
`BillingIntegrationTest`, `BillingDatabaseIntegrityTest`, `ServiceCatalog*`)
updated where the manual-charge HTTP contract changed
(`description`/`unit_price` -> `service_id`/`notes`) and re-verified
passing. Laboratory/Radiology/Dispensing/Stock order regression
(`LaboratoryOrder*`, `RadiologyOrder*`, `Dispensing*`,
`StockDispensingIntegrationTest`, including Concurrency/EndToEnd/
Authorization suites) re-run unmodified and passing — the new capture hooks
are a no-op for every existing test's unmapped/unpriced catalog fixtures, so
none of their own assertions (audit-count, item-count, stock-deduction
amounts, etc.) needed to change. Full whole-app regression re-run in full
(all `tests/Feature`/`tests/Unit` directories, ~1,138 tests total across
this step's own suite plus every pre-existing one) — all passing.

## Known Issues

- **Medium** (carried forward from Step 53, still not this step's to fix) —
  the `where('date_column', op, $bareDateString)`/`whereDate()` same-day
  boundary bug remains unfixed in Step 49's `StockService`/
  `StockSearchService` `expiry_date` comparisons.

## Final Status

```
PHASE 7 — STEP 54 STATUS: PASS

READY FOR STEP 55 — INVOICES & BILLING: YES
```
