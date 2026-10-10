<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** v3.3 a hotel vehicle on the transport desk. */
class Vehicle extends Model
{
    use BelongsToHotel;

    public const TYPES = ['sedan' => 'Sedan', 'suv' => 'SUV / MUV', 'van' => 'Van', 'minibus' => 'Mini-bus (Tempo Traveller)', 'coach' => 'Coach', 'luxury' => 'Luxury car', 'buggy' => 'Buggy / e-cart'];

    public const FUELS = ['diesel' => 'Diesel', 'petrol' => 'Petrol', 'cng' => 'CNG', 'electric' => 'Electric', 'hybrid' => 'Hybrid'];

    /** compliance papers: column => label */
    public const PAPERS = ['insurance_until' => 'Insurance', 'permit_until' => 'Permit', 'fitness_until' => 'Fitness', 'puc_until' => 'PUC'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['insurance_until' => 'date', 'permit_until' => 'date', 'fitness_until' => 'date', 'puc_until' => 'date', 'is_active' => 'boolean'];
    }

    public function trips()
    {
        return $this->hasMany(TransportTrip::class);
    }

    public function expenses()
    {
        return $this->hasMany(VehicleExpense::class);
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function label(): string
    {
        return $this->code.' · '.$this->reg_no;
    }

    /** Papers that have expired on $date: [label => date]. */
    public function expiredPapers($date = null): array
    {
        $d = Carbon::parse($date ?? now())->startOfDay();
        $out = [];
        foreach (self::PAPERS as $col => $label) {
            if ($this->{$col} && $this->{$col}->lt($d)) {
                $out[$label] = $this->{$col};
            }
        }

        return $out;
    }

    /** Papers expiring within $days (not yet expired): [label => date]. */
    public function expiringPapers(int $days): array
    {
        $today = now()->startOfDay();
        $out = [];
        foreach (self::PAPERS as $col => $label) {
            if ($this->{$col} && $this->{$col}->gte($today) && $this->{$col}->lte($today->copy()->addDays($days))) {
                $out[$label] = $this->{$col};
            }
        }

        return $out;
    }
}
