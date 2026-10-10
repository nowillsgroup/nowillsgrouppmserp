<?php

namespace App\Mail;

use App\Models\Hotel;
use App\Models\OnlinePayment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PaymentRequest extends Mailable
{
    public function __construct(public OnlinePayment $payment, public Hotel $hotel) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Payment request '.$this->payment->pay_no.' – '.$this->hotel->name);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.payment-request');
    }
}
