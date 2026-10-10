<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.0 – one event queued for one endpoint (the outbox row) and the outcome of its delivery attempts. */
class WebhookDelivery extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['pending' => ['Pending', 'blue'], 'success' => ['Delivered', 'green'], 'failed' => ['Failed', 'red']];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'attempt_log' => 'array', 'next_attempt_at' => 'datetime', 'delivered_at' => 'datetime', 'attempts' => 'integer',
            'response_code' => 'integer', 'duration_ms' => 'integer'];
    }

    public function endpoint()
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function statusColor(): string
    {
        return self::STATUSES[$this->status][1] ?? 'gray';
    }
}
