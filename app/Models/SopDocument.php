<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** v6.0 A standard operating procedure: a draft body being edited plus its published versions and staff sign-offs. */
class SopDocument extends Model
{
    use BelongsToHotel;

    public const CATEGORIES = ['operations' => 'Operations', 'guest_service' => 'Guest service', 'safety' => 'Health & safety', 'security' => 'Security',
        'food_safety' => 'Food safety', 'finance' => 'Cash & finance', 'hr' => 'People & HR', 'it_privacy' => 'IT & data privacy', 'emergency' => 'Emergency'];

    public const STATUSES = ['draft' => ['Draft', 'slate'], 'published' => ['Published', 'green'], 'retired' => ['Retired', 'red']];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['ack_departments' => 'array', 'requires_ack' => 'boolean', 'has_draft' => 'boolean', 'effective_date' => 'date', 'review_due' => 'date', 'last_reviewed' => 'date'];
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function versions()
    {
        return $this->hasMany(SopVersion::class)->orderByDesc('version');
    }

    public function current()
    {
        return $this->hasOne(SopVersion::class)->ofMany('version', 'max');
    }

    public function acknowledgements()
    {
        return $this->hasMany(SopAcknowledgement::class);
    }

    /** the version staff must have signed: the latest version flagged "re-acknowledge" (earlier signatures on minor edits still count) */
    public function ackVersion(): int
    {
        if (! $this->version) {
            return 0;
        }

        return (int) ($this->versions()->where('reack', true)->max('version') ?: $this->version);
    }

    /** department ids whose staff must sign; null = everyone */
    public function ackScope(): ?array
    {
        if ($this->ack_departments) {
            return array_map('intval', $this->ack_departments);
        }

        return $this->department_id ? [(int) $this->department_id] : null;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function statusColor(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst($this->category);
    }

    public function reviewOverdue(): bool
    {
        return $this->status === 'published' && $this->review_due && $this->review_due->lt(bdate());
    }

    public static function render(?string $md): string
    {
        return Str::markdown((string) $md, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }
}
