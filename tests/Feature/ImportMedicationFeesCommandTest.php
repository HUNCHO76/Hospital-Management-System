<?php

use App\Models\Medication;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

function medicationFeeWorkbook(array $rows): string
{
    $directory = storage_path('framework/testing/medication-fees-'.uniqid());
    File::makeDirectory($directory.'/xl/worksheets', 0755, true);

    $shared = ['Code', 'Medication Name', 'Fee'];
    foreach ($rows as $row) {
        $shared = array_merge($shared, [$row[0], $row[1], (string) $row[2]]);
    }
    $shared = array_values(array_unique($shared));
    $sharedXml = '<sst count="'.count($shared).'" uniqueCount="'.count($shared).'">';
    foreach ($shared as $value) {
        $sharedXml .= '<si><t>'.htmlspecialchars($value, ENT_XML1).'</t></si>';
    }
    $sharedXml .= '</sst>';
    File::put($directory.'/xl/sharedStrings.xml', $sharedXml);

    $sheetRows = [[0, 1, 2]];
    foreach ($rows as $row) {
        $sheetRows[] = [array_search($row[0], $shared, true), array_search($row[1], $shared, true), array_search((string) $row[2], $shared, true)];
    }
    $sheetXml = '<worksheet><sheetData>';
    foreach ($sheetRows as $rowNumber => $values) {
        $sheetXml .= '<row r="'.($rowNumber + 1).'">';
        foreach ($values as $column => $value) {
            $letter = chr(65 + $column);
            $sheetXml .= '<c r="'.$letter.($rowNumber + 1).'" t="s"><v>'.$value.'</v></c>';
        }
        $sheetXml .= '</row>';
    }
    $sheetXml .= '</sheetData></worksheet>';
    File::put($directory.'/xl/worksheets/sheet1.xml', $sheetXml);

    $path = storage_path('framework/testing/medication-fees-'.uniqid().'.xlsx');
    $process = new Process(['tar', '-cf', $path, '-C', $directory, 'xl']);
    $process->mustRun();
    File::deleteDirectory($directory);

    return $path;
}

test('imports medication fees by code and records price audit events', function () {
    $admin = userWithRole('hospital-admin');
    $medication = activeMedication(['code' => 'PCM-500-TAB', 'generic_name' => 'Paracetamol', 'price' => '5.00']);
    $path = medicationFeeWorkbook([['PCM-500-TAB', 'Paracetamol', '25.50'], ['UNKNOWN', 'Unknown', '10.00']]);

    $this->artisan('medications:import-fees', ['path' => $path, '--user' => $admin->email])
        ->assertSuccessful()
        ->expectsOutputToContain('Medication fees imported.');

    expect($medication->fresh()->price)->toBe('25.50');
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'medication_service_mapping_changed',
        'auditable_id' => $medication->id,
    ]);
    expect(Medication::where('code', 'UNKNOWN')->exists())->toBeFalse();

    File::delete($path);
});

test('dry-run does not change medication prices', function () {
    $admin = userWithRole('hospital-admin');
    $medication = activeMedication(['code' => 'DRY-RUN', 'generic_name' => 'Dry Run', 'price' => '5.00']);
    $path = medicationFeeWorkbook([['DRY-RUN', 'Dry Run', '25.50']]);

    $this->artisan('medications:import-fees', ['path' => $path, '--dry-run' => true, '--user' => $admin->email])
        ->assertSuccessful()
        ->expectsOutputToContain('DRY RUN: no database changes were made.');

    expect($medication->fresh()->price)->toBe('5.00');
    $this->assertDatabaseMissing('audit_logs', ['action' => 'medication_service_mapping_changed']);

    File::delete($path);
});

test('missing medication fee workbook fails cleanly', function () {
    $this->artisan('medications:import-fees', ['path' => 'database/missing-medication-fees.xlsx'])
        ->assertFailed();
});

test('create-missing adds unknown medications with the selling price and supports dry-run', function () {
    $admin = userWithRole('hospital-admin');
    $path = medicationFeeWorkbook([['NEW-ITEM-1', 'New Item One', '1500'], ['NEW-ITEM-2', 'New Item Two', '2500.00']]);

    $this->artisan('medications:import-fees', ['path' => $path, '--dry-run' => true, '--create-missing' => true, '--user' => $admin->email])->assertSuccessful();
    expect(Medication::where('code', 'NEW-ITEM-1')->exists())->toBeFalse();

    $this->artisan('medications:import-fees', ['path' => $path, '--create-missing' => true, '--user' => $admin->email])->assertSuccessful();
    expect(Medication::where('code', 'NEW-ITEM-1')->first()->price)->toBe('1500.00');
    expect(Medication::where('code', 'NEW-ITEM-2')->first()->generic_name)->toBe('New Item Two');
    $this->assertDatabaseHas('audit_logs', ['action' => 'medication_created']);

    File::delete($path);
});
