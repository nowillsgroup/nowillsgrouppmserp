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
        return ['monthly_budget' => 'float', 'is_cost_centre' => 'boolean'];
    }

    /** v8.1 – the code printed next to the name in the ledger and the cost-centre reports. */
    public function label(): string
    {
        return trim(($this->code ? $this->code.' · ' : '').$this->name);
    }

    /** budget versions of this department (NULL on the version = the whole hotel) */
    public function budgets()
    {
        return $this->hasMany(Budget::class);
    }

    /** ledger lines tagged to this department */
    public function glLines()
    {
        return $this->hasMany(GlLine::class);
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
