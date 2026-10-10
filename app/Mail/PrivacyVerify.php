<?php

namespace App\Mail;

use App\Models\Hotel;
use App\Models\PrivacyRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PrivacyVerify extends Mailable
{
    public function __construct(public PrivacyRequest $req, public Hotel $hotel, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Confirm your privacy request {$this->req->request_no} – {$this->hotel->name}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.privacy-verify');
    }
}
