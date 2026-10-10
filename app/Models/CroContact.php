<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.4 A follow-up contact (call, e-mail, WhatsApp, note) on a reservations enquiry. */
class CroContact extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    public function enquiry()
    {
        return $this->belongsTo(CroEnquiry::class, 'cro_enquiry_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function typeLabel(): string
    {
        return CroEnquiry::CONTACT_TYPES[$this->type] ?? ucfirst($this->type);
    }
}
