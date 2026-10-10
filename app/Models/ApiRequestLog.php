<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.0 – one REST API call (who, what, result, how long). Pruned after `api_log_days`. */
class ApiRequestLog extends Model
{
    use BelongsToHotel;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => 'integer', 'duration_ms' => 'integer'];
    }

    public function client()
    {
        return $this->belongsTo(ApiClient::class, 'api_client_id');
    }

    public function statusColor(): string
    {
        return $this->status >= 500 ? 'red' : ($this->status >= 400 ? 'amber' : 'green');
    }
}
