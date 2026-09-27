<?php

namespace Database\Seeders;

use App\Models\LaboratoryReferenceRange;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestParameter;
use Illuminate\Database\Seeder;

/**
 * Seeds analyte parameter definitions and structured reference ranges for the
 * existing flat test catalog (Batch A of the LIS parameter engine).
 *
 * One parameter per single-analyte test; multiple parameters for URINALYSIS.
 * Option-type tests (HIV, Malaria, etc.) get option parameters so the result
 * engine can apply flag logic (abnormal_options) rather than free-text.
 *
 * Age bands use days for precision:
 *   neonate   0 – 27 days
 *   infant    28 – 364 days
 *   child     365 – 4379 days (1 – 11 years)
 *   adult     ≥ 4380 days (12 years+)
 *
 * All boundary values match WHO/Clinical laboratory reference intervals for
 * sub-Saharan African adults where available, with broad critical thresholds.
 */
class LaboratoryTestParameterSeeder extends Seeder
{
    /**
     * @var array<string, list<array{
     *   code: string,
     *   name: string,
     *   unit?: string,
     *   data_type: string,
     *   decimal_places?: int,
     *   options_json?: list<string>,
     *   abnormal_options?: list<string>,
     *   display_order: int,
     *   analyser_code?: string,
     *   loinc_code?: string,
     *   ranges?: list<array{sex: string, age_min_days?: int, age_max_days?: int, low?: float, high?: float, critical_low?: float, critical_high?: float, notes?: string}>
     * }>>
     */
    protected array $catalog = [

        // ── Hematology ──────────────────────────────────────────────────────
        'HB' => [[
            'code' => 'HB', 'name' => 'Hemoglobin', 'unit' => 'g/dL',
            'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 1,
            'analyser_code' => 'HGB', 'loinc_code' => '718-7',
            'ranges' => [
                ['sex' => 'male',   'age_min_days' => 4380,  'age_max_days' => null,  'low' => 13.0, 'high' => 17.0, 'critical_low' => 7.0, 'critical_high' => 20.0, 'notes' => 'Adult male'],
                ['sex' => 'female', 'age_min_days' => 4380,  'age_max_days' => null,  'low' => 12.0, 'high' => 15.5, 'critical_low' => 7.0, 'critical_high' => 20.0, 'notes' => 'Adult female'],
                ['sex' => 'any',    'age_min_days' => 365,   'age_max_days' => 4379,  'low' => 11.5, 'high' => 15.5, 'critical_low' => 7.0, 'critical_high' => 20.0, 'notes' => 'Child 1–11 years'],
                ['sex' => 'any',    'age_min_days' => 28,    'age_max_days' => 364,   'low' => 10.5, 'high' => 14.0, 'critical_low' => 7.0, 'critical_high' => 20.0, 'notes' => 'Infant 1–11 months'],
                ['sex' => 'any',    'age_min_days' => 0,     'age_max_days' => 27,    'low' => 14.0, 'high' => 21.0, 'critical_low' => 10.0, 'critical_high' => 25.0, 'notes' => 'Neonate 0–27 days'],
            ],
        ]],

        'PCV' => [[
            'code' => 'PCV', 'name' => 'Packed Cell Volume', 'unit' => '%',
            'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 1,
            'analyser_code' => 'HCT', 'loinc_code' => '4544-3',
            'ranges' => [
                ['sex' => 'male',   'age_min_days' => 4380, 'age_max_days' => null, 'low' => 40.0, 'high' => 52.0, 'critical_low' => 20.0, 'critical_high' => 60.0, 'notes' => 'Adult male'],
                ['sex' => 'female', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 36.0, 'high' => 46.0, 'critical_low' => 20.0, 'critical_high' => 60.0, 'notes' => 'Adult female'],
                ['sex' => 'any',    'age_min_days' => 365,  'age_max_days' => 4379, 'low' => 35.0, 'high' => 45.0, 'critical_low' => 20.0, 'critical_high' => 60.0, 'notes' => 'Child 1–11 years'],
                ['sex' => 'any',    'age_min_days' => 0,    'age_max_days' => 27,   'low' => 42.0, 'high' => 65.0, 'critical_low' => 30.0, 'critical_high' => 75.0, 'notes' => 'Neonate'],
            ],
        ]],

        'WBC' => [[
            'code' => 'WBC', 'name' => 'White Blood Cell Count', 'unit' => '10⁹/L',
            'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 1,
            'analyser_code' => 'WBC', 'loinc_code' => '6690-2',
            'ranges' => [
                ['sex' => 'any', 'age_min_days' => 4380,  'age_max_days' => null,  'low' => 4.0,  'high' => 11.0,  'critical_low' => 2.0, 'critical_high' => 30.0, 'notes' => 'Adults'],
                ['sex' => 'any', 'age_min_days' => 365,   'age_max_days' => 4379,  'low' => 5.0,  'high' => 15.0,  'critical_low' => 2.0, 'critical_high' => 30.0, 'notes' => 'Children 1–11 years'],
                ['sex' => 'any', 'age_min_days' => 0,     'age_max_days' => 27,    'low' => 9.0,  'high' => 30.0,  'critical_low' => 5.0, 'critical_high' => 40.0, 'notes' => 'Neonates'],
            ],
        ]],

        'RBC' => [[
            'code' => 'RBC', 'name' => 'Red Blood Cell Count', 'unit' => '10¹²/L',
            'data_type' => 'numeric', 'decimal_places' => 2, 'display_order' => 1,
            'analyser_code' => 'RBC', 'loinc_code' => '789-8',
            'ranges' => [
                ['sex' => 'male',   'age_min_days' => 4380, 'age_max_days' => null, 'low' => 4.5, 'high' => 5.9, 'critical_low' => 2.5, 'critical_high' => 7.0, 'notes' => 'Adult male'],
                ['sex' => 'female', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 4.0, 'high' => 5.2, 'critical_low' => 2.5, 'critical_high' => 7.0, 'notes' => 'Adult female'],
                ['sex' => 'any',    'age_min_days' => 365,  'age_max_days' => 4379, 'low' => 3.9, 'high' => 5.3, 'critical_low' => 2.5, 'critical_high' => 7.0, 'notes' => 'Children 1–11 years'],
            ],
        ]],

        'PLT' => [[
            'code' => 'PLT', 'name' => 'Platelet Count', 'unit' => '10⁹/L',
            'data_type' => 'numeric', 'decimal_places' => 0, 'display_order' => 1,
            'analyser_code' => 'PLT', 'loinc_code' => '777-3',
            'ranges' => [
                ['sex' => 'any', 'age_min_days' => 0, 'age_max_days' => null, 'low' => 150.0, 'high' => 450.0, 'critical_low' => 50.0, 'critical_high' => 1000.0, 'notes' => 'All ages'],
            ],
        ]],

        'MCV' => [[
            'code' => 'MCV', 'name' => 'Mean Cell Volume', 'unit' => 'fL',
            'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 1,
            'analyser_code' => 'MCV', 'loinc_code' => '787-2',
            'ranges' => [
                ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 80.0, 'high' => 100.0, 'critical_low' => null, 'critical_high' => null, 'notes' => 'Adults'],
                ['sex' => 'any', 'age_min_days' => 365,  'age_max_days' => 4379, 'low' => 72.0, 'high' => 88.0,  'critical_low' => null, 'critical_high' => null, 'notes' => 'Children 1–11 years'],
            ],
        ]],

        'MCH' => [[
            'code' => 'MCH', 'name' => 'Mean Cell Hemoglobin', 'unit' => 'pg',
            'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 1,
            'analyser_code' => 'MCH', 'loinc_code' => '785-6',
            'ranges' => [
                ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 27.0, 'high' => 33.0, 'critical_low' => null, 'critical_high' => null, 'notes' => 'Adults'],
                ['sex' => 'any', 'age_min_days' => 365,  'age_max_days' => 4379, 'low' => 24.0, 'high' => 30.0, 'critical_low' => null, 'critical_high' => null, 'notes' => 'Children 1–11 years'],
            ],
        ]],

        'MCHC' => [[
            'code' => 'MCHC', 'name' => 'Mean Cell Hemoglobin Concentration', 'unit' => 'g/dL',
            'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 1,
            'analyser_code' => 'MCHC', 'loinc_code' => '786-4',
            'ranges' => [
                ['sex' => 'any', 'age_min_days' => 0, 'age_max_days' => null, 'low' => 32.0, 'high' => 36.0, 'critical_low' => null, 'critical_high' => null, 'notes' => 'All ages'],
            ],
        ]],

        'ESR' => [[
            'code' => 'ESR', 'name' => 'Erythrocyte Sedimentation Rate', 'unit' => 'mm/hr',
            'data_type' => 'numeric', 'decimal_places' => 0, 'display_order' => 1,
            'loinc_code' => '30341-2',
            'ranges' => [
                ['sex' => 'male',   'age_min_days' => 4380, 'age_max_days' => null, 'low' => 0.0, 'high' => 20.0, 'critical_low' => null, 'critical_high' => 100.0, 'notes' => 'Adult male'],
                ['sex' => 'female', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 0.0, 'high' => 30.0, 'critical_low' => null, 'critical_high' => 100.0, 'notes' => 'Adult female'],
                ['sex' => 'any',    'age_min_days' => 0,    'age_max_days' => 4379, 'low' => 0.0, 'high' => 20.0, 'critical_low' => null, 'critical_high' => 100.0, 'notes' => 'Children'],
            ],
        ]],

        // ── Chemistry ───────────────────────────────────────────────────────
        'FBG' => [[
            'code' => 'FBG', 'name' => 'Fasting Blood Glucose', 'unit' => 'mmol/L',
            'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 1,
            'loinc_code' => '1558-6',
            'ranges' => [
                ['sex' => 'any', 'age_min_days' => 0, 'age_max_days' => null, 'low' => 3.9, 'high' => 5.6, 'critical_low' => 2.5, 'critical_high' => 25.0, 'notes' => 'Fasting reference'],
            ],
        ]],

        'RBG' => [[
            'code' => 'RBG', 'name' => 'Random Blood Glucose', 'unit' => 'mmol/L',
            'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 1,
            'loinc_code' => '2339-0',
            'ranges' => [
                ['sex' => 'any', 'age_min_days' => 0, 'age_max_days' => null, 'low' => 3.9, 'high' => 11.1, 'critical_low' => 2.5, 'critical_high' => 30.0, 'notes' => 'Random glucose'],
            ],
        ]],

        'HBA1C' => [[
            'code' => 'HBA1C', 'name' => 'Glycated Hemoglobin (HbA1c)', 'unit' => '%',
            'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 1,
            'loinc_code' => '4548-4',
            'ranges' => [
                ['sex' => 'any', 'age_min_days' => 0, 'age_max_days' => null, 'low' => 4.0, 'high' => 5.6, 'critical_low' => null, 'critical_high' => 15.0, 'notes' => 'Normal: <5.7%, Prediabetes: 5.7–6.4%, Diabetes: ≥6.5%'],
            ],
        ]],

        'UREA' => [[
            'code' => 'UREA', 'name' => 'Urea', 'unit' => 'mmol/L',
            'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 1,
            'loinc_code' => '3094-0',
            'ranges' => [
                ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 2.5, 'high' => 7.1, 'critical_low' => null, 'critical_high' => 35.0, 'notes' => 'Adults'],
            ],
        ]],

        'CREAT' => [[
            'code' => 'CREAT', 'name' => 'Creatinine', 'unit' => 'µmol/L',
            'data_type' => 'numeric', 'decimal_places' => 0, 'display_order' => 1,
            'analyser_code' => 'CREA', 'loinc_code' => '2160-0',
            'ranges' => [
                ['sex' => 'male',   'age_min_days' => 4380, 'age_max_days' => null, 'low' => 62.0,  'high' => 106.0, 'critical_low' => null, 'critical_high' => 800.0, 'notes' => 'Adult male'],
                ['sex' => 'female', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 44.0,  'high' => 80.0,  'critical_low' => null, 'critical_high' => 800.0, 'notes' => 'Adult female'],
                ['sex' => 'any',    'age_min_days' => 365,  'age_max_days' => 4379, 'low' => 27.0,  'high' => 62.0,  'critical_low' => null, 'critical_high' => 500.0, 'notes' => 'Children 1–11 years'],
            ],
        ]],

        'NA' => [[
            'code' => 'NA', 'name' => 'Sodium', 'unit' => 'mmol/L',
            'data_type' => 'numeric', 'decimal_places' => 0, 'display_order' => 1,
            'loinc_code' => '2951-2',
            'ranges' => [
                ['sex' => 'any', 'age_min_days' => 0, 'age_max_days' => null, 'low' => 135.0, 'high' => 145.0, 'critical_low' => 120.0, 'critical_high' => 160.0, 'notes' => 'All ages'],
            ],
        ]],

        'K' => [[
            'code' => 'K', 'name' => 'Potassium', 'unit' => 'mmol/L',
            'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 1,
            'loinc_code' => '2823-3',
            'ranges' => [
                ['sex' => 'any', 'age_min_days' => 0, 'age_max_days' => null, 'low' => 3.5, 'high' => 5.1, 'critical_low' => 2.5, 'critical_high' => 6.5, 'notes' => 'All ages'],
            ],
        ]],

        'CL' => [[
            'code' => 'CL', 'name' => 'Chloride', 'unit' => 'mmol/L',
            'data_type' => 'numeric', 'decimal_places' => 0, 'display_order' => 1,
            'loinc_code' => '2075-0',
            'ranges' => [
                ['sex' => 'any', 'age_min_days' => 0, 'age_max_days' => null, 'low' => 98.0, 'high' => 107.0, 'critical_low' => 75.0, 'critical_high' => 125.0, 'notes' => 'All ages'],
            ],
        ]],

        'CA' => [[
            'code' => 'CA', 'name' => 'Calcium', 'unit' => 'mmol/L',
            'data_type' => 'numeric', 'decimal_places' => 2, 'display_order' => 1,
            'loinc_code' => '17861-6',
            'ranges' => [
                ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 2.1, 'high' => 2.6, 'critical_low' => 1.5, 'critical_high' => 3.5, 'notes' => 'Adults'],
            ],
        ]],

        'ALB' => [[
            'code' => 'ALB', 'name' => 'Albumin', 'unit' => 'g/L',
            'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 1,
            'loinc_code' => '1751-7',
            'ranges' => [
                ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 35.0, 'high' => 50.0, 'critical_low' => 20.0, 'critical_high' => null, 'notes' => 'Adults'],
            ],
        ]],

        'TP' => [[
            'code' => 'TP', 'name' => 'Total Protein', 'unit' => 'g/L',
            'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 1,
            'loinc_code' => '2885-2',
            'ranges' => [
                ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 60.0, 'high' => 80.0, 'critical_low' => null, 'critical_high' => null, 'notes' => 'Adults'],
            ],
        ]],

        'TBIL' => [[
            'code' => 'TBIL', 'name' => 'Total Bilirubin', 'unit' => 'µmol/L',
            'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 1,
            'loinc_code' => '1975-2',
            'ranges' => [
                ['sex' => 'any', 'age_min_days' => 28,    'age_max_days' => null, 'low' => null, 'high' => 20.5, 'critical_low' => null, 'critical_high' => 300.0, 'notes' => 'Adults/children >1 month'],
                ['sex' => 'any', 'age_min_days' => 0,     'age_max_days' => 27,   'low' => null, 'high' => 205.0, 'critical_low' => null, 'critical_high' => 340.0, 'notes' => 'Neonates (physiological jaundice threshold)'],
            ],
        ]],

        'DBIL' => [[
            'code' => 'DBIL', 'name' => 'Direct Bilirubin', 'unit' => 'µmol/L',
            'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 1,
            'loinc_code' => '1968-7',
            'ranges' => [
                ['sex' => 'any', 'age_min_days' => 0, 'age_max_days' => null, 'low' => null, 'high' => 6.8, 'critical_low' => null, 'critical_high' => null, 'notes' => 'All ages'],
            ],
        ]],

        'ALT' => [[
            'code' => 'ALT', 'name' => 'Alanine Aminotransferase', 'unit' => 'U/L',
            'data_type' => 'numeric', 'decimal_places' => 0, 'display_order' => 1,
            'loinc_code' => '1742-6',
            'ranges' => [
                ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => null, 'high' => 56.0, 'critical_low' => null, 'critical_high' => 1000.0, 'notes' => 'Adults (upper limit)'],
            ],
        ]],

        'AST' => [[
            'code' => 'AST', 'name' => 'Aspartate Aminotransferase', 'unit' => 'U/L',
            'data_type' => 'numeric', 'decimal_places' => 0, 'display_order' => 1,
            'loinc_code' => '1920-8',
            'ranges' => [
                ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => null, 'high' => 40.0, 'critical_low' => null, 'critical_high' => 1000.0, 'notes' => 'Adults'],
            ],
        ]],

        'ALP' => [[
            'code' => 'ALP', 'name' => 'Alkaline Phosphatase', 'unit' => 'U/L',
            'data_type' => 'numeric', 'decimal_places' => 0, 'display_order' => 1,
            'loinc_code' => '6768-6',
            'ranges' => [
                ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 44.0, 'high' => 147.0, 'critical_low' => null, 'critical_high' => null, 'notes' => 'Adults'],
                ['sex' => 'any', 'age_min_days' => 365,  'age_max_days' => 4379, 'low' => 100.0, 'high' => 350.0, 'critical_low' => null, 'critical_high' => null, 'notes' => 'Children (physiological elevation)'],
            ],
        ]],

        'GGT' => [[
            'code' => 'GGT', 'name' => 'Gamma-Glutamyl Transferase', 'unit' => 'U/L',
            'data_type' => 'numeric', 'decimal_places' => 0, 'display_order' => 1,
            'loinc_code' => '2324-2',
            'ranges' => [
                ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => null, 'high' => 61.0, 'critical_low' => null, 'critical_high' => null, 'notes' => 'Adults'],
            ],
        ]],

        'CHOL' => [[
            'code' => 'CHOL', 'name' => 'Total Cholesterol', 'unit' => 'mmol/L',
            'data_type' => 'numeric', 'decimal_places' => 2, 'display_order' => 1,
            'loinc_code' => '2093-3',
            'ranges' => [
                ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => null, 'high' => 5.2, 'critical_low' => null, 'critical_high' => 10.0, 'notes' => 'Adults — desirable <5.2 mmol/L'],
            ],
        ]],

        'HDL' => [[
            'code' => 'HDL', 'name' => 'HDL Cholesterol', 'unit' => 'mmol/L',
            'data_type' => 'numeric', 'decimal_places' => 2, 'display_order' => 1,
            'loinc_code' => '2085-9',
            'ranges' => [
                ['sex' => 'male',   'age_min_days' => 4380, 'age_max_days' => null, 'low' => 1.0,  'high' => null, 'critical_low' => null, 'critical_high' => null, 'notes' => 'Adult male — low risk >1.0'],
                ['sex' => 'female', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 1.2,  'high' => null, 'critical_low' => null, 'critical_high' => null, 'notes' => 'Adult female — low risk >1.2'],
            ],
        ]],

        'LDL' => [[
            'code' => 'LDL', 'name' => 'LDL Cholesterol', 'unit' => 'mmol/L',
            'data_type' => 'numeric', 'decimal_places' => 2, 'display_order' => 1,
            'loinc_code' => '2089-1',
            'ranges' => [
                ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => null, 'high' => 3.4, 'critical_low' => null, 'critical_high' => null, 'notes' => 'Adults — optimal <3.4 mmol/L'],
            ],
        ]],

        'TG' => [[
            'code' => 'TG', 'name' => 'Triglycerides', 'unit' => 'mmol/L',
            'data_type' => 'numeric', 'decimal_places' => 2, 'display_order' => 1,
            'loinc_code' => '2571-8',
            'ranges' => [
                ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => null, 'high' => 1.7, 'critical_low' => null, 'critical_high' => 11.0, 'notes' => 'Adults — normal <1.7 mmol/L'],
            ],
        ]],

        'CRP' => [[
            'code' => 'CRP', 'name' => 'C-Reactive Protein', 'unit' => 'mg/L',
            'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 1,
            'loinc_code' => '1988-5',
            'ranges' => [
                ['sex' => 'any', 'age_min_days' => 0, 'age_max_days' => null, 'low' => null, 'high' => 10.0, 'critical_low' => null, 'critical_high' => null, 'notes' => 'High-sensitivity; >10 indicates acute inflammation'],
            ],
        ]],

        // ── Urinalysis (multi-parameter) ─────────────────────────────────────
        'URINALYSIS' => [
            [
                'code' => 'UA_COLOR',   'name' => 'Colour',   'unit' => null,       'data_type' => 'option',  'decimal_places' => 0, 'display_order' => 1,
                'options_json' => ['yellow', 'pale yellow', 'dark yellow', 'amber', 'orange', 'red', 'brown', 'colourless'],
                'abnormal_options' => ['red', 'brown', 'orange'],
            ],
            [
                'code' => 'UA_APPEARANCE', 'name' => 'Appearance', 'unit' => null, 'data_type' => 'option', 'decimal_places' => 0, 'display_order' => 2,
                'options_json' => ['clear', 'slightly cloudy', 'cloudy', 'turbid'],
                'abnormal_options' => ['cloudy', 'turbid'],
            ],
            [
                'code' => 'UA_SG',  'name' => 'Specific Gravity', 'unit' => null, 'data_type' => 'numeric', 'decimal_places' => 3, 'display_order' => 3,
                'ranges' => [
                    ['sex' => 'any', 'age_min_days' => 0, 'age_max_days' => null, 'low' => 1.005, 'high' => 1.030, 'critical_low' => null, 'critical_high' => null],
                ],
            ],
            [
                'code' => 'UA_PH',  'name' => 'pH', 'unit' => null, 'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 4,
                'ranges' => [
                    ['sex' => 'any', 'age_min_days' => 0, 'age_max_days' => null, 'low' => 5.0, 'high' => 8.0, 'critical_low' => null, 'critical_high' => null],
                ],
            ],
            [
                'code' => 'UA_PROT', 'name' => 'Protein', 'unit' => null, 'data_type' => 'option', 'decimal_places' => 0, 'display_order' => 5,
                'options_json' => ['negative', 'trace', '+', '++', '+++', '++++'],
                'abnormal_options' => ['trace', '+', '++', '+++', '++++'],
            ],
            [
                'code' => 'UA_GLUC', 'name' => 'Glucose', 'unit' => null, 'data_type' => 'option', 'decimal_places' => 0, 'display_order' => 6,
                'options_json' => ['negative', 'trace', '+', '++', '+++'],
                'abnormal_options' => ['trace', '+', '++', '+++'],
            ],
            [
                'code' => 'UA_KETONES', 'name' => 'Ketones', 'unit' => null, 'data_type' => 'option', 'decimal_places' => 0, 'display_order' => 7,
                'options_json' => ['negative', 'trace', '+', '++', '+++'],
                'abnormal_options' => ['trace', '+', '++', '+++'],
            ],
            [
                'code' => 'UA_BLOOD', 'name' => 'Blood', 'unit' => null, 'data_type' => 'option', 'decimal_places' => 0, 'display_order' => 8,
                'options_json' => ['negative', 'trace', '+', '++', '+++'],
                'abnormal_options' => ['trace', '+', '++', '+++'],
            ],
            [
                'code' => 'UA_BILI', 'name' => 'Bilirubin', 'unit' => null, 'data_type' => 'option', 'decimal_places' => 0, 'display_order' => 9,
                'options_json' => ['negative', '+', '++', '+++'],
                'abnormal_options' => ['+', '++', '+++'],
            ],
            [
                'code' => 'UA_NIT',  'name' => 'Nitrites', 'unit' => null, 'data_type' => 'option', 'decimal_places' => 0, 'display_order' => 10,
                'options_json' => ['negative', 'positive'],
                'abnormal_options' => ['positive'],
            ],
            [
                'code' => 'UA_LE',   'name' => 'Leucocyte Esterase', 'unit' => null, 'data_type' => 'option', 'decimal_places' => 0, 'display_order' => 11,
                'options_json' => ['negative', 'trace', '+', '++', '+++'],
                'abnormal_options' => ['trace', '+', '++', '+++'],
            ],
            [
                'code' => 'UA_UROBIL', 'name' => 'Urobilinogen', 'unit' => 'µmol/L', 'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 12,
                'ranges' => [
                    ['sex' => 'any', 'age_min_days' => 0, 'age_max_days' => null, 'low' => null, 'high' => 17.0, 'critical_low' => null, 'critical_high' => null],
                ],
            ],
            // Microscopy
            [
                'code' => 'UA_RBC_HPF', 'name' => 'RBC (per HPF)', 'unit' => '/HPF', 'data_type' => 'numeric', 'decimal_places' => 0, 'display_order' => 13,
                'ranges' => [
                    ['sex' => 'any', 'age_min_days' => 0, 'age_max_days' => null, 'low' => null, 'high' => 2.0, 'critical_low' => null, 'critical_high' => null, 'notes' => '0–2/HPF normal'],
                ],
            ],
            [
                'code' => 'UA_WBC_HPF', 'name' => 'WBC (per HPF)', 'unit' => '/HPF', 'data_type' => 'numeric', 'decimal_places' => 0, 'display_order' => 14,
                'ranges' => [
                    ['sex' => 'any', 'age_min_days' => 0, 'age_max_days' => null, 'low' => null, 'high' => 5.0, 'critical_low' => null, 'critical_high' => null, 'notes' => '0–5/HPF normal'],
                ],
            ],
            [
                'code' => 'UA_CASTS',  'name' => 'Casts', 'unit' => null, 'data_type' => 'option', 'decimal_places' => 0, 'display_order' => 15,
                'options_json' => ['none seen', 'hyaline', 'granular', 'waxy', 'red cell', 'white cell', 'epithelial'],
                'abnormal_options' => ['granular', 'waxy', 'red cell', 'white cell', 'epithelial'],
            ],
            [
                'code' => 'UA_BACTERIA', 'name' => 'Bacteria', 'unit' => null, 'data_type' => 'option', 'decimal_places' => 0, 'display_order' => 16,
                'options_json' => ['none seen', 'few', 'moderate', 'many'],
                'abnormal_options' => ['moderate', 'many'],
            ],
        ],

        // ── Qualitative / option-type ────────────────────────────────────────
        'HIV' => [[
            'code' => 'HIV', 'name' => 'HIV Result', 'unit' => null,
            'data_type' => 'option', 'decimal_places' => 0, 'display_order' => 1,
            'options_json' => ['negative', 'positive', 'indeterminate'],
            'abnormal_options' => ['positive', 'indeterminate'],
            'loinc_code' => '75622-1',
        ]],

        'HBSAG' => [[
            'code' => 'HBSAG', 'name' => 'HBsAg Result', 'unit' => null,
            'data_type' => 'option', 'decimal_places' => 0, 'display_order' => 1,
            'options_json' => ['negative', 'positive'],
            'abnormal_options' => ['positive'],
            'loinc_code' => '5195-3',
        ]],

        'HCV' => [[
            'code' => 'HCV', 'name' => 'HCV Antibody Result', 'unit' => null,
            'data_type' => 'option', 'decimal_places' => 0, 'display_order' => 1,
            'options_json' => ['negative', 'positive', 'indeterminate'],
            'abnormal_options' => ['positive', 'indeterminate'],
            'loinc_code' => '16128-1',
        ]],

        'VDRL' => [[
            'code' => 'VDRL', 'name' => 'VDRL Result', 'unit' => null,
            'data_type' => 'option', 'decimal_places' => 0, 'display_order' => 1,
            'options_json' => ['non_reactive', 'reactive'],
            'abnormal_options' => ['reactive'],
            'loinc_code' => '5292-8',
        ]],

        'RPR' => [[
            'code' => 'RPR', 'name' => 'RPR Result', 'unit' => null,
            'data_type' => 'option', 'decimal_places' => 0, 'display_order' => 1,
            'options_json' => ['non_reactive', 'reactive'],
            'abnormal_options' => ['reactive'],
            'loinc_code' => '20508-0',
        ]],

        'MALARIA' => [[
            'code' => 'MALARIA', 'name' => 'Malaria Parasite Result', 'unit' => null,
            'data_type' => 'option', 'decimal_places' => 0, 'display_order' => 1,
            'options_json' => ['negative', 'positive — P. falciparum', 'positive — P. vivax', 'positive — P. malariae', 'positive — P. ovale', 'positive — mixed'],
            'abnormal_options' => ['positive — P. falciparum', 'positive — P. vivax', 'positive — P. malariae', 'positive — P. ovale', 'positive — mixed'],
            'loinc_code' => '51587-4',
        ]],

        'SPUTUM_AFB' => [[
            'code' => 'AFB', 'name' => 'AFB Smear Result', 'unit' => null,
            'data_type' => 'option', 'decimal_places' => 0, 'display_order' => 1,
            'options_json' => ['negative', 'scanty', '+', '++', '+++'],
            'abnormal_options' => ['scanty', '+', '++', '+++'],
        ]],

        'ABO' => [[
            'code' => 'ABO', 'name' => 'ABO Blood Group', 'unit' => null,
            'data_type' => 'option', 'decimal_places' => 0, 'display_order' => 1,
            'options_json' => ['A', 'B', 'AB', 'O'],
            'abnormal_options' => [],
        ]],

        'RH' => [[
            'code' => 'RH', 'name' => 'Rhesus Factor', 'unit' => null,
            'data_type' => 'option', 'decimal_places' => 0, 'display_order' => 1,
            'options_json' => ['positive', 'negative'],
            'abnormal_options' => [],
        ]],

        'BLOODGRP' => [[
            'code' => 'BLOODGRP', 'name' => 'Blood Group & Rhesus', 'unit' => null,
            'data_type' => 'option', 'decimal_places' => 0, 'display_order' => 1,
            'options_json' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'],
            'abnormal_options' => [],
        ]],

        'PREG_URINE' => [[
            'code' => 'PREG', 'name' => 'Pregnancy Test Result', 'unit' => null,
            'data_type' => 'option', 'decimal_places' => 0, 'display_order' => 1,
            'options_json' => ['negative', 'positive'],
            'abnormal_options' => [],
            'loinc_code' => '45392-5',
        ]],

        'RF' => [[
            'code' => 'RF', 'name' => 'Rheumatoid Factor Result', 'unit' => null,
            'data_type' => 'option', 'decimal_places' => 0, 'display_order' => 1,
            'options_json' => ['negative', 'positive'],
            'abnormal_options' => ['positive'],
        ]],

        'STOOL_OB' => [[
            'code' => 'STOOL_OB', 'name' => 'Occult Blood Result', 'unit' => null,
            'data_type' => 'option', 'decimal_places' => 0, 'display_order' => 1,
            'options_json' => ['negative', 'positive'],
            'abnormal_options' => ['positive'],
        ]],

        // ── Multi-parameter panel tests ──────────────────────────────────────
        'FBC' => [
            [
                'code' => 'FBC_WBC', 'name' => 'WBC', 'unit' => '10⁹/L',
                'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 1,
                'analyser_code' => 'WBC', 'loinc_code' => '6690-2',
                'ranges' => [
                    ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 4.0, 'high' => 11.0, 'critical_low' => 2.0, 'critical_high' => 30.0, 'notes' => 'Adults'],
                    ['sex' => 'any', 'age_min_days' => 365, 'age_max_days' => 4379, 'low' => 5.0, 'high' => 15.0, 'critical_low' => 2.0, 'critical_high' => 30.0, 'notes' => 'Children'],
                ],
            ],
            [
                'code' => 'FBC_RBC', 'name' => 'RBC', 'unit' => '10¹²/L',
                'data_type' => 'numeric', 'decimal_places' => 2, 'display_order' => 2,
                'analyser_code' => 'RBC', 'loinc_code' => '789-8',
                'ranges' => [
                    ['sex' => 'male', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 4.5, 'high' => 5.9, 'critical_low' => 2.5, 'critical_high' => 7.0, 'notes' => 'Adult male'],
                    ['sex' => 'female', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 4.0, 'high' => 5.2, 'critical_low' => 2.5, 'critical_high' => 7.0, 'notes' => 'Adult female'],
                ],
            ],
            [
                'code' => 'FBC_HGB', 'name' => 'HGB', 'unit' => 'g/dL',
                'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 3,
                'analyser_code' => 'HGB', 'loinc_code' => '718-7',
                'ranges' => [
                    ['sex' => 'male', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 13.0, 'high' => 17.0, 'critical_low' => 7.0, 'critical_high' => 20.0, 'notes' => 'Adult male'],
                    ['sex' => 'female', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 12.0, 'high' => 15.5, 'critical_low' => 7.0, 'critical_high' => 20.0, 'notes' => 'Adult female'],
                    ['sex' => 'any', 'age_min_days' => 365, 'age_max_days' => 4379, 'low' => 11.5, 'high' => 15.5, 'critical_low' => 7.0, 'critical_high' => 20.0, 'notes' => 'Children'],
                ],
            ],
            [
                'code' => 'FBC_HCT', 'name' => 'HCT', 'unit' => '%',
                'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 4,
                'analyser_code' => 'HCT', 'loinc_code' => '4544-3',
                'ranges' => [
                    ['sex' => 'male', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 40.0, 'high' => 52.0, 'critical_low' => 20.0, 'critical_high' => 60.0, 'notes' => 'Adult male'],
                    ['sex' => 'female', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 36.0, 'high' => 46.0, 'critical_low' => 20.0, 'critical_high' => 60.0, 'notes' => 'Adult female'],
                ],
            ],
            [
                'code' => 'FBC_MCV', 'name' => 'MCV', 'unit' => 'fL',
                'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 5,
                'analyser_code' => 'MCV', 'loinc_code' => '787-2',
                'ranges' => [
                    ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 80.0, 'high' => 100.0, 'critical_low' => 60.0, 'critical_high' => 120.0, 'notes' => 'Adults'],
                ],
            ],
            [
                'code' => 'FBC_MCH', 'name' => 'MCH', 'unit' => 'pg',
                'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 6,
                'analyser_code' => 'MCH', 'loinc_code' => '785-6',
                'ranges' => [
                    ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 27.0, 'high' => 33.0, 'critical_low' => 15.0, 'critical_high' => 45.0, 'notes' => 'Adults'],
                ],
            ],
            [
                'code' => 'FBC_MCHC', 'name' => 'MCHC', 'unit' => 'g/dL',
                'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 7,
                'analyser_code' => 'MCHC', 'loinc_code' => '786-4',
                'ranges' => [
                    ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 32.0, 'high' => 36.0, 'critical_low' => 20.0, 'critical_high' => 40.0, 'notes' => 'Adults'],
                ],
            ],
            [
                'code' => 'FBC_RDW', 'name' => 'RDW-CV', 'unit' => '%',
                'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 8,
                'analyser_code' => 'RDW', 'loinc_code' => '788-0',
                'ranges' => [
                    ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 11.5, 'high' => 14.5, 'critical_low' => null, 'critical_high' => null, 'notes' => 'Adults'],
                ],
            ],
            [
                'code' => 'FBC_PLT', 'name' => 'PLT', 'unit' => '10⁹/L',
                'data_type' => 'numeric', 'decimal_places' => 0, 'display_order' => 9,
                'analyser_code' => 'PLT', 'loinc_code' => '777-3',
                'ranges' => [
                    ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 150.0, 'high' => 450.0, 'critical_low' => 50.0, 'critical_high' => 1000.0, 'notes' => 'Adults'],
                    ['sex' => 'any', 'age_min_days' => 365, 'age_max_days' => 4379, 'low' => 150.0, 'high' => 450.0, 'critical_low' => 50.0, 'critical_high' => 1000.0, 'notes' => 'Children'],
                ],
            ],
        ],

        'LFT' => [
            [
                'code' => 'LFT_ALT', 'name' => 'ALT', 'unit' => 'U/L',
                'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 1,
                'analyser_code' => 'ALT', 'loinc_code' => '1742-6',
                'ranges' => [
                    ['sex' => 'male', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 7.0, 'high' => 56.0, 'critical_low' => null, 'critical_high' => 500.0, 'notes' => 'Adult male'],
                    ['sex' => 'female', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 7.0, 'high' => 35.0, 'critical_low' => null, 'critical_high' => 500.0, 'notes' => 'Adult female'],
                ],
            ],
            [
                'code' => 'LFT_AST', 'name' => 'AST', 'unit' => 'U/L',
                'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 2,
                'analyser_code' => 'AST', 'loinc_code' => '1920-8',
                'ranges' => [
                    ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 10.0, 'high' => 40.0, 'critical_low' => null, 'critical_high' => 500.0, 'notes' => 'Adults'],
                ],
            ],
            [
                'code' => 'LFT_ALP', 'name' => 'ALP', 'unit' => 'U/L',
                'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 3,
                'analyser_code' => 'ALP', 'loinc_code' => '6768-6',
                'ranges' => [
                    ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 44.0, 'high' => 147.0, 'critical_low' => null, 'critical_high' => null, 'notes' => 'Adults'],
                ],
            ],
            [
                'code' => 'LFT_TBIL', 'name' => 'Total Bilirubin', 'unit' => 'µmol/L',
                'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 4,
                'analyser_code' => 'TBIL', 'loinc_code' => '1975-2',
                'ranges' => [
                    ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 3.4, 'high' => 20.5, 'critical_low' => null, 'critical_high' => 350.0, 'notes' => 'Adults'],
                ],
            ],
            [
                'code' => 'LFT_DBIL', 'name' => 'Direct Bilirubin', 'unit' => 'µmol/L',
                'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 5,
                'analyser_code' => 'DBIL', 'loinc_code' => '1968-7',
                'ranges' => [
                    ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => null, 'high' => 6.8, 'critical_low' => null, 'critical_high' => null, 'notes' => 'Adults'],
                ],
            ],
            [
                'code' => 'LFT_TP', 'name' => 'Total Protein', 'unit' => 'g/L',
                'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 6,
                'analyser_code' => 'TP', 'loinc_code' => '2885-2',
                'ranges' => [
                    ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 60.0, 'high' => 80.0, 'critical_low' => 40.0, 'critical_high' => null, 'notes' => 'Adults'],
                ],
            ],
            [
                'code' => 'LFT_ALB', 'name' => 'Albumin', 'unit' => 'g/L',
                'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 7,
                'analyser_code' => 'ALB', 'loinc_code' => '1751-7',
                'ranges' => [
                    ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 35.0, 'high' => 50.0, 'critical_low' => 20.0, 'critical_high' => null, 'notes' => 'Adults'],
                ],
            ],
        ],

        'RFT' => [
            [
                'code' => 'RFT_UREA', 'name' => 'Urea', 'unit' => 'mmol/L',
                'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 1,
                'analyser_code' => 'UREA', 'loinc_code' => '3094-0',
                'ranges' => [
                    ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 2.5, 'high' => 7.1, 'critical_low' => null, 'critical_high' => 50.0, 'notes' => 'Adults'],
                ],
            ],
            [
                'code' => 'RFT_CREAT', 'name' => 'Creatinine', 'unit' => 'µmol/L',
                'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 2,
                'analyser_code' => 'CREAT', 'loinc_code' => '2160-0',
                'ranges' => [
                    ['sex' => 'male', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 62.0, 'high' => 106.0, 'critical_low' => null, 'critical_high' => 800.0, 'notes' => 'Adult male'],
                    ['sex' => 'female', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 44.0, 'high' => 80.0, 'critical_low' => null, 'critical_high' => 800.0, 'notes' => 'Adult female'],
                ],
            ],
            [
                'code' => 'RFT_NA', 'name' => 'Sodium', 'unit' => 'mmol/L',
                'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 3,
                'analyser_code' => 'NA', 'loinc_code' => '2951-2',
                'ranges' => [
                    ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 135.0, 'high' => 145.0, 'critical_low' => 120.0, 'critical_high' => 160.0, 'notes' => 'Adults'],
                ],
            ],
            [
                'code' => 'RFT_K', 'name' => 'Potassium', 'unit' => 'mmol/L',
                'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 4,
                'analyser_code' => 'K', 'loinc_code' => '2823-3',
                'ranges' => [
                    ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 3.5, 'high' => 5.1, 'critical_low' => 2.5, 'critical_high' => 6.5, 'notes' => 'Adults'],
                ],
            ],
            [
                'code' => 'RFT_CL', 'name' => 'Chloride', 'unit' => 'mmol/L',
                'data_type' => 'numeric', 'decimal_places' => 1, 'display_order' => 5,
                'analyser_code' => 'CL', 'loinc_code' => '2075-0',
                'ranges' => [
                    ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 98.0, 'high' => 107.0, 'critical_low' => 80.0, 'critical_high' => 120.0, 'notes' => 'Adults'],
                ],
            ],
        ],
    ];

    public function run(): void
    {
        foreach ($this->catalog as $testCode => $parameters) {
            $test = LaboratoryTest::where('code', $testCode)->first();

            if (! $test) {
                $this->command?->warn("Skipping {$testCode}: test not found in catalog.");

                continue;
            }

            foreach ($parameters as $paramData) {
                $ranges = $paramData['ranges'] ?? [];
                unset($paramData['ranges']);

                $parameter = LaboratoryTestParameter::updateOrCreate(
                    [
                        'laboratory_test_id' => $test->id,
                        'code' => $paramData['code'],
                    ],
                    array_merge($paramData, ['laboratory_test_id' => $test->id]),
                );

                foreach ($ranges as $rangeData) {
                    $this->seedRange($parameter, $rangeData);
                }
            }
        }
    }

    /**
     * @param  array{sex: string, age_min_days?: int|null, age_max_days?: int|null, low?: float|null, high?: float|null, critical_low?: float|null, critical_high?: float|null, notes?: string}  $rangeData
     */
    private function seedRange(LaboratoryTestParameter $parameter, array $rangeData): void
    {
        LaboratoryReferenceRange::updateOrCreate(
            [
                'laboratory_test_parameter_id' => $parameter->id,
                'sex' => $rangeData['sex'],
                'age_min_days' => $rangeData['age_min_days'] ?? null,
                'age_max_days' => $rangeData['age_max_days'] ?? null,
            ],
            array_merge($rangeData, ['laboratory_test_parameter_id' => $parameter->id]),
        );
    }
}
