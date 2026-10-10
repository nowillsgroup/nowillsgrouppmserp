<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use BelongsToHotel;

    protected $table = 'departments';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['monthly_budget' => 'float'];
    }

    public function hod()
    {
        return $this->belongsTo(User::class, 'hod_user_id');
    }

    public function expenseHead()
    {
        return $this->belongsTo(AccountHead::class, 'expense_head_id');
    }

    public function defaultStore()
    {
        return $this->belongsTo(Store::class, 'default_store_id');
    }

    public function requisitions()
    {
        return $this->hasMany(Requisition::class);
    }

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }
}
