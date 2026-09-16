<?php

namespace Database\Seeders;

use App\Models\Medication;
use Illuminate\Database\Seeder;

/**
 * A representative starter medication catalog (Phase 6, Step 45, spec §29)
 * — not a full pharmacy formulary (which would have thousands of entries
 * and isn't available in this project), the same "representative starter
 * set" precedent LaboratoryTestSeeder/RadiologyProcedureSeeder already
 * established for their own catalogs. `updateOrCreate()` keyed on each
 * medication's stable, hand-assigned `code` — never `count()+1` or a
 * random value (spec §6) — so running this seeder repeatedly never creates
 * duplicates or touches unrelated rows.
 *
 * Replaces Step 44's own name-keyed seed rows wholesale: those rows carried
 * no code/form/route/strength at all, and — verified before this change —
 * are referenced by zero prescriptions, so correcting them now is a safe
 * seed-data fix, not a destructive change to committed clinical data.
 * `MedicationService::update()`'s own code-lock is what actually protects a
 * medication once a real prescription exists; this seeder never touches a
 * referenced row's code.
 */
class MedicationSeeder extends Seeder
{
    /**
     * @var list<array{code: string, generic_name: string, category: string, strength?: string, form: string, route: string}>
     */
    protected array $medications = [
        // Analgesics / antipyretics
        ['code' => 'PCM-500-TAB', 'generic_name' => 'Paracetamol', 'category' => 'Analgesic', 'strength' => '500 mg', 'form' => 'tablet', 'route' => 'oral'],
        ['code' => 'IBU-400-TAB', 'generic_name' => 'Ibuprofen', 'category' => 'Analgesic', 'strength' => '400 mg', 'form' => 'tablet', 'route' => 'oral'],
        ['code' => 'DIC-50-TAB', 'generic_name' => 'Diclofenac', 'category' => 'Analgesic', 'strength' => '50 mg', 'form' => 'tablet', 'route' => 'oral'],
        ['code' => 'ASA-300-TAB', 'generic_name' => 'Aspirin', 'category' => 'Analgesic', 'strength' => '300 mg', 'form' => 'tablet', 'route' => 'oral'],
        ['code' => 'TRM-50-CAP', 'generic_name' => 'Tramadol', 'category' => 'Analgesic', 'strength' => '50 mg', 'form' => 'capsule', 'route' => 'oral'],

        // Antibiotics
        ['code' => 'AMOX-500-CAP', 'generic_name' => 'Amoxicillin', 'category' => 'Antibiotic', 'strength' => '500 mg', 'form' => 'capsule', 'route' => 'oral'],
        ['code' => 'AMOXCL-625-TAB', 'generic_name' => 'Amoxicillin-Clavulanate', 'category' => 'Antibiotic', 'strength' => '625 mg', 'form' => 'tablet', 'route' => 'oral'],
        ['code' => 'CIP-500-TAB', 'generic_name' => 'Ciprofloxacin', 'category' => 'Antibiotic', 'strength' => '500 mg', 'form' => 'tablet', 'route' => 'oral'],
        ['code' => 'DOXY-100-CAP', 'generic_name' => 'Doxycycline', 'category' => 'Antibiotic', 'strength' => '100 mg', 'form' => 'capsule', 'route' => 'oral'],
        ['code' => 'MET-400-TAB', 'generic_name' => 'Metronidazole', 'category' => 'Antibiotic', 'strength' => '400 mg', 'form' => 'tablet', 'route' => 'oral'],
        ['code' => 'AZI-500-TAB', 'generic_name' => 'Azithromycin', 'category' => 'Antibiotic', 'strength' => '500 mg', 'form' => 'tablet', 'route' => 'oral'],
        ['code' => 'CFT-1G-INJ', 'generic_name' => 'Ceftriaxone', 'category' => 'Antibiotic', 'strength' => '1 g', 'form' => 'injection', 'route' => 'intravenous'],
        ['code' => 'ERY-500-TAB', 'generic_name' => 'Erythromycin', 'category' => 'Antibiotic', 'strength' => '500 mg', 'form' => 'tablet', 'route' => 'oral'],

        // Antimalarials
        ['code' => 'ARTLUM-20120-TAB', 'generic_name' => 'Artemether-Lumefantrine', 'category' => 'Antimalarial', 'strength' => '20 mg/120 mg', 'form' => 'tablet', 'route' => 'oral'],
        ['code' => 'QNN-300-TAB', 'generic_name' => 'Quinine', 'category' => 'Antimalarial', 'strength' => '300 mg', 'form' => 'tablet', 'route' => 'oral'],
        ['code' => 'SP-50025-TAB', 'generic_name' => 'Sulfadoxine-Pyrimethamine', 'category' => 'Antimalarial', 'strength' => '500 mg/25 mg', 'form' => 'tablet', 'route' => 'oral'],

        // Antihypertensives
        ['code' => 'AML-5-TAB', 'generic_name' => 'Amlodipine', 'category' => 'Antihypertensive', 'strength' => '5 mg', 'form' => 'tablet', 'route' => 'oral'],
        ['code' => 'LIS-10-TAB', 'generic_name' => 'Lisinopril', 'category' => 'Antihypertensive', 'strength' => '10 mg', 'form' => 'tablet', 'route' => 'oral'],
        ['code' => 'LOS-50-TAB', 'generic_name' => 'Losartan', 'category' => 'Antihypertensive', 'strength' => '50 mg', 'form' => 'tablet', 'route' => 'oral'],
        ['code' => 'HCTZ-25-TAB', 'generic_name' => 'Hydrochlorothiazide', 'category' => 'Antihypertensive', 'strength' => '25 mg', 'form' => 'tablet', 'route' => 'oral'],
        ['code' => 'ATN-50-TAB', 'generic_name' => 'Atenolol', 'category' => 'Antihypertensive', 'strength' => '50 mg', 'form' => 'tablet', 'route' => 'oral'],

        // Antidiabetics
        ['code' => 'MTF-500-TAB', 'generic_name' => 'Metformin', 'category' => 'Antidiabetic', 'strength' => '500 mg', 'form' => 'tablet', 'route' => 'oral'],
        ['code' => 'GLB-5-TAB', 'generic_name' => 'Glibenclamide', 'category' => 'Antidiabetic', 'strength' => '5 mg', 'form' => 'tablet', 'route' => 'oral'],
        ['code' => 'INS-100IU-INJ', 'generic_name' => 'Insulin (Soluble)', 'category' => 'Antidiabetic', 'strength' => '100 IU/mL', 'form' => 'injection', 'route' => 'subcutaneous'],

        // Gastrointestinal
        ['code' => 'OMZ-20-CAP', 'generic_name' => 'Omeprazole', 'category' => 'Gastrointestinal', 'strength' => '20 mg', 'form' => 'capsule', 'route' => 'oral'],
        ['code' => 'RAN-150-TAB', 'generic_name' => 'Ranitidine', 'category' => 'Gastrointestinal', 'strength' => '150 mg', 'form' => 'tablet', 'route' => 'oral'],
        ['code' => 'MCP-10-TAB', 'generic_name' => 'Metoclopramide', 'category' => 'Gastrointestinal', 'strength' => '10 mg', 'form' => 'tablet', 'route' => 'oral'],
        ['code' => 'ORS-STD-PWD', 'generic_name' => 'Oral Rehydration Salts', 'category' => 'Gastrointestinal', 'form' => 'powder', 'route' => 'oral'],
        ['code' => 'LOP-2-CAP', 'generic_name' => 'Loperamide', 'category' => 'Gastrointestinal', 'strength' => '2 mg', 'form' => 'capsule', 'route' => 'oral'],

        // Respiratory / allergy
        ['code' => 'SAL-100MCG-INH', 'generic_name' => 'Salbutamol', 'category' => 'Respiratory', 'strength' => '100 mcg/dose', 'form' => 'inhaler', 'route' => 'inhalation'],
        ['code' => 'CTZ-10-TAB', 'generic_name' => 'Cetirizine', 'category' => 'Antihistamine', 'strength' => '10 mg', 'form' => 'tablet', 'route' => 'oral'],
        ['code' => 'CPM-4-TAB', 'generic_name' => 'Chlorpheniramine', 'category' => 'Antihistamine', 'strength' => '4 mg', 'form' => 'tablet', 'route' => 'oral'],
        ['code' => 'PRED-5-TAB', 'generic_name' => 'Prednisolone', 'category' => 'Corticosteroid', 'strength' => '5 mg', 'form' => 'tablet', 'route' => 'oral'],

        // Vitamins / supplements
        ['code' => 'FESO4-200-TAB', 'generic_name' => 'Ferrous Sulphate', 'category' => 'Supplement', 'strength' => '200 mg', 'form' => 'tablet', 'route' => 'oral'],
        ['code' => 'FA-5-TAB', 'generic_name' => 'Folic Acid', 'category' => 'Supplement', 'strength' => '5 mg', 'form' => 'tablet', 'route' => 'oral'],
        ['code' => 'MVI-STD-TAB', 'generic_name' => 'Multivitamin', 'category' => 'Supplement', 'form' => 'tablet', 'route' => 'oral'],
        ['code' => 'VITC-100-TAB', 'generic_name' => 'Vitamin C', 'category' => 'Supplement', 'strength' => '100 mg', 'form' => 'tablet', 'route' => 'oral'],

        // Topical / wound care
        ['code' => 'PVI-10PCT-SOL', 'generic_name' => 'Povidone-Iodine', 'category' => 'Antiseptic', 'strength' => '10%', 'form' => 'solution', 'route' => 'topical'],
        ['code' => 'SSD-1PCT-CRM', 'generic_name' => 'Silver Sulfadiazine', 'category' => 'Antiseptic', 'strength' => '1%', 'form' => 'cream', 'route' => 'topical'],
        ['code' => 'HCT-1PCT-CRM', 'generic_name' => 'Hydrocortisone Cream', 'category' => 'Topical', 'strength' => '1%', 'form' => 'cream', 'route' => 'topical'],
    ];

    /**
     * Seed the medication catalog. Idempotent via updateOrCreate() so it can
     * be run repeatedly without creating duplicates.
     */
    public function run(): void
    {
        foreach ($this->medications as $entry) {
            $code = $entry['code'];
            unset($entry['code']);

            Medication::query()->updateOrCreate(
                ['code' => $code],
                [...$entry, 'status' => Medication::STATUS_ACTIVE],
            );
        }
    }
}
