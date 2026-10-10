<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.6 Audit trail of a period: checks run, postings, submitted, rejected, closed, reopen requested / approved. */
class CloseEvent extends Model
{
    use BelongsToHotel;

    public const UPDATED_AT = null;

    public const LABELS = ['checked' => 'Checks run', 'posted' => 'Period-end postings', 'task' => 'Task', 'submitted' => 'Submitted for approval', 'rejected' => 'Sent back',
        'closed' => 'Closed & locked', 'reopen_req' => 'Reopen requested', 'reopened' => 'Reopened', 'reopen_denied' => 'Reopen declined', 'year_end' => 'Year-end closing entry'];

    public const TONES = ['closed' => 'tl-ok', 'year_end' => 'tl-ok', 'rejected' => 'tl-bad', 'reopened' => 'tl-bad', 'reopen_req' => 'tl-bad', 'submitted' => 'tl-info', 'posted' => 'tl-info'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
