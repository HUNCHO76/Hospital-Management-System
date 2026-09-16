# Billing & Payment Foundation (Phase 7, Step 52)

## Audit findings

Before writing any code, the entire repository was searched for
`billing`/`invoice`/`payment`/`receipt`/`charge`/`patient_account`/`ledger`/
`cashier`/`refund`/`copayment`/`tariff`/`service_price` (case-insensitive,
across `app`, `database`, `resources/js`, `routes`, `tests`). Nothing exists:
no `Invoice`/`Payment`/`Receipt`/`Charge`/`PatientAccount`/ledger model,
migration, controller, service, or route anywhere in this codebase.

What does exist, reserved for this exact future by earlier phases:
`Medication.price`, `LaboratoryTest.price`, `RadiologyProcedure.price` — each
`decimal(10, 2)`, `nullable`, explicitly documented in their own migrations
as "preserved for a future billing/claims module — nothing in this system
currently reads or acts on these values." No unified service/pricing catalog
exists across modules — each clinical module owns its own priced catalog
independently. No `Consultation` price field exists at all (a doctor
consultation has never been priced anywhere in this system). No currency
configuration exists anywhere (`config/app.php` only has locale). No
financial ledger/general-ledger structure exists.

## Decision: no `service_id` FK on `Charge` — a polymorphic `source_type`/`source_id` pointer instead

Spec §4/§12 itself anticipates this: "do not hard-code every module into the
billing schema if a safe polymorphic/reference architecture is already
used." This codebase already has exactly that architecture — `AuditLog`'s
own `auditable_type`/`auditable_id` and `StockTransaction`'s own
`reference_type`/`reference_id` (Phase 6, Step 49) — so `Charge` follows the
same precedent rather than inventing a `services` table that Step 53
("Service Catalog & Pricing") is explicitly the step that will actually
build. `description`/`unit_price` are captured on the charge itself at
creation time (the same snapshot precedent every order-item table in this
app already uses), so a later catalog price change — once Step 53
introduces one — can never rewrite a historical charge.

## Decision: `PatientAccount` is scoped by `hospital_id` directly, one per patient per hospital

Mirrors `StockItem`'s own facility-scoping precedent (Phase 6, Step 49)
exactly: a patient treated at two hospitals gets two separate, fully
isolated accounts, never one account spanning hospitals — spec §9's own
multi-facility isolation requirement is satisfied by the schema itself, not
just application-level filtering. The account is auto-provisioned on first
charge (`PatientAccountService::findOrCreateForPatient()`), never created
ahead of time for a patient who has never been charged (spec §6's own "avoid
creating a separate financial account for every individual service" —
satisfied by reusing this one account for every charge, not by avoiding
accounts altogether).

## Decision: no ledger/transaction table in this step

Spec §17 itself asks whether one is required, and explicitly warns against
"full accounting/general-ledger functionality." `Payment` doesn't exist yet
(a later step's job) — a debit/credit ledger with nothing on the credit side
would be a premature abstraction. For now, the `charges` table itself *is*
the append-only-once-posted ledger of billable events (a `POSTED` charge is
never edited, only status-transitioned via a new, reason-required, audited
change — spec §25/§26). If a real double-entry ledger is ever needed once
`Payment` exists, it is additive on top of this same `Charge`/`PatientAccount`
shape, not a rewrite.

## Decision: `ChargeService::cancel()` is the minimal correction mechanism, not a full Adjustment entity

Spec §24 explicitly defers "Refunds, Reversals & Adjustments" to a later
step, but also says (§24) the foundation "must not prevent" that later work,
and (§14) "a posted charge should not simply be edited... corrections should
use controlled adjustment/reversal mechanisms." `cancel()` (POSTED ->
CANCELLED, reason required, actor/timestamp recorded, original row
untouched) is the smallest mechanism that satisfies both constraints at
once: charges are never silently edited, yet nothing here blocks a later
`Adjustment`/credit-note entity from being layered on top.

## What this step builds

```
Patient
   v
Patient Account (hospital-scoped, auto-provisioned, ACC-YYYY-###### number)
   v
Charge (PENDING -> POSTED -> CANCELLED, or PENDING -> VOIDED)
   v
[Step 53] Service Catalog & Pricing
[Step 54] Patient Charges & Charge Capture (automated triggers)
[Step 55] Invoices & Billing
[Step 56] Insurance Billing & Copayment
[Step 57+] Payment / Refunds / Cashier / Reports
[Phase 8] NHIF Claims
```

- **Schema**: `patient_accounts` (hospital_id, patient_id, account_number,
  status, currency — unique per hospital+patient), `charges` (hospital_id,
  patient_account_id, patient_id, encounter_id, source_type/source_id,
  description, quantity, unit_price, gross/discount/net_amount, currency,
  status, charged_at/posted_at, created_by/updated_by,
  cancelled_at/cancelled_by/cancellation_reason).
- **`PatientAccountService`**: `findOrCreateForPatient()` (row-locked,
  never audits itself — designed to be called from inside another
  service's own transaction, mirrors `StockService::allocateFefo()`'s own
  precedent), `ensureAccountExists()` (the standalone, self-auditing entry
  point), `balance()`/`pendingTotal()` (always computed live from the
  ledger of charges, never stored).
- **`ChargeService`**: `create()` (validates money server-side — rejects
  negative/zero/non-numeric/`NaN`/`Infinity`/over-2-decimal-place amounts,
  computes gross/discount/net, auto-provisions the account), `post()`,
  `void()`, `cancel()` — every transition row-locked and audited.
- **Controllers/routes** (`billing/*`, all Inertia, no JSON API):
  dashboard, searchable account list, account detail, manual charge
  entry/lifecycle. `BillingSearchService` mirrors
  `StockSearchService`/`DispensingSearchService`'s own shape.
- **Policies**: `PatientAccountPolicy`, `ChargePolicy` — mirror
  `StockItemPolicy`'s hospital-scoping precedent exactly (only
  `hospital-admin` is fenced; every other role holding the permission is
  currently unrestricted, the same systemic behavior every other policy in
  this app already has).
- **Permissions**: `billing.view`, `billing-accounts.view`,
  `billing-charges.{view,create}` (create also covers post/void/cancel —
  spec's own permission list has no separate ability per transition).
  Granted to `hospital-admin` (full, within their hospital) and the
  pre-existing `accountant` role (extended from its `patient-insurance.view`
  stub — this app's one financial-facing role — rather than inventing a
  Cashier/Billing Officer/Finance split with no backing feature yet).
- **Frontend**: `billing/index` (dashboard: real counts only), `billing/
  accounts/index` (server-filtered list), `billing/accounts/show` (balance +
  full financial timeline, post/void/cancel actions), `billing/charges/
  create` (manual entry, reusing the existing `patients.search-json`
  endpoint via a new `PatientSearch` combobox). A "Billing" sidebar entry
  gated on `billing.view`.
- **Money representation**: `decimal(10, 2)` throughout — the exact
  precision precedent already established by `Medication.price`/
  `LaboratoryTest.price`/`RadiologyProcedure.price`, not a new standard.
  Every amount is validated and computed server-side
  (`ChargeService::validateMoney()`); the frontend never computes or
  transmits a derived total as authoritative.
- **Currency**: centralized in `config/billing.php`
  (`BILLING_CURRENCY`, default `TZS`), stamped onto each account/charge at
  creation — never hard-coded elsewhere.

## Integration boundary for Step 53 — Service Catalog & Pricing

Nothing here needs to change once a real service catalog exists.
`ChargeService::create()` already accepts a plain `description`/`unit_price`
pair; Step 53's own catalog just becomes another (better) source of those
two values for whatever calls `ChargeService::create()`, exactly the way a
manual entry supplies them today. No `service_id` migration/backfill is
needed.

## Integration boundary for Step 54 — Patient Charges & Charge Capture

Built — see `docs/charge-capture.md`. `ChargeCaptureService` is now the one
centralized entry point every automated (Laboratory/Radiology/Pharmacy) and
manual charge goes through, calling `ChargeService::create()` exactly the
way this section originally anticipated. Verified end-to-end in
`BillingIntegrationTest`: a charge sourced from a `Consultation` traces
correctly back through `ClinicalEncounter` -> `Patient` -> `Hospital`.

## Integration boundary for Step 55 — Invoices & Billing

Built — see `docs/invoices.md`. `PatientAccount -> Charge` was exactly the
target diagram (spec §18) already described; `Invoice` groups existing
`Charge` rows via one new `charges.invoice_id` column (never an
`InvoiceItem` table duplicating their amounts — see `docs/invoices.md`'s own
reasoning), a pure addition on top, no restructuring required.

## Integration boundary for Step 56/57+ — Insurance, Payment, Refunds, Cashier, Reports

`PatientAccountService::balance()`'s own docblock explains exactly how it
partially implements spec §16's full formula (Total Charges - Discounts -
Insurance Portion - Payments + Refunds/Adjustments) and is architected for
later steps to subtract from, not replace. No insurance/payment/refund
table exists yet — nothing here needs to.

## Security

Authentication: every billing route sits behind the same session guard as
the rest of the app. Authorization: every mutating action is gated by its
own `billing-*` permission, checked via `PatientAccountPolicy`/
`ChargePolicy`. Facility isolation / IDOR: a hospital-admin cannot view,
charge into, or manage another hospital's account/charges by id (verified
by test). Mass assignment: `hospital_id`/`status`/`gross_amount`/
`net_amount`/`created_by`/`posted_at`/`cancelled_by` are never accepted from
any request — every computed field and status transition happens
server-side. Financial manipulation: amount/discount/status can never be
set directly by the client; every Form Request only accepts the raw inputs
a real actor would supply, and `ChargeService` recomputes and validates
everything itself under a row lock.

## Tests

`PatientAccountTest` (5), `ChargeTest` (11), `BillingAuthorizationTest` (6),
`BillingIdorTest` (4), `BillingSecurityTest` (6), `BillingConcurrencyTest`
(5), `BillingIntegrationTest` (2), `BillingDatabaseIntegrityTest` (3) —
**43/43 pass**. Regression: `Authorization` (which asserts an exact
`Role::count() === 12` — extending the pre-existing `accountant` role rather
than adding a new one keeps this exact) — **32/32 pass**. `tsc` clean,
`eslint` clean, `pint --dirty` clean. Full whole-app regression not
exhaustively re-run given this step's changes are additive-only (new
tables/files, appended routes/permissions, no existing controller/service/
model touched) — verified via `route:list` (no name collisions) and the
`Role::count()` regression assertion specifically.

**STEP 52 COMPLETE.**
