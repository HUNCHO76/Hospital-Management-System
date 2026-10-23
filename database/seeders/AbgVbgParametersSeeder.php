<?php

namespace Database\Seeders;

use App\Models\LaboratoryReferenceRange;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestParameter;
use Illuminate\Database\Seeder;

class AbgVbgParametersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $test = LaboratoryTest::where('code', 'NHIF-5026')
            ->orWhere('nhif_code', '5026')
            ->orWhere('name', 'like', '%Blood Gas%')
            ->first();

        if (! $test) {
            $test = LaboratoryTest::create([
                'code' => 'NHIF-5026',
                'name' => 'Arterial Blood Gases(ABG)/ Venous Blood Gases(VBG) Analysis',
                'nhif_code' => '5026',
                'status' => LaboratoryTest::STATUS_ACTIVE,
                'result_type' => 'numeric',
                'default_priority' => 'routine',
                'specimen_type' => 'whole_blood',
                'specimen_container' => 'other',
                'collection_notes' => 'Heparinized blood gas syringe',
            ]);
        }

        $parameters = [
            // Core Required Parameters
            [
                'code' => 'PH',
                'name' => 'pH',
                'unit' => 'pH units',
                'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                'decimal_places' => 2,
                'display_order' => 1,
                'ranges' => [
                    [
                        'specimen_source' => 'arterial',
                        'sex' => 'any',
                        'low' => 7.35,
                        'high' => 7.45,
                        'critical_low' => 7.20,
                        'critical_high' => 7.60,
                        'notes' => 'Arterial reference interval',
                    ],
                    [
                        'specimen_source' => 'venous',
                        'sex' => 'any',
                        'low' => 7.31,
                        'high' => 7.41,
                        'critical_low' => 7.20,
                        'critical_high' => 7.60,
                        'notes' => 'Venous reference interval',
                    ],
                ],
            ],
            [
                'code' => 'PCO2',
                'name' => 'Partial Pressure of Carbon Dioxide (pCO₂)',
                'unit' => 'mmHg',
                'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                'decimal_places' => 1,
                'display_order' => 2,
                'ranges' => [
                    [
                        'specimen_source' => 'arterial',
                        'sex' => 'any',
                        'low' => 35.0,
                        'high' => 45.0,
                        'critical_low' => 20.0,
                        'critical_high' => 70.0,
                        'notes' => 'Arterial reference interval',
                    ],
                    [
                        'specimen_source' => 'venous',
                        'sex' => 'any',
                        'low' => 41.0,
                        'high' => 51.0,
                        'critical_low' => 25.0,
                        'critical_high' => 75.0,
                        'notes' => 'Venous reference interval',
                    ],
                ],
            ],
            [
                'code' => 'PO2',
                'name' => 'Partial Pressure of Oxygen (pO₂)',
                'unit' => 'mmHg',
                'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                'decimal_places' => 1,
                'display_order' => 3,
                'ranges' => [
                    [
                        'specimen_source' => 'arterial',
                        'sex' => 'any',
                        'low' => 80.0,
                        'high' => 100.0,
                        'critical_low' => 60.0,
                        'critical_high' => null,
                        'notes' => 'Arterial reference interval (sea level, room air)',
                    ],
                    [
                        'specimen_source' => 'venous',
                        'sex' => 'any',
                        'low' => 30.0,
                        'high' => 40.0,
                        'critical_low' => null,
                        'critical_high' => null,
                        'notes' => 'Venous pO2 has limited clinical value for oxygenation assessment',
                    ],
                ],
            ],
            [
                'code' => 'HCO3',
                'name' => 'Bicarbonate (HCO₃⁻)',
                'unit' => 'mmol/L',
                'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                'decimal_places' => 1,
                'display_order' => 4,
                'ranges' => [
                    [
                        'specimen_source' => 'arterial',
                        'sex' => 'any',
                        'low' => 22.0,
                        'high' => 26.0,
                        'critical_low' => 10.0,
                        'critical_high' => 40.0,
                        'notes' => 'Arterial reference interval',
                    ],
                    [
                        'specimen_source' => 'venous',
                        'sex' => 'any',
                        'low' => 22.0,
                        'high' => 29.0,
                        'critical_low' => 10.0,
                        'critical_high' => 40.0,
                        'notes' => 'Venous reference interval',
                    ],
                ],
            ],
            [
                'code' => 'BE',
                'name' => 'Base Excess (BE)',
                'unit' => 'mmol/L',
                'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                'decimal_places' => 1,
                'display_order' => 5,
                'ranges' => [
                    [
                        'specimen_source' => 'any',
                        'sex' => 'any',
                        'low' => -2.0,
                        'high' => 2.0,
                        'critical_low' => -10.0,
                        'critical_high' => 10.0,
                        'notes' => 'Applicable to arterial and venous blood gases',
                    ],
                ],
            ],
            [
                'code' => 'SO2',
                'name' => 'Oxygen Saturation (sO₂)',
                'unit' => '%',
                'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                'decimal_places' => 1,
                'display_order' => 6,
                'ranges' => [
                    [
                        'specimen_source' => 'arterial',
                        'sex' => 'any',
                        'low' => 95.0,
                        'high' => 100.0,
                        'critical_low' => 90.0,
                        'critical_high' => null,
                        'notes' => 'Arterial oxygen saturation',
                    ],
                    [
                        'specimen_source' => 'venous',
                        'sex' => 'any',
                        'low' => 70.0,
                        'high' => 75.0,
                        'critical_low' => null,
                        'critical_high' => null,
                        'notes' => 'Venous oxygen saturation',
                    ],
                ],
            ],
            [
                'code' => 'TCO2',
                'name' => 'Total Carbon Dioxide (tCO₂)',
                'unit' => 'mmol/L',
                'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                'decimal_places' => 1,
                'display_order' => 7,
                'ranges' => [
                    [
                        'specimen_source' => 'any',
                        'sex' => 'any',
                        'low' => 23.0,
                        'high' => 27.0,
                        'critical_low' => 10.0,
                        'critical_high' => 40.0,
                        'notes' => 'Total CO2',
                    ],
                ],
            ],
            [
                'code' => 'LACTATE',
                'name' => 'Lactate',
                'unit' => 'mmol/L',
                'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                'decimal_places' => 2,
                'display_order' => 8,
                'ranges' => [
                    [
                        'specimen_source' => 'any',
                        'sex' => 'any',
                        'low' => 0.50,
                        'high' => 2.00,
                        'critical_low' => null,
                        'critical_high' => 4.00,
                        'notes' => 'Lactate > 2.0 mmol/L indicates hypoperfusion/sepsis; > 4.0 is critical',
                    ],
                ],
            ],
            [
                'code' => 'NA',
                'name' => 'Sodium (Na⁺)',
                'unit' => 'mmol/L',
                'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                'decimal_places' => 1,
                'display_order' => 9,
                'ranges' => [
                    [
                        'specimen_source' => 'any',
                        'sex' => 'any',
                        'low' => 135.0,
                        'high' => 145.0,
                        'critical_low' => 120.0,
                        'critical_high' => 160.0,
                        'notes' => 'Electrolytes - Sodium',
                    ],
                ],
            ],
            [
                'code' => 'K',
                'name' => 'Potassium (K⁺)',
                'unit' => 'mmol/L',
                'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                'decimal_places' => 2,
                'display_order' => 10,
                'ranges' => [
                    [
                        'specimen_source' => 'any',
                        'sex' => 'any',
                        'low' => 3.50,
                        'high' => 5.00,
                        'critical_low' => 2.80,
                        'critical_high' => 6.20,
                        'notes' => 'Electrolytes - Potassium',
                    ],
                ],
            ],
            [
                'code' => 'CL',
                'name' => 'Chloride (Cl⁻)',
                'unit' => 'mmol/L',
                'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                'decimal_places' => 1,
                'display_order' => 11,
                'ranges' => [
                    [
                        'specimen_source' => 'any',
                        'sex' => 'any',
                        'low' => 98.0,
                        'high' => 106.0,
                        'critical_low' => 80.0,
                        'critical_high' => 125.0,
                        'notes' => 'Electrolytes - Chloride',
                    ],
                ],
            ],
            [
                'code' => 'ICA',
                'name' => 'Ionized Calcium (iCa²⁺)',
                'unit' => 'mmol/L',
                'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                'decimal_places' => 2,
                'display_order' => 12,
                'ranges' => [
                    [
                        'specimen_source' => 'any',
                        'sex' => 'any',
                        'low' => 1.12,
                        'high' => 1.32,
                        'critical_low' => 0.80,
                        'critical_high' => 1.60,
                        'notes' => 'Free / Ionized Calcium',
                    ],
                ],
            ],
            [
                'code' => 'GLUCOSE',
                'name' => 'Glucose',
                'unit' => 'mmol/L',
                'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                'decimal_places' => 1,
                'display_order' => 13,
                'ranges' => [
                    [
                        'specimen_source' => 'any',
                        'sex' => 'any',
                        'low' => 3.9,
                        'high' => 6.1,
                        'critical_low' => 2.8,
                        'critical_high' => 25.0,
                        'notes' => 'Fasting reference 3.9-6.1 mmol/L; critical < 2.8 or > 25.0',
                    ],
                ],
            ],
            [
                'code' => 'HCT',
                'name' => 'Hematocrit (Hct)',
                'unit' => '%',
                'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                'decimal_places' => 1,
                'display_order' => 14,
                'ranges' => [
                    [
                        'specimen_source' => 'any',
                        'sex' => 'male',
                        'age_min_days' => 6570,
                        'low' => 40.0,
                        'high' => 52.0,
                        'critical_low' => 20.0,
                        'critical_high' => 60.0,
                        'notes' => 'Adult male hematocrit',
                    ],
                    [
                        'specimen_source' => 'any',
                        'sex' => 'female',
                        'age_min_days' => 6570,
                        'low' => 36.0,
                        'high' => 48.0,
                        'critical_low' => 20.0,
                        'critical_high' => 60.0,
                        'notes' => 'Adult female hematocrit',
                    ],
                    [
                        'specimen_source' => 'any',
                        'sex' => 'any',
                        'low' => 36.0,
                        'high' => 50.0,
                        'critical_low' => 20.0,
                        'critical_high' => 60.0,
                        'notes' => 'General hematocrit',
                    ],
                ],
            ],
            [
                'code' => 'THB',
                'name' => 'Total Hemoglobin (tHb)',
                'unit' => 'g/dL',
                'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                'decimal_places' => 1,
                'display_order' => 15,
                'ranges' => [
                    [
                        'specimen_source' => 'any',
                        'sex' => 'male',
                        'age_min_days' => 6570,
                        'low' => 13.0,
                        'high' => 17.5,
                        'critical_low' => 7.0,
                        'critical_high' => 20.0,
                        'notes' => 'Adult male hemoglobin',
                    ],
                    [
                        'specimen_source' => 'any',
                        'sex' => 'female',
                        'age_min_days' => 6570,
                        'low' => 12.0,
                        'high' => 15.5,
                        'critical_low' => 7.0,
                        'critical_high' => 20.0,
                        'notes' => 'Adult female hemoglobin',
                    ],
                    [
                        'specimen_source' => 'any',
                        'sex' => 'any',
                        'low' => 12.0,
                        'high' => 16.5,
                        'critical_low' => 7.0,
                        'critical_high' => 20.0,
                        'notes' => 'General adult hemoglobin',
                    ],
                ],
            ],
            // Additional Optional Analyzer Parameters
            [
                'code' => 'ST_HCO3',
                'name' => 'Standard Bicarbonate (st. HCO₃⁻)',
                'unit' => 'mmol/L',
                'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                'decimal_places' => 1,
                'display_order' => 16,
                'ranges' => [
                    [
                        'specimen_source' => 'any',
                        'sex' => 'any',
                        'low' => 22.0,
                        'high' => 26.0,
                        'critical_low' => 10.0,
                        'critical_high' => 40.0,
                        'notes' => 'Standard bicarbonate at pCO2 40 mmHg',
                    ],
                ],
            ],
            [
                'code' => 'ANION_GAP',
                'name' => 'Anion Gap (AG)',
                'unit' => 'mmol/L',
                'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                'decimal_places' => 1,
                'display_order' => 17,
                'ranges' => [
                    [
                        'specimen_source' => 'any',
                        'sex' => 'any',
                        'low' => 7.0,
                        'high' => 17.0,
                        'critical_low' => null,
                        'critical_high' => 20.0,
                        'notes' => 'Calculated: (Na + K) - (Cl + HCO3) or analyzer-reported',
                    ],
                ],
            ],
            [
                'code' => 'O2HB',
                'name' => 'Fractional Oxyhemoglobin (O₂Hb)',
                'unit' => '%',
                'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                'decimal_places' => 1,
                'display_order' => 18,
                'ranges' => [
                    [
                        'specimen_source' => 'any',
                        'sex' => 'any',
                        'low' => 94.0,
                        'high' => 98.0,
                        'critical_low' => 88.0,
                        'critical_high' => null,
                        'notes' => 'Co-oximetry oxyhemoglobin fraction',
                    ],
                ],
            ],
            [
                'code' => 'COHB',
                'name' => 'Carboxyhemoglobin (COHb)',
                'unit' => '%',
                'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                'decimal_places' => 1,
                'display_order' => 19,
                'ranges' => [
                    [
                        'specimen_source' => 'any',
                        'sex' => 'any',
                        'low' => 0.0,
                        'high' => 1.5,
                        'critical_low' => null,
                        'critical_high' => 10.0,
                        'notes' => 'Non-smoker reference: 0-1.5%; smokers may range 3-8%; >10% toxic',
                    ],
                ],
            ],
            [
                'code' => 'METHB',
                'name' => 'Methemoglobin (MetHb)',
                'unit' => '%',
                'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
                'decimal_places' => 1,
                'display_order' => 20,
                'ranges' => [
                    [
                        'specimen_source' => 'any',
                        'sex' => 'any',
                        'low' => 0.0,
                        'high' => 1.5,
                        'critical_low' => null,
                        'critical_high' => 5.0,
                        'notes' => 'Co-oximetry methemoglobin fraction; >5% methemoglobinemia alert',
                    ],
                ],
            ],
        ];

        foreach ($parameters as $paramData) {
            $ranges = $paramData['ranges'] ?? [];
            unset($paramData['ranges']);

            $parameter = LaboratoryTestParameter::updateOrCreate(
                [
                    'laboratory_test_id' => $test->id,
                    'code' => $paramData['code'],
                ],
                array_merge($paramData, [
                    'laboratory_test_id' => $test->id,
                    'active' => true,
                ]),
            );

            foreach ($ranges as $rangeData) {
                LaboratoryReferenceRange::updateOrCreate(
                    [
                        'laboratory_test_parameter_id' => $parameter->id,
                        'specimen_source' => $rangeData['specimen_source'] ?? null,
                        'sex' => $rangeData['sex'] ?? 'any',
                        'age_min_days' => $rangeData['age_min_days'] ?? null,
                        'age_max_days' => $rangeData['age_max_days'] ?? null,
                    ],
                    array_merge($rangeData, ['laboratory_test_parameter_id' => $parameter->id]),
                );
            }
        }
    }
}
