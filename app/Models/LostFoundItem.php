<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class LostFoundItem extends Model
{
    use BelongsToHotel;

    protected $table = 'lost_found_items';

    protected $guarded = ['id', 'hotel_id'];

    /** v3.5 – key => [label, valuable by default] */
    public const CATEGORIES = ['electronics' => ['Electronics', false], 'jewellery' => ['Jewellery / watch', true], 'documents' => ['Passport / ID / documents', true],
        'cash' => ['Cash / cards / wallet', true], 'clothing' => ['Clothing / shoes', false], 'bags' => ['Bags / luggage', false], 'keys' => ['Keys', false],
        'toiletries' => ['Toiletries / cosmetics', false], 'perishable' => ['Food / perishable', false], 'other' => ['Other', false]];

    public const STATUSES = ['stored' => ['In store', 'blue'], 'returned' => ['Returned', 'green'], 'disposed' => ['Disposed', 'slate']];

    public const DISPOSAL = ['donated' => 'Donated to charity', 'destroyed' => 'Destroyed / discarded', 'police' => 'Handed to police', 'finder' => 'Given to the finder', 'staff_auction' => 'Staff auction'];

    public const RETURN_METHODS = ['in_person' => 'Collected in person', 'courier' => 'Sent by courier', 'police' => 'Handed to police'];

    protected function casts(): array
    {
        return [
            'found_date' => 'date',
            'returned_date' => 'date',
            'retain_until' => 'date',
            'disposed_date' => 'date',
            'valuable' => 'boolean',
            'est_value' => 'decimal:2',
            'courier_charge' => 'decimal:2',
        ];
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function finder()
    {
        return $this->belongsTo(User::class, 'found_by_user_id');
    }

    public function releaser()
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function disposer()
    {
        return $this->belongsTo(User::class, 'disposed_by');
    }

    public function witness()
    {
        return $this->belongsTo(User::class, 'disposal_witness_id');
    }

    public function report()
    {
        return $this->belongsTo(LostReport::class, 'lost_report_id');
    }

    public function isStored(): bool
    {
        return $this->status === 'stored';
    }

    /** retention period is over – may be disposed */
    public function isDue(): bool
    {
        return $this->isStored() && $this->retain_until && $this->retain_until->lte(bdate());
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category][0] ?? ucfirst((string) $this->category);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? $this->status;
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }

    public function label(): string
    {
        return ($this->item_no ? $this->item_no.' · ' : '').$this->item_name;
    }
}
