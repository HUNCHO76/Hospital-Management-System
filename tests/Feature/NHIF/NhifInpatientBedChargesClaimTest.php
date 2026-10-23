<?php

namespace Tests\Feature\NHIF;

use App\Http\Controllers\NhifClaimController;
use App\Models\Admission;
use App\Models\ClinicalEncounter;
use App\Models\Hospital;
use App\Models\NhifAdmission;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\Staff;
use App\Models\User;
use App\Services\NHIF\ClaimCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class NhifInpatientBedChargesClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_inpatient_admission_calculates_bed_charges(): void
    {
        $hospital = Hospital::factory()->create(['code' => 'MAIN', 'nhif_facility_code' => '01141']);
        $user = User::factory()->create();
        $this->actingAs($user);

        $patient = Patient::factory()->create();
        $insurance = PatientInsurance::factory()->create([
            'patient_id' => $patient->id,
            'card_number' => '12345678',
            'nhif_authorization_no' => 'AUTH123456',
        ]);

        $admission = Admission::factory()->create([
            'hospital_id' => $hospital->id,
            'patient_id' => $patient->id,
            'patient_insurance_id' => $insurance->id,
            'ward_type' => 'Normal Ward',
            'charges_per_day' => 20000.00,
            'admitted_at' => now()->subDay(),
            'discharged_at' => now(),
            'status' => Admission::STATUS_DISCHARGED,
        ]);

        $calculator = app(ClaimCalculationService::class);
        $readiness = $calculator->validateClaimReadiness($admission);

        $bedItems = collect($readiness['items'])->filter(fn ($i) => $i['nhif_item_code'] === '21');
        $this->assertNotEmpty($bedItems);
        $this->assertEquals(20000.00, $bedItems->first()['amount_claimed']);
    }

    public function test_clinical_encounter_linked_to_admission_calculates_bed_charges(): void
    {
        $hospital = Hospital::factory()->create(['code' => 'MAIN', 'nhif_facility_code' => '01141']);
        $user = User::factory()->create();
        $this->actingAs($user);

        $patient = Patient::factory()->create();
        $insurance = PatientInsurance::factory()->create([
            'patient_id' => $patient->id,
            'card_number' => '12345678',
            'nhif_authorization_no' => 'AUTH123456',
        ]);

        $encounter = ClinicalEncounter::factory()->create([
            'patient_id' => $patient->id,
            'status' => ClinicalEncounter::STATUS_COMPLETED,
        ]);

        $admission = Admission::factory()->create([
            'hospital_id' => $hospital->id,
            'patient_id' => $patient->id,
            'patient_insurance_id' => $insurance->id,
            'clinical_encounter_id' => $encounter->id,
            'ward_type' => 'Normal Ward',
            'charges_per_day' => 20000.00,
            'admitted_at' => now()->subDay(),
            'discharged_at' => now(),
            'status' => Admission::STATUS_DISCHARGED,
        ]);

        $calculator = app(ClaimCalculationService::class);
        $readiness = $calculator->validateClaimReadiness($encounter);

        $bedItems = collect($readiness['items'])->filter(fn ($i) => $i['nhif_item_code'] === '21');
        $this->assertNotEmpty($bedItems);
        $this->assertEquals(20000.00, $bedItems->first()['amount_claimed']);
    }

    public function test_eligible_visits_excludes_encounters_that_are_linked_to_admissions(): void
    {
        $hospital = Hospital::factory()->create(['code' => 'MAIN', 'nhif_facility_code' => '01141']);
        $user = userWithRole('hospital-admin');
        Staff::factory()->create(['user_id' => $user->id, 'hospital_id' => $hospital->id]);
        $this->actingAs($user);

        $patient = Patient::factory()->create();
        $insurance = PatientInsurance::factory()->create([
            'patient_id' => $patient->id,
            'card_number' => '12345678',
            'nhif_authorization_no' => 'AUTH123456',
        ]);

        $encounter = ClinicalEncounter::factory()->create([
            'patient_id' => $patient->id,
            'status' => ClinicalEncounter::STATUS_COMPLETED,
        ]);

        $admission = Admission::factory()->create([
            'hospital_id' => $hospital->id,
            'patient_id' => $patient->id,
            'patient_insurance_id' => $insurance->id,
            'clinical_encounter_id' => $encounter->id,
            'ward_type' => 'Normal Ward',
            'charges_per_day' => 20000.00,
            'admitted_at' => now()->subDay(),
            'status' => Admission::STATUS_ACTIVE,
        ]);

        $controller = app(NhifClaimController::class);
        $request = Request::create('/nhif/claims/eligible-visits', 'GET');
        $request->setUserResolver(fn () => $user);

        $response = $controller->eligibleVisits($request);
        $data = json_decode($response->getContent(), true)['data'];

        $encEntries = collect($data)->where('type', 'encounter');
        $admEntries = collect($data)->where('type', 'admission');

        $this->assertEmpty($encEntries);
        $this->assertNotEmpty($admEntries);
        $this->assertEquals($admission->id, $admEntries->first()['id']);
    }

    public function test_nhif_admission_authoritative_charges_per_day_is_used(): void
    {
        $hospital = Hospital::factory()->create(['code' => 'MAIN', 'nhif_facility_code' => '01141']);
        $user = User::factory()->create();
        $this->actingAs($user);

        $patient = Patient::factory()->create();
        $insurance = PatientInsurance::factory()->create([
            'patient_id' => $patient->id,
            'card_number' => '12345678',
            'nhif_authorization_no' => 'AUTH888999',
        ]);

        $admission = Admission::factory()->create([
            'hospital_id' => $hospital->id,
            'patient_id' => $patient->id,
            'patient_insurance_id' => $insurance->id,
            'ward_type' => 'Normal Ward',
            'charges_per_day' => 20000.00,
            'admitted_at' => now()->subDay(),
            'discharged_at' => now(),
            'status' => Admission::STATUS_DISCHARGED,
        ]);

        NhifAdmission::create([
            'admission_id' => $admission->id,
            'nhif_authorization_no' => 'AUTH888999',
            'nhif_ward_type_id' => 21,
            'nhif_status' => NhifAdmission::STATUS_SUBMITTED,
            'raw_request' => json_encode([
                'chargesPerDay' => 100000,
            ]),
            'raw_response' => json_encode([
                'ChargesPerDay' => 100000,
            ]),
        ]);

        $calculator = app(ClaimCalculationService::class);
        $readiness = $calculator->validateClaimReadiness($admission);

        $bedItems = collect($readiness['items'])->filter(fn ($i) => $i['nhif_item_code'] === '21');
        $this->assertNotEmpty($bedItems);
        $this->assertEquals(100000.00, $bedItems->first()['amount_claimed']);
        $this->assertEquals(100000.00, $bedItems->first()['unit_price']);
    }
}
