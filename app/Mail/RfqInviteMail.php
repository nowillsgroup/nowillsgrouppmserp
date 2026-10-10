<?php

namespace App\Mail;

use App\Models\Hotel;
use App\Models\Rfq;
use App\Models\RfqSupplier;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** v4.8: request for quotation with the supplier's private quote link. */
class RfqInviteMail extends Mailable
{
    public function __construct(public Rfq $rfq, public RfqSupplier $invite, public Hotel $hotel) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Request for quotation {$this->rfq->rfq_no} – {$this->rfq->title} – {$this->hotel->name}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rfq-invite', with: ['link' => route('rfq.public', $this->invite->token), 'lines' => $this->rfq->lines()->with('item')->get()]);
    }
}
