<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.0 A frozen, published version of an SOP. */
class SopVersion extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['reack' => 'boolean', 'effective_date' => 'date', 'published_at' => 'datetime'];
    }

    public function document()
    {
        return $this->belongsTo(SopDocument::class, 'sop_document_id');
    }

    public function publisher()
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
