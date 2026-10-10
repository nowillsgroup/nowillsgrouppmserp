<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.6 The closing of a fiscal year: income and expense accounts cleared to retained earnings. */
class FiscalYearClose extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['closing_date' => 'date', 'income' => 'decimal:2', 'expense' => 'decimal:2', 'net' => 'decimal:2', 'closed_at' => 'datetime', 'reopened_at' => 'datetime'];
    }

    public function journal()
    {
        return $this->belongsTo(GlJournal::class, 'gl_journal_id');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
