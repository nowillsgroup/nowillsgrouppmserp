<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.5 An audit checklist built from standards, with a frequency and a pass mark. */
class QaTemplate extends Model
{
    use BelongsToHotel;

    public const TYPES = ['internal' => 'Internal audit', 'walkthrough' => 'Department walk-through', 'mystery' => 'Mystery guest', 'brand' => 'Brand / LQA audit'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['pass_mark' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function standards()
    {
        return $this->belongsToMany(QaStandard::class, 'qa_template_items')->withPivot('sort')->orderBy('qa_template_items.sort');
    }

    public function audits()
    {
        return $this->hasMany(QaAudit::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }
}
