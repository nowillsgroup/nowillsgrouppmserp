<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class LinenMovement extends Model
{
    use BelongsToHotel;

    public const UPDATED_AT = null;

    public const TYPES = ['purchase' => 'New stock received', 'issue' => 'Issued to floors', 'collect' => 'Soiled collected', 'send' => 'Sent to laundry',
        'return' => 'Returned clean', 'discard' => 'Discarded (damaged)', 'lost' => 'Lost / missing', 'adjust' => 'Count adjustment'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'value' => 'decimal:2'];
    }

    public function linen()
    {
        return $this->belongsTo(LinenType::class, 'linen_type_id');
    }

    public function batch()
    {
        return $this->belongsTo(LinenBatch::class, 'linen_batch_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
