<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class PiiAccessLog extends Model
{
    use BelongsToHotel;

    public const ACTIONS = ['reveal' => 'Revealed identifiers', 'document' => 'Viewed ID document', 'signature' => 'Viewed e-signature', 'export' => 'Exported personal data', 'print' => 'Printed a statutory form'];

    public $timestamps = false;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }
}
