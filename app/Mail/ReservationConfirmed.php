<?php

namespace App\Mail;

use App\Models\Hotel;
use App\Models\Reservation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ReservationConfirmed extends Mailable
{
    public function __construct(public Reservation $reservation, public Hotel $hotel) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->reservation->status === 'cancelled' ? 'Booking cancelled' : 'Booking confirmation')
            ." {$this->reservation->reservation_no} – {$this->hotel->name}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.reservation');
    }
}
