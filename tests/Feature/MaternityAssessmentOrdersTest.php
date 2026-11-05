<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryTest;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\PrescriptionOrder;
use App\Models\RadiologyOrder;
use App\Models\RadiologyProcedure;
use App\Models\ReceptionVisit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaternityAssessmentOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected User $nurse;

    protected ReceptionVisit $visit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionSeeder::class,
            RoleSeeder::class,
        ]);

        $this->nurse = User::factory()->create();
        $this->nurse->assignRole('nurse');

        $patient = Patient::factory()->create([
            'gender' => 'female',
        ]);

        $department = Department::factory()->create([
            'name' => 'Maternity Outpatient',
            'code' => 'MAT-OPD',
        ]);

        $this->visit = ReceptionVisit::factory()->create([
            'patient_id' => $patient->id,
            'department_id' => $department->id,
            'maternity_status' => ReceptionVisit::MATERNITY_STATUS_WAITING,
        ]);
    }

    public function test_nurse_can_view_mother_assessment_with_orders_props(): void
    {
        $response = $this->actingAs($this->nurse)
            ->get(route('maternity.mother-assessment', $this->visit->id));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('maternity/mother-assessment')
                ->has('laboratoryOrders')
                ->has('prescriptionOrders')
                ->has('radiologyOrders')
                ->has('radiologyProcedures')
                ->where('canPrescribe', true)
                ->where('canOrderLabs', true)
                ->where('canOrderRadiology', true)
            );
    }

    public function test_nurse_can_prescribe_medication_on_mother_assessment(): void
    {
        $medication = Medication::factory()->create([
            'generic_name' => 'Ferrous Sulfate + Folic Acid',
            'status' => Medication::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($this->nurse)
            ->post(route('maternity.prescriptions.store', $this->visit->id), [
                'items' => [
                    [
                        'medication_id' => $medication->id,
                        'dose' => '200',
                        'dose_unit' => 'mg',
                        'route' => 'oral',
                        'frequency' => 'once_daily',
                        'duration' => 30,
                        'duration_unit' => 'days',
                        'quantity' => 30,
                        'instructions' => 'Take 1 tablet daily after meals',
                    ],
                ],
                'clinical_notes' => 'Routine Antenatal Supplementation',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('prescription_orders', [
            'prescriber_id' => $this->nurse->id,
            'status' => PrescriptionOrder::STATUS_ACTIVE,
            'clinical_notes' => 'Routine Antenatal Supplementation',
        ]);

        $this->assertDatabaseHas('prescription_order_items', [
            'medication_id' => $medication->id,
            'dose' => '200',
            'dose_unit' => 'mg',
            'route' => 'oral',
            'frequency' => 'once_daily',
            'quantity' => 30,
        ]);
    }

    public function test_nurse_can_order_laboratory_tests_on_mother_assessment(): void
    {
        $hbTest = LaboratoryTest::factory()->create([
            'code' => 'LAB-HB',
            'name' => 'Hemoglobin Level',
            'status' => LaboratoryTest::STATUS_ACTIVE,
        ]);

        $urinalysis = LaboratoryTest::factory()->create([
            'code' => 'LAB-URI',
            'name' => 'Urinalysis Complete',
            'status' => LaboratoryTest::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($this->nurse)
            ->post(route('maternity.laboratory-orders.store', $this->visit->id), [
                'test_ids' => [$hbTest->id, $urinalysis->id],
                'priority' => LaboratoryOrder::PRIORITY_ROUTINE,
                'clinical_indication' => 'Routine Antenatal Profile (ANC)',
                'notes' => 'First ANC booking labs',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('laboratory_orders', [
            'ordered_by' => $this->nurse->id,
            'priority' => LaboratoryOrder::PRIORITY_ROUTINE,
            'clinical_indication' => 'Routine Antenatal Profile (ANC)',
            'status' => LaboratoryOrder::STATUS_ORDERED,
        ]);

        $this->assertDatabaseHas('laboratory_order_items', [
            'laboratory_test_id' => $hbTest->id,
        ]);

        $this->assertDatabaseHas('laboratory_order_items', [
            'laboratory_test_id' => $urinalysis->id,
        ]);
    }

    public function test_nurse_can_order_ultrasound_on_mother_assessment(): void
    {
        $obstetricUs = RadiologyProcedure::factory()->create([
            'code' => 'US-OBS',
            'name' => 'Obstetric Ultrasound Scan',
            'modality' => 'Ultrasound',
            'status' => RadiologyProcedure::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($this->nurse)
            ->post(route('maternity.radiology-orders.store', $this->visit->id), [
                'procedure_ids' => [$obstetricUs->id],
                'priority' => RadiologyOrder::PRIORITY_ROUTINE,
                'clinical_indication' => 'Routine Obstetric Dating & Viability Scan',
                'notes' => 'Confirm gestational age and fetal viability',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('radiology_orders', [
            'ordered_by' => $this->nurse->id,
            'priority' => RadiologyOrder::PRIORITY_ROUTINE,
            'clinical_indication' => 'Routine Obstetric Dating & Viability Scan',
            'status' => RadiologyOrder::STATUS_ORDERED,
        ]);

        $this->assertDatabaseHas('radiology_order_items', [
            'radiology_procedure_id' => $obstetricUs->id,
            'procedure_code' => 'US-OBS',
        ]);
    }
}

