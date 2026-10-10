<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.3 An exception raised by an income-audit check and how the auditor dealt with it. */
class AuditFinding extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['open' => 'Open', 'explained' => 'Explained', 'corrected' => 'Corrected', 'escalated' => 'Escalated', 'accepted' => 'Accepted', 'cleared' => 'Cleared'];

    /** Statuses that still stop the day being signed. */
    public const UNRESOLVED = ['open', 'escalated'];

    public const SEVERITIES = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['expected' => 'decimal:2', 'actual' => 'decimal:2', 'amount' => 'decimal:2', 'resolved_at' => 'datetime'];
    }

    public function day()
    {
        return $this->belongsTo(AuditDay::class, 'audit_day_id');
    }

    public function authorisation()
    {
        return $this->belongsTo(RevenueAuthorisation::class, 'revenue_authorisation_id');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isResolved(): bool
    {
        return ! in_array($this->status, self::UNRESOLVED, true);
    }

    /** Link to the source document, when there is one. */
    public function link(): ?string
    {
        return match ($this->ref_type) {
            'stay' => ($s = ReservationRoom::find($this->ref_id)) ? route('reservations.show', $s->reservation_id) : null,
            'folio' => route('folios.show', $this->ref_id),
            'pos_order' => route('pos.bill', $this->ref_id),
            default => null,
        };
    }
}
