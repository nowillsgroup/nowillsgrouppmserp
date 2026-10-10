<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class SpaAppointment extends Model
{
    use BelongsToHotel;

    protected $table = 'spa_appointments';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'price' => 'decimal:2', 'discount' => 'decimal:2', 'settled_at' => 'datetime'];
    }

    public function service()
    {
        return $this->belongsTo(SpaService::class, 'spa_service_id');
    }

    public function therapist()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function spaRoom()
    {
        return $this->belongsTo(SpaRoom::class);
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function folio()
    {
        return $this->belongsTo(Folio::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function net(): float
    {
        return round(max(0, (float) $this->price - (float) $this->discount), 2);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['booked', 'in_progress']);
    }
}
