<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.0 A requirement on the eco-certification self-assessment (Green Key / EarthCheck style) with its status and evidence. */
class EsgChecklistItem extends Model
{
    use BelongsToHotel;

    public const SECTIONS = ['management' => 'Environmental management', 'staff' => 'Staff involvement', 'guests' => 'Guest information', 'energy' => 'Energy',
        'water' => 'Water', 'waste' => 'Waste', 'purchasing' => 'Purchasing & food', 'chemicals' => 'Cleaning & chemicals', 'social' => 'Social & community'];

    public const STATUSES = ['met' => ['Met', 'green'], 'partial' => ['Partly met', 'amber'], 'not' => ['Not met', 'red'], 'na' => ['Not applicable', 'slate']];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['is_mandatory' => 'boolean', 'reviewed_on' => 'date'];
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
