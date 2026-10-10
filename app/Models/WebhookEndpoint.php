<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.0 – a URL that receives signed event notifications. */
class WebhookEndpoint extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected $hidden = ['secret', 'previous_secret'];

    protected function casts(): array
    {
        return ['events' => 'array', 'secret' => 'encrypted', 'previous_secret' => 'encrypted', 'previous_expires_at' => 'datetime', 'is_active' => 'boolean',
            'disabled_at' => 'datetime', 'last_success_at' => 'datetime', 'last_failure_at' => 'datetime', 'consecutive_failures' => 'integer'];
    }

    public function deliveries()
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function listensTo(string $event): bool
    {
        $ev = $this->events ?? [];

        return in_array('*', $ev, true) || in_array($event, $ev, true);
    }

    public function state(): string
    {
        return $this->is_active ? ($this->consecutive_failures > 0 ? 'failing' : 'active') : ($this->disabled_reason ? 'disabled' : 'paused');
    }

    public function stateColor(): string
    {
        return ['active' => 'green', 'failing' => 'amber', 'disabled' => 'red', 'paused' => 'slate'][$this->state()];
    }

    public function host(): string
    {
        return (string) parse_url($this->url, PHP_URL_HOST);
    }
}
