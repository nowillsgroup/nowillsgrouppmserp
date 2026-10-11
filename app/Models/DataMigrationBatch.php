<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/**
 * One uploaded legacy-system CSV file being cut over (v7.35).
 */
class DataMigrationBatch extends Model
{
    use BelongsToHotel;

    protected $table = 'data_migration_batches';

    protected $guarded = ['id', 'hotel_id'];

    protected $casts = [
        'rows_total' => 'int',
        'rows_created' => 'int',
        'rows_updated' => 'int',
        'rows_skipped' => 'int',
        'rows_failed' => 'int',
        'validated_at' => 'datetime',
        'imported_at' => 'datetime',
    ];

    public function rows()
    {
        return $this->hasMany(DataMigrationRow::class, 'batch_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function counts(): array
    {
        return $this->rows()
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->all();
    }
}
