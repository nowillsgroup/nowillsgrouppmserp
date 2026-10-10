<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.1 A card holder on a membership (the primary member, spouse, children, or a corporate employee). */
class ClubMember extends Model
{
    use BelongsToHotel;

    public const RELATIONS = ['primary' => 'Primary member', 'spouse' => 'Spouse / partner', 'child' => 'Child', 'dependant' => 'Dependant', 'employee' => 'Employee (corporate)'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['dob' => 'date', 'health_declared_at' => 'datetime', 'is_active' => 'boolean'];
    }

    public function membership()
    {
        return $this->belongsTo(ClubMembership::class, 'club_membership_id');
    }

    public function visits()
    {
        return $this->hasMany(ClubVisit::class);
    }

    public function initials(): string
    {
        return collect(preg_split('/\s+/', trim($this->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
    }

    public function age(): ?int
    {
        return $this->dob?->age;
    }
}
