<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.3 Keyword routing for incoming guest messages: team, urgency, guest request and / or an automatic answer. */
class InboxRule extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['urgent' => 'boolean', 'is_active' => 'boolean', 'last_hit_at' => 'datetime'];
    }

    /** @return string[] lower-case keywords / phrases */
    public function words(): array
    {
        return array_values(array_filter(array_map(fn ($w) => mb_strtolower(trim($w)), explode(',', (string) $this->keywords)), fn ($w) => $w !== ''));
    }

    public function matches(string $text): bool
    {
        $t = ' '.preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text)).' ';
        foreach ($this->words() as $w) {
            $w = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $w));
            if ($w !== '' && str_contains($t, ' '.$w.' ')) {
                return true;
            }
        }

        return false;
    }

    public function actionsLabel(): string
    {
        $a = [];
        if ($this->department) {
            $a[] = 'Route to '.(Conversation::DEPARTMENTS[$this->department] ?? $this->department);
        }
        if ($this->urgent) {
            $a[] = 'mark urgent';
        }
        if ($this->request_item) {
            $a[] = 'raise request “'.$this->request_item.'”';
        }
        if ($this->reply) {
            $a[] = 'auto-reply';
        }

        return $a ? ucfirst(implode(' · ', $a)) : '—';
    }
}
