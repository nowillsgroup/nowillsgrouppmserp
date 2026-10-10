<?php

namespace App\Mail;

use App\Models\Hotel;
use App\Models\ReportSchedule;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** v5.4 A scheduled report: HTML table in the body and/or the full CSV attached. */
class ScheduledReportMail extends Mailable
{
    public function __construct(public Hotel $hotel, public ReportSchedule $schedule, public array $data, public string $period, public ?string $csv = null, public string $filename = 'report.csv') {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->schedule->name.' – '.$this->period.' – '.$this->hotel->name);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.scheduled-report', with: [
            'inline' => $this->schedule->format !== 'csv',
            'limit' => (int) \App\Services\ReportScheduleService::cfg('rpt_inline_rows'),
            'title' => $this->schedule->title(),
        ]);
    }

    public function attachments(): array
    {
        return $this->csv !== null ? [Attachment::fromData(fn () => $this->csv, $this->filename)->withMime('text/csv')] : [];
    }
}
