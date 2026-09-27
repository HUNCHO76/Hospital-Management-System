<?php

use App\Models\NhifService;

function nhifCatalogDumpFixture(): string
{
    $sql = <<<'SQL'
CREATE TABLE `schemes_services` (
  `id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schemes_services` (`id`, `item_code`, `item_name`, `item_type_id`, `type_name`, `scheme_id`, `dosage`, `strength`, `start_date`, `end_date`, `created_by`, `last_modified_by`, `created_at`, `updated_at`) VALUES
(3, 10001, 'General Practitioner Consultation fee (MD/MBBS/DDS)', 1, 'Registration/Consultation Charges', 1001, NULL, NULL, NULL, NULL, NULL, NULL, '2026-06-16 06:41:14', '2026-06-16 06:41:14'),
(1829, 10001, 'General Practitioner Consultation fee (MD/MBBS/DDS)', 1, 'Registration/Consultation Charges', 1002, NULL, NULL, NULL, NULL, NULL, NULL, '2026-06-16 07:10:29', '2026-06-16 07:10:29'),
(9, 11001, 'Lidocaine', 3, 'Medicine and Consumables', 1001, 'Tube', 'Gel: 2%,5%', NULL, NULL, NULL, NULL, '2026-06-16 06:41:14', '2026-06-16 06:41:14'),
(5001, 50001, 'A&B Scan (Eye)', 5, 'Diagnostic Examinations', 1001, NULL, NULL, NULL, NULL, NULL, NULL, '2026-06-16 06:41:14', '2026-06-16 06:41:14');
SQL;

    $path = storage_path('framework/testing/nhif_catalog_fixture.sql');

    file_put_contents($path, $sql);

    return $path;
}

test('imports distinct item codes and dedupes scheme repetitions', function () {
    $path = nhifCatalogDumpFixture();

    $this->artisan('nhif:import-catalog', ['path' => $path])
        ->assertSuccessful();

    // 4 raw rows, 3 distinct item_codes (10001 repeats across two schemes).
    expect(NhifService::count())->toBe(3);

    $consultation = NhifService::query()->where('nhif_item_code', '10001')->sole();

    expect($consultation->nhif_item_name)->toBe('General Practitioner Consultation fee (MD/MBBS/DDS)')
        ->and($consultation->nhif_item_type_id)->toBe('1')
        ->and($consultation->nhif_item_type_name)->toBe('Registration/Consultation Charges')
        ->and($consultation->is_active)->toBeTrue()
        ->and($consultation->synced_at)->not->toBeNull()
        ->and($consultation->raw_data['scheme_ids'])->toEqualCanonicalizing(['1001', '1002']);

    $medicine = NhifService::query()->where('nhif_item_code', '11001')->sole();

    expect($medicine->raw_data['dosage'])->toBe('Tube')
        ->and($medicine->raw_data['strength'])->toBe('Gel: 2%,5%');

    unlink($path);
});

test('re-running the import is idempotent', function () {
    $path = nhifCatalogDumpFixture();

    $this->artisan('nhif:import-catalog', ['path' => $path])->assertSuccessful();
    $firstSyncedAt = NhifService::query()->where('nhif_item_code', '10001')->sole()->synced_at;

    $this->travel(1)->minutes();

    $this->artisan('nhif:import-catalog', ['path' => $path])->assertSuccessful();

    expect(NhifService::count())->toBe(3);

    $refreshedSyncedAt = NhifService::query()->where('nhif_item_code', '10001')->sole()->synced_at;
    expect($refreshedSyncedAt->gt($firstSyncedAt))->toBeTrue();

    unlink($path);
});

test('a missing file fails cleanly', function () {
    $this->artisan('nhif:import-catalog', ['path' => 'database/does-not-exist.sql'])
        ->assertFailed();

    expect(NhifService::count())->toBe(0);
});
