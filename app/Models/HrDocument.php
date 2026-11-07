<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Services\EmployeeSelfService;
use Illuminate\Database\Eloquent\Model;

/** v7.40 A staff-uploaded document (ID, certificate, contract …) awaiting HR verification. */
class HrDocument extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public const STATUSES = ['new' => ['New', 'blue'], 'verified' => ['Verified', 'green'], 'rejected' => ['Rejected', 'red']];

    /** Who put this on file: the member of staff, or HR on their behalf. */
    public function sourceLabel(): string
    {
        return $this->source === 'hr' ? 'HR' : 'Staff';
    }

    public function isImage(): bool
    {
        return in_array((string) $this->mime, ['image/jpeg', 'image/png', 'image/webp'], true);
    }

    public function sizeLabel(): string
    {
        $b = (int) $this->bytes;
        if ($b >= 1048576) {
            return rtrim(rtrim(number_format($b / 1048576, 1), '0'), '.').' MB';
        }

        return max(1, (int) round($b / 1024)).' KB';
    }

    public function kindLabel(): string
    {
        return EmployeeSelfService::DOC_KINDS[$this->kind] ?? ucfirst((string) ($this->kind ?: 'Document'));
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }
}
