<?php

namespace Database\Seeders;

use App\Models\DiagnosisCode;
use Illuminate\Database\Seeder;

/**
 * A representative starter set of common ICD-10 codes — not a full ICD-10
 * import (the complete registry has tens of thousands of codes and isn't
 * available in this project). Gives the diagnosis-code search something
 * real to query against; a production deployment would load the full
 * official ICD-10-CM dataset the same way, through this table.
 */
class DiagnosisCodeSeeder extends Seeder
{
    /**
     * @var list<array{code: string, description: string, category: string}>
     */
    protected array $codes = [
        // Infectious diseases
        ['code' => 'A00.9', 'description' => 'Cholera, unspecified', 'category' => 'Infectious diseases'],
        ['code' => 'A09', 'description' => 'Infectious gastroenteritis and colitis, unspecified', 'category' => 'Infectious diseases'],
        ['code' => 'A15.9', 'description' => 'Respiratory tuberculosis, unspecified', 'category' => 'Infectious diseases'],
        ['code' => 'A41.9', 'description' => 'Sepsis, unspecified organism', 'category' => 'Infectious diseases'],
        ['code' => 'B01.9', 'description' => 'Varicella without complication', 'category' => 'Infectious diseases'],
        ['code' => 'B05.9', 'description' => 'Measles without complication', 'category' => 'Infectious diseases'],
        ['code' => 'B50.9', 'description' => 'Plasmodium falciparum malaria, unspecified', 'category' => 'Infectious diseases'],
        ['code' => 'B51.9', 'description' => 'Plasmodium vivax malaria without complication', 'category' => 'Infectious diseases'],
        ['code' => 'B54', 'description' => 'Unspecified malaria', 'category' => 'Infectious diseases'],
        ['code' => 'B99.9', 'description' => 'Unspecified infectious disease', 'category' => 'Infectious diseases'],

        // Respiratory
        ['code' => 'J00', 'description' => 'Acute nasopharyngitis (common cold)', 'category' => 'Respiratory'],
        ['code' => 'J02.9', 'description' => 'Acute pharyngitis, unspecified', 'category' => 'Respiratory'],
        ['code' => 'J03.9', 'description' => 'Acute tonsillitis, unspecified', 'category' => 'Respiratory'],
        ['code' => 'J06.9', 'description' => 'Acute upper respiratory infection, unspecified', 'category' => 'Respiratory'],
        ['code' => 'J18.9', 'description' => 'Pneumonia, unspecified organism', 'category' => 'Respiratory'],
        ['code' => 'J20.9', 'description' => 'Acute bronchitis, unspecified', 'category' => 'Respiratory'],
        ['code' => 'J45.909', 'description' => 'Unspecified asthma, uncomplicated', 'category' => 'Respiratory'],
        ['code' => 'J44.9', 'description' => 'Chronic obstructive pulmonary disease, unspecified', 'category' => 'Respiratory'],

        // Digestive
        ['code' => 'K21.9', 'description' => 'Gastro-esophageal reflux disease without esophagitis', 'category' => 'Digestive'],
        ['code' => 'K29.70', 'description' => 'Gastritis, unspecified, without bleeding', 'category' => 'Digestive'],
        ['code' => 'K35.80', 'description' => 'Unspecified acute appendicitis', 'category' => 'Digestive'],
        ['code' => 'K52.9', 'description' => 'Noninfective gastroenteritis and colitis, unspecified', 'category' => 'Digestive'],
        ['code' => 'K59.00', 'description' => 'Constipation, unspecified', 'category' => 'Digestive'],
        ['code' => 'K92.2', 'description' => 'Gastrointestinal hemorrhage, unspecified', 'category' => 'Digestive'],

        // Circulatory
        ['code' => 'I10', 'description' => 'Essential (primary) hypertension', 'category' => 'Circulatory'],
        ['code' => 'I20.9', 'description' => 'Angina pectoris, unspecified', 'category' => 'Circulatory'],
        ['code' => 'I21.9', 'description' => 'Acute myocardial infarction, unspecified', 'category' => 'Circulatory'],
        ['code' => 'I50.9', 'description' => 'Heart failure, unspecified', 'category' => 'Circulatory'],
        ['code' => 'I63.9', 'description' => 'Cerebral infarction, unspecified', 'category' => 'Circulatory'],
        ['code' => 'I84.9', 'description' => 'Hemorrhoids, unspecified', 'category' => 'Circulatory'],

        // Endocrine
        ['code' => 'E10.9', 'description' => 'Type 1 diabetes mellitus without complications', 'category' => 'Endocrine'],
        ['code' => 'E11.9', 'description' => 'Type 2 diabetes mellitus without complications', 'category' => 'Endocrine'],
        ['code' => 'E03.9', 'description' => 'Hypothyroidism, unspecified', 'category' => 'Endocrine'],
        ['code' => 'E66.9', 'description' => 'Obesity, unspecified', 'category' => 'Endocrine'],
        ['code' => 'E86.0', 'description' => 'Dehydration', 'category' => 'Endocrine'],

        // Musculoskeletal
        ['code' => 'M25.50', 'description' => 'Pain in unspecified joint', 'category' => 'Musculoskeletal'],
        ['code' => 'M54.5', 'description' => 'Low back pain', 'category' => 'Musculoskeletal'],
        ['code' => 'M79.1', 'description' => 'Myalgia', 'category' => 'Musculoskeletal'],
        ['code' => 'M79.3', 'description' => 'Panniculitis, unspecified', 'category' => 'Musculoskeletal'],

        // Injury
        ['code' => 'S00.90', 'description' => 'Unspecified superficial injury of head', 'category' => 'Injury'],
        ['code' => 'S09.90', 'description' => 'Unspecified injury of head', 'category' => 'Injury'],
        ['code' => 'S52.90', 'description' => 'Unspecified fracture of forearm', 'category' => 'Injury'],
        ['code' => 'S61.409', 'description' => 'Unspecified open wound of hand', 'category' => 'Injury'],
        ['code' => 'T14.90', 'description' => 'Injury, unspecified', 'category' => 'Injury'],
        ['code' => 'T78.40', 'description' => 'Allergy, unspecified', 'category' => 'Injury'],

        // Symptoms and signs
        ['code' => 'R05', 'description' => 'Cough', 'category' => 'Symptoms and signs'],
        ['code' => 'R06.02', 'description' => 'Shortness of breath', 'category' => 'Symptoms and signs'],
        ['code' => 'R10.9', 'description' => 'Unspecified abdominal pain', 'category' => 'Symptoms and signs'],
        ['code' => 'R11.10', 'description' => 'Vomiting, unspecified', 'category' => 'Symptoms and signs'],
        ['code' => 'R50.9', 'description' => 'Fever, unspecified', 'category' => 'Symptoms and signs'],
        ['code' => 'R51.9', 'description' => 'Headache, unspecified', 'category' => 'Symptoms and signs'],
        ['code' => 'R53.83', 'description' => 'Other fatigue', 'category' => 'Symptoms and signs'],
        ['code' => 'R42', 'description' => 'Dizziness and giddiness', 'category' => 'Symptoms and signs'],
    ];

    /**
     * Seed the diagnosis-code catalog. Idempotent via updateOrCreate() so it
     * can be run repeatedly without creating duplicates.
     */
    public function run(): void
    {
        foreach ($this->codes as $entry) {
            DiagnosisCode::query()->updateOrCreate(
                ['coding_system' => DiagnosisCode::CODING_SYSTEM_ICD10, 'code' => $entry['code']],
                ['description' => $entry['description'], 'category' => $entry['category'], 'status' => DiagnosisCode::STATUS_ACTIVE],
            );
        }
    }
}
