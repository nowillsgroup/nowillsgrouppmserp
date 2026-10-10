<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use BelongsToHotel;

    protected $table = 'attendances';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveRequest()
    {
        return $this->belongsTo(LeaveRequest::class);
    }
}
