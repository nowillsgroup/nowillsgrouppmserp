<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.6 A parking ticket: one vehicle's stay in the car park, self-parked or with the valet. */
class ParkingSession extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['parked' => ['Parked', 'blue'], 'requested' => ['Requested', 'amber'], 'ready' => ['Ready at the door', 'purple'], 'out' => ['Exited', 'green'], 'cancelled' => ['Cancelled', 'slate']];

    public const KINDS = ['guest' => 'In-house guest', 'visitor' => 'Visitor', 'staff' => 'Staff / permit'];

    public const ACTIVE = ['parked', 'requested', 'ready'];

    public const PANELS = ['front' => 'Front bumper', 'bonnet' => 'Bonnet', 'windscreen' => 'Windscreen', 'left' => 'Left side', 'right' => 'Right side', 'rear' => 'Rear / boot', 'roof' => 'Roof', 'wheels' => 'Wheels / rims', 'interior' => 'Interior'];

    public const FUEL = ['E', '1/4', '1/2', '3/4', 'F'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['is_valet' => 'boolean', 'lost_ticket' => 'boolean', 'damage' => 'array', 'entered_at' => 'datetime', 'exited_at' => 'datetime',
            'requested_at' => 'datetime', 'ready_at' => 'datetime'];
    }

    public function zone()
    {
        return $this->belongsTo(ParkingZone::class, 'parking_zone_id');
    }

    public function vehicle()
    {
        return $this->belongsTo(ParkingVehicle::class, 'parking_vehicle_id');
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function permit()
    {
        return $this->belongsTo(ParkingPermit::class, 'parking_permit_id');
    }

    public function charges()
    {
        return $this->hasMany(ParkingCharge::class);
    }

    public function folio()
    {
        return $this->belongsTo(Folio::class);
    }

    public function paymentMode()
    {
        return $this->belongsTo(PaymentMode::class);
    }

    public function parker()
    {
        return $this->belongsTo(User::class, 'parked_by');
    }

    public function runner()
    {
        return $this->belongsTo(User::class, 'runner_id');
    }

    public function exiter()
    {
        return $this->belongsTo(User::class, 'exited_by');
    }

    public function validator()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }

    public function kindLabel(): string
    {
        return (self::KINDS[$this->kind] ?? ucfirst($this->kind)).($this->is_valet ? ' · valet' : '');
    }

    /** Minutes parked so far (or in total once out). */
    public function minutes(): int
    {
        return (int) max(0, floor($this->entered_at->diffInSeconds($this->exited_at ?? now(), true) / 60));
    }

    public function durationLabel(): string
    {
        $m = $this->minutes();
        $d = intdiv($m, 1440);
        $h = intdiv($m % 1440, 60);

        return ($d ? $d.'d ' : '').($d || $h ? $h.'h ' : '').($m % 60).'m';
    }

    /** Minutes the guest has been waiting for the car (request → ready, or → now while still requested). */
    public function waitMinutes(): ?int
    {
        if (! $this->requested_at) {
            return null;
        }

        return (int) floor($this->requested_at->diffInSeconds($this->ready_at ?? ($this->status === 'requested' ? now() : $this->exited_at ?? now()), true) / 60);
    }

    public function publicUrl(): ?string
    {
        return $this->token ? route('parking.public', ['token' => $this->token]) : null;
    }
}
