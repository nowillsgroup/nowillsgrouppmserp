<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.0 Community investment: donation, in-kind support, staff volunteering, sponsorship, training or surplus-food donation. */
class EsgCommunity extends Model
{
    use BelongsToHotel;

    public const KINDS = ['donation' => 'Cash donation', 'in_kind' => 'In-kind support', 'volunteering' => 'Staff volunteering', 'sponsorship' => 'Sponsorship',
        'training' => 'Skills / training', 'food' => 'Surplus food donation'];

    protected $table = 'esg_community';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2', 'hours' => 'float'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
