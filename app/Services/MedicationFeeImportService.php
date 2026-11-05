<?php

namespace App\Services;

use App\Models\Medication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class MedicationFeeImportService
{
    public function __construct(protected MedicationService $medications) {}

    /**
     * @return array{total: int, created: int, updated: int, unchanged: int, unmatched: int, ambiguous: int, invalid: int, rows: list<array{row: int, status: string, detail: string}>}
     */
    public function import(string $path, Request $request, bool $dryRun = false, bool $createMissing = false): array
    {
        $rows = $this->readRows($path);

        return DB::transaction(fn (): array => $this->process($rows, $request, $dryRun, $createMissing));
    }

    /**
     * @param  list<array<string, string|null>>  $rows
     * @return array{total: int, created: int, updated: int, unchanged: int, unmatched: int, ambiguous: int, invalid: int, rows: list<array{row: int, status: string, detail: string}>}
     */
    protected function process(array $rows, Request $request, bool $dryRun, bool $createMissing): array
    {
        $catalog = Medication::query()->get(['id', 'code', 'generic_name', 'brand_name', 'strength', 'form', 'price']);
        $byCode = $catalog->keyBy(fn (Medication $medication): string => $this->normalize($medication->code));
        $byIdentity = $catalog->groupBy(fn (Medication $medication): string => $this->identity($medication->generic_name, $medication->brand_name, $medication->strength, $medication->form));
        $result = ['total' => count($rows), 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'unmatched' => 0, 'ambiguous' => 0, 'invalid' => 0, 'rows' => []];

        foreach ($rows as $rowNumber => $row) {
            $fee = $this->parseFee($row['fee'] ?? null);
            if ($fee === null) {
                $result['invalid']++;
                $result['rows'][] = ['row' => $rowNumber, 'status' => 'invalid', 'detail' => 'Fee is blank, non-numeric, or negative.'];

                continue;
            }

            $medication = null;
            if (($code = $this->normalize($row['code'] ?? '')) !== '') {
                $medication = $byCode->get($code);
            } else {
                $matches = $byIdentity->get($this->identity($row['generic_name'] ?? '', $row['brand_name'] ?? '', $row['strength'] ?? '', $row['form'] ?? ''), collect());
                if ($matches->count() > 1) {
                    $result['ambiguous']++;
                    $result['rows'][] = ['row' => $rowNumber, 'status' => 'ambiguous', 'detail' => 'Medication identity matches more than one catalog row.'];

                    continue;
                }
                $medication = $matches->first();
            }

            if (! $medication) {
                $name = trim((string) ($row['generic_name'] ?? ''));
                if ($createMissing && $code !== '' && $name !== '') {
                    if (! $dryRun) {
                        $created = $this->medications->create(array_filter([
                            'code' => $row['code'],
                            'generic_name' => $name,
                            'category' => trim((string) ($row['category'] ?? '')) ?: null,
                            'price' => $fee,
                        ], fn ($value): bool => $value !== null), $request);
                        $byCode->put($code, $created);
                    } else {
                        // Track sheet-internal duplicates the same way a real run would.
                        $byCode->put($code, new Medication(['code' => $row['code'], 'price' => $fee]));
                    }
                    $result['created']++;
                    $result['rows'][] = ['row' => $rowNumber, 'status' => 'created', 'detail' => strtoupper(trim((string) $row['code'])).": {$name} @ {$fee}"];

                    continue;
                }

                $result['unmatched']++;
                $result['rows'][] = ['row' => $rowNumber, 'status' => 'unmatched', 'detail' => 'No medication catalog row matched.'];

                continue;
            }

            if ((string) $medication->price === $fee) {
                $result['unchanged']++;

                continue;
            }

            if (! $dryRun) {
                $this->medications->update($medication, ['price' => $fee], $request);
            }

            $result['updated']++;
            $result['rows'][] = ['row' => $rowNumber, 'status' => 'updated', 'detail' => "{$medication->code}: {$fee}"];
        }

        return $result;
    }

    /**
     * @return list<array{code: string, generic_name: string, brand_name: string, strength: string, form: string, fee: string|null}>
     */
    protected function readRows(string $path): array
    {
        if (! is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw ValidationException::withMessages(['path' => 'A readable .xlsx file is required.']);
        }

        $directory = storage_path('framework/cache/medication-fees-'.uniqid());
        File::makeDirectory($directory, 0755, true);

        try {
            $process = new Process(['tar', '-xf', $path, '-C', $directory]);
            $process->run();
            if (! $process->isSuccessful()) {
                throw ValidationException::withMessages(['path' => 'The XLSX archive could not be opened.']);
            }

            $sharedStrings = [];
            $sharedPath = $directory.'/xl/sharedStrings.xml';
            if (is_file($sharedPath)) {
                $xml = simplexml_load_file($sharedPath);
                $namespace = $xml->getDocNamespaces(true)[''] ?? null;
                $root = $namespace ? $xml->children($namespace) : $xml;
                foreach ($root->si as $item) {
                    $itemRoot = $namespace ? $item->children($namespace) : $item;
                    $sharedStrings[] = (string) $itemRoot->t;
                }
            }

            $sheetPath = $directory.'/xl/worksheets/sheet1.xml';
            if (! is_file($sheetPath)) {
                throw ValidationException::withMessages(['path' => 'The workbook has no first worksheet.']);
            }

            $sheet = simplexml_load_file($sheetPath);
            $namespace = $sheet->getDocNamespaces(true)[''] ?? null;
            $sheetRoot = $namespace ? $sheet->children($namespace) : $sheet;
            $rawRows = [];
            foreach ($sheetRoot->sheetData->row as $xmlRow) {
                $values = [];
                $rowRoot = $namespace ? $xmlRow->children($namespace) : $xmlRow;
                foreach ($rowRoot->c as $cell) {
                    // Attributes live in no namespace; children($namespace) would otherwise hide them.
                    $attributes = $cell->attributes('', true);
                    $reference = (string) $attributes['r'];
                    preg_match('/([A-Z]+)/', $reference, $match);
                    $column = $this->columnNumber($match[1] ?? 'A');
                    $cellRoot = $namespace ? $cell->children($namespace) : $cell;
                    $value = (string) $cellRoot->v;
                    if ((string) $attributes['t'] === 's') {
                        $value = $sharedStrings[(int) $value] ?? '';
                    } elseif ((string) $attributes['t'] === 'inlineStr') {
                        $value = (string) $cellRoot->is->t;
                    }
                    $values[$column] = trim($value);
                }
                $rawRows[] = $values;
            }

            $headers = array_map(fn ($value): string => $this->normalizeHeader((string) $value), $rawRows[0] ?? []);
            $columns = [
                'code' => $this->findColumn($headers, ['code', 'medicationcode', 'itemcode']),
                'generic_name' => $this->findColumn($headers, ['genericname', 'medication', 'medicationname', 'name', 'itemname']),
                'brand_name' => $this->findColumn($headers, ['brandname', 'brand']),
                'strength' => $this->findColumn($headers, ['strength', 'dose']),
                'form' => $this->findColumn($headers, ['form', 'dosageform']),
                'category' => $this->findColumn($headers, ['category']),
                'fee' => $this->findColumn($headers, ['fee', 'price', 'sellingprice', 'sellingcost', 'unitprice', 'amount']),
            ];
            if ($columns['fee'] === null || ($columns['code'] === null && $columns['generic_name'] === null)) {
                throw ValidationException::withMessages(['path' => 'The worksheet must contain a fee/price column and a medication code or name column.']);
            }

            return collect(array_slice($rawRows, 1))->map(function (array $values) use ($columns): array {
                return collect($columns)->mapWithKeys(fn (?int $column, string $key): array => [$key => $column === null ? '' : ($values[$column] ?? '')])->all();
            })->values()->all();
        } finally {
            File::deleteDirectory($directory);
        }
    }

    protected function parseFee(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || ! preg_match('/^-?[0-9]+(?:[,.][0-9]{1,2})?$/', str_replace([' ', '$', '€', '£'], '', $value))) {
            return null;
        }
        $value = str_replace(',', '.', str_replace([' ', '$', '€', '£'], '', $value));
        if ((float) $value < 0) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    protected function normalizeHeader(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $value));
    }

    protected function normalize(string $value): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', $value)));
    }

    protected function identity(?string $genericName, ?string $brandName, ?string $strength, ?string $form): string
    {
        return implode('|', [$this->normalize((string) $genericName), $this->normalize((string) $brandName), $this->normalize((string) $strength), $this->normalize((string) $form)]);
    }

    /** @param array<int, string> $headers */
    protected function findColumn(array $headers, array $aliases): ?int
    {
        foreach ($aliases as $alias) {
            $column = array_search($alias, $headers, true);
            if ($column !== false) {
                return $column;
            }
        }

        return null;
    }

    protected function columnNumber(string $letters): int
    {
        $number = 0;
        foreach (str_split($letters) as $letter) {
            $number = ($number * 26) + ord($letter) - 64;
        }

        return $number - 1;
    }
}
