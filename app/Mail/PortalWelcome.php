<?php

namespace App\Mail;

use App\Models\Hotel;
use App\Models\ReservationRoom;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PortalWelcome extends Mailable
{
    public function __construct(public ReservationRoom $stay, public Hotel $hotel) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your stay at {$this->hotel->name} – room service, requests & bill");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.portal');
    }
}
