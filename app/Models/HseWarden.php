<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.7 A fire warden, chief warden, first aider or fire-team member on a shift, with training validity. */
class HseWarden extends Model
{
    use BelongsToHotel;

    public const ROLES = ['chief' => 'Chief fire warden', 'floor' => 'Floor / area warden', 'first_aider' => 'First aider', 'fire_team' => 'Fire team'];

    public const SHIFTS = ['morning' => 'Morning (06–14)', 'evening' => 'Evening (14–22)', 'night' => 'Night (22–06)'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['trained_on' => 'date', 'trained_until' => 'date', 'is_active' => 'boolean'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? ucfirst($this->role);
    }

    public function trainingValid(?string $today = null): bool
    {
        return $this->trained_until !== null && $this->trained_until->toDateString() >= ($today ?? bdate()->toDateString());
    }
}
