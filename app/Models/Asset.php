<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['active' => 'In use', 'in_repair' => 'Under repair', 'disposed' => 'Disposed'];

    public const TONES = ['active' => 'green', 'in_repair' => 'amber', 'disposed' => 'slate'];

    public const VIA = ['opening' => 'Opening balance (existing asset)', 'direct' => 'Paid directly (cash / bank)', 'bill' => 'Supplier bill'];

    public const DISPOSALS = ['sold' => 'Sold', 'scrapped' => 'Scrapped', 'lost' => 'Lost / stolen'];

    public const DEPARTMENTS = ['Rooms', 'Front Office', 'Housekeeping', 'F&B – Kitchen', 'F&B – Restaurant & Bar', 'Banquets', 'Engineering', 'Spa & Leisure', 'Administration', 'Transport'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['purchase_date' => 'date', 'dep_start' => 'date', 'dep_through' => 'date', 'warranty_until' => 'date', 'amc_until' => 'date', 'disposed_date' => 'date',
            'cost' => 'decimal:2', 'residual_value' => 'decimal:2', 'opening_acc_dep' => 'decimal:2', 'disposal_proceeds' => 'decimal:2', 'disposal_nbv' => 'decimal:2', 'wdv_rate' => 'decimal:2'];
    }

    public function category()
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function custodian()
    {
        return $this->belongsTo(Employee::class, 'custodian_id');
    }

    public function capexProject() // v5.8
    {
        return $this->belongsTo(CapexProject::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function bill()
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    public function paymentMode()
    {
        return $this->belongsTo(PaymentMode::class);
    }

    public function disposalMode()
    {
        return $this->belongsTo(PaymentMode::class, 'disposal_mode_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Depreciation lines from runs that are still posted. */
    public function depLines()
    {
        return $this->hasMany(AssetDepLine::class)->whereHas('run', fn ($q) => $q->where('status', 'posted'));
    }

    public function tickets()
    {
        return $this->hasMany(MaintenanceRequest::class);
    }

    public function schedules()
    {
        return $this->hasMany(PmSchedule::class);
    }

    /** Accumulated depreciation: brought in + every posted run (uses withSum('depLines', 'amount') when loaded). */
    public function accumulated(): float
    {
        $runs = array_key_exists('dep_lines_sum_amount', $this->attributes) ? (float) $this->dep_lines_sum_amount : (float) $this->depLines()->sum('amount');

        return round((float) $this->opening_acc_dep + $runs, 2);
    }

    public function nbv(): float
    {
        return round((float) $this->cost - $this->accumulated(), 2);
    }

    public function isDisposed(): bool
    {
        return $this->status === 'disposed';
    }

    public function placeLabel(): string
    {
        return trim(($this->room ? 'Room '.$this->room->room_no : '').($this->room && $this->location ? ' · ' : '').($this->location ?? ''));
    }

    public function warrantyState(): ?string
    {
        if (! $this->warranty_until) {
            return null;
        }
        $today = bdate();

        return $this->warranty_until->lt($today) ? 'expired' : ($this->warranty_until->lte($today->copy()->addDays(60)) ? 'expiring' : 'valid');
    }
}
