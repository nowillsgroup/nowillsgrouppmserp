<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.7 – one call detail record (SMDR line) from the PABX, rated and (for guest rooms) posted to the folio. */
class CallRecord extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['posted' => ['Posted', 'green'], 'pending' => ['Review', 'amber'], 'free' => ['Free', 'slate'], 'admin' => ['Admin', 'blue'], 'waived' => ['Waived', 'purple']];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'handled_at' => 'datetime', 'cost' => 'float', 'charge' => 'float', 'duration_secs' => 'integer', 'pulses' => 'integer'];
    }

    public function phoneExtension()
    {
        return $this->belongsTo(PhoneExtension::class);
    }

    public function tariff()
    {
        return $this->belongsTo(CallTariff::class, 'call_tariff_id');
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function transaction()
    {
        return $this->belongsTo(FolioTransaction::class, 'folio_transaction_id');
    }

    public function handler()
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function durationLabel(): string
    {
        $s = $this->duration_secs;

        return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }

    /** dialled number with the middle digits masked (guest privacy on printed bills) */
    public function maskedNumber(): string
    {
        $n = $this->dialled;

        return strlen($n) > 6 ? substr($n, 0, strlen($n) - 4).'XXXX' : $n;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }
}
