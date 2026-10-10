<?php

namespace App\Mail;

use App\Models\KpiAlert;
use App\Models\KpiAlertEvent;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** v5.4 KPI threshold breached. */
class KpiAlertMail extends Mailable
{
    public function __construct(public KpiAlert $alert, public KpiAlertEvent $event) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '['.strtoupper($this->alert->severity).'] KPI alert: '.$this->alert->name.' – '.hotel()->name);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.kpi-alert', with: ['hotel' => hotel()]);
    }
}
