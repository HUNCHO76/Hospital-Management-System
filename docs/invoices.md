# Invoices & Billing (Phase 7, Step 55)

## Audit findings

Before writing any code, the entire repository was searched for
`invoice`/`invoice_items`/`billing_invoices`/`billing_items`/
`patient_invoices`/`accounts_receivable`/`receivables`/`financial_documents`
(case-insensitive, across `app`, `database`, `resources/js`, `routes`,
`tests`). Nothing exists — only a single forward-reference comment in
`PermissionSeeder` ("Steps 55/57/58 own them") and a routing comment in
`routes/web.php` ("No invoice/payment/receipt/refund routes ... those belong
to Steps 55/57/58"). `Charge`/`ChargeService` (Step 52, extended Step 54)
and `PatientAccount`/`PatientAccountService` (Step 52) and
`Service`/`ServicePrice`/`ServicePriceResolver` (Step 53) were all found
fully authoritative and unchanged in shape — this step is a pure addition
on top of them.

**Duplicates found**: none — greenfield.
**Architecture reused**: `GeneratesBusinessNumber` (now also powering
`Invoice.invoice_number`, `INV-YYYY-######`), the shared-cancellation-column
pattern (`Charge.cancelled_at`/`cancelled_by`/`cancellation_reason`, mirrored
exactly on `Invoice` for both VOIDED and CANCELLED), the hospital-scoping
policy pattern (`ChargePolicy`/`StockItemPolicy`), row-locked
`DB::transaction()` state transitions (`ChargeService::post()`/`void()`/
`cancel()`), the "one query builder, several callers" search-service shape
(`BillingSearchService`).
**Architecture changed**: `charges` gained one nullable `invoice_id` column
(see the decision below) — no other existing table, model, service,
controller, or policy was modified beyond that single addition plus
`ChargeResource`/`types/billing.ts` exposing the new column.

## Decision: no separate `invoice_items` table

Spec §8's own illustrative invoice-item shape (`charge_id`, `service_id`,
`service_code_snapshot`, `service_name_snapshot`, `quantity`, `unit_price`,
`gross_amount`, `discount_amount`, `net_amount`, `currency`, `description`)
is, field-for-field, already exactly what `Charge` carries (Step 52/54:
`service_id`, `service_code_snapshot`, `description` as the name snapshot,
`quantity`, `unit_price`, `gross_amount`, `discount_amount`, `net_amount`,
`currency`). Building a second table that copies these same values would be
precisely the "duplicated financial values that can silently diverge" spec
§41 itself warns against, and would violate the "reuse, fix, and extend"
mandate (spec §2). Instead, a single nullable `charges.invoice_id` column
(migration `add_invoice_id_to_charges_table`) is the entire "invoice item"
relationship — a charge IS the line item once linked, under a lock, by
`InvoiceService::addCharge()`. This is also spec §31's own "one
authoritative mechanism" for charge-invoiced state, satisfied for free: a
charge is invoiced if and only if `invoice_id` is not null, with no
competing `is_billed`/`invoice_status` boolean anywhere.

## Decision: DRAFT/POSTED/VOIDED/CANCELLED, mirroring Charge's own transitions

Spec §22 explicitly restricts this step to a core lifecycle with no payment
states yet. `Invoice::TRANSITIONS` mirrors `Charge::TRANSITIONS` structurally
exactly, with DRAFT playing the role PENDING plays for a charge: `DRAFT ->
{POSTED, VOIDED}`, `POSTED -> {CANCELLED}`. Voiding a DRAFT (never became a
financial document) and cancelling a POSTED invoice (a controlled correction
after the fact) both release every linked charge (`invoice_id` cleared) so
the same still-POSTED charges can be included on a corrected invoice — spec
§13's own "Void/Cancel -> Corrected Charges -> New Invoice" — without ever
touching the charges' own status. Verified by test
(`InvoiceTest`: "cancelling a posted invoice releases its charges for a
corrected invoice").

## Decision: totals are live while DRAFT, frozen at POSTED

`Invoice.subtotal`/`total_amount` are nullable columns, populated only by
`InvoiceService::post()`. While a DRAFT is being built, the authoritative
total is always `InvoiceService::currentTotal()` — a live `SUM(net_amount)`
over currently-linked charges, mirroring `PatientAccountService::balance()`'s
own "never derive financial truth from the UI, always recompute" principle.
Posting recalculates that same sum one final time, under a lock on every
linked charge, and freezes it — spec §14's own "backend must calculate
totals" and §13's posted-invoice immutability, satisfied by the same value
simply never being touched again afterward.

## Decision: no invoice-level `discount_amount`/`tax_amount`

Spec's own illustrative field list includes both. This codebase has no tax
concept anywhere, and a discount is already applied and permanently baked
into `Charge.net_amount` at charge-creation time (Step 52) — an
invoice-level discount field would be a second, competing discounting
mechanism with no consumer, exactly what spec §29 itself warns against
("do not invent a broad adjustment system"). `subtotal` and `total_amount`
remain two distinct columns purely so a later step (an insurance-adjusted
portion, a tax line) can extend without a schema change, not because they
differ today.

## Decision: atomic all-or-nothing draft creation

`InvoiceService::createDraft()` wraps the invoice row **and** every
requested charge link in one outer transaction (a later revision from this
step's own testing: an earlier version created the `Invoice` row in its own
transaction, then linked charges afterward — a request selecting an
already-claimed charge left an orphaned, empty draft behind). Now, if any
requested charge turns out ineligible, nothing is created at all — spec
§40's "no partial" principle, applied to creation exactly as it already
applies to posting. Verified by test (`InvoiceSecurityTest`: "a charge
cannot be included in two invoices even when submitted twice over HTTP" —
asserts exactly one invoice row exists after the failed second attempt).

## What this step builds

```
Patient
   v
Patient Account
   v
Charge (POSTED, invoice_id null)   <- eligible for invoicing
   v
Draft Invoice (InvoiceService::createDraft/addCharge/removeChargeBeforePosting)
   v
Posted Invoice (InvoiceService::post() — totals frozen, spec §12/§40)
   v
[Step 56] Insurance Billing & Copayment
[Step 57] Payment
[Step 58] Refunds / Cashier
```

- **Migrations**: `create_invoices_table`, `add_invoice_id_to_charges_table`.
- **Models**: `Invoice` (new); `Charge` gains an `invoice()` relation and
  `invoice_id` in its fillable list.
- **Services**: `InvoiceService` (`createDraft()`, `addCharge()`,
  `removeChargeBeforePosting()`, `currentTotal()`, `post()`, `void()`,
  `cancel()`), `InvoiceSearchService` (`invoicesQuery()`,
  `scopeToHospital()`, `eligibleChargesQuery()`).
- **Controllers**: `InvoiceController` (index/create/store/show/addCharge/
  removeCharge/post/void/cancel).
- **Form Requests**: `CreateInvoiceRequest`, `AddInvoiceChargeRequest`,
  `VoidInvoiceRequest`, `CancelInvoiceRequest`.
- **Policies**: `InvoicePolicy` — mirrors `ChargePolicy`'s hospital-scoping
  precedent exactly (only `hospital-admin` is fenced; every other
  permission-holding role is unrestricted, this app's existing systemic
  behavior).
- **Resources**: `InvoiceResource`; `ChargeResource` gains `invoice_id`.
- **Routes**: `billing/invoices/*`, nested under the existing `billing.`
  name group — all Inertia, no `routes/api.php` usage.
- **Permissions**: `billing-invoices.{view,create,post,void}` — granted to
  `hospital-admin` and `accountant` (this app's one financial-facing role,
  extended incrementally exactly as it already grew across Steps 52-53). No
  Cashier/Billing Officer/Finance role split invented (spec §29/§33's own
  "no backing feature yet" precedent, carried forward unchanged).
- **Frontend**: `billing/invoices/{index,create,show}`, a
  `VoidInvoiceDialog` component (mirrors `VoidChargeDialog`), a "Create
  Invoice" entry point on the account show page, an "Invoices" button on
  the billing dashboard. No separate `/billing/invoices/{id}/edit` route —
  the show page itself is the draft editor (add/remove charges) while
  still DRAFT, mirroring how the account show page is itself the charge
  timeline rather than a separate view.
- **Test helpers** (`tests/Pest.php`): `postedChargeFor()` — a `chargeFor()`
  charge immediately posted, the minimum eligible-for-invoicing charge.

## Security

Authentication/authorization: every invoice route sits behind the same
session guard and is gated by its own `billing-invoices.*` permission via
`InvoicePolicy`. Facility isolation / IDOR: a hospital-admin cannot view,
build into, post, or void another hospital's invoice by id (verified by
test). Mass assignment: `status`/`subtotal`/`total_amount`/`invoice_number`/
`hospital_id` are never accepted from any request — every computed field and
status transition happens server-side (verified by test, including a
direct attempt to override the post-invoice totals). Financial
manipulation: a client-supplied amount is never used for anything —
`InvoiceService::post()` recomputes `subtotal`/`total_amount` from the
linked charges' own already-server-computed `net_amount` values, under a
lock. Duplicate invoicing: database-consistent by construction — a charge's
`invoice_id` is only ever set under a row lock that re-checks it is still
null, and the whole draft-creation operation is one transaction (see the
atomicity decision above).

## Tests

`InvoiceTest` — 17/17: draft creation (with/without initial charges),
eligibility (PENDING/voided/already-invoiced/cross-account rejected),
add/remove charge, posting (freezes totals, requires ≥1 charge, rejects an
already-posted invoice, rejects a charge that became ineligible after being
added), void (DRAFT only, releases charges), cancel (POSTED only, releases
charges for a corrected invoice), terminal-state checks.
`InvoiceAuthorizationTest` — 7/7. `InvoiceIdorTest` — 4/4.
`InvoiceSecurityTest` — 5/5 (mass assignment, financial manipulation,
duplicate invoicing, invalid transition, XSS-safe text storage).
`InvoiceConcurrencyTest` — 4/4 (only one of several repeated attempts to
add the same charge to different invoices/post/cancel ever succeeds;
invoice numbers never collide). `InvoiceDatabaseIntegrityTest` — 4/4.
`InvoiceIntegrationTest` — 4/4: Laboratory, Radiology, and Pharmacy-sourced
charges each verified end-to-end through to a posted invoice (correct
patient/facility/source traceability, correct historical price, correct
total, correct audit trail), plus a multi-source invoice combining
Laboratory, and a manual charge, in one document. Existing Step 52/53/54
suites (`ChargeTest`, `Billing*`, `ServiceCatalog*`, `ChargeCaptureTest`,
Laboratory/Radiology/Dispensing order regressions) re-run unmodified and
passing — this step's only touch to shared code (`Charge.invoice_id`) is
purely additive and defaults to null, so nothing pre-existing changed
behavior.

## Known Issues

- **Medium** (carried forward from Step 53, still not this step's to fix) —
  the `where('date_column', op, $bareDateString)`/`whereDate()` same-day
  boundary bug remains unfixed in Step 49's `StockService`/
  `StockSearchService` `expiry_date` comparisons.
- **Low** — a charge that becomes ineligible (e.g. separately cancelled)
  after being added to a still-DRAFT invoice is not automatically released;
  `InvoiceService::post()` detects and rejects this case, requiring the
  charge to be explicitly removed first, rather than silently dropping it —
  a deliberate "never silently modify the invoice" choice, not an oversight
  (see the addCharge()/post() docblocks).

## Final Status

```
PHASE 7 — STEP 55 STATUS: PASS

READY FOR STEP 56 — INSURANCE BILLING & COPAYMENT: YES
```
