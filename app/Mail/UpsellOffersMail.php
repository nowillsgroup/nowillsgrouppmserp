<?php

namespace App\Mail;

use App\Models\Hotel;
use App\Models\Reservation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/** v4.1 – pre-arrival "enhance your stay" e-mail with personalised offers. */
class UpsellOffersMail extends Mailable
{
    /** @param Collection $offers [['offer' => UpsellOffer, 'quote' => [...]], …] */
    public function __construct(public Reservation $reservation, public Hotel $hotel, public Collection $offers) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Make the most of your stay – {$this->reservation->arrival_date->format('d M')} at {$this->hotel->name}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.upsell-offers');
    }
}
