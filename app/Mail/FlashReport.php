<?php

namespace App\Mail;

use App\Models\Hotel;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FlashReport extends Mailable
{
    public function __construct(public Hotel $hotel, public Carbon $date, public Collection $rows) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Flash report {$this->date->format('d M Y')} – {$this->hotel->name}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.flash');
    }
}
