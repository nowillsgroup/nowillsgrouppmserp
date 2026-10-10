<?php

namespace App\Mail;

use App\Models\Hotel;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** v3.1 a personal message from staff to a guest (service-recovery follow-up). */
class GuestMessage extends Mailable
{
    public function __construct(public string $subjectLine, public string $text, public Hotel $hotel) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.guest-message');
    }
}
