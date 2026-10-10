<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.8 – a kind of gift voucher / stored-value card the hotel sells or gives away. */
class GiftVoucherType extends Model
{
    use BelongsToHotel;

    public const KINDS = ['value' => 'Gift card – spend in parts', 'single' => 'Experience – single use', 'stored' => 'Stored-value card – reloadable'];

    public const USES = ['folio' => 'Room bill (folio)', 'pos' => 'Restaurant & bar (POS)'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['face_value' => 'float', 'min_value' => 'float', 'max_value' => 'float', 'usable_on' => 'array', 'is_active' => 'boolean'];
    }

    public function vouchers()
    {
        return $this->hasMany(GiftVoucher::class);
    }

    public function usableOn(string $where): bool
    {
        return in_array($where, $this->usable_on ?: array_keys(self::USES), true);
    }

    public function usesLabel(): string
    {
        return implode(' · ', array_map(fn ($u) => self::USES[$u] ?? $u, $this->usable_on ?: array_keys(self::USES)));
    }

    public function valueLabel(): string
    {
        return $this->face_value ? money($this->face_value) : 'Any amount'.($this->min_value || $this->max_value ? ' ('.money($this->min_value).' – '.($this->max_value ? money($this->max_value) : '…').')' : '');
    }
}
