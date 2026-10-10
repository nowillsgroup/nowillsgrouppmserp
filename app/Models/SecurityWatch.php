<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.2 – a person barred from (block) or flagged for (alert) the property. ID numbers are kept only as a hash + last 4. */
class SecurityWatch extends Model
{
    use BelongsToHotel;

    protected $table = 'security_watchlist';

    protected $guarded = ['id', 'hotel_id'];

    public const LEVELS = ['block' => ['Barred – refuse entry', 'red'], 'alert' => ['Alert – allow, but inform security', 'amber']];

    protected function casts(): array
    {
        return ['valid_until' => 'date', 'is_active' => 'boolean'];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeCurrent($q)
    {
        return $q->where('is_active', true)->where(fn ($w) => $w->whereNull('valid_until')->orWhere('valid_until', '>=', bdate()->toDateString()));
    }

    public function isCurrent(): bool
    {
        return $this->is_active && (! $this->valid_until || $this->valid_until->gte(bdate()->copy()->startOfDay()));
    }
}
