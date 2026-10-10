<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.3 One message in a guest conversation: from the guest (in), to the guest (out), an internal note or a system line. */
class ConversationMessage extends Model
{
    use BelongsToHotel;

    public const DIRECTIONS = ['in' => 'From guest', 'out' => 'To guest', 'note' => 'Internal note', 'system' => 'System'];

    public const STATUSES = ['received' => 'Received', 'sent' => 'Sent', 'delivered' => 'Delivered', 'read' => 'Read', 'failed' => 'Failed'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['auto' => 'boolean', 'read_at' => 'datetime'];
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function rule()
    {
        return $this->belongsTo(InboxRule::class, 'rule_id');
    }

    public function broadcast()
    {
        return $this->belongsTo(InboxBroadcast::class, 'broadcast_id');
    }

    public function authorLabel(): string
    {
        return match ($this->direction) {
            'in' => $this->conversation?->displayName() ?? 'Guest',
            'system' => 'System',
            default => $this->auto ? 'Automatic reply' : ($this->broadcast_id ? 'Broadcast' : ($this->user?->name ?? 'Hotel')),
        };
    }
}
