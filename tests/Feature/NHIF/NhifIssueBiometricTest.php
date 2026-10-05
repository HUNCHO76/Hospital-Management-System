<?php

namespace Tests\Feature\NHIF;

use App\Models\Hospital;
use App\Models\NhifPreApprovalSession;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NhifIssueBiometricTest extends TestCase
{
    use RefreshDatabase;

    public function test_issue_pre_approval_with_biometrics()
    {
        $hospital = Hospital::factory()->create();
        $user = userWithRole('hospital-admin');
        $user->givePermissionTo('nhif-preapproval.create');
        Staff::factory()->create(['user_id' => $user->id, 'hospital_id' => $hospital->id]);

        $patient = Patient::factory()->create();
        $insurance = PatientInsurance::factory()->create([
            'patient_id' => $patient->id,
            'nhif_authorization_no' => 'AUTH-2024-001',
            'card_number' => 'TEST123456',
        ]);

        $session = NhifPreApprovalSession::factory()->approved()->create([
            'patient_id' => $patient->id,
            'patient_insurance_id' => $insurance->id,
            'hospital_id' => $hospital->id,
            'nhif_authorization_no' => 'AUTH-2024-001',
            'created_by' => $user->id,
            'facility_code' => 'FAC001',
        ]);

        \App\Models\NhifPreApprovalSessionItem::factory()->approved()->create([
            'nhif_pre_approval_session_id' => $session->id,
            'nhif_item_code' => 'CONSULT001',
            'approval_reference_no' => 'REF-001',
        ]);

        $mockClient = \Mockery::mock(\App\Services\NHIF\NhifHttpClient::class);
        $mockClient->shouldReceive('issuePreApprovalRequestServices')
            ->once()
            ->andReturn([
                'success' => true,
                'data' => [],
                'http_status' => 200,
                'error' => null,
                'correlation_id' => 'x',
                'operation' => 'IssueRequestServices',
            ]);

        app()->instance(\App\Services\NHIF\NhifHttpClient::class, $mockClient);

        $response = $this->actingAs($user)->post(route('patients.insurance.nhif.pre-approval-sessions.issue', [
            $patient->id,
            $insurance->id,
            $session->id,
        ]), [
            'biometricMethod' => 'FINGERPRINT',
            'fpCode' => 'R1',
            'imageData' => 'YmFzZTY0LWVuY29kZWQtaW1hZ2UtZGF0YQ==',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        
        $session->refresh();
        $this->assertEquals(\App\Models\NhifPreApprovalSession::STATUS_ISSUED, $session->status);
        
        $payload = $session->issue_payload;
        $this->assertEquals('FINGERPRINT', $payload['biometricMethod']);
        $this->assertEquals('R1', $payload['fpCode']);
        $this->assertStringContainsString('[base64 omitted', $payload['imageData']);
    }
}
