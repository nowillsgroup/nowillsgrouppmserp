<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class FeedbackCaseEvent extends Model
{
    use BelongsToHotel;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'hotel_id'];

    public const LABELS = ['opened' => 'Case opened', 'assigned' => 'Assigned', 'started' => 'Guest acknowledged', 'note' => 'Note', 'contacted' => 'Guest contacted', 'resolved' => 'Resolved', 'closed' => 'Closed', 'reopened' => 'Re-opened', 'escalated' => 'SLA missed – escalated', 'updated' => 'Guest updated the feedback'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
