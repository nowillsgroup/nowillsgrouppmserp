<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.8 Request for quotation sent to several suppliers; quotes are compared and awarded line by line. */
class Rfq extends Model
{
    use BelongsToHotel;

    public const OPEN = ['draft', 'sent', 'closed'];

    protected $guarded = ['id', 'hotel_id'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['rfq_date' => 'date', 'respond_by' => 'date', 'needed_by' => 'date', 'sent_at' => 'datetime', 'closed_at' => 'datetime', 'awarded_at' => 'datetime'];
    }

    public function lines()
    {
        return $this->hasMany(RfqLine::class)->orderBy('id');
    }

    public function invites()
    {
        return $this->hasMany(RfqSupplier::class)->orderBy('id');
    }

    public function quotes()
    {
        return $this->hasMany(RfqQuote::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function awarder()
    {
        return $this->belongsTo(User::class, 'awarded_by');
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /** Suppliers can still submit / revise quotes: sent and the deadline (end of day, hotel time) not passed. */
    public function acceptingQuotes(): bool
    {
        return $this->status === 'sent' && bdate()->toDateString() <= $this->respond_by->toDateString();
    }

    public function deadlinePassed(): bool
    {
        return bdate()->toDateString() > $this->respond_by->toDateString();
    }
}
