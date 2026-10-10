<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.1 a guest survey (the default one is sent after check-out). */
class Survey extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    public const CHANNELS = ['email' => 'E-mail', 'sms' => 'SMS / WhatsApp', 'both' => 'E-mail + SMS'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_default' => 'boolean'];
    }

    public function questions()
    {
        return $this->hasMany(SurveyQuestion::class)->orderBy('sort')->orderBy('id');
    }

    public function activeQuestions()
    {
        return $this->questions()->where('is_active', true);
    }

    public function invitations()
    {
        return $this->hasMany(SurveyInvitation::class);
    }

    public function responses()
    {
        return $this->hasMany(GuestFeedback::class);
    }
}
