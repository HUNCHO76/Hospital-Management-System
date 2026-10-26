<?php

namespace Tests\Feature;

use App\Models\ClinicalEncounter;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Models\Staff;
use App\Models\StockBatch;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicationStockAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setupEncounterWithDoctor(): array
    {
        $hospital = Hospital::factory()->create();
        $department = Department::factory()->create([
            'hospital_id' => $hospital->id,
            'status' => Department::STATUS_ACTIVE,
        ]);

        $user = userWithRole('doctor');
        $staff = Staff::factory()->create([
            'user_id' => $user->id,
            'hospital_id' => $hospital->id,
            'department_id' => $department->id,
            'status' => Staff::STATUS_ACTIVE,
        ]);
        $practitioner = Practitioner::factory()->create([
            'staff_id' => $staff->id,
            'status' => Practitioner::STATUS_ACTIVE,
        ]);

        $patient = Patient::factory()->create();
        $encounter = ClinicalEncounter::factory()->create([
            'patient_id' => $patient->id,
            'department_id' => $department->id,
            'practitioner_id' => $practitioner->id,
            'encounter_type' => 'inpatient',
            'status' => ClinicalEncounter::STATUS_IN_PROGRESS,
        ]);

        return compact('hospital', 'department', 'user', 'staff', 'practitioner', 'patient', 'encounter');
    }

    public function test_search_json_returns_stock_availability_scoped_to_hospital(): void
    {
        $ctx = $this->setupEncounterWithDoctor();

        // Create medication with active batches
        $medicationInStock = Medication::factory()->create([
            'generic_name' => 'Amoxicillin Trihydrate',
            'status' => Medication::STATUS_ACTIVE,
        ]);

        $stockItemInStock = StockItem::factory()->create([
            'hospital_id' => $ctx['hospital']->id,
            'medication_id' => $medicationInStock->id,
            'reorder_level' => 10,
        ]);

        // Active, valid batch: 50 units
        StockBatch::factory()->create([
            'stock_item_id' => $stockItemInStock->id,
            'batch_number' => 'BATCH-001',
            'quantity' => 50,
            'status' => StockBatch::STATUS_ACTIVE,
            'expiry_date' => now()->addMonths(6)->toDateString(),
        ]);

        // Expired batch: 30 units (must be excluded from available_stock)
        StockBatch::factory()->create([
            'stock_item_id' => $stockItemInStock->id,
            'batch_number' => 'BATCH-EXP',
            'quantity' => 30,
            'status' => StockBatch::STATUS_ACTIVE,
            'expiry_date' => now()->subDay()->toDateString(),
        ]);

        // Quarantined batch: 20 units (must be excluded from available_stock)
        StockBatch::factory()->create([
            'stock_item_id' => $stockItemInStock->id,
            'batch_number' => 'BATCH-QRN',
            'quantity' => 20,
            'status' => StockBatch::STATUS_QUARANTINED,
            'expiry_date' => now()->addMonths(6)->toDateString(),
        ]);

        // Medication with 0 stock
        $medicationOutOfStock = Medication::factory()->create([
            'generic_name' => 'Amoxicillin Clavulanate',
            'status' => Medication::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($ctx['user'])
            ->getJson(route('medications.search-json', [
                'search' => 'Amoxicillin',
                'clinical_encounter_id' => $ctx['encounter']->id,
            ]));

        $response->assertOk();

        $data = collect($response->json('data'));

        $itemInStock = $data->firstWhere('id', $medicationInStock->id);
        $this->assertNotNull($itemInStock);
        $this->assertEquals(50, $itemInStock['available_stock']);
        $this->assertEquals(10, $itemInStock['reorder_level']);
        $this->assertFalse($itemInStock['is_out_of_stock']);
        $this->assertFalse($itemInStock['is_low_stock']);

        $itemOutOfStock = $data->firstWhere('id', $medicationOutOfStock->id);
        $this->assertNotNull($itemOutOfStock);
        $this->assertEquals(0, $itemOutOfStock['available_stock']);
        $this->assertTrue($itemOutOfStock['is_out_of_stock']);
    }

    public function test_search_json_flags_low_stock_when_at_or_below_reorder_level(): void
    {
        $ctx = $this->setupEncounterWithDoctor();

        $medicationLowStock = Medication::factory()->create([
            'generic_name' => 'Paracetamol Tablets',
            'status' => Medication::STATUS_ACTIVE,
        ]);

        $stockItem = StockItem::factory()->create([
            'hospital_id' => $ctx['hospital']->id,
            'medication_id' => $medicationLowStock->id,
            'reorder_level' => 15,
        ]);

        StockBatch::factory()->create([
            'stock_item_id' => $stockItem->id,
            'batch_number' => 'PARA-001',
            'quantity' => 8,
            'status' => StockBatch::STATUS_ACTIVE,
            'expiry_date' => now()->addMonths(12)->toDateString(),
        ]);

        $response = $this->actingAs($ctx['user'])
            ->getJson(route('medications.search-json', [
                'search' => 'Paracetamol',
                'clinical_encounter_id' => $ctx['encounter']->id,
            ]));

        $response->assertOk();

        $item = collect($response->json('data'))->firstWhere('id', $medicationLowStock->id);
        $this->assertNotNull($item);
        $this->assertEquals(8, $item['available_stock']);
        $this->assertEquals(15, $item['reorder_level']);
        $this->assertTrue($item['is_low_stock']);
        $this->assertFalse($item['is_out_of_stock']);
    }

    public function test_prescription_creation_flashes_pharmacy_stock_alert_when_deficit_exists(): void
    {
        $ctx = $this->setupEncounterWithDoctor();

        $medicationOutOfStock = Medication::factory()->create([
            'generic_name' => 'Ibuprofen Syrup',
            'status' => Medication::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($ctx['user'])
            ->post(route('clinical-encounters.prescription-orders.store', $ctx['encounter']), [
                'items' => [
                    [
                        'medication_id' => $medicationOutOfStock->id,
                        'dose' => '10',
                        'dose_unit' => 'ml',
                        'frequency' => 'three_times_daily',
                        'route' => 'oral',
                        'duration' => 5,
                        'duration_unit' => 'days',
                        'quantity' => 1,
                        'quantity_unit' => 'bottle',
                        'instructions' => 'Take after meals',
                    ],
                ],
                'send_preapproval' => false,
            ]);

        $response->assertStatus(302);
        if ($response->getSession()->has('errors')) {
            dd($response->getSession()->get('errors')->all());
        }
        $msg = $response->getSession()->get('success');
        $this->assertStringContainsString('Prescription written.', (string) $msg);
        $this->assertStringContainsString('Pharmacy stock alert', (string) $msg);
        $this->assertStringContainsString('Ibuprofen Syrup (Out of Stock)', (string) $msg);
    }
}
