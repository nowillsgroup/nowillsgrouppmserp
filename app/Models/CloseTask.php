<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.6 A close-checklist line: an automatic check against a sub-ledger, or a manual task signed off by a person. */
class CloseTask extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['pending' => 'Not checked', 'pass' => 'Passed', 'warn' => 'Passed with warning', 'fail' => 'Failed', 'na' => 'Not applicable', 'done' => 'Done', 'waived' => 'Waived'];

    public const TONES = ['pending' => 'slate', 'pass' => 'green', 'warn' => 'amber', 'fail' => 'red', 'na' => 'slate', 'done' => 'green', 'waived' => 'purple'];

    /** statuses that let the period be submitted for close */
    public const CLEARED = ['pass', 'warn', 'na', 'done', 'waived'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['done_at' => 'datetime', 'checked_at' => 'datetime'];
    }

    public function period()
    {
        return $this->belongsTo(ClosePeriod::class, 'close_period_id');
    }

    public function doer()
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    public function cleared(): bool
    {
        return in_array($this->status, self::CLEARED, true);
    }
}
