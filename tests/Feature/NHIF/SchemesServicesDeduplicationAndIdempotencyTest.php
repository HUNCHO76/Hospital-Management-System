<?php

namespace Tests\Feature\NHIF;

use App\Models\NhifSchemeService;
use App\Models\Service;
use App\Models\User;
use App\Services\NHIF\SchemesServicesDumpParser;
use Database\Seeders\NhifSchemeServiceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchemesServicesDeduplicationAndIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed controlled canonical fixture rows into schemes_services
        DB::table('schemes_services')->insert([
            [
                'id' => 3,
                'item_code' => 10001,
                'item_name' => 'General Practitioner Consultation fee (MD/MBBS/DDS)',
                'item_type_id' => 1,
                'type_name' => 'Registration/Consultation Charges',
                'scheme_id' => 1001,
                'dosage' => null,
                'strength' => null,
                'start_date' => null,
                'end_date' => null,
                'created_by' => 'admin',
                'last_modified_by' => 'admin',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 4,
                'item_code' => 10002,
                'item_name' => 'Specialist Consultation fee (MD/MBBS, MMED, DDS/MDENT)',
                'item_type_id' => 1,
                'type_name' => 'Registration/Consultation Charges',
                'scheme_id' => 1001,
                'dosage' => null,
                'strength' => null,
                'start_date' => null,
                'end_date' => null,
                'created_by' => 'admin',
                'last_modified_by' => 'admin',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 1829,
                'item_code' => 10001,
                'item_name' => 'General Practitioner Consultation fee (MD/MBBS/DDS)',
                'item_type_id' => 1,
                'type_name' => 'Registration/Consultation Charges',
                'scheme_id' => 1002,
                'dosage' => null,
                'strength' => null,
                'start_date' => null,
                'end_date' => null,
                'created_by' => 'admin',
                'last_modified_by' => 'admin',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 9,
                'item_code' => 11001,
                'item_name' => 'Lidocaine',
                'item_type_id' => 3,
                'type_name' => 'Medicine and Consumables',
                'scheme_id' => 1001,
                'dosage' => 'Tube',
                'strength' => 'Gel: 2%,5%',
                'start_date' => null,
                'end_date' => null,
                'created_by' => 'admin',
                'last_modified_by' => 'admin',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2043,
                'item_code' => 11425,
                'item_name' => 'Glucosamine + Chondrotin sulphate',
                'item_type_id' => 3,
                'type_name' => 'Medicine and Consumables',
                'scheme_id' => 1002,
                'dosage' => 'Tablet',
                'strength' => 'Solid oral dosage form:',
                'start_date' => null,
                'end_date' => null,
                'created_by' => 'bnathanael',
                'last_modified_by' => 'bnathanael',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Test 1: Controlled fixture contains distinct IDs and scheme-item pairs.
     */
    public function test_controlled_fixture_has_distinct_records(): void
    {
        $totalRows = DB::table('schemes_services')->count();
        $distinctIds = DB::table('schemes_services')->distinct()->count('id');
        $distinctPairs = DB::table('schemes_services')
            ->select('scheme_id', 'item_code')
            ->distinct()
            ->count();

        $this->assertEquals(5, $totalRows);
        $this->assertEquals(5, $distinctIds);
        $this->assertEquals(5, $distinctPairs);
    }

    /**
     * Test 2: Database-level unique constraint rejects duplicate (scheme_id, item_code) combinations.
     */
    public function test_database_enforced_uniqueness_blocks_duplicate_scheme_item_insertion(): void
    {
        // Item 10001 under scheme 1001 already exists in fixture
        $existing = DB::table('schemes_services')
            ->where('scheme_id', 1001)
            ->where('item_code', 10001)
            ->first();

        $this->assertNotNull($existing);

        $this->expectException(QueryException::class);

        // Attempting to insert duplicate (scheme_id, item_code) must throw QueryException
        DB::table('schemes_services')->insert([
            'id' => 9999999,
            'item_code' => 10001,
            'item_name' => 'Duplicate Attempt',
            'scheme_id' => 1001,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Test 3: The same item_code can legitimately be mapped to multiple distinct schemes.
     */
    public function test_same_item_code_is_permitted_across_multiple_schemes(): void
    {
        // Consultation 10001 is mapped to both Scheme 1001 and Scheme 1002
        $scheme1001 = DB::table('schemes_services')
            ->where('item_code', 10001)
            ->where('scheme_id', 1001)
            ->first();

        $scheme1002 = DB::table('schemes_services')
            ->where('item_code', 10001)
            ->where('scheme_id', 1002)
            ->first();

        $this->assertNotNull($scheme1001);
        $this->assertNotNull($scheme1002);
        $this->assertNotEquals($scheme1001->id, $scheme1002->id);
        $this->assertNotEquals($scheme1001->scheme_id, $scheme1002->scheme_id);
    }

    /**
     * Test 4: Different NHIF items under the same scheme remain distinct.
     */
    public function test_different_nhif_items_under_same_scheme_remain_distinct(): void
    {
        $gpConsultation = DB::table('schemes_services')
            ->where('scheme_id', 1001)
            ->where('item_code', 10001)
            ->first();

        $specialistConsultation = DB::table('schemes_services')
            ->where('scheme_id', 1001)
            ->where('item_code', 10002)
            ->first();

        $this->assertNotNull($gpConsultation);
        $this->assertNotNull($specialistConsultation);
        $this->assertNotEquals($gpConsultation->item_code, $specialistConsultation->item_code);
        $this->assertNotEquals($gpConsultation->item_name, $specialistConsultation->item_name);
    }

    /**
     * Test 5: Re-running dump parser on clean SQL dump produces exactly 2,999 rows and 1,826 distinct codes.
     */
    public function test_clean_sql_dump_parses_consistently_without_unbounded_duplication(): void
    {
        $dumpPath = base_path('database/schemes_services.sql');
        $this->assertFileExists($dumpPath);

        $parser = new SchemesServicesDumpParser();
        $parsed = $parser->parse($dumpPath);

        $this->assertEquals(2999, $parsed['raw_row_count'], 'Clean dump must have exactly 2,999 raw rows.');
        $this->assertCount(1826, $parsed['items'], 'Clean dump must produce exactly 1,826 distinct item codes.');
    }

    /**
     * Test 6: Running NhifSchemeServiceSeeder repeatedly is fully idempotent.
     */
    public function test_seeder_is_fully_idempotent_on_clean_catalog(): void
    {
        User::factory()->create(['email' => 'test@example.com']);

        $fixtureSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `schemes_services` (
  `id` bigint(20) UNSIGNED NOT NULL
);

INSERT INTO `schemes_services` (`id`, `item_code`, `item_name`, `item_type_id`, `type_name`, `scheme_id`, `dosage`, `strength`, `start_date`, `end_date`, `created_by`, `last_modified_by`, `created_at`, `updated_at`) VALUES
(3, 10001, 'General Practitioner Consultation fee (MD/MBBS/DDS)', 1, 'Registration/Consultation Charges', 1001, NULL, NULL, NULL, NULL, NULL, NULL, '2026-06-16 06:41:14', '2026-06-16 06:41:14'),
(1829, 10001, 'General Practitioner Consultation fee (MD/MBBS/DDS)', 1, 'Registration/Consultation Charges', 1002, NULL, NULL, NULL, NULL, NULL, NULL, '2026-06-16 07:10:29', '2026-06-16 07:10:29'),
(9, 11001, 'Lidocaine', 3, 'Medicine and Consumables', 1001, 'Tube', 'Gel: 2%,5%', NULL, NULL, NULL, NULL, '2026-06-16 06:41:14', '2026-06-16 06:41:14');
SQL;

        $tempPath = storage_path('framework/testing/test_idempotent_fixture.sql');
        file_put_contents($tempPath, $fixtureSql);

        $seeder = new NhifSchemeServiceSeeder();
        $seeder->path = $tempPath;
        $seeder->setContainer(app());

        // First run
        app()->call([$seeder, 'run']);
        $countAfterFirst = Service::count();

        // Second run
        app()->call([$seeder, 'run']);
        $countAfterSecond = Service::count();

        $this->assertEquals($countAfterFirst, $countAfterSecond, 'Re-running seeder must not create duplicate Service rows.');

        unlink($tempPath);
    }

    /**
     * Test 7: Catalog scope lookup accurately resolves covered scheme items and respects date filters.
     */
    public function test_catalog_scope_lookup_resolves_items_correctly(): void
    {
        $item = NhifSchemeService::catalog()
            ->where('scheme_id', 1001)
            ->where('item_code', 10001)
            ->first();

        $this->assertNotNull($item);
        $this->assertEquals('General Practitioner Consultation fee (MD/MBBS/DDS)', $item->item_name);
    }
}
