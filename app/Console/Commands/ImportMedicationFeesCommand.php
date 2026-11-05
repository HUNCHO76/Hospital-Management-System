<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MedicationFeeImportService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ImportMedicationFeesCommand extends Command
{
    protected $signature = 'medications:import-fees
        {path : Path to the medication fee .xlsx workbook}
        {--dry-run : Preview matches and changes without updating the database}
        {--user= : Email of the user recorded as the importer}';

    protected $description = 'Import current patient-facing medication fees into the medication catalog';

    public function handle(MedicationFeeImportService $importer): int
    {
        $path = is_file($this->argument('path')) ? $this->argument('path') : base_path($this->argument('path'));
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $actor = User::query()->when($this->option('user'), fn ($query) => $query->where('email', $this->option('user')))->first();
        if (! $actor) {
            $this->error('A valid user is required for audit logging. Use --user=email.');

            return self::FAILURE;
        }

        $request = Request::create('/artisan/medications:import-fees', 'POST');
        $request->setUserResolver(fn () => $actor);

        try {
            $result = $importer->import($path, $request, (bool) $this->option('dry-run'), (bool) $this->option('create-missing'));
        } catch (ValidationException $exception) {
            $this->error(collect($exception->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $this->info($this->option('dry-run') ? 'DRY RUN: no database changes were made.' : 'Medication fees imported.');
        $this->table(['Rows', 'Created', 'Updated', 'Unchanged', 'Unmatched', 'Ambiguous', 'Invalid'], [[
            $result['total'], $result['created'], $result['updated'], $result['unchanged'], $result['unmatched'], $result['ambiguous'], $result['invalid'],
        ]]);

        foreach ($result['rows'] as $row) {
            if (! in_array($row['status'], ['updated', 'created'], true)) {
                $this->warn("Row {$row['row']}: {$row['status']} - {$row['detail']}");
            }
        }

        return self::SUCCESS;
    }
}
