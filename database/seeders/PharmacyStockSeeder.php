<?php

namespace Database\Seeders;

use App\Models\Hospital;
use App\Models\Medication;
use App\Models\StockBatch;
use App\Models\StockItem;
use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds initial pharmacy stock (StockItem + StockBatch + opening StockTransaction)
 * for every active medication in the catalog, scoped to the MAIN hospital.
 *
 * Design decisions:
 *  - One StockItem per (hospital, medication) — mirrors the unique constraint.
 *  - One opening batch per item using a generated lot number — representative
 *    of a first GRN from a supplier. Quantity, cost, and expiry are plausible
 *    values per medication category, not random noise.
 *  - One TYPE_RECEIPT StockTransaction records the opening receipt in the
 *    ledger, so reports and audit trails are consistent from day one.
 *  - Idempotent: uses firstOrCreate() on (hospital_id, medication_id) for
 *    StockItem and (stock_item_id, batch_number) for StockBatch, then
 *    creates the StockTransaction only when the batch was just created.
 *  - Runs after MedicationSeeder and HospitalSeeder — both must exist.
 */
class PharmacyStockSeeder extends Seeder
{
    /**
     * Overrides keyed by medication code.
     * Keys: quantity, unit_cost, reorder_level, maximum_level, months_to_expiry.
     *
     * @var array<string, array{quantity?: int, unit_cost?: string, reorder_level?: int, maximum_level?: int, months_to_expiry?: int}>
     */
    protected array $overrides = [
        // Injections hold fewer units, cost more, expire sooner.
        'CFT-1G-INJ' => ['quantity' => 200, 'unit_cost' => '280.00', 'reorder_level' => 30, 'maximum_level' => 400, 'months_to_expiry' => 18],
        'INS-100IU-INJ' => ['quantity' => 100, 'unit_cost' => '450.00', 'reorder_level' => 20, 'maximum_level' => 200, 'months_to_expiry' => 12],
        // Inhalers — limited shelf life.
        'SAL-100MCG-INH' => ['quantity' => 60, 'unit_cost' => '130.00', 'reorder_level' => 10, 'maximum_level' => 120, 'months_to_expiry' => 18],
        // Topicals — large unit cost.
        'PVI-10PCT-SOL' => ['quantity' => 150, 'unit_cost' => '90.00', 'reorder_level' => 20, 'maximum_level' => 300, 'months_to_expiry' => 24],
        'SSD-1PCT-CRM' => ['quantity' => 80, 'unit_cost' => '180.00', 'reorder_level' => 15, 'maximum_level' => 160, 'months_to_expiry' => 24],
        // High-volume oral tablets.
        'PCM-500-TAB' => ['quantity' => 5000, 'unit_cost' => '4.00', 'reorder_level' => 500, 'maximum_level' => 10000, 'months_to_expiry' => 36],
        'AMOX-500-CAP' => ['quantity' => 2000, 'unit_cost' => '18.00', 'reorder_level' => 200, 'maximum_level' => 4000, 'months_to_expiry' => 30],
        'ARTLUM-20120-TAB' => ['quantity' => 1000, 'unit_cost' => '180.00', 'reorder_level' => 100, 'maximum_level' => 2000, 'months_to_expiry' => 24],
        'MTF-500-TAB' => ['quantity' => 3000, 'unit_cost' => '12.00', 'reorder_level' => 300, 'maximum_level' => 6000, 'months_to_expiry' => 30],
    ];

    public function run(): void
    {
        $hospital = Hospital::where('code', 'MAIN')->firstOrFail();
        $user = User::where('email', 'hospitaladmin@hms.test')->first()
            ?? User::first();

        Medication::where('status', Medication::STATUS_ACTIVE)
            ->orderBy('code')
            ->each(function (Medication $medication) use ($hospital, $user): void {
                $this->seedMedication($hospital->id, $medication, $user->id);
            });
    }

    private function seedMedication(int $hospitalId, Medication $medication, int $userId): void
    {
        $override = $this->overrides[$medication->code] ?? [];

        $reorderLevel = $override['reorder_level'] ?? $this->defaultReorderLevel($medication);
        $maximumLevel = $override['maximum_level'] ?? $reorderLevel * 4;
        $quantity = $override['quantity'] ?? $reorderLevel * 2;
        $unitCost = $override['unit_cost'] ?? $this->defaultUnitCost($medication);
        $monthsToExpiry = $override['months_to_expiry'] ?? $this->defaultMonthsToExpiry($medication);

        [$item, $itemCreated] = $this->firstOrCreateStockItem(
            $hospitalId, $medication->id, $reorderLevel, $maximumLevel, $userId
        );

        $batchNumber = 'OPEN-'.str_replace('-', '', $medication->code).'-001';

        [$batch, $batchCreated] = $this->firstOrCreateBatch(
            $item->id, $batchNumber, $quantity, $unitCost, $monthsToExpiry, $userId
        );

        if ($batchCreated) {
            $this->recordOpeningReceipt($item->id, $batch->id, $quantity, $userId);
        }
    }

    /**
     * @return array{StockItem, bool}
     */
    private function firstOrCreateStockItem(
        int $hospitalId,
        int $medicationId,
        int $reorderLevel,
        int $maximumLevel,
        int $userId
    ): array {
        $existing = StockItem::where('hospital_id', $hospitalId)
            ->where('medication_id', $medicationId)
            ->first();

        if ($existing) {
            return [$existing, false];
        }

        $item = StockItem::create([
            'hospital_id' => $hospitalId,
            'medication_id' => $medicationId,
            'reorder_level' => $reorderLevel,
            'maximum_level' => $maximumLevel,
            'status' => StockItem::STATUS_ACTIVE,
            'created_by' => $userId,
        ]);

        return [$item, true];
    }

    /**
     * @return array{StockBatch, bool}
     */
    private function firstOrCreateBatch(
        int $stockItemId,
        string $batchNumber,
        int $quantity,
        string $unitCost,
        int $monthsToExpiry,
        int $userId
    ): array {
        $existing = StockBatch::where('stock_item_id', $stockItemId)
            ->where('batch_number', $batchNumber)
            ->first();

        if ($existing) {
            return [$existing, false];
        }

        $batch = StockBatch::create([
            'stock_item_id' => $stockItemId,
            'batch_number' => $batchNumber,
            'manufacture_date' => now()->subMonths(3)->toDateString(),
            'expiry_date' => now()->addMonths($monthsToExpiry)->toDateString(),
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'supplier_name' => 'Opening Stock',
            'status' => StockBatch::STATUS_ACTIVE,
            'created_by' => $userId,
        ]);

        return [$batch, true];
    }

    private function recordOpeningReceipt(int $stockItemId, int $batchId, int $quantity, int $userId): void
    {
        StockTransaction::create([
            'stock_item_id' => $stockItemId,
            'stock_batch_id' => $batchId,
            'transaction_type' => StockTransaction::TYPE_RECEIPT,
            'quantity' => $quantity,
            'balance_before' => 0,
            'balance_after' => $quantity,
            'reason' => 'Opening stock receipt',
            'performed_by' => $userId,
            'occurred_at' => now(),
        ]);
    }

    private function defaultReorderLevel(Medication $medication): int
    {
        return match ($medication->form) {
            'injection' => 50,
            'inhaler' => 20,
            'cream', 'ointment', 'solution', 'drops' => 30,
            'powder' => 40,
            default => 200, // tablets, capsules, syrups
        };
    }

    private function defaultUnitCost(Medication $medication): string
    {
        $price = (float) ($medication->price ?? '10.00');

        return number_format(max(1.0, $price * 0.7), 2, '.', '');
    }

    private function defaultMonthsToExpiry(Medication $medication): int
    {
        return match ($medication->form) {
            'injection' => 18,
            'cream', 'ointment' => 24,
            'inhaler' => 18,
            'syrup', 'suspension' => 24,
            default => 36,
        };
    }
}
