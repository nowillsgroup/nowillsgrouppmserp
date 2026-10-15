<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/**
 * Legacy external id → local model mapping, so imports are idempotent and
 * later datasets can reference earlier ones (v7.35).
 */
class DataMigrationIdMap extends Model
{
    use BelongsToHotel;

    protected $table = 'data_migration_id_map';

    protected $guarded = ['id', 'hotel_id'];

    protected $casts = [
        'model_id' => 'int',
    ];

    public function batch()
    {
        return $this->belongsTo(DataMigrationBatch::class, 'batch_id');
    }
}
