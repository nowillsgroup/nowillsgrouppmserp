<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.7 A permit to work: requested → issued (precautions confirmed by an authorised issuer) → closed out, or cancelled. */
class HsePermit extends Model
{
    use BelongsToHotel;

    /** type => [label, max hours, precautions] */
    public const TYPES = [
        'hot_work' => ['Hot work (welding, cutting, grinding)', 8, ['combustibles' => 'Combustibles removed or covered within 10 m', 'extinguisher' => 'Suitable extinguisher at the work point', 'screens' => 'Fire screens / blankets in place', 'openings' => 'Floor and wall openings covered', 'watch' => 'Fire watch briefed for the work and 60 min after']],
        'height' => ['Work at height (above 2 m)', 12, ['equipment' => 'Scaffold / MEWP / ladder inspected and tagged', 'harness' => 'Harness and lanyard worn and anchored', 'area' => 'Area below barricaded and signed', 'weather' => 'Weather / wind checked (outdoors)', 'rescue' => 'Rescue plan agreed']],
        'confined' => ['Confined space (tanks, pits, ducts)', 8, ['isolated' => 'Space isolated – valves and power locked off', 'ventilated' => 'Space ventilated', 'gas' => 'Atmosphere tested (O₂ 19.5–23.5 %)', 'attendant' => 'Attendant stationed at the entry', 'rescue' => 'Rescue equipment and plan ready']],
        'electrical' => ['Electrical isolation (LOTO)', 12, ['isolated' => 'Circuit isolated at the source', 'locked' => 'Lock and tag applied by each worker', 'proved' => 'Dead proven with a tester', 'ppe' => 'Insulated tools and PPE', 'notice' => 'Affected areas / guests informed']],
        'roof' => ['Roof / façade / sign work', 12, ['access' => 'Safe access route agreed', 'edge' => 'Edge protection or harness', 'tools' => 'Tools tethered, area below closed', 'weather' => 'Weather / wind checked']],
    ];

    public const STATUSES = ['requested' => 'Requested', 'issued' => 'Issued – work may proceed', 'closed' => 'Closed out', 'cancelled' => 'Cancelled'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'issued_at' => 'datetime', 'closed_at' => 'datetime', 'precautions' => 'array', 'gas_o2' => 'float'];
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function ticket()
    {
        return $this->belongsTo(MaintenanceRequest::class, 'maintenance_request_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type][0] ?? ucfirst($this->type);
    }

    public function precautionList(): array
    {
        return self::TYPES[$this->type][2] ?? [];
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    /** Issued but its time is up and nobody has closed it out. */
    public function isOverdue(): bool
    {
        return $this->status === 'issued' && $this->ends_at->lt(now());
    }
}
