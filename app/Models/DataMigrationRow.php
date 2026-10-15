<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/**
 * Outcome of validating / importing one CSV row of a data-migration batch (v7.35).
 */
class DataMigrationRow extends Model
{
    use BelongsToHotel;

    protected $table = 'data_migration_rows';

    protected $guarded = ['id', 'hotel_id'];

    protected $casts = [
        'row_no' => 'int',
        'model_id' => 'int',
        'payload' => 'array',
    ];

    public function batch()
    {
        return $this->belongsTo(DataMigrationBatch::class, 'batch_id');
    }
}
