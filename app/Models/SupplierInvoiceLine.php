<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierInvoiceLine extends Model
{
    protected $table = 'supplier_invoice_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'tax_rate' => 'decimal:2', 'tax_amount' => 'decimal:2'];
    }

    public function head()
    {
        return $this->belongsTo(AccountHead::class, 'account_head_id');
    }
}
