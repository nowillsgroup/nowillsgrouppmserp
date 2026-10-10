<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class BanquetEvent extends Model
{
    use BelongsToHotel;

    protected $table = 'banquet_events';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['event_date' => 'date', 'end_date' => 'date', 'option_date' => 'date', 'deposit_due_date' => 'date',
            'confirmed_at' => 'datetime', 'closed_at' => 'datetime', 'billed_at' => 'datetime',
            'deposit_due' => 'decimal:2', 'cancellation_fee' => 'decimal:2', 'rate_per_pax' => 'decimal:2', 'hall_charge' => 'decimal:2',
            'other_charges' => 'decimal:2', 'advance' => 'decimal:2', 'tax_rate' => 'decimal:2',
            'portal_enabled' => 'boolean', 'beo_published_at' => 'datetime', 'beo_snapshot' => 'array', 'beo_approved_at' => 'datetime'];
    }

    public const STATUSES = ['enquiry' => 'Enquiry', 'tentative' => 'Tentative', 'definite' => 'Definite', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'lost' => 'Lost'];

    public const TONES = ['enquiry' => 'slate', 'tentative' => 'amber', 'definite' => 'green', 'completed' => 'blue', 'cancelled' => 'red', 'lost' => 'slate'];

    /** Statuses that hold the venue. */
    public const HOLDS = ['tentative', 'definite'];

    public const TYPES = ['wedding' => 'Wedding', 'reception' => 'Reception', 'engagement' => 'Engagement', 'conference' => 'Conference', 'meeting' => 'Meeting',
        'seminar' => 'Seminar / training', 'launch' => 'Product launch', 'birthday' => 'Birthday', 'party' => 'Party', 'gala' => 'Gala dinner', 'other' => 'Other'];

    public const SOURCES = ['phone' => 'Phone', 'email' => 'E-mail', 'walk_in' => 'Walk-in', 'website' => 'Website', 'agent' => 'Agent / planner', 'repeat' => 'Repeat client', 'referral' => 'Referral'];

    public function functions()
    {
        return $this->hasMany(BanquetFunction::class)->orderBy('date')->orderBy('start_time')->orderBy('id');
    }

    public function salesUser()
    {
        return $this->belongsTo(User::class, 'sales_user_id');
    }

    public function roomBlock()
    {
        return $this->belongsTo(RoomBlock::class);
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function statusBadge(): string
    {
        return '<span class="badge badge-'.(self::TONES[$this->status] ?? 'slate').'">'.e(self::STATUSES[$this->status] ?? ucfirst((string) $this->status)).'</span>';
    }

    public function net(): float
    {
        return round($this->functions->sum(fn ($f) => $f->net()), 2);
    }

    public function tax(): float
    {
        return round($this->functions->sum(fn ($f) => $f->tax()), 2);
    }

    public function total(): float
    {
        return round($this->net() + $this->tax(), 2);
    }

    public function pax(): int
    {
        return (int) $this->functions->max(fn ($f) => $f->billablePax());
    }

    /** Deposits received on the event folio, net of refunds (voided lines ignored). */
    public function depositsReceived(): float
    {
        if (! $this->folio_id) {
            return 0.0;
        }

        return round((float) FolioTransaction::where('folio_id', $this->folio_id)->where('is_void', false)->where('category', 'advance')
            ->selectRaw("SUM(CASE WHEN type='refund' THEN -amount ELSE amount END) s")->value('s'), 2);
    }

    public function optionExpired(): bool
    {
        return $this->status === 'tentative' && $this->option_date && $this->option_date->lt(bdate());
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['enquiry', 'tentative', 'definite'], true);
    }

    public function isEditable(): bool
    {
        return $this->isOpen() && ! $this->billed_at;
    }

    /** Definite, not billed, and every function has taken place (or is today). */
    public function readyToBill(): bool
    {
        return $this->status === 'definite' && ! $this->billed_at && $this->end_date && $this->end_date->lte(bdate());
    }

    public function hall()
    {
        return $this->belongsTo(BanquetHall::class, 'banquet_hall_id');
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function folio()
    {
        return $this->belongsTo(Folio::class);
    }

    /** v7.5 organiser portal: requests, guest list and the BEO publisher. */
    public function organiserRequests()
    {
        return $this->hasMany(OrganiserRequest::class, 'banquet_event_id');
    }

    public function attendees()
    {
        return $this->hasMany(OrganiserAttendee::class, 'banquet_event_id')->orderBy('name');
    }

    public function beoPublisher()
    {
        return $this->belongsTo(User::class, 'beo_published_by');
    }

    /** none (never published), awaiting (published, organiser must sign), signed. */
    public function beoStatus(): string
    {
        return ! $this->beo_published_at ? 'none' : ($this->beo_approved_at ? 'signed' : 'awaiting');
    }
}
