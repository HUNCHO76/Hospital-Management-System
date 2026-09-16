# Insurance Billing & Copayment (Phase 7, Step 56)

## Audit findings

Before writing any code, the repository was searched for every term spec §2
lists (`insurance`, `insurer`, `payer`, `coverage`, `patient_insurance`,
`patient_coverage`, `nhif`, etc.) across `app`, `database`, `resources/js`,
`routes`, `tests`. Two pre-existing, fully authoritative systems were found
and reused rather than duplicated:

- **`InsuranceProvider` → `InsuranceScheme` → `PatientInsurance`** (Phase 2,
  Step 17, documented in `docs/insurance.md`) — the patient's own coverage
  record, already carrying `is_primary` (coverage priority already solved),
  `status`/`verification_status` (already separates administrative status
  from an internal confirmation flag), and `effective_from`/`effective_to`.
  Nothing here needed a schema change.
- **`Charge`/`Invoice`/`Service`/`ServicePrice`** (Phase 7, Steps 52-55) — the
  billing foundation this step calculates responsibility *for*.
  `charges.invoice_id` already *is* the invoice line-item relationship (no
  `InvoiceItem` table exists), so a charge's insurance allocation is
  naturally the item-level coverage spec §22/§23 asks for.

**No NHIF integration exists anywhere in this codebase** — confirmed by the
same audit `docs/insurance.md` and `docs/billing.md` both already document:
only unread `nhif_code` placeholder columns on `Medication`/`LaboratoryTest`/
`RadiologyProcedure`. Spec §17-19/§46 all condition NHIF integration on one
already existing ("where existing NHIF verification is available, integrate
with it... do not reimplement NHIF API communication") — since none exists,
none was built. This is documented as the integration boundary below, not
silently skipped.

**Duplicates found**: none — the two systems above were extended, never
re-implemented. **Architecture reused**: the effective-dated,
create-or-cancel-only versioning algorithm (`ServicePricingService`'s own
overlap-prevention logic, Step 53, mirrored exactly for coverage rules),
the `ValidatesMoney` trait, the hospital-scoping policy pattern
(`InvoicePolicy::belongsToHospital()`), row-locked `DB::transaction()` state
changes. **Architecture changed**: `Charge` gained an `insuranceAllocation()`
relation, `Invoice` gained an `insuranceAllocations()` relation,
`InsuranceScheme` gained a `coverageRules()` relation, `ChargeResource`
gained one new `insurance_allocation` key (only populated when eager-loaded)
— every other existing file (`ChargeService`, `InvoiceService`,
`ServicePriceResolver`, `LaboratoryOrderService`/`RadiologyOrderService`/
`DispensingService`, `PatientInsuranceService`) is completely unmodified.

## Domain separation (spec §4)

```
InsuranceProvider (Phase 2)
   v
InsuranceScheme (Phase 2)
   v
PatientInsurance (Phase 2) — patient coverage + eligibility (status/dates)
   v
InsuranceCoverageRule (this step) — how much of a service this scheme covers
   v
Charge -> Invoice (Phase 7, Steps 52/55)
   v
ChargeInsuranceAllocation (this step) — the computed responsibility split
   |-- Insurer (covered_amount)
   \-- Patient (patient_responsibility)
            v
         Payment [Step 57 — not built here]
```

Enrollment (`PatientInsuranceService`), eligibility (`PatientInsurance`'s own
status/dates), coverage rules (`InsuranceCoverageRule`), billing
(`Charge`/`Invoice`), and calculation (`InsuranceBillingService`) each stay
in their own file/service — none of Step 52-55's billing code was taught
anything about insurance, and none of Phase 2's insurance code was taught
anything about billing.

## Eligibility vs. coverage (spec §7)

Kept as two distinct questions, resolved by two distinct methods on
`InsuranceBillingService`:

- **Eligibility** — `primaryCoverage()`/`isCoverageValidOn()`: is this
  patient's one primary coverage record administratively `active` and
  within its own `effective_from`/`effective_to` range, *on the charge's own
  date* (not just "today" — a charge from three months ago is checked
  against whether coverage was valid three months ago, spec §6's own
  "valid on service/invoice date"). `verification_status` (internal-only,
  manually set — no live provider check exists, per `docs/insurance.md`) is
  deliberately **not** a hard eligibility gate — spec §6's own checklist
  never lists it, and treating an administrative confirmation flag as a
  live eligibility gate would block coverage anywhere staff haven't gotten
  around to flipping it. It remains fully visible to the UI as an
  informational signal (`eligibility()`'s own return shape).
- **Coverage** — `calculateForCharge()`/`CoverageRuleResolver::resolveRule()`:
  given eligibility holds, *how much* is covered. A patient can be fully
  eligible and still have a service that is 0% covered (a configured
  `NOT_COVERED` rule, or simply no rule at all) — eligibility never implies
  100% financial coverage (spec §7's own worked example, reproduced exactly
  by test).

## Coverage priority (spec §11)

`PatientInsurance.is_primary` already guarantees at most one primary record
per patient (`PatientInsuranceService`'s own invariant, Phase 2). This step
always resolves exactly that one record and never stacks a secondary
coverage on top — spec's own "do not implement secondary insurance
complexity unless the existing architecture requires it." A patient with no
primary coverage is simply not eligible for insurance billing at all
(self-pay); `applyToInvoice()` refuses outright rather than fabricating a
zero-value allocation nobody asked for.

## Coverage rules (spec §12/§13)

`InsuranceCoverageRule` — a new, global (no `hospital_id`) entity, mirroring
`ServicePrice`'s exact effective-dated, create-or-cancel-only lifecycle
(`InsuranceCoverageRuleService::create()`'s overlap-prevention/versioning
algorithm is `ServicePricingService::create()`'s, applied to a different
target tuple). Three levels of specificity, most specific wins
(`CoverageRuleResolver::resolveRule()`):

1. An exact `service_id` override.
2. A whole `category` (reuses `Service::CATEGORIES` — never a second
   category system). This is the **only** way to configure pharmacy
   coverage: a pharmacy-sourced `Charge` never carries a `service_id` at all
   (Medication is deliberately never mapped into the Service catalog — Step
   54's own Pharmacy Boundary decision), so `InsuranceBillingService`
   identifies it by `source_type === DispensingItem::class` instead and
   matches it against the `'pharmacy'` category.
3. The scheme's own default (both null).

**No rule matching at any level is a legitimate, deliberate outcome** — not
an error, not a hard-coded percentage. `CoverageRuleResolver::resolveRule()`
returns `null`; `InsuranceBillingService` treats it as `NOT_COVERED` (patient
pays 100%), satisfying spec §12's explicit "do not hard-code rules... unless
an explicit configured business rule."

### Copayment types (spec §10/§25)

`percentage` (0-100, validated), `fixed_copay` (a non-negative amount,
capped at the charge's own eligible amount — a fixed copay larger than the
charge never produces negative insurance coverage), `full` (100% insurer),
`not_covered` (100% patient, distinct from no rule at all — see Coverage
Rules above). No `NONE` type was added separately from `not_covered` — spec
§25's own list treats them as the same outcome, and this codebase never adds
a second name for one concept.

## Coverage calculation (spec §8/§9/§24)

`InsuranceBillingService::calculateForCharge()` — pure, unit-testable, no
database write:

```
eligible_amount = charge.net_amount            (never a client-supplied figure)
covered_amount  = per the resolved rule's type
patient_responsibility = eligible_amount - covered_amount   (always derived)
```

`patient_responsibility` is **always derived**, never independently
rounded — this is what guarantees `covered_amount + patient_responsibility
== eligible_amount` exactly (spec §24's own rounding requirement, spec §44's
reconciliation invariant), with no possibility of the two halves drifting
apart from separate rounding. Verified directly by test across every
coverage type.

## Preauthorization boundary (spec §16)

No preauthorization workflow/entity exists anywhere in this codebase. Rather
than building one, `InsuranceCoverageRule.requires_preauthorization` is a
plain informational boolean: when a matched rule carries it, the coverage
breakdown UI surfaces "Requires preauthorization" next to that line — it
never blocks or alters the calculation itself, since there is no
approval-tracking entity to check an "approved" status against. Documented
here as a deliberate, minimal boundary marker, not a full system (spec §16's
own "do NOT build a completely new preauthorization system here").

## Coverage limits (spec §15) — explicitly not built

No benefit-limit/annual-cap subsystem exists in this codebase, and none was
added. Building one (tracking cumulative usage per patient per scheme per
period) is a substantial, independent feature with no existing architecture
to extend, exactly the "do NOT invent a full benefit-limit subsystem if one
does not exist" spec §15 itself anticipates. Documented here as a future
extension boundary.

## Manual overrides (spec §35) — explicitly not built

No existing business process in this codebase asks for a manual override
mechanism, and spec §35 itself conditions building one on that need
("if the current business process does not require overrides, do not
implement them"). None was built. If ever needed, it would require its own
`billing-insurance.override` permission, an audited original/new
calculation pair, and a mandatory reason — the same shape every other
controlled correction in this codebase already uses
(`ChargeService::cancel()`/`InvoiceService::cancel()`).

## Recalculation protection (spec §28/§29)

`InsuranceBillingService::applyToInvoice()` is only permitted while the
invoice is still `DRAFT`. This is not an arbitrary restriction: a charge can
only ever be added to a `DRAFT` invoice in the first place
(`InvoiceService::addCharge()`'s own guard) — by the time an invoice is
`POSTED`, its charge set is already permanently frozen, so `DRAFT` is the
only point recalculation could ever meaningfully apply. Re-running
calculation while still `DRAFT` **updates** each charge's existing
allocation row in place (audited distinctly as `insurance_coverage_
recalculated` vs. the initial `insurance_coverage_calculated`) — the UNIQUE
constraint on `charge_insurance_allocations.charge_id` is what makes "never
a duplicate allocation" (spec §27/§43) a database guarantee, not just an
application check. Once `POSTED`, a later coverage-rule change (or a repeat
calculation attempt, which is rejected outright) can never silently rewrite
an already-finalized allocation — verified directly by test.

## NHIF integration boundary (spec §17-19/§46)

No NHIF service, HTTP client, or credential exists anywhere in this
codebase to integrate with (confirmed by this step's own audit and by
`docs/insurance.md`'s own explicit statement). Building a live external
integration is outside a "Billing & Copayment" step's own scope regardless —
it is genuinely Phase 8's job. Nothing here talks to any external API;
nothing exposes a credential to React (none exist to expose). The
architecture is nonetheless ready for it without a rewrite: `PatientInsurance`
already models a payer/scheme/membership number a future NHIF verification
call would key off of, and `ChargeInsuranceAllocation` already models the
exact insurer-vs-patient split a future claim submission would report —
Phase 8 is additive on top of this shape, not a redesign of it.

## Billing integration boundary (Step 57 — Payment Management)

`ChargeInsuranceAllocation.patient_responsibility` is the exact figure spec
§30 asks to expose for Step 57 to later consume — this step computes and
persists it, and deliberately collects no payment against it.
`PatientAccountService::balance()` (Step 52) remains unmodified — its own
docblock already documents that it is architected for a later step to
subtract the insurance portion from, not this one.

## Backend Changes

- **Migrations**: `create_insurance_coverage_rules_table`,
  `create_charge_insurance_allocations_table`.
- **Models**: `InsuranceCoverageRule`, `ChargeInsuranceAllocation` (new);
  `Charge` gains `insuranceAllocation()`; `Invoice` gains
  `insuranceAllocations()`; `InsuranceScheme` gains `coverageRules()`.
- **Services**: `CoverageRuleResolver` (rule lookup, mirrors
  `ServicePriceResolver`), `InsuranceCoverageRuleService` (rule admin
  CRUD/versioning, mirrors `ServicePricingService`), `InsuranceBillingService`
  (`primaryCoverage()`, `isCoverageValidOn()`, `eligibility()`,
  `calculateForCharge()`, `applyToInvoice()`).
- **Controllers**: `InvoiceInsuranceController` (`show`/`calculate`, thin —
  every calculation lives in the service), `InsuranceCoverageRuleController`
  (rule admin `index`/`create`/`store`/`cancel`).
- **Form Requests**: `CreateInsuranceCoverageRuleRequest`,
  `CancelInsuranceCoverageRuleRequest`.
- **Policies**: `InsuranceCoverageRulePolicy` (global, mirrors
  `ServicePolicy`); `InvoicePolicy` gains `viewInsurance()`/
  `calculateInsurance()` (hospital-scoped, mirrors its own existing
  abilities).
- **Resources**: `ChargeInsuranceAllocationResource`,
  `InsuranceCoverageRuleResource`; `ChargeResource` gains
  `insurance_allocation` (only populated when eager-loaded).
- **Routes**: `billing/invoices/{invoice}/insurance` (show/calculate, nested
  under the existing `billing.` name group), `billing/insurance-rules/*`
  (flat, mirrors `billing-pricing` sitting outside that group) — all
  Inertia, no `routes/api.php` usage.
- **Permissions**: `billing-insurance.{view,calculate}`,
  `billing-insurance-rules.{view,create,deactivate}` — granted to
  `hospital-admin` and `accountant` (this app's one financial-facing role,
  extended incrementally exactly as it already grew across Steps 52-55). No
  `.verify` permission (folded into `.view` — eligibility display is
  read-only, nothing to separately gate). No `.override` permission (no
  override mechanism was built — see above).

## Frontend Changes

- **Pages**: `billing/invoices/insurance` (eligibility badge, coverage
  breakdown table per charge, calculate/recalculate action, totals summary),
  `billing/insurance-rules/{index,create}` (rule admin — create + cancel
  only, no update, mirrors `billing/pricing`'s own precedent of having no
  frontend cancel trigger either).
- **Types**: `types/insurance-billing.ts`; `types/billing.ts`'s `Charge`
  gains an optional `insurance_allocation`.
- **Utils**: `lib/insurance-billing-status.ts`.
- **Navigation**: an "Insurance & Coverage" button on the invoice show page
  (permission-gated), an "Insurance Rules" button on the billing dashboard.

## Security

Authentication: every route sits behind the same session guard as the rest
of the app. Authorization: `billing-insurance.*` (hospital-scoped, via
`InvoicePolicy`) and `billing-insurance-rules.*` (global, via
`InsuranceCoverageRulePolicy`) gate every action. Facility isolation/IDOR: a
hospital-admin cannot view or calculate insurance for another hospital's
invoice by id (verified by test); coverage rules are a global resource with
no facility boundary to violate (mirrors `Service`'s own precedent). Mass
assignment / financial manipulation: the calculate endpoint accepts **no
body at all** — `covered_amount`/`patient_responsibility`/`status`/
`coverage_percentage_applied`/`insurance_coverage_rule_id`/
`patient_insurance_id` submitted anyway are provably ignored (verified by
test posting each alongside the real request and asserting the persisted
row matches only the server's own calculation); the create-rule request
validates `coverage_percentage` (0-100) and `fixed_copay_amount` (≥0)
server-side regardless of client input, and ignores a client-supplied
`created_by`/`status`. Concurrency: `applyToInvoice()` locks the invoice row
first (mirrors every other billing transition), so two concurrent
calculation attempts on the same invoice serialize rather than race; the
UNIQUE constraint on `charge_id` is the database-level backstop. XSS: a
cancellation reason containing markup is stored and returned as plain text.

## Testing

`InsuranceCoverageRuleTest` (15), `InsuranceCoverageRuleAuthorizationTest`
(6), `InsuranceBillingCalculationTest` (12, pure unit tests: every coverage
type, rounding, rule-priority order, pharmacy category resolution, rejected
vs. not-covered vs. no-rule, ambiguous-rule guard), `InsuranceBillingTest`
(8, feature: draft-only enforcement, no-coverage rejection, empty-invoice
rejection, recalculation updates in place, posted invoices frozen against
later rule changes), `InsuranceBillingAuthorizationTest` (8, HTTP
permission + hospital IDOR), `InsuranceBillingSecurityTest` (6, financial
manipulation/mass assignment/XSS), `InsuranceBillingConcurrencyTest` (2),
`InsuranceBillingEndToEndTest` (2, the full enrollment → clinical order →
automated charge capture → invoice → coverage calculation pathway, plus the
self-pay/no-coverage counterpart) — **59/59 pass**.

Regression: the complete pre-existing `tests/Feature/Billing/` suite
(Steps 52/54/55) — **162/162 pass** (103 pre-existing + 59 new, together in
one run). `tests/Feature/Authorization` + `tests/Feature/ServiceCatalog` —
**81/81 pass** (`Role::count() === 12` and `Permission::count()` idempotency
both hold — no new role was added, and re-seeding remains stable). No file
in Phase 2-6 or Steps 52-55's own controllers/services/models was modified
by this step (only additive relations on three existing models and one
additive `ChargeResource` key), so no broader whole-app regression run was
required beyond what is listed above. `tsc`/`eslint` clean; `pint --dirty`
clean.

## Known Issues

- **Low** — no benefit-limit/annual-cap subsystem exists (spec §15's own
  "do not invent one if it doesn't exist" — a deliberate, documented
  omission, not an oversight).
- **Low** — no manual override mechanism exists (spec §35's own "if not
  required, do not implement" — no existing business process asked for
  one).
- **Low** — no live NHIF eligibility verification exists anywhere in this
  codebase to integrate with; this step's own eligibility check is
  necessarily internal-record-only, matching `docs/insurance.md`'s existing,
  pre-established scope.

## Final Status

```
STEP 56 STATUS: PASS

READY FOR STEP 57 — PAYMENT MANAGEMENT: YES
```
