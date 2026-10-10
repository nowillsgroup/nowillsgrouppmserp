<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class GuestRequest extends Model
{
    use BelongsToHotel;

    protected $table = 'guest_requests';

    protected $guarded = ['id', 'hotel_id'];

    /** key => [label, icon, default items] */
    public const CATEGORIES = [
        'housekeeping' => ['Housekeeping', 'broom', ['Clean my room now', 'Turn-down service', 'Change bed linen', 'Collect laundry', 'Remove room-service tray']],
        'amenity' => ['Amenities', 'box', ['Extra towels', 'Extra pillows', 'Extra blanket', 'Toiletries', 'Drinking water', 'Iron & board', 'Baby cot', 'Phone charger']],
        'maintenance' => ['Something is not working', 'tool', ['Air-conditioning', 'TV / remote', 'Wi-Fi', 'Hot water', 'Lights / power', 'Plumbing / leak', 'Door / lock', 'Other']],
        'laundry' => ['Laundry', 'shirt', ['Pick up my laundry', 'Express laundry (same day)', 'Pressing only']],
        'wakeup' => ['Wake-up call', 'clock', ['Wake-up call']],
        'transport' => ['Transport', 'globe', ['Airport drop', 'Taxi', 'City tour']],
        'late_checkout' => ['Late check-out', 'moon', ['Late check-out']],
        'checkout' => ['Express check-out', 'logout', ['Express check-out']],
        'front_desk' => ['Message the front desk', 'message', ['Message']],
    ];

    public const STATUSES = ['new' => 'New', 'acknowledged' => 'Acknowledged', 'in_progress' => 'In progress', 'done' => 'Done', 'declined' => 'Declined', 'cancelled' => 'Cancelled'];

    public const OPEN = ['new', 'acknowledged', 'in_progress'];

    protected function casts(): array
    {
        return ['preferred_at' => 'datetime', 'acknowledged_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function handler()
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category][0] ?? ucfirst($this->category);
    }

    /** Minutes since the request was raised (until acknowledged, for SLA). */
    public function waitingMinutes(): int
    {
        return (int) $this->created_at->diffInMinutes($this->acknowledged_at ?? now());
    }

    public function isOverdue(): bool
    {
        return $this->status === 'new' && $this->waitingMinutes() >= max(1, (int) setting('portal_sla_minutes', 15));
    }

    public function linked(): ?Model
    {
        return match ($this->linked_type) {
            'MaintenanceRequest' => MaintenanceRequest::find($this->linked_id),
            'HousekeepingTask' => HousekeepingTask::find($this->linked_id),
            default => null,
        };
    }
}
