<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== CLAIM 6 ITEMS ===\n";
$c6Items = App\Models\NhifClaimItem::where('nhif_claim_id', 6)->get();
echo json_encode($c6Items->toArray(), JSON_PRETTY_PRINT) . "\n\n";

echo "=== CLAIM 7 ITEMS ===\n";
$c7Items = App\Models\NhifClaimItem::where('nhif_claim_id', 7)->get();
echo json_encode($c7Items->toArray(), JSON_PRETTY_PRINT) . "\n\n";

echo "=== CLAIM 6 & 7 ENCOUNTER & RECEPTION DETAILS ===\n";
$claims = App\Models\NhifClaim::whereIn('id', [6, 7])->get();
foreach ($claims as $c) {
    echo "Claim ID: {$c->id}, Number: {$c->claim_number}, Folio: {$c->folio_no}, Status: {$c->status}, Encounter ID: {$c->clinical_encounter_id}, Patient ID: {$c->patient_id}, Auth: {$c->authorization_no}\n";
    if ($c->clinical_encounter_id) {
        $encounter = App\Models\ClinicalEncounter::find($c->clinical_encounter_id);
        echo "  Encounter: " . json_encode($encounter?->only(['id', 'patient_id', 'reception_visit_id', 'status', 'started_at', 'ended_at']), JSON_PRETTY_PRINT) . "\n";
        $consultation = App\Models\Consultation::where('clinical_encounter_id', $c->clinical_encounter_id)->first();
        echo "  Consultation: " . json_encode($consultation?->toArray(), JSON_PRETTY_PRINT) . "\n";
    }
}

echo "=== WHERE IS 10001 IN SERVICES / CHARGES / CONSULTATIONS? ===\n";
$services10001 = App\Models\Service::where('code', '10001')->orWhere('name', 'like', '%Consultation%')->get(['id', 'code', 'name', 'price']);
echo "Services matching 10001 or Consultation:\n" . json_encode($services10001->toArray(), JSON_PRETTY_PRINT) . "\n";

$nhifServices10001 = App\Models\NhifService::where('nhif_item_code', '10001')->get(['id', 'nhif_item_code', 'nhif_item_name', 'item_type_id', 'price']);
echo "NhifService 10001:\n" . json_encode($nhifServices10001->toArray(), JSON_PRETTY_PRINT) . "\n";

$charges = App\Models\PatientCharge::where('patient_id', 13)->latest()->take(10)->get();
echo "PatientCharges for Patient 13:\n" . json_encode($charges->toArray(), JSON_PRETTY_PRINT) . "\n";
