<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.5 A brand / service standard: what "right" looks like, its weight and whether one failure fails the audit. */
class QaStandard extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['is_critical' => 'boolean', 'is_active' => 'boolean'];
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function sop()
    {
        return $this->belongsTo(SopDocument::class, 'sop_document_id');
    }

    public function templates()
    {
        return $this->belongsToMany(QaTemplate::class, 'qa_template_items')->withPivot('sort');
    }
}
