<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.1 Sales collateral library: brochures, rate cards, fact sheets, presentations, menus, photos – a file and / or a link. */
class MarketingCollateral extends Model
{
    use BelongsToHotel;

    protected $table = 'marketing_collateral';

    public const KINDS = [
        'brochure' => 'Brochure', 'rate_card' => 'Rate card', 'fact_sheet' => 'Fact sheet', 'presentation' => 'Sales presentation', 'menu' => 'Banquet / F&B menu',
        'photo' => 'Photo pack', 'video' => 'Video', 'template' => 'Proposal / letter template', 'other' => 'Other',
    ];

    public const LANGUAGES = ['en' => 'English', 'fr' => 'French', 'zh' => 'Chinese', 'ar' => 'Arabic', 'de' => 'German', 'es' => 'Spanish'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['valid_until' => 'date', 'is_active' => 'boolean'];
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isExpired(): bool
    {
        return $this->valid_until && $this->valid_until->lt(bdate()->copy()->startOfDay());
    }
}
