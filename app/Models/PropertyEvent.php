<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * v10.0 property audit trail: who created, edited, activated, suspended, archived or granted access
 * to a property, and when. Deliberately not tenant-scoped — a decision taken on a property must stay
 * readable from the group's side even if that property is later archived, and it is written while the
 * acting user is standing in a *different* property's tenant context.
 */
class PropertyEvent extends Model
{
    protected $table = 'property_events';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }

    public function group()
    {
        return $this->belongsTo(HotelGroup::class, 'hotel_group_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function record(string $action, string $summary, ?Hotel $hotel = null, array $details = []): self
    {
        return self::create([
            'hotel_id' => $hotel?->id,
            'hotel_group_id' => $hotel?->hotel_group_id ?? (\App\Support\Tenant::hotel()?->hotel_group_id),
            'user_id' => auth()->id(),
            'action' => $action,
            'summary' => $summary,
            'details' => $details ?: null,
        ]);
    }

    public function actionLabel(): string
    {
        return ucfirst(str_replace('_', ' ', $this->action));
    }

    public function tone(): string
    {
        return match ($this->action) {
            'created', 'activated', 'access_granted', 'resumed' => 'green',
            'suspended', 'access_revoked', 'archived' => 'amber',
            default => 'slate',
        };
    }
}
