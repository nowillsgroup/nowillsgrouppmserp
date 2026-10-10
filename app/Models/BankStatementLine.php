<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.6 One statement line: + money in, − money out; unmatched | matched (to GL lines) | ignored. */
class BankStatementLine extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected $attributes = ['status' => 'unmatched'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2', 'balance' => 'decimal:2', 'matched_at' => 'datetime'];
    }

    public function statement()
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function matches()
    {
        return $this->hasMany(BankMatch::class);
    }

    public function glLines()
    {
        return $this->belongsToMany(GlLine::class, 'bank_matches', 'bank_statement_line_id', 'gl_line_id');
    }

    public function entryAccount()
    {
        return $this->belongsTo(AccountHead::class, 'entry_account_id');
    }

    public function matcher()
    {
        return $this->belongsTo(User::class, 'matched_by');
    }
}
