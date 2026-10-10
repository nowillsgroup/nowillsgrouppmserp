<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.9 – history of a compliance item: renewal applied / renewed / inspected / document / archived. */
class ComplianceEvent extends Model
{
    use BelongsToHotel;

    public const TYPES = ['created' => 'Added to register', 'applied' => 'Renewal applied', 'renewed' => 'Renewed', 'inspected' => 'Inspection done',
        'document' => 'Document uploaded', 'archived' => 'Archived', 'restored' => 'Restored', 'note' => 'Note', 'alert' => 'Alert sent'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['event_date' => 'date', 'new_expiry' => 'date', 'fee' => 'float'];
    }

    public function item()
    {
        return $this->belongsTo(ComplianceItem::class, 'compliance_item_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
