<?php

namespace Database\Seeders;

use App\Models\RadiologyProcedure;
use Illuminate\Database\Seeder;

/**
 * A representative starter radiology procedure catalog (Phase 5, Step 37,
 * Part 20) — not a real hospital's actual equipment inventory (which this
 * project has no way to know), the same "representative starter set"
 * precedent MedicationSeeder/LaboratoryTestSeeder/Step 36's own seeder
 * already established. `updateOrCreate()` keyed on each procedure's unique
 * `code`, never `count()+1` or a random code, so running this seeder
 * repeatedly never creates duplicates or touches unrelated rows.
 *
 * Replaces Step 36's own 12-procedure starter list wholesale: those codes
 * (XRAY_CHEST, USS_ABDOMEN, etc.) never matched this step's own canonical
 * code scheme (XR-CHEST, US-ABD, ...) and — verified before this change —
 * were never referenced by any radiology_order_item, so correcting them
 * now is a safe seed-data fix, not a destructive change to committed
 * clinical data. `RadiologyProcedureService::update()`'s own code-lock
 * (mirroring LaboratoryTestService) is what actually protects a procedure
 * once a real order exists; this seeder never touches a referenced row's
 * code.
 */
class RadiologyProcedureSeeder extends Seeder
{
    /**
     * @var list<array{code: string, name: string, modality: string, body_sites: list<string>, laterality?: string, contrast_requirement?: string, contrast_type?: string, preparation_type?: string, preparation_instructions?: string, turnaround_time_minutes: int, description: string}>
     */
    protected array $procedures = [
        // X-Ray
        ['code' => 'XR-CHEST', 'name' => 'Chest X-Ray', 'modality' => 'x_ray', 'body_sites' => ['chest'], 'turnaround_time_minutes' => 30, 'description' => 'Plain radiograph of the chest.'],
        ['code' => 'XR-ABD', 'name' => 'Abdominal X-Ray', 'modality' => 'x_ray', 'body_sites' => ['abdomen'], 'turnaround_time_minutes' => 30, 'description' => 'Plain radiograph of the abdomen.'],
        ['code' => 'XR-PELVIS', 'name' => 'Pelvic X-Ray', 'modality' => 'x_ray', 'body_sites' => ['pelvis'], 'turnaround_time_minutes' => 30, 'description' => 'Plain radiograph of the pelvis.'],
        ['code' => 'XR-CERVICAL-SPINE', 'name' => 'Cervical Spine X-Ray', 'modality' => 'x_ray', 'body_sites' => ['cervical_spine'], 'turnaround_time_minutes' => 30, 'description' => 'Plain radiograph of the cervical spine.'],
        ['code' => 'XR-THORACIC-SPINE', 'name' => 'Thoracic Spine X-Ray', 'modality' => 'x_ray', 'body_sites' => ['thoracic_spine'], 'turnaround_time_minutes' => 30, 'description' => 'Plain radiograph of the thoracic spine.'],
        ['code' => 'XR-LUMBAR-SPINE', 'name' => 'Lumbar Spine X-Ray', 'modality' => 'x_ray', 'body_sites' => ['lumbar_spine'], 'turnaround_time_minutes' => 30, 'description' => 'Plain radiograph of the lumbar spine.'],
        ['code' => 'XR-SKULL', 'name' => 'Skull X-Ray', 'modality' => 'x_ray', 'body_sites' => ['skull'], 'turnaround_time_minutes' => 30, 'description' => 'Plain radiograph of the skull.'],
        ['code' => 'XR-KNEE', 'name' => 'Knee X-Ray', 'modality' => 'x_ray', 'body_sites' => ['knee'], 'laterality' => 'bilateral', 'turnaround_time_minutes' => 30, 'description' => 'Plain radiograph of the knee.'],
        ['code' => 'XR-ANKLE', 'name' => 'Ankle X-Ray', 'modality' => 'x_ray', 'body_sites' => ['ankle'], 'laterality' => 'bilateral', 'turnaround_time_minutes' => 30, 'description' => 'Plain radiograph of the ankle.'],
        ['code' => 'XR-FOOT', 'name' => 'Foot X-Ray', 'modality' => 'x_ray', 'body_sites' => ['foot'], 'laterality' => 'bilateral', 'turnaround_time_minutes' => 30, 'description' => 'Plain radiograph of the foot.'],
        ['code' => 'XR-HAND', 'name' => 'Hand X-Ray', 'modality' => 'x_ray', 'body_sites' => ['hand'], 'laterality' => 'bilateral', 'turnaround_time_minutes' => 30, 'description' => 'Plain radiograph of the hand.'],
        ['code' => 'XR-WRIST', 'name' => 'Wrist X-Ray', 'modality' => 'x_ray', 'body_sites' => ['wrist'], 'laterality' => 'bilateral', 'turnaround_time_minutes' => 30, 'description' => 'Plain radiograph of the wrist.'],

        // Ultrasound
        ['code' => 'US-ABD', 'name' => 'Abdominal Ultrasound', 'modality' => 'ultrasound', 'body_sites' => ['abdomen'], 'preparation_type' => 'fasting', 'preparation_instructions' => 'Fasting for at least 6 hours may be required.', 'turnaround_time_minutes' => 45, 'description' => 'Ultrasound examination of the abdomen.'],
        ['code' => 'US-PELVIS', 'name' => 'Pelvic Ultrasound', 'modality' => 'ultrasound', 'body_sites' => ['pelvis'], 'preparation_type' => 'full_bladder', 'preparation_instructions' => 'A full bladder may be required — drink water and avoid urinating before the scan.', 'turnaround_time_minutes' => 45, 'description' => 'Ultrasound examination of the pelvis.'],
        ['code' => 'US-OBST', 'name' => 'Obstetric Ultrasound', 'modality' => 'ultrasound', 'body_sites' => ['uterus'], 'preparation_type' => 'full_bladder', 'preparation_instructions' => 'A full bladder may be required for early pregnancy scans.', 'turnaround_time_minutes' => 45, 'description' => 'Obstetric ultrasound examination.'],
        ['code' => 'US-RENAL', 'name' => 'Renal Ultrasound', 'modality' => 'ultrasound', 'body_sites' => ['kidney'], 'laterality' => 'bilateral', 'turnaround_time_minutes' => 45, 'description' => 'Ultrasound examination of the kidneys.'],
        ['code' => 'US-THYROID', 'name' => 'Thyroid Ultrasound', 'modality' => 'ultrasound', 'body_sites' => ['neck'], 'turnaround_time_minutes' => 45, 'description' => 'Ultrasound examination of the thyroid gland.'],
        ['code' => 'US-BREAST', 'name' => 'Breast Ultrasound', 'modality' => 'ultrasound', 'body_sites' => ['breast'], 'laterality' => 'bilateral', 'turnaround_time_minutes' => 45, 'description' => 'Ultrasound examination of the breast.'],
        ['code' => 'US-SCROTAL', 'name' => 'Scrotal Ultrasound', 'modality' => 'ultrasound', 'body_sites' => ['scrotum'], 'turnaround_time_minutes' => 45, 'description' => 'Ultrasound examination of the scrotum.'],
        ['code' => 'US-PROSTATE', 'name' => 'Prostate Ultrasound', 'modality' => 'ultrasound', 'body_sites' => ['prostate'], 'preparation_type' => 'full_bladder', 'preparation_instructions' => 'A full bladder may be required.', 'turnaround_time_minutes' => 45, 'description' => 'Ultrasound examination of the prostate.'],

        // CT
        ['code' => 'CT-BRAIN', 'name' => 'CT Brain', 'modality' => 'ct', 'body_sites' => ['brain'], 'contrast_requirement' => 'optional', 'contrast_type' => 'intravenous', 'turnaround_time_minutes' => 90, 'description' => 'Computed tomography of the brain.'],
        ['code' => 'CT-CHEST', 'name' => 'CT Chest', 'modality' => 'ct', 'body_sites' => ['chest'], 'contrast_requirement' => 'optional', 'contrast_type' => 'intravenous', 'turnaround_time_minutes' => 90, 'description' => 'Computed tomography of the chest.'],
        ['code' => 'CT-ABD', 'name' => 'CT Abdomen', 'modality' => 'ct', 'body_sites' => ['abdomen'], 'contrast_requirement' => 'optional', 'contrast_type' => 'oral', 'preparation_type' => 'contrast_preparation', 'preparation_instructions' => 'Oral contrast preparation may be required depending on protocol.', 'turnaround_time_minutes' => 90, 'description' => 'Computed tomography of the abdomen.'],
        ['code' => 'CT-PELVIS', 'name' => 'CT Pelvis', 'modality' => 'ct', 'body_sites' => ['pelvis'], 'contrast_requirement' => 'optional', 'preparation_type' => 'contrast_preparation', 'preparation_instructions' => 'Contrast preparation may be required depending on protocol.', 'turnaround_time_minutes' => 90, 'description' => 'Computed tomography of the pelvis.'],
        ['code' => 'CT-CHEST-ABD', 'name' => 'CT Chest-Abdomen', 'modality' => 'ct', 'body_sites' => ['chest', 'abdomen'], 'contrast_requirement' => 'optional', 'preparation_type' => 'contrast_preparation', 'preparation_instructions' => 'Contrast preparation may be required depending on protocol.', 'turnaround_time_minutes' => 120, 'description' => 'Combined computed tomography of the chest and abdomen.'],
        ['code' => 'CT-SPINE', 'name' => 'CT Spine', 'modality' => 'ct', 'body_sites' => ['spine'], 'turnaround_time_minutes' => 90, 'description' => 'Computed tomography of the spine.'],

        // MRI
        ['code' => 'MRI-BRAIN', 'name' => 'MRI Brain', 'modality' => 'mri', 'body_sites' => ['brain'], 'contrast_requirement' => 'optional', 'contrast_type' => 'intravenous', 'preparation_type' => 'mri_safety_screening', 'preparation_instructions' => 'Screen for contraindications and metallic implants before scanning.', 'turnaround_time_minutes' => 180, 'description' => 'Magnetic resonance imaging of the brain.'],
        ['code' => 'MRI-CERVICAL-SPINE', 'name' => 'MRI Cervical Spine', 'modality' => 'mri', 'body_sites' => ['cervical_spine'], 'preparation_type' => 'mri_safety_screening', 'preparation_instructions' => 'Screen for contraindications and metallic implants before scanning.', 'turnaround_time_minutes' => 180, 'description' => 'Magnetic resonance imaging of the cervical spine.'],
        ['code' => 'MRI-THORACIC-SPINE', 'name' => 'MRI Thoracic Spine', 'modality' => 'mri', 'body_sites' => ['thoracic_spine'], 'preparation_type' => 'mri_safety_screening', 'preparation_instructions' => 'Screen for contraindications and metallic implants before scanning.', 'turnaround_time_minutes' => 180, 'description' => 'Magnetic resonance imaging of the thoracic spine.'],
        ['code' => 'MRI-LUMBAR-SPINE', 'name' => 'MRI Lumbar Spine', 'modality' => 'mri', 'body_sites' => ['lumbar_spine'], 'preparation_type' => 'mri_safety_screening', 'preparation_instructions' => 'Screen for contraindications and metallic implants before scanning.', 'turnaround_time_minutes' => 180, 'description' => 'Magnetic resonance imaging of the lumbar spine.'],
        ['code' => 'MRI-KNEE', 'name' => 'MRI Knee', 'modality' => 'mri', 'body_sites' => ['knee'], 'laterality' => 'bilateral', 'preparation_type' => 'mri_safety_screening', 'preparation_instructions' => 'Screen for contraindications and metallic implants before scanning.', 'turnaround_time_minutes' => 180, 'description' => 'Magnetic resonance imaging of the knee.'],
        ['code' => 'MRI-SHOULDER', 'name' => 'MRI Shoulder', 'modality' => 'mri', 'body_sites' => ['shoulder'], 'laterality' => 'bilateral', 'preparation_type' => 'mri_safety_screening', 'preparation_instructions' => 'Screen for contraindications and metallic implants before scanning.', 'turnaround_time_minutes' => 180, 'description' => 'Magnetic resonance imaging of the shoulder.'],

        // Mammography
        ['code' => 'MG-BREAST', 'name' => 'Mammography Breast', 'modality' => 'mammography', 'body_sites' => ['breast'], 'laterality' => 'bilateral', 'turnaround_time_minutes' => 30, 'description' => 'Mammographic examination of the breast.'],
        ['code' => 'MG-SCREENING', 'name' => 'Mammography Screening', 'modality' => 'mammography', 'body_sites' => ['breast'], 'laterality' => 'bilateral', 'turnaround_time_minutes' => 30, 'description' => 'Routine screening mammography.'],
        ['code' => 'MG-DIAGNOSTIC', 'name' => 'Mammography Diagnostic', 'modality' => 'mammography', 'body_sites' => ['breast'], 'laterality' => 'bilateral', 'turnaround_time_minutes' => 45, 'description' => 'Diagnostic mammography for a specific clinical finding.'],

        // Other imaging
        ['code' => 'FL-GENERAL', 'name' => 'Fluoroscopy Study', 'modality' => 'fluoroscopy', 'body_sites' => ['other'], 'contrast_requirement' => 'optional', 'turnaround_time_minutes' => 60, 'description' => 'Real-time fluoroscopic imaging study.'],
    ];

    public function run(): void
    {
        // Correct Step 36's own placeholder codes before seeding the
        // canonical list — safe: verified above to have zero
        // radiology_order_item references.
        RadiologyProcedure::query()
            ->whereIn('code', ['XRAY_CHEST', 'XRAY_LIMB', 'XRAY_SPINE', 'USS_ABDOMEN', 'USS_OBSTETRIC', 'USS_PELVIC', 'CT_BRAIN', 'CT_ABDOMEN', 'MRI_BRAIN', 'MRI_SPINE', 'MAMMOGRAPHY', 'FLUOROSCOPY'])
            ->whereDoesntHave('orderItems')
            ->delete();

        foreach ($this->procedures as $index => $procedure) {
            RadiologyProcedure::query()->updateOrCreate(
                ['code' => $procedure['code']],
                [
                    'name' => $procedure['name'],
                    'modality' => $procedure['modality'],
                    'body_sites' => $procedure['body_sites'],
                    'laterality' => $procedure['laterality'] ?? RadiologyProcedure::LATERALITY_OPTIONS[0],
                    'contrast_requirement' => $procedure['contrast_requirement'] ?? RadiologyProcedure::CONTRAST_REQUIREMENTS[0],
                    'contrast_type' => $procedure['contrast_type'] ?? null,
                    'preparation_type' => $procedure['preparation_type'] ?? RadiologyProcedure::PREPARATION_TYPES[0],
                    'preparation_instructions' => $procedure['preparation_instructions'] ?? null,
                    'turnaround_time_minutes' => $procedure['turnaround_time_minutes'],
                    'sort_order' => ($index + 1) * 10,
                    'description' => $procedure['description'],
                    'status' => RadiologyProcedure::STATUS_ACTIVE,
                ],
            );
        }
    }
}
