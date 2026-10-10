<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class GuestDocument extends Model
{
    use BelongsToHotel;

    protected $table = 'guest_documents';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public const TYPES = ['id_front' => 'ID – front', 'id_back' => 'ID – back', 'passport' => 'Passport – photo page', 'visa' => 'Visa / permit', 'photo' => 'Guest photo', 'other' => 'Other'];

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
