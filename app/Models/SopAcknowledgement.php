<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.0 An employee's "read and understood" sign-off of one SOP version. */
class SopAcknowledgement extends Model
{
    use BelongsToHotel;

    public const VIA = ['self' => 'Signed online', 'sheet' => 'Paper sign-off sheet', 'training' => 'Covered in training'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime'];
    }

    public function document()
    {
        return $this->belongsTo(SopDocument::class, 'sop_document_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
