<?php

namespace App\Mail;

use App\Models\GiftVoucher;
use App\Models\Hotel;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** v3.8: a gift voucher sent to its recipient (or a reminder that it is about to expire). */
class GiftVoucherMail extends Mailable
{
    public function __construct(public GiftVoucher $voucher, public Hotel $hotel, public bool $reminder = false) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->reminder
            ? 'Your '.$this->hotel->name.' gift voucher expires on '.$this->voucher->expires_on->format('d M Y')
            : ($this->voucher->recipient_name ? $this->voucher->recipient_name.', a' : 'A').' gift voucher from '.($this->voucher->purchaser_name ?: $this->hotel->name));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.gift-voucher');
    }
}
