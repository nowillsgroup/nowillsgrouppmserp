<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.5 A standard as it was checked in one audit (snapshot of code, title, weight, critical flag). */
class QaAuditItem extends Model
{
    use BelongsToHotel;

    public const RESULTS = ['pass' => ['Pass', 'green'], 'fail' => ['Fail', 'red'], 'na' => ['N/A', 'slate']];

    public $timestamps = false;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['is_critical' => 'boolean', 'is_repeat' => 'boolean'];
    }

    public function audit()
    {
        return $this->belongsTo(QaAudit::class, 'qa_audit_id');
    }

    public function standard()
    {
        return $this->belongsTo(QaStandard::class, 'qa_standard_id');
    }

    public function action()
    {
        return $this->hasOne(QaAction::class, 'qa_audit_item_id');
    }
}
