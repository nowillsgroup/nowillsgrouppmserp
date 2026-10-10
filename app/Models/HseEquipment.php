<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.7 A piece of fire / life-safety equipment: monthly visual check, annual contractor service, GNFS tag. */
class HseEquipment extends Model
{
    use BelongsToHotel;

    protected $table = 'hse_equipment';

    /** type => [label, prefix, check days, service days (0 = none), visual checklist] */
    public const TYPES = [
        'ext_co2' => ['CO₂ extinguisher', 'FE', 30, 365, ['In place, visible and signed', 'Pin and tamper seal intact', 'Horn and hose undamaged', 'No damage or corrosion', 'Label and GNFS tag legible']],
        'ext_powder' => ['Dry powder extinguisher', 'FE', 30, 365, ['In place, visible and signed', 'Pin and tamper seal intact', 'Gauge in the green', 'No damage or corrosion', 'Label and GNFS tag legible']],
        'ext_foam' => ['Foam extinguisher', 'FE', 30, 365, ['In place, visible and signed', 'Pin and tamper seal intact', 'Gauge in the green', 'No damage or corrosion', 'Label and GNFS tag legible']],
        'ext_wet' => ['Wet chemical extinguisher (kitchen)', 'FE', 30, 365, ['In place beside the fryers', 'Pin and tamper seal intact', 'Gauge in the green', 'No damage or corrosion', 'Label and GNFS tag legible']],
        'blanket' => ['Fire blanket', 'FB', 30, 0, ['In place and visible', 'Pull tapes hanging free', 'Case undamaged']],
        'hose_reel' => ['Hose reel', 'HR', 30, 365, ['Cabinet unobstructed', 'Hose undamaged, no kinks', 'Nozzle and valve present', 'No leaks at the valve']],
        'hydrant' => ['Hydrant / riser', 'HY', 90, 365, ['Access clear', 'Valve and cap present', 'Signage in place']],
        'alarm_panel' => ['Fire alarm panel', 'AP', 7, 365, ['Panel shows normal – no faults', 'No zones disabled', 'Battery / mains healthy', 'Weekly call-point test done']],
        'detector' => ['Smoke / heat detector', 'SD', 30, 365, ['Head clean and uncovered', 'LED blinking', 'Test button / aerosol test sounds']],
        'call_point' => ['Manual call point', 'CP', 30, 365, ['Glass / element intact', 'Unobstructed and signed']],
        'emergency_light' => ['Emergency light / exit sign', 'EL', 30, 365, ['Lights on test', 'Charging LED on', 'Exit sign legible']],
        'fire_door' => ['Fire door', 'FD', 30, 0, ['Closes and latches by itself', 'Not wedged or blocked', 'Seals and hinges intact', 'Signage in place']],
        'sprinkler' => ['Sprinkler valve set', 'SP', 7, 365, ['Main valve locked open', 'Pressure within range', 'No leaks']],
        'first_aid' => ['First-aid kit', 'FA', 30, 0, ['Seal intact / contents complete', 'Items in date', 'Box clean and signed']],
        'aed' => ['AED (defibrillator)', 'AED', 30, 365, ['Status light OK', 'Pads in date', 'Battery in date', 'Cabinet alarm works']],
        'lpg' => ['LPG cylinder bank', 'LPG', 7, 365, ['No smell of gas', 'Cylinders upright, chained, outdoors', 'Hoses and regulator in date', 'Emergency shut-off accessible']],
        'eyewash' => ['Eyewash station', 'EW', 30, 0, ['Water flows clear', 'Caps in place', 'Access clear']],
    ];

    public const STATUSES = ['ok' => 'In order', 'defective' => 'Defective', 'out_of_service' => 'Out of service', 'retired' => 'Retired'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['installed_on' => 'date', 'expires_on' => 'date', 'last_checked_on' => 'date', 'next_check_on' => 'date', 'last_serviced_on' => 'date', 'next_service_on' => 'date'];
    }

    public function checks()
    {
        return $this->hasMany(HseCheck::class)->orderByDesc('checked_on')->orderByDesc('id');
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function ticket()
    {
        return $this->belongsTo(MaintenanceRequest::class, 'maintenance_request_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type][0] ?? ucfirst(str_replace('_', ' ', $this->type));
    }

    public function checklist(): array
    {
        return self::TYPES[$this->type][4] ?? ['Checked and in order'];
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    /** ok | due | overdue | defective | expired | retired – the worst thing about this item today. */
    public function state(?string $today = null): string
    {
        $today ??= bdate()->toDateString();
        if ($this->status === 'retired') {
            return 'retired';
        }
        if (in_array($this->status, ['defective', 'out_of_service'], true)) {
            return 'defective';
        }
        if ($this->expires_on && $this->expires_on->toDateString() < $today) {
            return 'expired';
        }
        if (! $this->next_check_on || $this->next_check_on->toDateString() < $today || ($this->next_service_on && $this->next_service_on->toDateString() < $today)) {
            return 'overdue';
        }
        $soon = \Carbon\Carbon::parse($today)->addDays(min(7, max(1, intdiv($this->check_days, 4))))->toDateString(); // weekly items: 1 day, monthly: 7
        if ($this->next_check_on->toDateString() <= $soon || ($this->next_service_on && $this->next_service_on->toDateString() <= \Carbon\Carbon::parse($today)->addDays(30)->toDateString())) {
            return 'due';
        }

        return 'ok';
    }
}
