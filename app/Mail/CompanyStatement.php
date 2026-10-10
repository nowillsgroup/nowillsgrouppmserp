<?php

namespace App\Mail;

use App\Models\Company;
use App\Models\Hotel;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** v2.7: statement of open city-ledger bills sent to a company / agent. */
class CompanyStatement extends Mailable
{
    public function __construct(public Company $company, public array $position, public Hotel $hotel) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Statement of account – '.$this->company->name.' – '.$this->hotel->name);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.company-statement');
    }
}
