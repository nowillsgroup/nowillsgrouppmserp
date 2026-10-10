<?php

namespace App\Mail;

use App\Models\Hotel;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Campaign / automation e-mail. Carries RFC 2369 + RFC 8058 List-Unsubscribe headers so mail clients can offer
 * a one-click unsubscribe button.
 */
class MarketingMail extends Mailable
{
    public function __construct(public string $subjectLine, public string $text, public ?string $ctaLabel, public ?string $ctaUrl,
        public string $unsubscribeUrl, public Hotel $hotel) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl.'/one-click>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.marketing');
    }

    /** Escaped paragraphs with clickable links – the body is plain text written by staff. */
    public static function renderBody(string $text): string
    {
        return collect(preg_split("/\n\s*\n/", trim($text)))->map(function ($p) {
            $p = nl2br(e(trim($p)));
            $p = preg_replace('~(https?://[^\s<]+)~', '<a href="$1" style="color:#1d4ed8">$1</a>', $p);
            $p = preg_replace('~\*\*(.+?)\*\*~', '<b>$1</b>', $p);

            return '<p style="margin:0 0 12px;line-height:1.55">'.$p.'</p>';
        })->implode('');
    }
}
