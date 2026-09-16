<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\PatientAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Owns patient financial accounts (Phase 7, Step 52) — auto-provisioned on
 * first use (spec §6's "avoid creating a separate financial account for
 * every individual service", satisfied by one reused account per
 * hospital+patient), never created ahead of a patient's first charge.
 */
class PatientAccountService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Find this patient's account at this hospital, or create it. Locks the
     * row to serialize concurrent first-charge attempts (spec §27) — the
     * same reason StockService::receive() locks its own StockItem row before
     * a possible first-creation.
     *
     * Never audits itself: this is designed to be called from inside another
     * service's own outer transaction (ChargeService::create()) as well as
     * standalone (via ensureAccountExists() below) — mirrors
     * StockService::allocateFefo()'s own "caller audits once its own
     * transaction commits" precedent exactly.
     *
     * @return array{0: PatientAccount, 1: bool} the account, and whether it was newly created
     */
    public function findOrCreateForPatient(int $hospitalId, int $patientId, Request $request): array
    {
        $account = PatientAccount::query()
            ->where('hospital_id', $hospitalId)
            ->where('patient_id', $patientId)
            ->lockForUpdate()
            ->first();

        $wasNew = ! $account;

        if (! $account) {
            $account = PatientAccount::create([
                'hospital_id' => $hospitalId,
                'patient_id' => $patientId,
                'status' => PatientAccount::STATUS_ACTIVE,
                'currency' => config('billing.currency'),
                'created_by' => $request->user()->id,
            ]);
        }

        return [$account, $wasNew];
    }

    /**
     * The standalone entry point (own transaction + own audit) for ensuring
     * an account exists without also creating a charge — e.g. opening the
     * account view for a patient before any charge has ever been raised.
     */
    public function ensureAccountExists(int $hospitalId, int $patientId, Request $request): PatientAccount
    {
        [$account, $wasNew] = DB::transaction(fn () => $this->findOrCreateForPatient($hospitalId, $patientId, $request));

        if ($wasNew) {
            $this->auditLog->record(
                'billing_account_created',
                $account,
                null,
                $account->only(['hospital_id', 'patient_id', 'account_number', 'status', 'currency']),
                $request,
            );
        }

        return $account;
    }

    /**
     * The account's current outstanding-relevant balance: the sum of every
     * POSTED charge's net amount. Spec §16's own formula (Total Charges -
     * Discounts - Insurance Portion - Payments + Refunds/Adjustments) is
     * intentionally not fully implemented here — insurance/payments/refunds
     * don't exist as entities yet (later steps own them); this is the
     * correct partial computation for what this foundation actually has,
     * architected so later steps subtract from it rather than replace it.
     * Never stored — always computed live from the ledger of charges, the
     * same "never derive financial truth from the UI" principle (spec §16)
     * StockService::availableQuantity() already follows for inventory.
     */
    public function balance(PatientAccount $account): float
    {
        return (float) $account->charges()->where('status', Charge::STATUS_POSTED)->sum('net_amount');
    }

    /**
     * The total value of charges still awaiting posting — surfaced on the
     * account view so a pending charge is never silently invisible.
     */
    public function pendingTotal(PatientAccount $account): float
    {
        return (float) $account->charges()->where('status', Charge::STATUS_PENDING)->sum('net_amount');
    }
}
