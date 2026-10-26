<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.9 An interview round with its panel; each panel member files one scorecard. */
class TalentInterview extends Model
{
    use BelongsToHotel;

    public const KINDS = ['phone' => 'Phone screen', 'video' => 'Video call', 'in_person' => 'In person', 'trade_test' => 'Trade test / practical'];

    public const STATUSES = ['scheduled' => 'Scheduled', 'done' => 'Held', 'no_show' => 'No-show', 'cancelled' => 'Cancelled'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'panel' => 'array'];
    }

    public function applicant()
    {
        return $this->belongsTo(TalentApplicant::class, 'applicant_id');
    }

    public function scorecards()
    {
        return $this->hasMany(TalentScorecard::class, 'interview_id');
    }

    public function panelUsers()
    {
        return User::visibleToHotel()->whereIn('id', $this->panel ?? [])->orderBy('name')->get(['id', 'name']);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function average(): ?float
    {
        $s = $this->relationLoaded('scorecards') ? $this->scorecards : $this->scorecards()->get();

        return $s->isEmpty() ? null : round((float) $s->avg('average'), 2);
    }
}
