<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class FeedbackAnswer extends Model
{
    use BelongsToHotel;

    public $timestamps = false;

    protected $guarded = ['id', 'hotel_id'];

    public function question()
    {
        return $this->belongsTo(SurveyQuestion::class, 'question_id');
    }

    public function feedback()
    {
        return $this->belongsTo(GuestFeedback::class, 'feedback_id');
    }

    public function display(): string
    {
        return match ($this->question?->type) {
            'rating' => $this->value_int ? str_repeat('★', $this->value_int).str_repeat('☆', 5 - $this->value_int) : '–',
            'yesno' => $this->value_int === null ? '–' : ($this->value_int ? 'Yes' : 'No'),
            'nps' => $this->value_int === null ? '–' : $this->value_int.' / 10',
            default => (string) ($this->value_text ?? '–'),
        };
    }
}
