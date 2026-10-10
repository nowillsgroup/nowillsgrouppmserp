<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class KotPrinter extends Model
{
    use BelongsToHotel;

    protected $table = 'kot_printers';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'beep' => 'boolean',
            'is_fallback' => 'boolean',
            'is_active' => 'boolean',
            'copies' => 'integer',
            'port' => 'integer',
        ];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function jobs()
    {
        return $this->hasMany(KotPrintJob::class);
    }

    public function categories()
    {
        return $this->hasMany(MenuCategory::class);
    }

    public function label(): string
    {
        $bits = [$this->name];
        if ($this->role === 'captain') {
            $bits[] = 'captain';
        } elseif ($this->station) {
            $bits[] = $this->station;
        }
        if ($this->connection === 'network' && $this->host) {
            $bits[] = $this->host;
        }

        return implode(' · ', $bits);
    }
}
