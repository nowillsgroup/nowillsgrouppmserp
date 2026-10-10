<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class SurveyQuestion extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    public const TYPES = ['nps' => 'Recommend score (0-10, NPS)', 'rating' => 'Rating (1-5 stars)', 'yesno' => 'Yes / No', 'choice' => 'Multiple choice', 'text' => 'Free text'];

    protected function casts(): array
    {
        return ['options' => 'array', 'required' => 'boolean', 'is_active' => 'boolean'];
    }

    public function survey()
    {
        return $this->belongsTo(Survey::class);
    }

    public function answers()
    {
        return $this->hasMany(FeedbackAnswer::class, 'question_id');
    }

    /** Validation rule for the public form field. */
    public function rule(): array
    {
        $base = [$this->required ? 'required' : 'nullable'];

        return array_merge($base, match ($this->type) {
            'nps' => ['integer', 'between:0,10'],
            'rating' => ['integer', 'between:1,5'],
            'yesno' => ['in:0,1'],
            'choice' => ['string', 'in:'.implode(',', array_map(fn ($o) => str_replace(',', '', $o), $this->options ?? []))],
            default => ['string', 'max:2000'],
        });
    }
}
