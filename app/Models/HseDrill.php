<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.7 A fire / emergency drill: planned → done (evacuation time, roll call, result) or cancelled. */
class HseDrill extends Model
{
    use BelongsToHotel;

    public const TYPES = ['fire_evacuation' => 'Fire evacuation (full)', 'fire_partial' => 'Fire evacuation (one wing / floor)', 'tabletop' => 'Table-top exercise',
        'bomb_threat' => 'Bomb threat', 'medical' => 'Medical emergency', 'power' => 'Power failure / lift rescue'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'alarm_at' => 'datetime', 'clear_at' => 'datetime', 'announced' => 'boolean', 'fire_service_attended' => 'boolean'];
    }

    public function staff()
    {
        return $this->belongsToMany(Employee::class, 'hse_drill_staff', 'hse_drill_id', 'employee_id');
    }

    public function leader()
    {
        return $this->belongsTo(User::class, 'led_by');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }

    public function evacLabel(): string
    {
        return $this->evac_seconds === null ? '—' : intdiv($this->evac_seconds, 60).' min '.str_pad((string) ($this->evac_seconds % 60), 2, '0', STR_PAD_LEFT).' s';
    }
}
