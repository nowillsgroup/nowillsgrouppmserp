<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.1 a review published on a review site / OTA, imported or keyed in, with the hotel's managed response. */
class OnlineReview extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    public const SOURCES = ['tripadvisor' => 'TripAdvisor', 'google' => 'Google', 'booking' => 'Booking.com', 'expedia' => 'Expedia', 'makemytrip' => 'MakeMyTrip', 'agoda' => 'Agoda', 'other' => 'Other'];

    public const STATUSES = ['new' => 'New', 'draft' => 'Draft', 'pending' => 'Awaiting approval', 'approved' => 'Approved – to post', 'posted' => 'Responded', 'ignored' => 'No response'];

    public const TRIP_TYPES = ['business' => 'Business', 'couple' => 'Couple', 'family' => 'Family', 'friends' => 'Friends', 'solo' => 'Solo'];

    protected function casts(): array
    {
        return ['review_date' => 'date', 'rating' => 'decimal:1', 'rating5' => 'decimal:1', 'aspects' => 'array',
            'drafted_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime'];
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function recoveryCase()
    {
        return $this->hasOne(FeedbackCase::class, 'review_id');
    }

    public function drafter()
    {
        return $this->belongsTo(User::class, 'drafted_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function poster()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function sourceName(): string
    {
        return self::SOURCES[$this->source] ?? ucfirst($this->source);
    }

    public function isLocked(): bool
    {
        return in_array($this->status, ['posted', 'ignored']);
    }

    /** Hours from import (or the review date when imported later) to the posted response. */
    public function responseHours(): ?float
    {
        if (! $this->posted_at) {
            return null;
        }
        $from = $this->created_at->max($this->review_date->copy()->startOfDay());

        return round(max(0, $from->diffInMinutes($this->posted_at)) / 60, 1);
    }
}
