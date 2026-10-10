<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.0 A completed checklist for one period, signed off by a manager. */
class FsChecklistRun extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['period' => 'date', 'answers' => 'array', 'completed_at' => 'datetime', 'signed_at' => 'datetime'];
    }

    public function checklist()
    {
        return $this->belongsTo(FsChecklist::class, 'fs_checklist_id');
    }

    public function completer()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function signer()
    {
        return $this->belongsTo(User::class, 'signed_by');
    }
}
