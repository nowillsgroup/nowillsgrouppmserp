<?php

namespace App\Mail;

use App\Models\Folio;
use App\Models\Hotel;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class GuestInvoice extends Mailable
{
    public function __construct(public Folio $folio, public Hotel $hotel) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->folio->invoice_no ? "Invoice {$this->folio->invoice_no}" : "Folio statement {$this->folio->folio_no}")." – {$this->hotel->name}");
    }

    public function content(): Content
    {
        $tx = $this->folio->activeTransactions()->with('paymentMode')->orderBy('business_date')->orderBy('id')->get();

        return new Content(view: 'emails.invoice', with: ['tx' => $tx, 'totals' => $this->folio->totals()]);
    }
}
