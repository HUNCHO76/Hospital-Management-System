<?php

use App\Models\Admission;
use App\Models\Charge;
use App\Models\ClinicalEncounter;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\AdmissionService;
use App\Services\ChargeService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Validation\ValidationException;

/**
 * @return array{hospital: Hospital, department: Department, practitioner: Practitioner, patient: Patient, service: Service}
 */
function admissionReadyContext(): array
{
    $hospital = Hospital::factory()->create();
    $department = Department::factory()->create(['hospital_id' => $hospital->id]);
    $staff = Staff::factory()->create(['hospital_id' => $hospital->id]);
    $practitioner = Practitioner::factory()->create(['staff_id' => $staff->id]);
    $patient = Patient::factory()->create();
    $service = serviceFor(['category' => 'inpatient']);
    priceFor($service, $hospital, ['amount' => 20000]);

    return compact('hospital', 'department', 'practitioner', 'patient', 'service');
}

// ---------------------------------------------------------------------------
// Shared helper: advance through the full review/approve flow
// ---------------------------------------------------------------------------

function approvedAdmission(Admission $admission, User $reviewer): Admission
{
    $admission = app(AdmissionService::class)->startReview($admission, requestAs($reviewer));

    return app(AdmissionService::class)->approve($admission, null, requestAs($reviewer));
}

// ---------------------------------------------------------------------------
// 1. Creating an admission request
// ---------------------------------------------------------------------------

test('creating an admission request succeeds, is pending, and captures a charge when a priced service is selected', function () {
    $ctx = admissionReadyContext();
    $user = userWithRole('nurse');

    $admission = app(AdmissionService::class)->create([
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'service_id' => $ctx['service']->id,
        'admission_type' => 'emergency',
        'diagnosis_at_admission' => 'Acute appendicitis',
    ], requestAs($user));

    expect($admission->status)->toBe(Admission::STATUS_PENDING)
        ->and($admission->admission_number)->toStartWith('ADM-')
        ->and((float) $admission->charges_per_day)->toBe(20000.0);

    $this->assertDatabaseHas('charges', [
        'source_type' => Admission::class,
        'source_id' => $admission->id,
        'unit_price' => 20000,
    ]);
});

test('a request with no billing service selected still succeeds, uncharged', function () {
    $ctx = admissionReadyContext();
    $user = userWithRole('nurse');

    $admission = app(AdmissionService::class)->create([
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
    ], requestAs($user));

    expect($admission->status)->toBe(Admission::STATUS_PENDING)
        ->and($admission->charges_per_day)->toBeNull();
    $this->assertDatabaseCount('charges', 0);
});

// ---------------------------------------------------------------------------
// 2. Validation
// ---------------------------------------------------------------------------

test('store rejects a non-existent hospital or patient', function () {
    $user = userWithRole('nurse');

    $this->actingAs($user)->post(route('admissions.store'), [
        'hospital_id' => 999999,
        'patient_id' => 999999,
    ])->assertSessionHasErrors(['hospital_id', 'patient_id']);
});

test('store rejects an invalid admission_type', function () {
    $ctx = admissionReadyContext();
    $user = userWithRole('nurse');

    $this->actingAs($user)->post(route('admissions.store'), [
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
        'admission_type' => 'not-a-real-type',
    ])->assertSessionHasErrors('admission_type');
});

// ---------------------------------------------------------------------------
// 3. Review workflow — startReview
// ---------------------------------------------------------------------------

test('placing a pending admission under review transitions to under_review and records the reviewer', function () {
    $ctx = admissionReadyContext();
    $reviewer = userWithRole('nurse');

    $admission = app(AdmissionService::class)->create([
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
    ], requestAs($reviewer));

    $underReview = app(AdmissionService::class)->startReview($admission, requestAs($reviewer));

    expect($underReview->status)->toBe(Admission::STATUS_UNDER_REVIEW)
        ->and($underReview->reviewed_by)->toBe($reviewer->id)
        ->and($underReview->reviewed_at)->not->toBeNull();
});

test('startReview fails on a non-pending admission', function () {
    $ctx = admissionReadyContext();
    $reviewer = userWithRole('nurse');
    $admission = app(AdmissionService::class)->create(['hospital_id' => $ctx['hospital']->id, 'patient_id' => $ctx['patient']->id], requestAs($reviewer));
    $admission = app(AdmissionService::class)->startReview($admission, requestAs($reviewer));

    expect(fn () => app(AdmissionService::class)->startReview($admission, requestAs($reviewer)))
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// 4. Review workflow — approve
// ---------------------------------------------------------------------------

test('approving an under-review admission transitions to approved', function () {
    $ctx = admissionReadyContext();
    $reviewer = userWithRole('nurse');

    $admission = app(AdmissionService::class)->create([
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
    ], requestAs($reviewer));

    $admission = app(AdmissionService::class)->startReview($admission, requestAs($reviewer));
    $approved = app(AdmissionService::class)->approve($admission, 'Looks good.', requestAs($reviewer));

    expect($approved->status)->toBe(Admission::STATUS_APPROVED)
        ->and($approved->approved_by)->toBe($reviewer->id)
        ->and($approved->approved_at)->not->toBeNull();
});

test('approve fails if the admission is not under review', function () {
    $ctx = admissionReadyContext();
    $reviewer = userWithRole('nurse');
    $admission = app(AdmissionService::class)->create(['hospital_id' => $ctx['hospital']->id, 'patient_id' => $ctx['patient']->id], requestAs($reviewer));

    expect(fn () => app(AdmissionService::class)->approve($admission, null, requestAs($reviewer)))
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// 5. Review workflow — reject
// ---------------------------------------------------------------------------

test('rejecting an under-review admission records the reason and reaches terminal rejected state', function () {
    $ctx = admissionReadyContext();
    $reviewer = userWithRole('nurse');

    $admission = app(AdmissionService::class)->create([
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
    ], requestAs($reviewer));

    $admission = app(AdmissionService::class)->startReview($admission, requestAs($reviewer));
    $rejected = app(AdmissionService::class)->reject($admission, 'Does not meet clinical criteria.', requestAs($reviewer));

    expect($rejected->status)->toBe(Admission::STATUS_REJECTED)
        ->and($rejected->rejection_reason)->toBe('Does not meet clinical criteria.')
        ->and($rejected->rejected_by)->toBe($reviewer->id)
        ->and($rejected->rejected_at)->not->toBeNull();
});

test('reject fails if the admission is not under review', function () {
    $ctx = admissionReadyContext();
    $reviewer = userWithRole('nurse');
    $admission = app(AdmissionService::class)->create(['hospital_id' => $ctx['hospital']->id, 'patient_id' => $ctx['patient']->id], requestAs($reviewer));

    expect(fn () => app(AdmissionService::class)->reject($admission, 'not valid', requestAs($reviewer)))
        ->toThrow(ValidationException::class);
});

test('reject route requires a reason', function () {
    seedRolesAndPermissions();
    $ctx = admissionReadyContext();
    $reviewer = userWithRole('nurse');

    $admission = app(AdmissionService::class)->create(['hospital_id' => $ctx['hospital']->id, 'patient_id' => $ctx['patient']->id], requestAs($reviewer));
    $admission = app(AdmissionService::class)->startReview($admission, requestAs($reviewer));

    $this->actingAs($reviewer)->post(route('admissions.reject', $admission), [
        'reason' => '',
    ])->assertSessionHasErrors('reason');
});

// ---------------------------------------------------------------------------
// 6. Review workflow — return for correction
// ---------------------------------------------------------------------------

test('returning for correction moves the admission back to pending with correction notes', function () {
    $ctx = admissionReadyContext();
    $reviewer = userWithRole('nurse');

    $admission = app(AdmissionService::class)->create([
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
    ], requestAs($reviewer));

    $admission = app(AdmissionService::class)->startReview($admission, requestAs($reviewer));
    $returned = app(AdmissionService::class)->returnForCorrection($admission, 'Please update the diagnosis field.', requestAs($reviewer));

    expect($returned->status)->toBe(Admission::STATUS_PENDING)
        ->and($returned->notes)->toBe('Please update the diagnosis field.');
});

test('returnForCorrection fails on a non-under-review admission', function () {
    $ctx = admissionReadyContext();
    $reviewer = userWithRole('nurse');
    $admission = app(AdmissionService::class)->create(['hospital_id' => $ctx['hospital']->id, 'patient_id' => $ctx['patient']->id], requestAs($reviewer));

    expect(fn () => app(AdmissionService::class)->returnForCorrection($admission, 'fix it', requestAs($reviewer)))
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// 7. Confirm — requires APPROVED, not just PENDING
// ---------------------------------------------------------------------------

test('confirm is blocked on a pending admission — review and approval must happen first', function () {
    $ctx = admissionReadyContext();
    $user = userWithRole('nurse');

    $admission = app(AdmissionService::class)->create([
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
    ], requestAs($user));

    expect(fn () => app(AdmissionService::class)->confirm($admission, requestAs($user)))
        ->toThrow(ValidationException::class, 'Only an approved admission');

    expect($admission->fresh()->status)->toBe(Admission::STATUS_PENDING);
});

test('confirming an approved admission with no billing blocker transitions to active', function () {
    $ctx = admissionReadyContext();
    $user = userWithRole('nurse');

    $admission = app(AdmissionService::class)->create([
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
    ], requestAs($user)); // no service_id → no charge → no-charge rule applies

    $admission = approvedAdmission($admission, $user);
    $confirmed = app(AdmissionService::class)->confirm($admission, requestAs($user));

    expect($confirmed->status)->toBe(Admission::STATUS_ACTIVE)
        ->and($confirmed->admitted_at)->not->toBeNull()
        ->and($confirmed->admitted_by)->toBe($user->id);
});

// ---------------------------------------------------------------------------
// 8. Payment / NHIF clearance gating (on approved admission)
// ---------------------------------------------------------------------------

test('confirm is blocked while the approved admission charge is unpaid', function () {
    $ctx = admissionReadyContext();
    $user = userWithRole('nurse');

    $admission = app(AdmissionService::class)->create([
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
        'service_id' => $ctx['service']->id,
    ], requestAs($user));

    $this->assertDatabaseCount('charges', 1);

    $admission = approvedAdmission($admission, $user);

    expect(fn () => app(AdmissionService::class)->confirm($admission, requestAs($user)))
        ->toThrow(ValidationException::class, 'Payment is required');

    expect($admission->fresh()->status)->toBe(Admission::STATUS_APPROVED);
});

test('confirm succeeds once the approved admission charge is posted and paid', function () {
    $ctx = admissionReadyContext();
    $user = userWithRole('nurse');

    $admission = app(AdmissionService::class)->create([
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
        'service_id' => $ctx['service']->id,
    ], requestAs($user));

    $charge = Charge::where('source_type', Admission::class)->where('source_id', $admission->id)->sole();
    $account = $charge->patientAccount;
    $posted = app(ChargeService::class)->post($charge, requestAs($user));
    $invoice = app(InvoiceService::class)->createDraft($account, [$posted->id], null, requestAs($user));
    $invoice = app(InvoiceService::class)->post($invoice, requestAs($user));
    app(PaymentService::class)->createPayment([
        'invoice_id' => $invoice->id,
        'amount' => 20000,
        'payment_date' => now()->toDateString(),
        'payment_method' => 'cash',
    ], requestAs($user));

    $admission = approvedAdmission($admission->fresh(), $user);
    $confirmed = app(AdmissionService::class)->confirm($admission, requestAs($user));

    expect($confirmed->status)->toBe(Admission::STATUS_ACTIVE);
});

// ---------------------------------------------------------------------------
// 9. Cancelled at any pre-admission stage
// ---------------------------------------------------------------------------

test('cancelling a pending admission transitions to cancelled', function () {
    $ctx = admissionReadyContext();
    $user = userWithRole('nurse');
    $admission = app(AdmissionService::class)->create(['hospital_id' => $ctx['hospital']->id, 'patient_id' => $ctx['patient']->id], requestAs($user));

    $cancelled = app(AdmissionService::class)->cancel($admission, 'Patient declined admission.', requestAs($user));

    expect($cancelled->status)->toBe(Admission::STATUS_CANCELLED)
        ->and($cancelled->cancellation_reason)->toBe('Patient declined admission.');
});

test('cancelling an under-review admission transitions to cancelled', function () {
    $ctx = admissionReadyContext();
    $user = userWithRole('nurse');
    $admission = app(AdmissionService::class)->create(['hospital_id' => $ctx['hospital']->id, 'patient_id' => $ctx['patient']->id], requestAs($user));
    $admission = app(AdmissionService::class)->startReview($admission, requestAs($user));

    $cancelled = app(AdmissionService::class)->cancel($admission, 'No bed available.', requestAs($user));

    expect($cancelled->status)->toBe(Admission::STATUS_CANCELLED);
});

test('cancelling an approved admission transitions to cancelled', function () {
    $ctx = admissionReadyContext();
    $user = userWithRole('nurse');
    $admission = app(AdmissionService::class)->create(['hospital_id' => $ctx['hospital']->id, 'patient_id' => $ctx['patient']->id], requestAs($user));
    $admission = approvedAdmission($admission, $user);

    $cancelled = app(AdmissionService::class)->cancel($admission, 'Surgery postponed.', requestAs($user));

    expect($cancelled->status)->toBe(Admission::STATUS_CANCELLED);
});

// ---------------------------------------------------------------------------
// 10. Invalid status transitions
// ---------------------------------------------------------------------------

test('an active admission cannot be confirmed again', function () {
    $ctx = admissionReadyContext();
    $user = userWithRole('nurse');
    $admission = app(AdmissionService::class)->create(['hospital_id' => $ctx['hospital']->id, 'patient_id' => $ctx['patient']->id], requestAs($user));
    $admission = approvedAdmission($admission, $user);
    $admission = app(AdmissionService::class)->confirm($admission, requestAs($user));

    expect(fn () => app(AdmissionService::class)->confirm($admission, requestAs($user)))
        ->toThrow(ValidationException::class);
});

test('a pending admission cannot be discharged', function () {
    $ctx = admissionReadyContext();
    $user = userWithRole('nurse');
    $admission = app(AdmissionService::class)->create(['hospital_id' => $ctx['hospital']->id, 'patient_id' => $ctx['patient']->id], requestAs($user));

    expect(fn () => app(AdmissionService::class)->discharge($admission, ['discharge_type' => 'routine', 'discharge_reason' => 'x'], requestAs($user)))
        ->toThrow(ValidationException::class);
});

test('an active admission cannot be cancelled — it must be discharged instead', function () {
    $ctx = admissionReadyContext();
    $user = userWithRole('nurse');
    $admission = app(AdmissionService::class)->create(['hospital_id' => $ctx['hospital']->id, 'patient_id' => $ctx['patient']->id], requestAs($user));
    $admission = approvedAdmission($admission, $user);
    $admission = app(AdmissionService::class)->confirm($admission, requestAs($user));

    expect(fn () => app(AdmissionService::class)->cancel($admission, 'changed my mind', requestAs($user)))
        ->toThrow(ValidationException::class);
});

test('discharging an active admission transitions to discharged', function () {
    $ctx = admissionReadyContext();
    $user = userWithRole('nurse');
    $admission = app(AdmissionService::class)->create(['hospital_id' => $ctx['hospital']->id, 'patient_id' => $ctx['patient']->id], requestAs($user));
    $admission = approvedAdmission($admission, $user);
    $admission = app(AdmissionService::class)->confirm($admission, requestAs($user));

    $discharged = app(AdmissionService::class)->discharge($admission, [
        'discharge_type' => 'routine',
        'discharge_reason' => 'Recovered fully.',
    ], requestAs($user));

    expect($discharged->status)->toBe(Admission::STATUS_DISCHARGED)
        ->and($discharged->discharged_at)->not->toBeNull()
        ->and($discharged->discharge_type)->toBe('routine');
});

// ---------------------------------------------------------------------------
// 11. Facility isolation / IDOR
// ---------------------------------------------------------------------------

test('a hospital-admin cannot view or act on another hospital admission', function () {
    seedRolesAndPermissions();
    $myHospital = Hospital::factory()->create();
    $otherCtx = admissionReadyContext();

    $admin = User::factory()->create();
    $admin->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $admin->id, 'hospital_id' => $myHospital->id]);

    $admission = app(AdmissionService::class)->create([
        'hospital_id' => $otherCtx['hospital']->id,
        'patient_id' => $otherCtx['patient']->id,
    ], requestAs(User::factory()->create()));

    $this->actingAs($admin)->get(route('admissions.show', $admission))->assertForbidden();
    $this->actingAs($admin)->post(route('admissions.review', $admission))->assertForbidden();
    $this->actingAs($admin)->post(route('admissions.approve', $admission))->assertForbidden();
    $this->actingAs($admin)->post(route('admissions.reject', $admission), ['reason' => 'test'])->assertForbidden();
    $this->actingAs($admin)->post(route('admissions.confirm', $admission))->assertForbidden();

    expect($admission->fresh()->status)->toBe(Admission::STATUS_PENDING);
});

// ---------------------------------------------------------------------------
// 12. Audit trail covers all transitions
// ---------------------------------------------------------------------------

test('every transition writes its own named audit log entry', function () {
    $ctx = admissionReadyContext();
    $user = userWithRole('nurse');

    $admission = app(AdmissionService::class)->create(['hospital_id' => $ctx['hospital']->id, 'patient_id' => $ctx['patient']->id], requestAs($user));
    $this->assertDatabaseHas('audit_logs', ['action' => 'admission_requested']);

    $admission = app(AdmissionService::class)->startReview($admission, requestAs($user));
    $this->assertDatabaseHas('audit_logs', ['action' => 'admission_review_started']);

    $second = app(AdmissionService::class)->create(['hospital_id' => $ctx['hospital']->id, 'patient_id' => $ctx['patient']->id], requestAs($user));
    $second = app(AdmissionService::class)->startReview($second, requestAs($user));
    app(AdmissionService::class)->returnForCorrection($second, 'update diagnosis', requestAs($user));
    $this->assertDatabaseHas('audit_logs', ['action' => 'admission_returned_for_correction']);

    $third = app(AdmissionService::class)->create(['hospital_id' => $ctx['hospital']->id, 'patient_id' => $ctx['patient']->id], requestAs($user));
    $third = app(AdmissionService::class)->startReview($third, requestAs($user));
    app(AdmissionService::class)->reject($third, 'not eligible', requestAs($user));
    $this->assertDatabaseHas('audit_logs', ['action' => 'admission_rejected']);

    $admission = app(AdmissionService::class)->approve($admission, null, requestAs($user));
    $this->assertDatabaseHas('audit_logs', ['action' => 'admission_approved']);

    $admission = app(AdmissionService::class)->confirm($admission, requestAs($user));
    $this->assertDatabaseHas('audit_logs', ['action' => 'admission_confirmed']);

    $admission = app(AdmissionService::class)->discharge($admission, ['discharge_type' => 'routine', 'discharge_reason' => 'ok'], requestAs($user));
    $this->assertDatabaseHas('audit_logs', ['action' => 'admission_discharged']);

    $fourth = app(AdmissionService::class)->create(['hospital_id' => $ctx['hospital']->id, 'patient_id' => $ctx['patient']->id], requestAs($user));
    app(AdmissionService::class)->cancel($fourth, 'not needed', requestAs($user));
    $this->assertDatabaseHas('audit_logs', ['action' => 'admission_cancelled']);
});

// ---------------------------------------------------------------------------
// 13. No duplicate patient/encounter records
// ---------------------------------------------------------------------------

test('creating an admission never creates a new patient or clinical encounter record', function () {
    $ctx = admissionReadyContext();
    $encounter = ClinicalEncounter::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
    ]);
    $user = userWithRole('nurse');

    $patientCountBefore = Patient::count();
    $encounterCountBefore = ClinicalEncounter::count();

    $admission = app(AdmissionService::class)->create([
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
        'clinical_encounter_id' => $encounter->id,
    ], requestAs($user));

    expect(Patient::count())->toBe($patientCountBefore)
        ->and(ClinicalEncounter::count())->toBe($encounterCountBefore)
        ->and($admission->clinical_encounter_id)->toBe($encounter->id)
        ->and($admission->patient_id)->toBe($ctx['patient']->id);
});

test('creating an admission automatically resolves nhif_authorization_no from patient insurance if not specified', function () {
    $ctx = admissionReadyContext();
    $user = userWithRole('nurse');

    \App\Models\PatientInsurance::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'nhif_authorization_no' => 'AUTH-AUTO-12345',
        'status' => \App\Models\PatientInsurance::STATUS_ACTIVE,
        'verification_status' => \App\Models\PatientInsurance::VERIFICATION_VERIFIED,
    ]);

    $admission = app(AdmissionService::class)->create([
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
        'nhif_ward_type_id' => 21,
    ], requestAs($user));

    $nhifAdm = \App\Models\NhifAdmission::where('admission_id', $admission->id)->first();
    expect($nhifAdm)->not->toBeNull()
        ->and($nhifAdm->nhif_authorization_no)->toBe('AUTH-AUTO-12345');
});

test('submitNhif records nhif_admission_no and allows resubmitting an already submitted admission', function () {
    $ctx = admissionReadyContext();
    $user = userWithRole('nurse');

    $mockClient = Mockery::mock(\App\Services\NHIF\NhifHttpClient::class);
    $mockClient->shouldReceive('admitPatient')->andReturn([
        'success' => true,
        'http_status' => 200,
        'data' => [
            'AdmissionNo' => '901627800505',
            'AdmissionID' => '579ac53f-3a2a-48a1-8d30-1b0507ef788c',
            'FullName' => 'Ivy Smith',
        ],
        'error' => null,
    ]);
    app()->instance(\App\Services\NHIF\NhifHttpClient::class, $mockClient);

    $admission = app(AdmissionService::class)->create([
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
        'nhif_authorization_no' => '201627864221',
        'nhif_ward_type_id' => 21,
    ], requestAs($user));

    // First submission
    $admission = app(AdmissionService::class)->submitNhif($admission, requestAs($user));
    expect($admission->nhifAdmission->nhif_status)->toBe(\App\Models\NhifAdmission::STATUS_SUBMITTED)
        ->and($admission->nhifAdmission->nhif_admission_no)->toBe('901627800505');

    // Resubmission should be allowed and increment retry count
    $admission = app(AdmissionService::class)->submitNhif($admission, requestAs($user));
    expect($admission->nhifAdmission->nhif_status)->toBe(\App\Models\NhifAdmission::STATUS_SUBMITTED)
        ->and($admission->nhifAdmission->nhif_admission_no)->toBe('901627800505')
        ->and($admission->nhifAdmission->retry_count)->toBeGreaterThan(0);
});

test('NhifAdmissionController lookup retrieves details using GetAdmissionDetailsByAuthorizationNo endpoint and accepts authorizationNo parameter', function () {
    $user = userWithRole('nurse');

    $mockClient = Mockery::mock(\App\Services\NHIF\NhifHttpClient::class);
    $mockClient->shouldReceive('getAdmissionDetailsByAuthorizationNo')
        ->with('201627864221')
        ->once()
        ->andReturn([
            'success' => true,
            'http_status' => 200,
            'data' => [
                'AdmissionID' => '579ac53f-3a2a-48a1-8d30-1b0507ef788c',
                'AdmissionNo' => '901627800505',
                'CardNo' => 'CARD-0010',
                'AuthorizationNo' => '201627864221',
                'FullName' => 'Ivy Smith',
                'Gender' => 'Female',
                'DateAdmitted' => '2026-10-05T12:55:36',
                'DiagnosisAtAdmission' => 'Acute Appendicitis',
                'FacilityCode' => '01141',
                'AdmittingPractitionerNo' => 'MCT20240997',
                'IsDischarged' => false,
            ],
            'error' => null,
        ]);
    app()->instance(\App\Services\NHIF\NhifHttpClient::class, $mockClient);

    $response = $this->actingAs($user)->getJson(route('nhif.admissions.lookup', ['authorizationNo' => '201627864221']));

    $response->assertOk()
        ->assertJson([
            'AdmissionNo' => '901627800505',
            'FullName' => 'Ivy Smith',
            'AuthorizationNo' => '201627864221',
            'DiagnosisAtAdmission' => 'Acute Appendicitis',
        ]);
});

test('NhifAdmissionController sync updates local NhifAdmission record with live data', function () {
    $ctx = admissionReadyContext();
    $user = userWithRole('nurse');

    $admission = app(AdmissionService::class)->create([
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
        'nhif_authorization_no' => '201627864221',
        'nhif_ward_type_id' => 21,
    ], requestAs($user));

    $mockClient = Mockery::mock(\App\Services\NHIF\NhifHttpClient::class);
    $mockClient->shouldReceive('getAdmissionDetailsByAuthorizationNo')
        ->with('201627864221')
        ->once()
        ->andReturn([
            'success' => true,
            'http_status' => 200,
            'data' => [
                'AdmissionID' => '579ac53f-3a2a-48a1-8d30-1b0507ef788c',
                'AdmissionNo' => '901627800505',
                'FullName' => 'Ivy Smith',
                'FacilityCode' => '01141',
                'AdmittingPractitionerNo' => 'MCT20240997',
                'DateAdmitted' => '2026-10-05T12:55:36',
                'DiagnosisAtAdmission' => 'test',
            ],
            'error' => null,
        ]);
    app()->instance(\App\Services\NHIF\NhifHttpClient::class, $mockClient);

    $response = $this->actingAs($user)->postJson(route('nhif.admissions.sync'), [
        'admission_id' => $admission->id,
        'authorization_no' => '201627864221',
    ]);

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Admission details successfully synchronized with NHIF.',
        ]);

    $admission->refresh();
    expect($admission->nhifAdmission->nhif_admission_no)->toBe('901627800505')
        ->and($admission->nhifAdmission->nhif_status)->toBe(\App\Models\NhifAdmission::STATUS_SUBMITTED)
        ->and($admission->nhifAdmission->nhif_facility_code)->toBe('01141')
        ->and($admission->nhifAdmission->nhif_practitioner_no)->toBe('MCT20240997');
});

test('an approved admission with an NHIF admission number can be confirmed without cash payment', function () {
    $ctx = admissionReadyContext();
    $nurse = userWithRole('nurse');
    $admin = userWithRole('hospital-admin');

    $admission = app(AdmissionService::class)->create([
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'service_id' => $ctx['service']->id,
        'admission_type' => 'emergency',
        'nhif_authorization_no' => '201627864221',
        'nhif_ward_type_id' => 21,
    ], requestAs($nurse));

    // Stamped with NHIF Admission Number
    $admission->nhifAdmission->update([
        'nhif_admission_no' => '901627800505',
        'nhif_status' => \App\Models\NhifAdmission::STATUS_SUBMITTED,
    ]);

    $admission = approvedAdmission($admission, $admin);

    // Confirm should succeed with NO cash payment
    $confirmed = app(AdmissionService::class)->confirm($admission, requestAs($nurse));

    expect($confirmed->status)->toBe(Admission::STATUS_ACTIVE)
        ->and($confirmed->admitted_at)->not->toBeNull();
});

test('an un-authorized cash admission still requires payment before confirmation', function () {
    $ctx = admissionReadyContext();
    $nurse = userWithRole('nurse');
    $admin = userWithRole('hospital-admin');

    // Create admission with priced service, no NHIF
    $admission = app(AdmissionService::class)->create([
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'service_id' => $ctx['service']->id,
        'admission_type' => 'emergency',
    ], requestAs($nurse));

    $admission = approvedAdmission($admission, $admin);

    // Confirm should be blocked by payment
    expect(fn () => app(AdmissionService::class)->confirm($admission, requestAs($nurse)))
        ->toThrow(ValidationException::class);
});

test('discharging an active admission with an NHIF admission number automatically submits discharge to NHIF', function () {
    $ctx = admissionReadyContext();
    $nurse = userWithRole('nurse');

    $admission = app(AdmissionService::class)->create([
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'service_id' => $ctx['service']->id,
        'admission_type' => 'emergency',
        'nhif_authorization_no' => '201627864221',
        'diagnosis_at_admission' => 'Acute Appendicitis',
    ], requestAs($nurse));

    $admission->nhifAdmission->update([
        'nhif_admission_no' => '901627800505',
        'nhif_status' => \App\Models\NhifAdmission::STATUS_SUBMITTED,
    ]);

    $admission->update([
        'status' => Admission::STATUS_ACTIVE,
        'admitted_at' => now(),
    ]);

    $mockClient = Mockery::mock(\App\Services\NHIF\NhifHttpClient::class);
    $mockClient->shouldReceive('dischargePatient')
        ->once()
        ->with(Mockery::on(function ($payload) {
            return $payload['admissionNo'] === '901627800505'
                && $payload['dischargeTypeID'] === 1
                && $payload['diagnosisAtDischarge'] === 'Acute Appendicitis - Resolved'
                && $payload['practitionersRemarks'] === 'Patient recovered well'
                && ! empty($payload['dateDischarged']);
        }))
        ->andReturn([
            'success' => true,
            'http_status' => 200,
            'data' => ['success' => true],
            'error' => null,
        ]);
    app()->instance(\App\Services\NHIF\NhifHttpClient::class, $mockClient);

    $response = $this->actingAs($nurse)->post(route('admissions.discharge', $admission->id), [
        'discharge_type' => 'routine',
        'discharge_reason' => 'Patient recovered well',
        'diagnosis_at_discharge' => 'Acute Appendicitis - Resolved',
    ]);

    $response->assertRedirect();

    $admission->refresh();
    expect($admission->status)->toBe(Admission::STATUS_DISCHARGED)
        ->and($admission->diagnosis_at_discharge)->toBe('Acute Appendicitis - Resolved')
        ->and($admission->nhifAdmission->nhif_discharge_status)->toBe('submitted')
        ->and($admission->nhifAdmission->nhif_discharge_type_id)->toBe(1)
        ->and($admission->nhifAdmission->nhif_discharged_at)->not->toBeNull();
});

test('discharging an active admission when NHIF fails still discharges locally and records failure', function () {
    $ctx = admissionReadyContext();
    $nurse = userWithRole('nurse');

    $admission = app(AdmissionService::class)->create([
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'admission_type' => 'elective',
        'nhif_authorization_no' => '201627864221',
    ], requestAs($nurse));

    $admission->nhifAdmission->update([
        'nhif_admission_no' => '901627800505',
        'nhif_status' => \App\Models\NhifAdmission::STATUS_SUBMITTED,
    ]);

    $admission->update([
        'status' => Admission::STATUS_ACTIVE,
        'admitted_at' => now(),
    ]);

    $mockClient = Mockery::mock(\App\Services\NHIF\NhifHttpClient::class);
    $mockClient->shouldReceive('dischargePatient')
        ->once()
        ->andReturn([
            'success' => false,
            'http_status' => 500,
            'data' => [],
            'error' => 'NHIF ServiceHub timeout',
        ]);
    app()->instance(\App\Services\NHIF\NhifHttpClient::class, $mockClient);

    $response = $this->actingAs($nurse)->post(route('admissions.discharge', $admission->id), [
        'discharge_type' => 'routine',
        'discharge_reason' => 'Discharged home',
    ]);

    $response->assertRedirect();

    $admission->refresh();
    expect($admission->status)->toBe(Admission::STATUS_DISCHARGED)
        ->and($admission->nhifAdmission->nhif_discharge_status)->toBe('failed')
        ->and($admission->nhifAdmission->nhif_discharge_error)->toBe('NHIF ServiceHub timeout');
});

test('submitDischargeNhif route retries NHIF discharge submission', function () {
    $ctx = admissionReadyContext();
    $nurse = userWithRole('nurse');
    $admin = User::factory()->create();
    $admin->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $admin->id, 'hospital_id' => $ctx['hospital']->id]);

    $admission = app(AdmissionService::class)->create([
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'admission_type' => 'emergency',
        'nhif_authorization_no' => '201627864221',
    ], requestAs($nurse));

    $admission->nhifAdmission->update([
        'nhif_admission_no' => '901627800505',
        'nhif_status' => \App\Models\NhifAdmission::STATUS_SUBMITTED,
        'nhif_discharge_status' => 'failed',
        'nhif_discharge_error' => 'Previous error',
    ]);

    $admission->update([
        'status' => Admission::STATUS_DISCHARGED,
        'discharged_at' => now(),
        'discharge_type' => 'against_medical_advice',
        'discharge_reason' => 'Left against advice',
    ]);

    $mockClient = Mockery::mock(\App\Services\NHIF\NhifHttpClient::class);
    $mockClient->shouldReceive('dischargePatient')
        ->once()
        ->with(Mockery::on(function ($payload) {
            return $payload['admissionNo'] === '901627800505'
                && $payload['dischargeTypeID'] === 2; // AMA
        }))
        ->andReturn([
            'success' => true,
            'http_status' => 200,
            'data' => ['success' => true],
            'error' => null,
        ]);
    app()->instance(\App\Services\NHIF\NhifHttpClient::class, $mockClient);

    $response = $this->actingAs($admin)->post(route('admissions.submit-nhif-discharge', $admission->id));

    $response->assertRedirect();

    $admission->refresh();
    expect($admission->nhifAdmission->nhif_discharge_status)->toBe('submitted')
        ->and($admission->nhifAdmission->nhif_discharge_type_id)->toBe(2);
});



