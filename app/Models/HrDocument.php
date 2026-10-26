<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.40 A staff-uploaded document (ID, certificate, contract …) awaiting HR verification. */
class HrDocument extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public const STATUSES = ['new' => ['New', 'blue'], 'verified' => ['Verified', 'green'], 'rejected' => ['Rejected', 'red']];

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }
}
