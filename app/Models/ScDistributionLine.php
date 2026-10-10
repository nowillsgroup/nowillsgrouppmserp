<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class ScDistributionLine extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['excluded' => 'boolean'];
    }

    public function distribution()
    {
        return $this->belongsTo(ScDistribution::class, 'sc_distribution_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function payslipLine()
    {
        return $this->belongsTo(PayslipLine::class);
    }
}
