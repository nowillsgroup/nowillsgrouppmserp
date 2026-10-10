<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.9 One onboarding checklist item for a new starter. */
class TalentTask extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'done_at' => 'datetime'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function doer()
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    public function label(): string
    {
        return \App\Services\TalentService::ONBOARDING[$this->item][0] ?? $this->item;
    }
}
