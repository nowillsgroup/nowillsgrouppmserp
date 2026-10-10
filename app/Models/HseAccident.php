<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.7 Accident, injury, near miss or dangerous occurrence; statutory notice under Act 328 ss. 10–11 where it applies. */
class HseAccident extends Model
{
    use BelongsToHotel;

    public const KINDS = ['injury' => 'Accident / injury', 'near_miss' => 'Near miss', 'dangerous' => 'Dangerous occurrence'];

    public const SEVERITIES = ['near_miss' => 'No injury', 'first_aid' => 'First aid only', 'medical' => 'Medical treatment', 'lost_time' => 'Lost time', 'fatal' => 'Fatal'];

    public const PERSONS = ['staff' => 'Staff member', 'contractor' => 'Contractor', 'guest' => 'Guest', 'visitor' => 'Visitor'];

    public const INJURIES = ['slip' => 'Slip, trip or fall', 'height' => 'Fall from height', 'cut' => 'Cut / laceration', 'burn' => 'Burn / scald', 'strain' => 'Manual handling / strain',
        'struck' => 'Struck by / against', 'electric' => 'Electric shock', 'chemical' => 'Chemical contact', 'food' => 'Food-borne illness', 'assault' => 'Assault', 'other' => 'Other'];

    public const BODY = ['head' => 'Head / face', 'eye' => 'Eye', 'neck' => 'Neck', 'back' => 'Back', 'trunk' => 'Chest / abdomen', 'arm' => 'Arm / shoulder', 'hand' => 'Hand / fingers',
        'leg' => 'Leg / knee', 'foot' => 'Foot / ankle', 'multiple' => 'Multiple', 'internal' => 'Internal / illness'];

    public const OCCURRENCES = ['fire' => 'Fire', 'explosion' => 'Explosion', 'collapse' => 'Collapse of a building or structure', 'lift' => 'Failure of a lift, hoist or crane', 'machinery' => 'Machinery / plant accident', 'pressure' => 'Bursting of a pressure vessel'];

    public const STATUSES = ['open' => 'Open', 'investigating' => 'Under investigation', 'closed' => 'Closed'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'returned_on' => 'date', 'notified_on' => 'date', 'closed_at' => 'datetime', 'reportable' => 'boolean'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function incident()
    {
        return $this->belongsTo(SecurityIncident::class, 'security_incident_id');
    }

    public function ticket()
    {
        return $this->belongsTo(MaintenanceRequest::class, 'maintenance_request_id');
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function personLabel(): string
    {
        return $this->employee?->name ?? $this->guest?->full_name ?? $this->person_name ?? '—';
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? ucfirst($this->kind);
    }

    public function severityLabel(): string
    {
        return self::SEVERITIES[$this->severity] ?? ucfirst($this->severity);
    }

    public function isLostTime(): bool
    {
        return in_array($this->severity, ['lost_time', 'fatal'], true);
    }
}
