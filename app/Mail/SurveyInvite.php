<?php

namespace App\Mail;

use App\Models\Hotel;
use App\Models\SurveyInvitation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** v3.1 post-stay survey invitation / reminder. */
class SurveyInvite extends Mailable
{
    public function __construct(public SurveyInvitation $inv, public Hotel $hotel, public string $url, public bool $reminder = false) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->reminder ? 'Reminder: ' : '')."How was your stay at {$this->hotel->name}?");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.survey');
    }
}
