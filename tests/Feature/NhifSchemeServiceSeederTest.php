<?php

use App\Models\Service;
use App\Models\User;
use Database\Seeders\NhifSchemeServiceSeeder;
use Database\Seeders\ServiceCatalogSeeder;

function nhifSchemeServiceFixture(): string
{
    $sql = <<<'SQL'
CREATE TABLE `schemes_services` (
  `id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schemes_services` (`id`, `item_code`, `item_name`, `item_type_id`, `type_name`, `scheme_id`, `dosage`, `strength`, `start_date`, `end_date`, `created_by`, `last_modified_by`, `created_at`, `updated_at`) VALUES
(3, 10001, 'General Practitioner Consultation fee (MD/MBBS/DDS)', 1, 'Registration/Consultation Charges', 1001, NULL, NULL, NULL, NULL, NULL, NULL, '2026-06-16 06:41:14', '2026-06-16 06:41:14'),
(1829, 10001, 'General Practitioner Consultation fee (MD/MBBS/DDS)', 1, 'Registration/Consultation Charges', 1002, NULL, NULL, NULL, NULL, NULL, NULL, '2026-06-16 07:10:29', '2026-06-16 07:10:29'),
(9, 11001, 'Lidocaine', 3, 'Medicine and Consumables', 1001, 'Tube', 'Gel: 2%,5%', NULL, NULL, NULL, NULL, '2026-06-16 06:41:14', '2026-06-16 06:41:14'),
(5001, 50001, 'A&B Scan (Eye)', 5, 'Diagnostic Examinations', 1001, NULL, NULL, NULL, NULL, NULL, NULL, '2026-06-16 06:41:14', '2026-06-16 06:41:14'),
(6001, 60001, 'Ward Admission Fee', 2, 'Inpatient Charges', 1001, NULL, NULL, NULL, NULL, NULL, NULL, '2026-06-16 06:41:14', '2026-06-16 06:41:14'),
(7001, 70001, 'Miscellaneous Charge', 7, 'Other Charges', 1001, NULL, NULL, NULL, NULL, NULL, NULL, '2026-06-16 06:41:14', '2026-06-16 06:41:14');
SQL;

    $path = storage_path('framework/testing/nhif_scheme_service_fixture.sql');

    file_put_contents($path, $sql);

    return $path;
}

function seedNhifSchemeServices(string $path): void
{
    $seeder = new NhifSchemeServiceSeeder;
    $seeder->path = $path;
    $seeder->setContainer(app());
    app()->call([$seeder, 'run']);
}

test('seeds one service per distinct item code with mapped category and description', function () {
    User::factory()->create(['email' => 'test@example.com']);
    $path = nhifSchemeServiceFixture();

    seedNhifSchemeServices($path);

    expect(Service::count())->toBe(5);

    $consultation = Service::query()->where('code', '10001')->sole();
    expect($consultation->name)->toBe('General Practitioner Consultation fee (MD/MBBS/DDS)')
        ->and($consultation->category)->toBe('consultation')
        ->and($consultation->description)->toBe('NHIF item type: Registration/Consultation Charges')
        ->and($consultation->serviceable_type)->toBeNull()
        ->and($consultation->serviceable_id)->toBeNull()
        ->and($consultation->is_billable)->toBeTrue()
        ->and($consultation->status)->toBe(Service::STATUS_ACTIVE);

    $medicine = Service::query()->where('code', '11001')->sole();
    expect($medicine->category)->toBe('pharmacy')
        ->and($medicine->description)->toBe('Dosage: Tube; Strength: Gel: 2%,5%');

    $diagnostic = Service::query()->where('code', '50001')->sole();
    expect($diagnostic->category)->toBe('procedure');

    $inpatient = Service::query()->where('code', '60001')->sole();
    expect($inpatient->category)->toBe('inpatient');

    $other = Service::query()->where('code', '70001')->sole();
    expect($other->category)->toBe('other');

    unlink($path);
});

test('re-running the seeder is idempotent', function () {
    User::factory()->create(['email' => 'test@example.com']);
    $path = nhifSchemeServiceFixture();

    seedNhifSchemeServices($path);
    seedNhifSchemeServices($path);

    expect(Service::count())->toBe(5);

    unlink($path);
});

test('does not touch existing ServiceCatalogSeeder rows', function () {
    User::factory()->create(['email' => 'test@example.com']);
    $this->seed(ServiceCatalogSeeder::class);

    $existingCount = Service::count();
    expect($existingCount)->toBeGreaterThan(0);

    $path = nhifSchemeServiceFixture();
    seedNhifSchemeServices($path);

    expect(Service::where('code', 'CONSULT-GP')->exists())->toBeTrue();
    expect(Service::count())->toBe($existingCount + 5);

    unlink($path);
});
