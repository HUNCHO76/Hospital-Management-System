<?php

namespace Database\Seeders;

use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A representative starter laboratory test catalog (Phase 4, Step 29A) — not
 * a full national/LOINC test menu (which would run into the thousands and
 * isn't available in this project), the same "representative starter set"
 * precedent MedicationSeeder/DiagnosisCodeSeeder already established for
 * their own catalogs. Populates the single authoritative catalog table
 * `laboratory_tests` (Step 29) plus its `laboratory_test_categories` lookup
 * — no second catalog table exists or is created here; every column
 * populated below already exists on that table.
 *
 * The catalog has no panel/multi-component structure (confirmed by
 * re-auditing Steps 29, 33-35 — `laboratory_tests` is flat, one row per
 * orderable/resultable test, with no `lab_test_items`/analyte-grouping
 * table anywhere in this schema). A composite test like "Full Blood Count"
 * is therefore seeded as its own individual constituent analytes
 * (Hemoglobin, WBC, RBC, Platelets, MCV, MCH, MCHC, PCV, ESR) rather than as
 * a single "FBC" catalog row with an invented PANEL result type the model
 * does not support — a doctor already orders several catalog tests
 * together in one LaboratoryOrder (Step 30), so no standalone "FBC" code is
 * needed for that grouping to work. A few near-duplicate items from the
 * spec's own illustrative list are deliberately consolidated into one
 * catalog entry rather than seeded twice (documented per group below).
 */
class LaboratoryTestSeeder extends Seeder
{
    /**
     * Laboratory test categories (spec §25) — code => name. Reuses
     * `laboratory_test_categories` (Step 29); never duplicated if a
     * category with this code already exists.
     *
     * @var array<string, string>
     */
    protected array $categories = [
        'HEMATOLOGY' => 'Hematology',
        'CHEMISTRY' => 'Clinical Chemistry',
        'MICROBIOLOGY' => 'Microbiology',
        'PARASITOLOGY' => 'Parasitology',
        'SEROLOGY' => 'Serology',
        'IMMUNOLOGY' => 'Immunology',
        'BLOOD_BANK' => 'Blood Bank',
        'URINALYSIS' => 'Urinalysis',
        'STOOL' => 'Stool Examination',
    ];

    /**
     * The catalog itself. `specimen_type`/`specimen_container` values must
     * be one of `LaboratoryTest::SPECIMEN_TYPES`/`SPECIMEN_CONTAINERS`;
     * `result_type` one of `LaboratoryTest::RESULT_TYPES` — all already
     * validated by `StoreLaboratoryTestRequest` when a human edits a row
     * through the UI, and mirrored here so seeded rows are never invalid
     * per the catalog's own rules.
     *
     * @var list<array{code: string, name: string, category: string, specimen_type: string, specimen_container: string, result_type: string, unit?: string, reference_range?: string, result_options?: list<string>, turnaround_time_minutes: int, description: string}>
     */
    protected array $tests = [
        // Hematology — the individual constituent analytes a "Full Blood
        // Count" order groups together (see class docblock).
        ['code' => 'HB', 'name' => 'Hemoglobin', 'category' => 'HEMATOLOGY', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube', 'result_type' => 'numeric', 'unit' => 'g/dL', 'reference_range' => '13.0-17.0 (male), 12.0-15.5 (female)', 'turnaround_time_minutes' => 60, 'description' => 'Concentration of hemoglobin in whole blood.'],
        ['code' => 'PCV', 'name' => 'Packed Cell Volume (Hematocrit)', 'category' => 'HEMATOLOGY', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube', 'result_type' => 'numeric', 'unit' => '%', 'reference_range' => '40-52 (male), 36-46 (female)', 'turnaround_time_minutes' => 60, 'description' => 'Proportion of blood volume occupied by red blood cells.'],
        ['code' => 'WBC', 'name' => 'White Blood Cell Count', 'category' => 'HEMATOLOGY', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube', 'result_type' => 'numeric', 'unit' => '10^9/L', 'reference_range' => '4.0-11.0', 'turnaround_time_minutes' => 60, 'description' => 'Total white blood cell count.'],
        ['code' => 'RBC', 'name' => 'Red Blood Cell Count', 'category' => 'HEMATOLOGY', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube', 'result_type' => 'numeric', 'unit' => '10^12/L', 'reference_range' => '4.5-5.9 (male), 4.0-5.2 (female)', 'turnaround_time_minutes' => 60, 'description' => 'Total red blood cell count.'],
        ['code' => 'PLT', 'name' => 'Platelet Count', 'category' => 'HEMATOLOGY', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube', 'result_type' => 'numeric', 'unit' => '10^9/L', 'reference_range' => '150-450', 'turnaround_time_minutes' => 60, 'description' => 'Total platelet count.'],
        ['code' => 'MCV', 'name' => 'Mean Cell Volume', 'category' => 'HEMATOLOGY', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube', 'result_type' => 'numeric', 'unit' => 'fL', 'reference_range' => '80-100', 'turnaround_time_minutes' => 60, 'description' => 'Average volume of red blood cells.'],
        ['code' => 'MCH', 'name' => 'Mean Cell Hemoglobin', 'category' => 'HEMATOLOGY', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube', 'result_type' => 'numeric', 'unit' => 'pg', 'reference_range' => '27-33', 'turnaround_time_minutes' => 60, 'description' => 'Average mass of hemoglobin per red blood cell.'],
        ['code' => 'MCHC', 'name' => 'Mean Cell Hemoglobin Concentration', 'category' => 'HEMATOLOGY', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube', 'result_type' => 'numeric', 'unit' => 'g/dL', 'reference_range' => '32-36', 'turnaround_time_minutes' => 60, 'description' => 'Average concentration of hemoglobin within red blood cells.'],
        ['code' => 'ESR', 'name' => 'Erythrocyte Sedimentation Rate', 'category' => 'HEMATOLOGY', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube', 'result_type' => 'numeric', 'unit' => 'mm/hr', 'reference_range' => '0-20 (male), 0-30 (female)', 'turnaround_time_minutes' => 90, 'description' => 'Rate at which red blood cells settle, a non-specific marker of inflammation.'],
        ['code' => 'PBF', 'name' => 'Peripheral Blood Film', 'category' => 'HEMATOLOGY', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube', 'result_type' => 'text', 'turnaround_time_minutes' => 120, 'description' => 'Microscopic examination of a stained peripheral blood film.'],

        // Clinical chemistry
        ['code' => 'RBG', 'name' => 'Random Blood Glucose', 'category' => 'CHEMISTRY', 'specimen_type' => 'plasma', 'specimen_container' => 'fluoride_oxalate_tube', 'result_type' => 'numeric', 'unit' => 'mmol/L', 'reference_range' => '4.0-11.1 (random)', 'turnaround_time_minutes' => 45, 'description' => 'Blood glucose measured without regard to time of last meal.'],
        ['code' => 'FBG', 'name' => 'Fasting Blood Glucose', 'category' => 'CHEMISTRY', 'specimen_type' => 'plasma', 'specimen_container' => 'fluoride_oxalate_tube', 'result_type' => 'numeric', 'unit' => 'mmol/L', 'reference_range' => '3.9-5.6 (fasting)', 'turnaround_time_minutes' => 45, 'description' => 'Blood glucose measured after an overnight fast.'],
        ['code' => 'HBA1C', 'name' => 'Glycated Hemoglobin (HbA1c)', 'category' => 'CHEMISTRY', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube', 'result_type' => 'numeric', 'unit' => '%', 'reference_range' => '4.0-5.6 (normal)', 'turnaround_time_minutes' => 120, 'description' => 'Average blood glucose control over the preceding 2-3 months.'],
        ['code' => 'UREA', 'name' => 'Urea', 'category' => 'CHEMISTRY', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube', 'result_type' => 'numeric', 'unit' => 'mmol/L', 'reference_range' => '2.5-7.1', 'turnaround_time_minutes' => 60, 'description' => 'Marker of renal (kidney) function.'],
        ['code' => 'CREAT', 'name' => 'Creatinine', 'category' => 'CHEMISTRY', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube', 'result_type' => 'numeric', 'unit' => 'µmol/L', 'reference_range' => '62-106 (male), 44-80 (female)', 'turnaround_time_minutes' => 60, 'description' => 'Marker of renal (kidney) function.'],
        ['code' => 'NA', 'name' => 'Sodium', 'category' => 'CHEMISTRY', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube', 'result_type' => 'numeric', 'unit' => 'mmol/L', 'reference_range' => '135-145', 'turnaround_time_minutes' => 60, 'description' => 'Serum electrolyte.'],
        ['code' => 'K', 'name' => 'Potassium', 'category' => 'CHEMISTRY', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube', 'result_type' => 'numeric', 'unit' => 'mmol/L', 'reference_range' => '3.5-5.1', 'turnaround_time_minutes' => 60, 'description' => 'Serum electrolyte.'],
        ['code' => 'CL', 'name' => 'Chloride', 'category' => 'CHEMISTRY', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube', 'result_type' => 'numeric', 'unit' => 'mmol/L', 'reference_range' => '98-107', 'turnaround_time_minutes' => 60, 'description' => 'Serum electrolyte.'],
        ['code' => 'CA', 'name' => 'Calcium', 'category' => 'CHEMISTRY', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube', 'result_type' => 'numeric', 'unit' => 'mmol/L', 'reference_range' => '2.1-2.6', 'turnaround_time_minutes' => 60, 'description' => 'Serum total calcium.'],
        ['code' => 'TP', 'name' => 'Total Protein', 'category' => 'CHEMISTRY', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube', 'result_type' => 'numeric', 'unit' => 'g/L', 'reference_range' => '60-80', 'turnaround_time_minutes' => 60, 'description' => 'Total serum protein.'],
        ['code' => 'ALB', 'name' => 'Albumin', 'category' => 'CHEMISTRY', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube', 'result_type' => 'numeric', 'unit' => 'g/L', 'reference_range' => '35-50', 'turnaround_time_minutes' => 60, 'description' => 'Serum albumin, a marker of liver/nutritional status.'],
        ['code' => 'TBIL', 'name' => 'Total Bilirubin', 'category' => 'CHEMISTRY', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube', 'result_type' => 'numeric', 'unit' => 'µmol/L', 'reference_range' => '3.4-20.5', 'turnaround_time_minutes' => 60, 'description' => 'Marker of liver function/hemolysis.'],
        ['code' => 'DBIL', 'name' => 'Direct Bilirubin', 'category' => 'CHEMISTRY', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube', 'result_type' => 'numeric', 'unit' => 'µmol/L', 'reference_range' => '0-6.8', 'turnaround_time_minutes' => 60, 'description' => 'Conjugated fraction of bilirubin.'],
        ['code' => 'ALT', 'name' => 'Alanine Aminotransferase (ALT)', 'category' => 'CHEMISTRY', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube', 'result_type' => 'numeric', 'unit' => 'U/L', 'reference_range' => '7-56', 'turnaround_time_minutes' => 60, 'description' => 'Liver enzyme, marker of hepatocellular injury.'],
        ['code' => 'AST', 'name' => 'Aspartate Aminotransferase (AST)', 'category' => 'CHEMISTRY', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube', 'result_type' => 'numeric', 'unit' => 'U/L', 'reference_range' => '10-40', 'turnaround_time_minutes' => 60, 'description' => 'Liver enzyme, marker of hepatocellular injury.'],
        ['code' => 'ALP', 'name' => 'Alkaline Phosphatase (ALP)', 'category' => 'CHEMISTRY', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube', 'result_type' => 'numeric', 'unit' => 'U/L', 'reference_range' => '44-147', 'turnaround_time_minutes' => 60, 'description' => 'Liver/bone enzyme.'],
        ['code' => 'GGT', 'name' => 'Gamma-Glutamyl Transferase (GGT)', 'category' => 'CHEMISTRY', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube', 'result_type' => 'numeric', 'unit' => 'U/L', 'reference_range' => '8-61', 'turnaround_time_minutes' => 60, 'description' => 'Liver enzyme, marker of biliary/hepatocellular injury.'],
        ['code' => 'CHOL', 'name' => 'Total Cholesterol', 'category' => 'CHEMISTRY', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube', 'result_type' => 'numeric', 'unit' => 'mmol/L', 'reference_range' => '<5.2 desirable', 'turnaround_time_minutes' => 60, 'description' => 'Total serum cholesterol.'],
        ['code' => 'HDL', 'name' => 'HDL Cholesterol', 'category' => 'CHEMISTRY', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube', 'result_type' => 'numeric', 'unit' => 'mmol/L', 'reference_range' => '>1.0', 'turnaround_time_minutes' => 60, 'description' => 'High-density lipoprotein cholesterol.'],
        ['code' => 'LDL', 'name' => 'LDL Cholesterol', 'category' => 'CHEMISTRY', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube', 'result_type' => 'numeric', 'unit' => 'mmol/L', 'reference_range' => '<3.4', 'turnaround_time_minutes' => 60, 'description' => 'Low-density lipoprotein cholesterol.'],
        ['code' => 'TG', 'name' => 'Triglycerides', 'category' => 'CHEMISTRY', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube', 'result_type' => 'numeric', 'unit' => 'mmol/L', 'reference_range' => '<1.7', 'turnaround_time_minutes' => 60, 'description' => 'Serum triglycerides.'],

        // Microbiology / parasitology
        ['code' => 'MALARIA', 'name' => 'Malaria Parasite Examination', 'category' => 'PARASITOLOGY', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube', 'result_type' => 'positive_negative', 'result_options' => ['positive', 'negative'], 'turnaround_time_minutes' => 30, 'description' => 'Microscopic examination for malaria parasites.'],
        ['code' => 'BLD_CULT', 'name' => 'Blood Culture', 'category' => 'MICROBIOLOGY', 'specimen_type' => 'whole_blood', 'specimen_container' => 'sterile_container', 'result_type' => 'text', 'turnaround_time_minutes' => 4320, 'description' => 'Culture and sensitivity for bloodstream infection (up to 72 hours incubation).'],
        ['code' => 'URINE_CULT', 'name' => 'Urine Culture', 'category' => 'MICROBIOLOGY', 'specimen_type' => 'urine', 'specimen_container' => 'sterile_container', 'result_type' => 'text', 'turnaround_time_minutes' => 2880, 'description' => 'Culture and sensitivity for urinary tract infection.'],
        ['code' => 'STOOL_CULT', 'name' => 'Stool Culture', 'category' => 'MICROBIOLOGY', 'specimen_type' => 'stool', 'specimen_container' => 'sterile_container', 'result_type' => 'text', 'turnaround_time_minutes' => 2880, 'description' => 'Culture for enteric bacterial pathogens.'],
        ['code' => 'SPUTUM_EXAM', 'name' => 'Sputum Examination', 'category' => 'MICROBIOLOGY', 'specimen_type' => 'sputum', 'specimen_container' => 'sterile_container', 'result_type' => 'text', 'turnaround_time_minutes' => 120, 'description' => 'Macroscopic and microscopic examination of sputum.'],
        ['code' => 'SPUTUM_AFB', 'name' => 'Sputum AFB Examination', 'category' => 'MICROBIOLOGY', 'specimen_type' => 'sputum', 'specimen_container' => 'sterile_container', 'result_type' => 'positive_negative', 'result_options' => ['positive', 'negative'], 'turnaround_time_minutes' => 180, 'description' => 'Acid-fast bacilli smear microscopy for tuberculosis screening.'],
        ['code' => 'GRAM_STAIN', 'name' => 'Gram Stain', 'category' => 'MICROBIOLOGY', 'specimen_type' => 'swab', 'specimen_container' => 'swab_transport_medium', 'result_type' => 'text', 'turnaround_time_minutes' => 60, 'description' => 'Gram stain microscopy of a swab specimen.'],

        // Serology / immunology
        ['code' => 'HIV', 'name' => 'HIV Test', 'category' => 'SEROLOGY', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube', 'result_type' => 'positive_negative', 'result_options' => ['positive', 'negative'], 'turnaround_time_minutes' => 45, 'description' => 'HIV antibody/antigen screening test.'],
        ['code' => 'HBSAG', 'name' => 'Hepatitis B Surface Antigen (HBsAg)', 'category' => 'SEROLOGY', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube', 'result_type' => 'positive_negative', 'result_options' => ['positive', 'negative'], 'turnaround_time_minutes' => 45, 'description' => 'Screening test for active Hepatitis B infection.'],
        ['code' => 'HCV', 'name' => 'Hepatitis C Antibody', 'category' => 'SEROLOGY', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube', 'result_type' => 'positive_negative', 'result_options' => ['positive', 'negative'], 'turnaround_time_minutes' => 45, 'description' => 'Screening test for Hepatitis C exposure.'],
        ['code' => 'VDRL', 'name' => 'VDRL (Syphilis Screening)', 'category' => 'SEROLOGY', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube', 'result_type' => 'qualitative', 'result_options' => ['reactive', 'non_reactive'], 'turnaround_time_minutes' => 60, 'description' => 'Venereal Disease Research Laboratory test for syphilis.'],
        ['code' => 'RPR', 'name' => 'RPR (Syphilis Screening)', 'category' => 'SEROLOGY', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube', 'result_type' => 'qualitative', 'result_options' => ['reactive', 'non_reactive'], 'turnaround_time_minutes' => 60, 'description' => 'Rapid Plasma Reagin test for syphilis.'],
        ['code' => 'CRP', 'name' => 'C-Reactive Protein (CRP)', 'category' => 'IMMUNOLOGY', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube', 'result_type' => 'numeric', 'unit' => 'mg/L', 'reference_range' => '<10', 'turnaround_time_minutes' => 60, 'description' => 'Acute-phase marker of inflammation.'],
        ['code' => 'RF', 'name' => 'Rheumatoid Factor', 'category' => 'IMMUNOLOGY', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube', 'result_type' => 'positive_negative', 'result_options' => ['positive', 'negative'], 'turnaround_time_minutes' => 90, 'description' => 'Screening test for rheumatoid arthritis.'],

        // Blood bank / immunohematology
        ['code' => 'ABO', 'name' => 'ABO Blood Group', 'category' => 'BLOOD_BANK', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube', 'result_type' => 'enum', 'result_options' => ['A', 'B', 'AB', 'O'], 'turnaround_time_minutes' => 30, 'description' => 'ABO blood group typing.'],
        ['code' => 'RH', 'name' => 'Rhesus Factor', 'category' => 'BLOOD_BANK', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube', 'result_type' => 'positive_negative', 'result_options' => ['positive', 'negative'], 'turnaround_time_minutes' => 30, 'description' => 'Rhesus (D) factor typing.'],
        ['code' => 'BLOODGRP', 'name' => 'Blood Group and Rhesus Factor', 'category' => 'BLOOD_BANK', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube', 'result_type' => 'enum', 'result_options' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], 'turnaround_time_minutes' => 30, 'description' => 'Combined ABO group and Rhesus factor typing.'],
        ['code' => 'XMATCH', 'name' => 'Crossmatch', 'category' => 'BLOOD_BANK', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube', 'result_type' => 'text', 'turnaround_time_minutes' => 60, 'description' => 'Compatibility testing between donor and recipient blood prior to transfusion.'],

        // Urinalysis — consolidates the spec's "Urinalysis"/"Urine Routine
        // Examination"/"Urine Microscopy" into one report, matching how a
        // urinalysis is actually issued as a single combined result in
        // practice rather than three separate near-identical catalog codes.
        ['code' => 'URINALYSIS', 'name' => 'Urinalysis', 'category' => 'URINALYSIS', 'specimen_type' => 'urine', 'specimen_container' => 'sterile_container', 'result_type' => 'text', 'turnaround_time_minutes' => 45, 'description' => 'Routine urinalysis: macroscopic, chemical (dipstick), and microscopic examination.'],
        ['code' => 'PREG_URINE', 'name' => 'Urine Pregnancy Test (β-hCG)', 'category' => 'URINALYSIS', 'specimen_type' => 'urine', 'specimen_container' => 'sterile_container', 'result_type' => 'positive_negative', 'result_options' => ['positive', 'negative'], 'turnaround_time_minutes' => 20, 'description' => 'Qualitative urine beta-hCG pregnancy screening test.'],

        // Stool examination — consolidates "Stool Routine Examination" and
        // "Stool Microscopy" into one report for the same reason as above.
        ['code' => 'STOOL_EXAM', 'name' => 'Stool Routine Examination', 'category' => 'STOOL', 'specimen_type' => 'stool', 'specimen_container' => 'sterile_container', 'result_type' => 'text', 'turnaround_time_minutes' => 60, 'description' => 'Macroscopic and microscopic stool examination (ova, cysts, parasites).'],
        ['code' => 'STOOL_OB', 'name' => 'Occult Blood in Stool', 'category' => 'STOOL', 'specimen_type' => 'stool', 'specimen_container' => 'sterile_container', 'result_type' => 'positive_negative', 'result_options' => ['positive', 'negative'], 'turnaround_time_minutes' => 45, 'description' => 'Screening test for hidden (occult) blood in stool.'],

        // Multi-parameter panel tests — each produces a single ordered result
        // with per-analyte parameter rows rather than a flat scalar value.
        ['code' => 'FBC', 'name' => 'Full Blood Count', 'category' => 'HEMATOLOGY', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube', 'result_type' => 'numeric', 'turnaround_time_minutes' => 60, 'description' => 'Complete blood count including WBC, RBC, HGB, HCT, MCV, MCH, MCHC, RDW-CV, and PLT.'],
        ['code' => 'LFT', 'name' => 'Liver Function Tests', 'category' => 'CHEMISTRY', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube', 'result_type' => 'numeric', 'turnaround_time_minutes' => 90, 'description' => 'Panel: ALT, AST, ALP, Total Bilirubin, Direct Bilirubin, Total Protein, Albumin, GGT.'],
        ['code' => 'RFT', 'name' => 'Renal Function Tests', 'category' => 'CHEMISTRY', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube', 'result_type' => 'numeric', 'turnaround_time_minutes' => 90, 'description' => 'Panel: Urea, Creatinine, Sodium, Potassium, Chloride.'],
    ];

    /**
     * Seed the laboratory test catalog. Idempotent via updateOrCreate()
     * keyed by each table's own unique `code` column (never count()+1 or
     * MAX(id)+1) — safe to run repeatedly without creating duplicates, and
     * safe against any existing operational data (laboratory orders,
     * samples, processing, results, releases) since this seeder only ever
     * writes to `laboratory_test_categories`/`laboratory_tests` and never
     * truncates or deletes.
     */
    public function run(): void
    {
        DB::transaction(function () {
            $categoryIds = $this->seedCategories();
            $this->seedTests($categoryIds);
        });
    }

    /**
     * @return array<string, int> category code => id
     */
    protected function seedCategories(): array
    {
        $ids = [];

        foreach ($this->categories as $code => $name) {
            $category = LaboratoryTestCategory::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'status' => LaboratoryTestCategory::STATUS_ACTIVE],
            );

            $ids[$code] = $category->id;
        }

        return $ids;
    }

    /**
     * @param  array<string, int>  $categoryIds
     */
    protected function seedTests(array $categoryIds): void
    {
        foreach ($this->tests as $test) {
            // `code` is immutable once a test has been referenced by a
            // laboratory order (LaboratoryTestService::update()'s own
            // rule) — updateOrCreate() here never touches `code` itself on
            // an existing row, only the surrounding configuration fields,
            // so re-running this seeder against a hospital that has
            // already placed real orders remains completely safe.
            LaboratoryTest::query()->updateOrCreate(
                ['code' => $test['code']],
                [
                    'name' => $test['name'],
                    'short_name' => $test['code'],
                    'description' => $test['description'],
                    'category_id' => $categoryIds[$test['category']],
                    // department_id is deliberately left null: this catalog
                    // is shared, hospital-agnostic reference data (it has no
                    // hospital_id column at all), whereas a "Laboratory"
                    // department row only exists per-hospital — linking
                    // every seeded test to one specific hospital's
                    // department would misrepresent shared master data as
                    // belonging to only that hospital.
                    'specimen_type' => $test['specimen_type'],
                    'specimen_container' => $test['specimen_container'],
                    'result_type' => $test['result_type'],
                    'unit' => $test['unit'] ?? null,
                    'reference_range' => $test['reference_range'] ?? null,
                    'result_options' => $test['result_options'] ?? null,
                    'turnaround_time_minutes' => $test['turnaround_time_minutes'],
                    'default_priority' => 'routine',
                    'status' => LaboratoryTest::STATUS_ACTIVE,
                ],
            );
        }
    }
}
