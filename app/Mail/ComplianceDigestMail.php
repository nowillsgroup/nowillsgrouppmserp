<?php

namespace App\Mail;

use App\Models\Hotel;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** v3.9: licences / permits / inspections due for renewal or already expired. */
class ComplianceDigestMail extends Mailable
{
    /** @param array $due  items entering the renewal window  @param array $expired  items past expiry */
    public function __construct(public Hotel $hotel, public $due, public $expired) {}

    public function envelope(): Envelope
    {
        $n = count($this->expired);

        return new Envelope(subject: ($n ? "⚠ {$n} expired · " : '').count($this->due).' licence / compliance item(s) due for renewal – '.$this->hotel->name);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.compliance-digest');
    }
}
