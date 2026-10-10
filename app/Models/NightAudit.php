<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class NightAudit extends Model
{
    use BelongsToHotel;

    protected $table = 'night_audits';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'run_by');
    }
}
