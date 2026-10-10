<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class PrivacyRetentionRun extends Model
{
    use BelongsToHotel;

    public $timestamps = false;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['counts' => 'array', 'created_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function total(): int
    {
        return (int) array_sum(array_map('intval', $this->counts ?? []));
    }
}
