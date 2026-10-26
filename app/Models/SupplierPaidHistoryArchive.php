<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable, non-operational retention for historical supplier bills and payments.
 *
 * This archive is separate from supplier_invoices, supplier_payments,
 * ap_allocations, the AP subledger and the GL.
 */
class SupplierPaidHistoryArchive extends Model
{
    use BelongsToHotel;

    protected $table = 'supplier_paid_history_archives';

    protected $guarded = ['id', 'hotel_id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Supplier paid-history archive rows are immutable.'));
        static::deleting(fn () => throw new \LogicException('Supplier paid-history archive rows are retained as source audit records.'));
    }

    protected function casts(): array
    {
        return [
            'cutover_date' => 'date',
            'document_date' => 'date',
            'amount' => 'decimal:2',
            'source_payload' => 'array',
        ];
    }

    public function batch()
    {
        return $this->belongsTo(DataMigrationBatch::class, 'batch_id');
    }
}
