<?php

namespace App\Models;

use Database\Factories\NhifPackagePriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Raw NHIF GetPricePackage catalog entry.
 *
 * @property int $id
 * @property string $item_code
 * @property string|null $price_code
 * @property int|null $item_type_id
 * @property string|null $item_name
 * @property string|null $strength
 * @property string|null $dosage
 * @property int|null $package_id
 * @property int|null $scheme_id
 * @property float $unit_price
 * @property bool $is_restricted
 * @property bool $is_over_the_counter
 * @property int|null $maximum_quantity
 * @property int|null $maximum_quantity_out_patient
 * @property int|null $maximum_quantity_in_patient
 * @property bool $has_co_payment
 * @property Carbon|null $synced_at
 * @property array|null $raw_data
 */
class NhifPackagePrice extends Model
{
    /** @use HasFactory<NhifPackagePriceFactory> */
    use HasFactory;

    protected $fillable = [
        'item_code',
        'price_code',
        'item_type_id',
        'item_name',
        'strength',
        'dosage',
        'package_id',
        'scheme_id',
        'unit_price',
        'is_restricted',
        'is_over_the_counter',
        'maximum_quantity',
        'maximum_quantity_out_patient',
        'maximum_quantity_in_patient',
        'has_co_payment',
        'synced_at',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'float',
            'is_restricted' => 'boolean',
            'is_over_the_counter' => 'boolean',
            'has_co_payment' => 'boolean',
            'synced_at' => 'datetime',
            'raw_data' => 'array',
        ];
    }

    /**
     * Resolve the unit price for an item code within a scheme.
     * Returns null when no price record is found.
     */
    public static function resolveUnitPrice(string $itemCode, int $schemeId): ?float
    {
        $price = static::query()
            ->where('item_code', $itemCode)
            ->where('scheme_id', $schemeId)
            ->orderByDesc('id')
            ->value('unit_price');

        return $price !== null ? (float) $price : null;
    }
}
