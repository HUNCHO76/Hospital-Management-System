# Payment Management (Phase 7, Step 57)

## Audit findings

Before writing any code, the repository was searched for every term spec
§57.1 lists (`payment`, `patient_payments`, `invoice_payments`,
`payment_allocations`, `receipts`, `cashier`, `mobile_money`, `mpesa`,
`tigopesa`, `airtelmoney`, `halopesa`, `bank`, `card`, `payment_methods`,
`financial_transactions`, `balances`, etc.) across `app`, `database`,
`resources/js`, `routes`, `tests`. **Nothing exists** — every match was a
forward-reference comment already left by Steps 52-56 ("no payment/receipt/
refund routes — those belong to Steps 57/58",
`PatientAccountService::balance()`'s own "architected for later steps to
subtract from" docblock, etc.). Greenfield for `Payment`/`PaymentAllocation`
— no duplicate system was created; both are new, and every other billing
entity (`Charge`, `Invoice`, `ChargeInsuranceAllocation`, `PatientAccount`,
`Service`/`ServicePrice`) was reused completely unmodified in shape (only
additive relations were added — see Backend Changes below).

**Existing conventions reused**: `GeneratesBusinessNumber` (row-locked,
concurrency-safe sequence generation — Steps 52/55's own precedent, reused
verbatim for both `payment_number` and `receipt_number`), the
`ValidatesMoney` trait, the hospital-scoping policy pattern
(`InvoicePolicy`/`ChargePolicy`'s `belongsToHospital()`), row-locked
`DB::transaction()` state changes, the `PatientSearch` combobox component
(Step 52), the `billing.` route name group Charges/Invoices already live
under.

## Domain separation (spec §57.2)

```
Charge -> Invoice -> Responsibility (Step 56's ChargeInsuranceAllocation)
                          v
                       Payment (this step)
                          v
                  PaymentAllocation
                          v
                  Invoice/Account Balance (PaymentService::calculateOutstanding())
                          v
                       Receipt
```

A `Payment` is never a `Charge`, an `Invoice`, or a `ChargeInsuranceAllocation`
— it is a new, fourth financial fact: money actually received. Nothing in
this step modifies `Charge`, `Invoice`, `Service`/`ServicePrice`, or
`ChargeInsuranceAllocation`'s own write paths; `PaymentService` only *reads*
them to compute what is still owed.

## Decision: Payment reaches POSTED directly, not through a PENDING draft

Spec §57.4 suggests a PENDING/POSTED/FAILED lifecycle "if no existing
convention exists" — but every payment method this step supports (cash
already in hand, a card already charged at a POS terminal, a bank transfer
or mobile money transaction already confirmed by its own reference number)
represents money **already received** by the time anyone opens this form —
there is no live gateway callback this step integrates with to make a
genuine "awaiting confirmation" state meaningful (spec §57.5's own "do not
create unnecessary provider-specific integrations"). This mirrors
`StockService::receive()`'s own "a real, immediate event, no meaningful
draft state" precedent far more closely than `ChargeService`/
`InvoiceService`'s build-then-finalize documents. Spec §57.19's own
transaction pseudocode confirms this reading: it bundles creation,
allocation, and receipt-number generation into **one** transaction block,
never describing a separate later "post" step.

`Payment::STATUS_POSTED` is therefore reached directly by
`PaymentService::createPayment()`. `Payment::STATUS_FAILED` is the one
controlled correction this step provides (`markFailed()`) — mirrors
`ChargeService::cancel()`'s own "minimal correction mechanism, not a full
Adjustment entity"; genuine reversal/refund remains Step 58's job.

## Decision: `Payment.invoice_id` is direct; `PaymentAllocation` still exists as its own table

A payment in this step always targets exactly one invoice for its entire
lifetime (spec §57.10's own permitted "if the existing architecture only
supports one invoice per payment, preserve that boundary" simplification —
this step's own create-payment screen only ever offers one invoice at a
time, spec §57.26). `Payment.invoice_id` is therefore a direct, required
column — simpler than forcing every read through a join for the one case
that exists today. `PaymentAllocation` (`payment_id`, `invoice_id`,
`amount`) still exists as its own table anyway, purely so a **future** step
can let one payment span multiple invoices without a schema change (spec's
own explicit "structure the service so future extension is possible") —
`PaymentService` enforces `SUM(allocations.amount for this payment) ===
payment.amount` directly rather than assuming it from the schema.

## Decision: no separate Receipt entity

Spec §57.17 asks for a distinct, concurrency-safe `receipt_number`
(`RCT-2026-000001`, separate from `payment_number`) but never describes a
receipt being independently created, edited, voided, or reissued as its own
record. A receipt here is the printable view of an already-POSTED payment —
generated once, at the same moment as `payment_number`, via the exact same
`GeneratesBusinessNumber` trait called twice with a different prefix/column.
Building a second `receipts` table would only duplicate values that can
never diverge from the `Payment` row itself (the same "no separate
`InvoiceItem` table" reasoning Step 55 already established for charges).
`/billing/payments/{payment}/receipt` is simply a distinct, print-styled
render of the same authorized `Payment` resource.

## Decision: outstanding calculation lives on `PaymentService`, not `PatientAccountService`

Spec §57.13 asks for "one authoritative financial calculation service."
`PatientAccountService::balance()` (Step 52) is left completely untouched —
it remains "total posted charges," its own pre-existing, already-tested
figure — rather than risk regressing it by teaching it about insurance
allocations and payments it was never designed around. Instead,
`PaymentService::calculateOutstanding(Invoice)` is the one new authoritative
calculation:

```
invoice_total              = SUM(POSTED charges' net_amount)
insurance_responsibility   = invoice_total - patient_responsibility
patient_responsibility     = SUM, per charge: its own ChargeInsuranceAllocation
                              .patient_responsibility if one was ever
                              calculated (Step 56), else the full charge
                              net_amount (self-pay — insurance allocation is
                              a separate concern, never assumed, spec §57.7)
patient_paid                = SUM(PaymentAllocation.amount) whose own
                               Payment is still POSTED
patient_outstanding          = patient_responsibility - patient_paid
```

`PaymentService::outstandingBalanceForAccount(PatientAccount)` sums this
same per-invoice figure across every posted invoice — never a second,
independently-derived formula. No controller, service, or React component
computes any of this itself.

## Invoice payment status (spec §57.20)

UNPAID/PARTIALLY_PAID/PAID is a **computed value**, returned by
`calculateOutstanding()`'s own `status` key — never a stored column on
`invoices`. This mirrors this codebase's own repeated "never store a
derived status, always compute it live" principle
(`DispensingService::fulfillmentStatus()`, Phase 6; `PatientInsurance`'s own
status-vs-date-lapse distinction, Phase 2): `Invoice.status` remains
exclusively DRAFT/POSTED/VOIDED/CANCELLED, the document lifecycle Step 55
already owns: an invoice is never "marked paid" by a payment form
submission — the figure is simply recalculated from actual
`PaymentAllocation` rows every time it is asked for.

## Overpayment protection (spec §57.9)

`createPayment()` locks the target invoice first (`lockForUpdate()`,
the same serialization point every other billing transition already uses),
recomputes `calculateOutstanding()` under that lock, and rejects an amount
exceeding the freshly-computed `patient_outstanding` outright. No
unapplied-credit/floating-balance concept exists anywhere in this
architecture to accept the excess into — the payment is refused, never
silently capped, converted into a discount, or used to overwrite the
invoice's own total.

## Concurrency (spec §57.14)

Locking the invoice row first is what prevents "Cashier A reads 50,000,
Cashier B reads 50,000, both pay 50,000": the second call's
`lockForUpdate()` blocks until the first transaction commits, at which
point its own `calculateOutstanding()` re-read correctly sees the reduced
balance and rejects the excess. Verified directly by test (5 repeated
50,000 payment attempts against a single 50,000 balance: exactly 1 succeeds,
4 rejected, outstanding lands at exactly 0, never negative).

## Duplicate payment protection (spec §57.15)

Two layers, mirroring `ChargeCaptureService`'s own duplicate-charge
precedent: (1) a database UNIQUE constraint on
`(hospital_id, payment_method, reference_number)` — multiple NULL references
(cash/other payments) remain unrestricted, since every SQL engine this
project targets treats NULL as distinct from NULL in a UNIQUE index; (2) an
explicit, row-locked re-check inside the same transaction
(`assertReferenceNotAlreadyUsed()`), so a losing concurrent request gets a
clear validation error rather than a raw constraint-violation exception. No
arbitrary client-supplied idempotency/UUID token was introduced (spec's own
explicit "do not create an arbitrary UUID-based workaround") — the
combination of the invoice-lock-based overpayment check (for cash, which
carries no reference at all) and the reference-number uniqueness (for every
traceable method) is what this architecture already provides.

## Financial immutability (spec §57.16)

Once POSTED, a `Payment` row's `amount`/`invoice_id`/`patient_id`/
`payment_date` are never edited or deleted through any code path this step
adds — the only available transition is `markFailed()` (POSTED -> FAILED,
mandatory reason, actor/timestamp recorded), which only changes `status`/
`failed_at`/`failed_by`/`failure_reason`. Genuine reversal/refund/adjustment
remains Step 58's job, deliberately not built here.

## Backend Changes

- **Migrations**: `create_payments_table`, `create_payment_allocations_table`.
- **Models**: `Payment`, `PaymentAllocation` (new); `Invoice` gains
  `payments()`/`paymentAllocations()`; `PatientAccount` gains `invoices()`/
  `payments()`.
- **Services**: `PaymentService` (`calculateOutstanding()`,
  `outstandingBalanceForAccount()`, `createPayment()`, `markFailed()`),
  `PaymentSearchService` (`paymentsQuery()`, `scopeToHospital()`,
  `eligibleInvoicesForPatient()`).
- **Controllers**: `PaymentController` (index/create/store/show/receipt/void)
  — kept thin, mirrors `InvoiceController`; `InvoiceController::show()`
  extended (additively) to expose the invoice's own outstanding breakdown
  and payment history.
- **Form Requests**: `StorePaymentRequest` (structural validation only —
  `PaymentService` revalidates every financial condition under its own
  lock), `VoidPaymentRequest`.
- **Policies**: `PaymentPolicy` (mirrors `ChargePolicy`/`InvoicePolicy`).
- **Resources**: `PaymentResource`.
- **Enums**: `Payment::STATUSES` (posted/failed), `Payment::METHODS`
  (cash/card/bank_transfer/mobile_money/other — a plain validated list, no
  per-provider M-Pesa/Tigo Pesa/Airtel Money/HaloPesa entries, spec §57.5's
  own "do not create unnecessary provider-specific integrations").
- **Routes**: `billing/payments/*`, nested under the existing `billing.`
  name group alongside charges/invoices — all Inertia, no `routes/api.php`
  usage.
- **Permissions**: `billing-payments.{view,create,void}` — granted to
  `hospital-admin` and `accountant` (this app's one financial-facing role,
  extended incrementally exactly as it already grew across Steps 52-56).

## Frontend Changes

- **Pages**: `billing/payments/{index,create,show,receipt}`.
- **Components**: reuses `PatientSearch` (Step 52) — no new search
  component; a small inline `MarkFailedDialog` in the payment detail page
  (mirrors `VoidInvoiceDialog`'s shape, not extracted as a shared component
  since it is used in exactly one place).
- **Types**: `types/payment.ts`; `types/billing.ts`'s `Charge`/`Invoice` are
  unaffected (payments are their own type family).
- **Utils**: `lib/payment-status.ts`.
- **Navigation**: "Payments"/"Record Payment" buttons on the billing
  dashboard; a "Record Payment" button plus an outstanding-balance card and
  payment history table added to the existing invoice show page
  (additive — the page's own draft-building/post/void flow is untouched).

## Financial Rules

- **Payment eligibility**: the target invoice must exist, be POSTED, and not
  VOIDED/CANCELLED (locked and re-checked under `createPayment()`'s own
  transaction).
- **Outstanding calculation**: see the dedicated section above —
  `PaymentService::calculateOutstanding()` is the single source of truth.
- **Allocation rules**: every allocation is created in the same transaction
  as its payment, denormalizing `hospital_id`/`patient_id` from the invoice
  itself (never from client input) — a cross-patient/cross-facility
  allocation is structurally impossible since there is no field on the
  request for a client to redirect it.
- **Overpayment behavior**: rejected outright (see above); never capped,
  converted to a discount, or silently absorbed.
- **Invoice status behavior**: UNPAID/PARTIALLY_PAID/PAID is computed live,
  never stored (see above).

## Security

Authentication: every route sits behind the same session guard as the rest
of the app. Authorization: `billing-payments.*` (hospital-scoped, via
`PaymentPolicy`) gates every action. Facility isolation/IDOR: a
hospital-admin cannot view, record into, or void another hospital's payment
by id (verified by test). Mass assignment/financial manipulation: `status`,
`hospital_id`, `patient_id`, `received_by`, `posted_at`, `payment_number`,
`receipt_number` are not accepted fields at all on the store-payment
request — every one is provably server-derived (verified by test posting
each anyway and asserting the persisted row is unaffected); the amount is
independently re-validated against a freshly-locked, freshly-computed
outstanding balance regardless of what the client submitted. Concurrency:
invoice-row locking (see above), verified directly by test (only 1 of 5
simultaneous full-amount attempts succeeds; partial-payment attempts never
drive the balance negative). Duplicate protection: database UNIQUE
constraint plus a row-locked re-check (see above). Audit logging: every
payment posting and receipt generation is recorded
(`payment_posted`/`receipt_generated`), and every `markFailed()` call
(`payment_failed`) — via the existing `AuditLogService`, matching this
codebase's own established action-naming and payload conventions.

## Testing

`PaymentTest` (20: outstanding calculation for self-pay and
insurance-adjusted invoices, partial/full payment, overpayment rejection,
non-posted-invoice rejection, method/reference validation, duplicate
reference rejection/cross-facility allowance, payment/receipt number
uniqueness, allocation invariant, `markFailed()` and its own terminal-state
guard, account-wide outstanding aggregation), `PaymentAuthorizationTest` (8,
HTTP permission + hospital IDOR combined), `PaymentSecurityTest` (7, mass
assignment/financial manipulation/XSS), `PaymentConcurrencyTest` (4,
including the mandatory "two cashiers, one balance" scenario),
`PaymentEndToEndTest` (3, the complete spec §57.33 pathway reproduced with
its own exact numbers — 100,000/70,000/30,000, paid 10,000 then 20,000 to
exactly 0 outstanding, plus a fully-insurance-covered-needs-no-payment case
and a cancelled-charge-recomputation case) — **42/42 pass**.

Regression: the complete pre-existing `tests/Feature/Billing/` suite
(Steps 52/54/55/56) — **204/204 pass** (162 pre-existing + 42 new, together
in one run). `tests/Feature/Authorization` — **32/32 pass**
(`Role::count() === 12` unchanged — no new role was added). No file in
Phase 2-6 or Steps 52-56's own controllers/services/models was modified by
this step beyond three additive relations (`Invoice::payments()`/
`paymentAllocations()`, `PatientAccount::invoices()`/`payments()`) and one
additive controller prop (`InvoiceController::show()`), so no broader
whole-app regression run was required beyond what is listed above.
`tsc`/`eslint` clean across the entire frontend; `pint --dirty` clean.

## Known Issues

- **Low** — no refund/reversal/adjustment mechanism exists (deliberately not
  built — Step 58's own explicit scope).
- **Low** — no cashier shift/till/reconciliation concept exists
  (deliberately not built — Step 59's own explicit scope).
- **Low** — no live payment gateway (card processor, mobile money STK push,
  bank API) integration exists; every payment method in this step is
  recorded manually from an already-completed real-world transaction,
  matching spec §57.5's own explicit "do not create unnecessary
  provider-specific integrations" boundary.

## Final Status

```
STEP 57 STATUS: PASS

READY FOR STEP 58 — REFUNDS, REVERSALS & ADJUSTMENTS: YES
```
