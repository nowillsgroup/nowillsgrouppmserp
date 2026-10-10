<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class GlLine extends Model
{
    use BelongsToHotel;

    protected $table = 'gl_lines';

    protected $guarded = ['id', 'hotel_id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['date' => 'date', 'debit' => 'decimal:2', 'credit' => 'decimal:2'];
    }

    public function journal()
    {
        return $this->belongsTo(GlJournal::class, 'gl_journal_id');
    }

    public function account()
    {
        return $this->belongsTo(AccountHead::class, 'account_head_id');
    }
}
