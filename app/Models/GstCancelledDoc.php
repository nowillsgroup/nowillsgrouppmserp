<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** An invoice / credit note number that was issued and then cancelled before E-VAT stamping (kept for the numbering audit trail). */
class GstCancelledDoc extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    /** Cancellation reasons. */
    public const REASONS = ['1' => 'Duplicate', '2' => 'Data entry mistake', '3' => 'Order cancelled', '4' => 'Others'];

    protected function casts(): array
    {
        return ['doc_date' => 'date', 'value' => 'decimal:2'];
    }

    public function folio()
    {
        return $this->belongsTo(Folio::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
