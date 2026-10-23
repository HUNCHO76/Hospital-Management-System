<?php

namespace Database\Seeders;

use App\Models\LaboratoryReferenceRange;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestParameter;
use Illuminate\Database\Seeder;

/**
 * Seeds structured laboratory test parameters and standardizes specimen
 * configurations for tests that previously lacked parameters (both Normal
 * hospital catalog tests and NHIF items).
 */
class LaboratoryMissingParametersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Standardize specimens and containers for NHIF tests
        $specimenUpdates = [
            'NHIF-5091' => ['specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube'],
            'NHIF-5242' => ['specimen_type' => 'urine', 'specimen_container' => 'sterile_container'],
            'NHIF-5129' => ['specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube'],
            'NHIF-5040' => ['specimen_type' => 'whole_blood', 'specimen_container' => 'sterile_container'],
        ];

        foreach ($specimenUpdates as $testCode => $attrs) {
            LaboratoryTest::where('code', $testCode)->update($attrs);
        }

        // 2. Define parameters for all 12 tests
        $testParameters = [
            // Peripheral Blood Film (Hematology)
            'PBF' => [
                [
                    'code' => 'PBF_RBC',
                    'name' => 'RBC Morphology',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_TEXT,
                    'display_order' => 1,
                ],
                [
                    'code' => 'PBF_WBC',
                    'name' => 'WBC Morphology',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_TEXT,
                    'display_order' => 2,
                ],
                [
                    'code' => 'PBF_PLT',
                    'name' => 'Platelet Estimation',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
                    'options_json' => ['Adequate', 'Decreased', 'Increased', 'Clumped'],
                    'abnormal_options' => ['Decreased', 'Increased'],
                    'display_order' => 3,
                ],
                [
                    'code' => 'PBF_MP',
                    'name' => 'Blood Parasites',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
                    'options_json' => ['None seen', 'Malaria Parasites Seen', 'Microfilaria Seen', 'Other Parasites Seen'],
                    'abnormal_options' => ['Malaria Parasites Seen', 'Microfilaria Seen', 'Other Parasites Seen'],
                    'display_order' => 4,
                ],
                [
                    'code' => 'PBF_COMMENT',
                    'name' => 'Impression / Comment',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_TEXT,
                    'display_order' => 5,
                ],
            ],

            // Blood Culture (Microbiology)
            'BLD_CULT' => [
                [
                    'code' => 'BC_GROWTH',
                    'name' => 'Growth / Incubation',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
                    'options_json' => ['No growth after 7 days', 'No growth after 5 days', 'Growth detected', 'Contaminated'],
                    'abnormal_options' => ['Growth detected'],
                    'display_order' => 1,
                ],
                [
                    'code' => 'BC_ORGANISM',
                    'name' => 'Isolated Organism',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_TEXT,
                    'display_order' => 2,
                ],
                [
                    'code' => 'BC_ANTIBIOGRAM',
                    'name' => 'Antibiotic Sensitivity Pattern',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_TEXT,
                    'display_order' => 3,
                ],
                [
                    'code' => 'BC_REMARKS',
                    'name' => 'Remarks / Interpretation',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_TEXT,
                    'display_order' => 4,
                ],
            ],

            // Urine Culture (Microbiology)
            'URINE_CULT' => [
                [
                    'code' => 'UC_GROWTH',
                    'name' => 'Bacterial Growth',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
                    'options_json' => ['No significant growth', 'Significant growth (>10^5 CFU/mL)', 'Insignificant growth (<10^4 CFU/mL)', 'Mixed growth (suspect contamination)'],
                    'abnormal_options' => ['Significant growth (>10^5 CFU/mL)'],
                    'display_order' => 1,
                ],
                [
                    'code' => 'UC_ORGANISM',
                    'name' => 'Isolated Organism',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_TEXT,
                    'display_order' => 2,
                ],
                [
                    'code' => 'UC_COLONY_COUNT',
                    'name' => 'Colony Count',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_TEXT,
                    'display_order' => 3,
                ],
                [
                    'code' => 'UC_ANTIBIOGRAM',
                    'name' => 'Antibiotic Sensitivity Pattern',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_TEXT,
                    'display_order' => 4,
                ],
            ],

            // Stool Culture (Microbiology)
            'STOOL_CULT' => [
                [
                    'code' => 'SC_GROWTH',
                    'name' => 'Culture Result',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
                    'options_json' => ['No Salmonella, Shigella or Campylobacter isolated', 'Salmonella isolated', 'Shigella isolated', 'Campylobacter isolated', 'Vibrio cholerae isolated', 'Other pathogen isolated'],
                    'abnormal_options' => ['Salmonella isolated', 'Shigella isolated', 'Campylobacter isolated', 'Vibrio cholerae isolated', 'Other pathogen isolated'],
                    'display_order' => 1,
                ],
                [
                    'code' => 'SC_ORGANISM',
                    'name' => 'Isolated Pathogen',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_TEXT,
                    'display_order' => 2,
                ],
                [
                    'code' => 'SC_ANTIBIOGRAM',
                    'name' => 'Antibiotic Sensitivity Pattern',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_TEXT,
                    'display_order' => 3,
                ],
            ],

            // Sputum Examination (Microbiology)
            'SPUTUM_EXAM' => [
                [
                    'code' => 'SPUTUM_APPEARANCE',
                    'name' => 'Macroscopic Appearance',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
                    'options_json' => ['Mucoid', 'Mucopurulent', 'Purulent', 'Salivary', 'Blood-stained'],
                    'abnormal_options' => ['Purulent', 'Blood-stained'],
                    'display_order' => 1,
                ],
                [
                    'code' => 'SPUTUM_PUS',
                    'name' => 'Pus Cells / Microscopy',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_TEXT,
                    'display_order' => 2,
                ],
                [
                    'code' => 'SPUTUM_GRAM',
                    'name' => 'Gram Smear Findings',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_TEXT,
                    'display_order' => 3,
                ],
                [
                    'code' => 'SPUTUM_AFB',
                    'name' => 'Acid-Fast Bacilli (AFB)',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
                    'options_json' => ['Negative', '1+ (10-99 AFB / 100 fields)', '2+ (1-10 AFB / field)', '3+ (>10 AFB / field)'],
                    'abnormal_options' => ['1+ (10-99 AFB / 100 fields)', '2+ (1-10 AFB / field)', '3+ (>10 AFB / field)'],
                    'display_order' => 4,
                ],
            ],

            // Gram Stain (Microbiology)
            'GRAM_STAIN' => [
                [
                    'code' => 'GS_RESULT',
                    'name' => 'Microscopic Findings',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_TEXT,
                    'display_order' => 1,
                ],
                [
                    'code' => 'GS_PUS',
                    'name' => 'Pus Cells / Polymorphs',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
                    'options_json' => ['None seen', 'Few (1-5/HPF)', 'Moderate (5-10/HPF)', 'Many (>10/HPF)'],
                    'abnormal_options' => ['Moderate (5-10/HPF)', 'Many (>10/HPF)'],
                    'display_order' => 2,
                ],
                [
                    'code' => 'GS_EPITH',
                    'name' => 'Epithelial Cells',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
                    'options_json' => ['None seen', 'Few', 'Moderate', 'Many'],
                    'abnormal_options' => [],
                    'display_order' => 3,
                ],
                [
                    'code' => 'GS_GRAM_POS',
                    'name' => 'Gram-Positive Organisms',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_TEXT,
                    'display_order' => 4,
                ],
                [
                    'code' => 'GS_GRAM_NEG',
                    'name' => 'Gram-Negative Organisms',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_TEXT,
                    'display_order' => 5,
                ],
            ],

            // Crossmatch (Blood Bank)
            'XMATCH' => [
                [
                    'code' => 'XM_RECIPIENT_ABO',
                    'name' => 'Recipient Blood Group',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
                    'options_json' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'],
                    'abnormal_options' => [],
                    'display_order' => 1,
                ],
                [
                    'code' => 'XM_DONOR_UNIT',
                    'name' => 'Donor Unit Number',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_TEXT,
                    'display_order' => 2,
                ],
                [
                    'code' => 'XM_DONOR_ABO',
                    'name' => 'Donor Unit Blood Group',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
                    'options_json' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'],
                    'abnormal_options' => [],
                    'display_order' => 3,
                ],
                [
                    'code' => 'XM_MAJOR',
                    'name' => 'Major Crossmatch',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
                    'options_json' => ['Compatible', 'Incompatible'],
                    'abnormal_options' => ['Incompatible'],
                    'display_order' => 4,
                ],
                [
                    'code' => 'XM_MINOR',
                    'name' => 'Minor Crossmatch',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
                    'options_json' => ['Compatible', 'Incompatible'],
                    'abnormal_options' => ['Incompatible'],
                    'display_order' => 5,
                ],
                [
                    'code' => 'XM_VERDICT',
                    'name' => 'Transfusion Compatibility Verdict',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
                    'options_json' => ['Safe for Transfusion', 'Unsafe / Reject Unit'],
                    'abnormal_options' => ['Unsafe / Reject Unit'],
                    'display_order' => 6,
                ],
            ],

            // Stool Routine Examination (Stool Examination)
            'STOOL_EXAM' => [
                [
                    'code' => 'STOOL_CONSISTENCY',
                    'name' => 'Consistency / Macroscopy',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
                    'options_json' => ['Formed', 'Semi-formed', 'Soft', 'Watery', 'Mucoid'],
                    'abnormal_options' => ['Watery', 'Mucoid'],
                    'display_order' => 1,
                ],
                [
                    'code' => 'STOOL_COLOR',
                    'name' => 'Color',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
                    'options_json' => ['Brown', 'Yellow', 'Green', 'Pale', 'Dark/Tarry', 'Red/Bloody'],
                    'abnormal_options' => ['Pale', 'Dark/Tarry', 'Red/Bloody'],
                    'display_order' => 2,
                ],
                [
                    'code' => 'STOOL_BLOOD',
                    'name' => 'Macroscopic Blood',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
                    'options_json' => ['Absent', 'Present'],
                    'abnormal_options' => ['Present'],
                    'display_order' => 3,
                ],
                [
                    'code' => 'STOOL_MUCUS',
                    'name' => 'Mucus',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
                    'options_json' => ['Absent', 'Present'],
                    'abnormal_options' => ['Present'],
                    'display_order' => 4,
                ],
                [
                    'code' => 'STOOL_RBC',
                    'name' => 'Red Blood Cells (RBCs)',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_TEXT,
                    'display_order' => 5,
                ],
                [
                    'code' => 'STOOL_PUS',
                    'name' => 'Pus Cells / HPF',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_TEXT,
                    'display_order' => 6,
                ],
                [
                    'code' => 'STOOL_PARASITES',
                    'name' => 'Helminth Ova / Larvae',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
                    'options_json' => ['None seen', 'Hookworm ova seen', 'Ascaris lumbricoides ova seen', 'Trichuris trichiura ova seen', 'Taenia spp. ova seen', 'Schistosoma mansoni ova seen', 'Strongyloides stercoralis larvae seen'],
                    'abnormal_options' => ['Hookworm ova seen', 'Ascaris lumbricoides ova seen', 'Trichuris trichiura ova seen', 'Taenia spp. ova seen', 'Schistosoma mansoni ova seen', 'Strongyloides stercoralis larvae seen'],
                    'display_order' => 7,
                ],
                [
                    'code' => 'STOOL_PROTOZOA',
                    'name' => 'Protozoa / Cysts / Trophozoites',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
                    'options_json' => ['None seen', 'Entamoeba histolytica cysts seen', 'Entamoeba histolytica trophozoites seen', 'Giardia lamblia cysts seen', 'Giardia lamblia trophozoites seen', 'Blastocystis hominis seen'],
                    'abnormal_options' => ['Entamoeba histolytica cysts seen', 'Entamoeba histolytica trophozoites seen', 'Giardia lamblia cysts seen', 'Giardia lamblia trophozoites seen'],
                    'display_order' => 8,
                ],
            ],

            // NHIF-5091: Full Blood Picture (FBP) / + Peripheral Smear
            'NHIF-5091' => [
                [
                    'code' => 'WBC',
                    'name' => 'White Blood Cell Count',
                    'unit' => '10⁹/L',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                    'decimal_places' => 1,
                    'display_order' => 1,
                    'ranges' => [
                        ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 4.0, 'high' => 11.0, 'critical_low' => 2.0, 'critical_high' => 30.0, 'notes' => 'Adults'],
                        ['sex' => 'any', 'age_min_days' => 365, 'age_max_days' => 4379, 'low' => 5.0, 'high' => 15.0, 'critical_low' => 2.0, 'critical_high' => 30.0, 'notes' => 'Children 1–11 years'],
                        ['sex' => 'any', 'age_min_days' => 0, 'age_max_days' => 27, 'low' => 9.0, 'high' => 30.0, 'critical_low' => 5.0, 'critical_high' => 40.0, 'notes' => 'Neonates'],
                    ],
                ],
                [
                    'code' => 'RBC',
                    'name' => 'Red Blood Cell Count',
                    'unit' => '10¹²/L',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                    'decimal_places' => 2,
                    'display_order' => 2,
                    'ranges' => [
                        ['sex' => 'male', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 4.5, 'high' => 5.9, 'critical_low' => 2.5, 'critical_high' => 7.0, 'notes' => 'Adult male'],
                        ['sex' => 'female', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 4.0, 'high' => 5.2, 'critical_low' => 2.5, 'critical_high' => 7.0, 'notes' => 'Adult female'],
                        ['sex' => 'any', 'age_min_days' => 365, 'age_max_days' => 4379, 'low' => 3.9, 'high' => 5.3, 'critical_low' => 2.5, 'critical_high' => 7.0, 'notes' => 'Children 1–11 years'],
                    ],
                ],
                [
                    'code' => 'HGB',
                    'name' => 'Hemoglobin',
                    'unit' => 'g/dL',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                    'decimal_places' => 1,
                    'display_order' => 3,
                    'ranges' => [
                        ['sex' => 'male', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 13.0, 'high' => 17.0, 'critical_low' => 7.0, 'critical_high' => 20.0, 'notes' => 'Adult male'],
                        ['sex' => 'female', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 12.0, 'high' => 15.5, 'critical_low' => 7.0, 'critical_high' => 20.0, 'notes' => 'Adult female'],
                        ['sex' => 'any', 'age_min_days' => 365, 'age_max_days' => 4379, 'low' => 11.5, 'high' => 15.5, 'critical_low' => 7.0, 'critical_high' => 20.0, 'notes' => 'Child 1–11 years'],
                        ['sex' => 'any', 'age_min_days' => 28, 'age_max_days' => 364, 'low' => 10.5, 'high' => 14.0, 'critical_low' => 7.0, 'critical_high' => 20.0, 'notes' => 'Infant 1–11 months'],
                        ['sex' => 'any', 'age_min_days' => 0, 'age_max_days' => 27, 'low' => 14.0, 'high' => 21.0, 'critical_low' => 10.0, 'critical_high' => 25.0, 'notes' => 'Neonate 0–27 days'],
                    ],
                ],
                [
                    'code' => 'HCT',
                    'name' => 'Hematocrit (PCV)',
                    'unit' => '%',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                    'decimal_places' => 1,
                    'display_order' => 4,
                    'ranges' => [
                        ['sex' => 'male', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 40.0, 'high' => 52.0, 'critical_low' => 20.0, 'critical_high' => 60.0, 'notes' => 'Adult male'],
                        ['sex' => 'female', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 36.0, 'high' => 46.0, 'critical_low' => 20.0, 'critical_high' => 60.0, 'notes' => 'Adult female'],
                        ['sex' => 'any', 'age_min_days' => 365, 'age_max_days' => 4379, 'low' => 35.0, 'high' => 45.0, 'critical_low' => 20.0, 'critical_high' => 60.0, 'notes' => 'Child 1–11 years'],
                        ['sex' => 'any', 'age_min_days' => 0, 'age_max_days' => 27, 'low' => 42.0, 'high' => 65.0, 'critical_low' => 30.0, 'critical_high' => 75.0, 'notes' => 'Neonate'],
                    ],
                ],
                [
                    'code' => 'MCV',
                    'name' => 'Mean Cell Volume',
                    'unit' => 'fL',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                    'decimal_places' => 1,
                    'display_order' => 5,
                    'ranges' => [
                        ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 80.0, 'high' => 100.0, 'critical_low' => null, 'critical_high' => null, 'notes' => 'Adults'],
                        ['sex' => 'any', 'age_min_days' => 365, 'age_max_days' => 4379, 'low' => 72.0, 'high' => 88.0, 'critical_low' => null, 'critical_high' => null, 'notes' => 'Children 1–11 years'],
                    ],
                ],
                [
                    'code' => 'MCH',
                    'name' => 'Mean Cell Hemoglobin',
                    'unit' => 'pg',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                    'decimal_places' => 1,
                    'display_order' => 6,
                    'ranges' => [
                        ['sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null, 'low' => 27.0, 'high' => 33.0, 'critical_low' => null, 'critical_high' => null, 'notes' => 'Adults'],
                        ['sex' => 'any', 'age_min_days' => 365, 'age_max_days' => 4379, 'low' => 24.0, 'high' => 30.0, 'critical_low' => null, 'critical_high' => null, 'notes' => 'Children 1–11 years'],
                    ],
                ],
                [
                    'code' => 'MCHC',
                    'name' => 'Mean Cell Hemoglobin Concentration',
                    'unit' => 'g/dL',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                    'decimal_places' => 1,
                    'display_order' => 7,
                    'ranges' => [
                        ['sex' => 'any', 'age_min_days' => 0, 'age_max_days' => null, 'low' => 32.0, 'high' => 36.0, 'critical_low' => null, 'critical_high' => null, 'notes' => 'All ages'],
                    ],
                ],
                [
                    'code' => 'PLT',
                    'name' => 'Platelet Count',
                    'unit' => '10⁹/L',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                    'decimal_places' => 0,
                    'display_order' => 8,
                    'ranges' => [
                        ['sex' => 'any', 'age_min_days' => 0, 'age_max_days' => null, 'low' => 150.0, 'high' => 450.0, 'critical_low' => 50.0, 'critical_high' => 1000.0, 'notes' => 'All ages'],
                    ],
                ],
                [
                    'code' => 'PBF_MORPH',
                    'name' => 'Blood Film Morphology / Impression',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_TEXT,
                    'display_order' => 9,
                ],
            ],

            // NHIF-5242: Urine Culture & Sensitivity
            'NHIF-5242' => [
                [
                    'code' => 'GROWTH',
                    'name' => 'Bacterial Growth',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
                    'options_json' => ['No significant growth', 'Significant growth (>10^5 CFU/mL)', 'Insignificant growth (<10^4 CFU/mL)', 'Mixed growth (suspect contamination)'],
                    'abnormal_options' => ['Significant growth (>10^5 CFU/mL)'],
                    'display_order' => 1,
                ],
                [
                    'code' => 'ORGANISM',
                    'name' => 'Isolated Organism',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_TEXT,
                    'display_order' => 2,
                ],
                [
                    'code' => 'COLONY_COUNT',
                    'name' => 'Colony Count',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_TEXT,
                    'display_order' => 3,
                ],
                [
                    'code' => 'ANTIBIOGRAM',
                    'name' => 'Antibiotic Sensitivity Pattern',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_TEXT,
                    'display_order' => 4,
                ],
            ],

            // NHIF-5129: Malaria Blood Smear (B/S)
            'NHIF-5129' => [
                [
                    'code' => 'BS_RESULT',
                    'name' => 'Malaria Blood Smear Result',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
                    'options_json' => [
                        'Negative (No malaria parasites seen)',
                        'Positive (Plasmodium falciparum)',
                        'Positive (Plasmodium vivax)',
                        'Positive (Plasmodium malariae)',
                        'Positive (Plasmodium ovale)',
                        'Mixed Plasmodium infection',
                    ],
                    'abnormal_options' => [
                        'Positive (Plasmodium falciparum)',
                        'Positive (Plasmodium vivax)',
                        'Positive (Plasmodium malariae)',
                        'Positive (Plasmodium ovale)',
                        'Mixed Plasmodium infection',
                    ],
                    'display_order' => 1,
                ],
                [
                    'code' => 'BS_DENSITY',
                    'name' => 'Parasite Density / Quantification',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_TEXT,
                    'display_order' => 2,
                ],
                [
                    'code' => 'BS_GAMETOCYTES',
                    'name' => 'Gametocytes',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
                    'options_json' => ['None seen', 'Present'],
                    'abnormal_options' => ['Present'],
                    'display_order' => 3,
                ],
                [
                    'code' => 'BS_PIGMENT',
                    'name' => 'Malarial Pigment in Monocytes',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
                    'options_json' => ['None seen', 'Present'],
                    'abnormal_options' => ['Present'],
                    'display_order' => 4,
                ],
            ],

            // NHIF-5040: Blood Culture and Sensitivity (Aerobic/Anaerobic)
            'NHIF-5040' => [
                [
                    'code' => 'AEROBIC_GROWTH',
                    'name' => 'Aerobic Culture Growth',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
                    'options_json' => ['No growth after 7 days', 'Growth detected', 'Contaminated'],
                    'abnormal_options' => ['Growth detected'],
                    'display_order' => 1,
                ],
                [
                    'code' => 'ANAEROBIC_GROWTH',
                    'name' => 'Anaerobic Culture Growth',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
                    'options_json' => ['No growth after 7 days', 'Growth detected', 'Contaminated'],
                    'abnormal_options' => ['Growth detected'],
                    'display_order' => 2,
                ],
                [
                    'code' => 'ORGANISM',
                    'name' => 'Isolated Organism',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_TEXT,
                    'display_order' => 3,
                ],
                [
                    'code' => 'ANTIBIOGRAM',
                    'name' => 'Antibiotic Sensitivity Pattern',
                    'data_type' => LaboratoryTestParameter::DATA_TYPE_TEXT,
                    'display_order' => 4,
                ],
            ],
        ];

        foreach ($testParameters as $testCode => $parameters) {
            $test = LaboratoryTest::where('code', $testCode)->first();
            if (! $test) {
                continue;
            }

            foreach ($parameters as $param) {
                $ranges = $param['ranges'] ?? [];
                unset($param['ranges']);

                $parameter = LaboratoryTestParameter::updateOrCreate(
                    [
                        'laboratory_test_id' => $test->id,
                        'code' => $param['code'],
                    ],
                    [
                        'name' => $param['name'],
                        'unit' => $param['unit'] ?? null,
                        'data_type' => $param['data_type'],
                        'decimal_places' => $param['decimal_places'] ?? 1,
                        'options_json' => $param['options_json'] ?? null,
                        'abnormal_options' => $param['abnormal_options'] ?? null,
                        'display_order' => $param['display_order'] ?? 0,
                        'active' => true,
                    ]
                );

                foreach ($ranges as $rangeData) {
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
        }
    }
}
