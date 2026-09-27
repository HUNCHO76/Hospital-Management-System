<?php

namespace App\Services\NHIF;

use Illuminate\Validation\ValidationException;

/**
 * Parses NHIF's own `schemes_services` catalog dump (a phpMyAdmin SQL
 * export bundled at `database/schemes_services.sql`) into a deduplicated,
 * item_code-keyed array. Shared by `ImportNhifCatalogCommand` (writes to
 * `nhif_services`) and `NhifSchemeServiceSeeder` (writes to `services`) so
 * the parsing/dedup rules live in exactly one place.
 *
 * The dump repeats each catalog item once per NHIF scheme it is payable
 * under (and, in this particular export, duplicates entire INSERT blocks
 * verbatim) — item_code is the true unique key; name/type are identical
 * across every repetition of the same item_code.
 */
class SchemesServicesDumpParser
{
    /**
     * @return array{raw_row_count: int, items: array<string, array{item_name: string, item_type_id: string, type_name: string, scheme_ids: array<int, string>, dosage: ?string, strength: ?string}>}
     *
     * @throws ValidationException
     */
    public function parse(string $path): array
    {
        $contents = file_get_contents($path);

        preg_match_all(
            "/\(\d+, (\d+|NULL), '((?:[^'\\\\]|\\\\.)*)', (\d+|NULL), '((?:[^'\\\\]|\\\\.)*)', (\d+|NULL), (NULL|'(?:[^'\\\\]|\\\\.)*'), (NULL|'(?:[^'\\\\]|\\\\.)*'),/",
            $contents,
            $matches,
            PREG_SET_ORDER,
        );

        if ($matches === []) {
            throw ValidationException::withMessages([
                'path' => 'No catalog rows were found in the given file.',
            ]);
        }

        /** @var array<string, array{item_name: string, item_type_id: string, type_name: string, scheme_ids: array<int, string>, dosage: ?string, strength: ?string}> $items */
        $items = [];

        foreach ($matches as $row) {
            $itemCode = $row[1];

            if ($itemCode === 'NULL') {
                continue;
            }

            $schemeId = $row[5];
            $dosage = $row[6] === 'NULL' ? null : trim($row[6], "'");
            $strength = $row[7] === 'NULL' ? null : trim($row[7], "'");

            if (! isset($items[$itemCode])) {
                $items[$itemCode] = [
                    'item_name' => $row[2],
                    'item_type_id' => $row[3],
                    'type_name' => $row[4],
                    'scheme_ids' => [],
                    'dosage' => $dosage,
                    'strength' => $strength,
                ];
            }

            if ($schemeId !== 'NULL' && ! in_array($schemeId, $items[$itemCode]['scheme_ids'], true)) {
                $items[$itemCode]['scheme_ids'][] = $schemeId;
            }
        }

        return [
            'raw_row_count' => count($matches),
            'items' => $items,
        ];
    }
}
