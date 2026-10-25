<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable, non-operational archive of legacy stock movements.
 *
 * This table is never queried by stock valuation, stocktakes, requisitions, or
 * ledger posting. It is deliberately separate from stock_movements and from
 * data_migration_id_map.
 */
class HistoricalStockMovementArchive extends Model
{
    use BelongsToHotel;

    protected $table = 'historical_stock_movement_archives';

    protected $guarded = ['id', 'hotel_id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Historical stock archive rows are immutable.'));
        static::deleting(fn () => throw new \LogicException('Historical stock archive rows are retained as source audit records.'));
    }

    protected function casts(): array
    {
        return [
            'cutover_date' => 'date',
            'movement_date' => 'date',
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'source_payload' => 'array',
        ];
    }

    public function batch()
    {
        return $this->belongsTo(DataMigrationBatch::class, 'batch_id');
    }
}
