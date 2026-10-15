<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.38 Sellable recreation product — access ticket, rental or service at the ticket outlet. */
class ClubTicketType extends Model
{
    use BelongsToHotel;

    public const CATEGORIES = ['access' => 'Access ticket', 'rental' => 'Rental / hire', 'service' => 'Service'];

    public const PAX_TYPES = ['adult' => 'Adults', 'child' => 'Children'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'tax_rate' => 'decimal:4', 'issues_pass' => 'boolean', 'is_active' => 'boolean'];
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }
}
