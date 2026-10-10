<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use BelongsToHotel;

    protected $table = 'suppliers';

    protected $guarded = ['id', 'hotel_id'];

    /** v4.4: mirror the column default so a supplier created in the same request quotes WHT correctly. */
    protected $attributes = ['wht_category' => 'services'];

    protected function casts(): array
    {
        return ['wht_exempt_until' => 'date', 
            'is_active' => 'boolean',
            'is_local' => 'boolean', // v7.0 local sourcing
        ];
    }

    public function expenseHead()
    {
        return $this->belongsTo(AccountHead::class, 'expense_head_id');
    }

    public function invoices()
    {
        return $this->hasMany(SupplierInvoice::class);
    }

    public function payments()
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /** v4.4 */
    public function isNonResident(): bool
    {
        return $this->wht_category === 'nonresident';
    }
}
