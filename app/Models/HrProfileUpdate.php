<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.40 An employee's requested change to their own contact/bank details — applied by HR on approval. */
class HrProfileUpdate extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public const STATUSES = ['pending' => ['Pending', 'amber'], 'approved' => ['Approved', 'green'], 'rejected' => ['Rejected', 'red']];

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }
}
