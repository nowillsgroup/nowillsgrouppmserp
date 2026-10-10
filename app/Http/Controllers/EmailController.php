<?php

namespace App\Http\Controllers;

use App\Mail\GuestInvoice;
use App\Mail\ReservationConfirmed;
use App\Models\EmailLog;
use App\Models\Folio;
use App\Models\Reservation;
use App\Services\MailService;
use Illuminate\Http\Request;

class EmailController extends Controller
{
    public function index(Request $request)
    {
        $logs = EmailLog::with('user')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->template, fn ($q, $t) => $q->where('template', $t))
            ->when($request->q, fn ($q, $t) => $q->where(fn ($w) => $w->where('to_email', 'like', "%$t%")->orWhere('subject', 'like', "%$t%")))
            ->latest('id')->paginate(30)->withQueryString();

        return view('emails.index', ['logs' => $logs, 'mailer' => config('mail.default'),
            'stats' => EmailLog::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status')]);
    }

    public function reservation(Request $request, Reservation $reservation)
    {
        $to = $request->validate(['to' => 'nullable|email|max:120'])['to'] ?? null;
        $log = MailService::reservationConfirmation($reservation, $to);

        return back()->with($log->status === 'sent' ? 'success' : 'error',
            $log->status === 'sent' ? "Confirmation e-mailed to {$log->to_email}." : "E-mail failed: {$log->error}");
    }

    public function precheckin(Request $request, Reservation $reservation)
    {
        abort_unless($reservation->onlineCheckinOpen(), 422, 'Online check-in is not available for this reservation.');
        $to = $request->validate(['to' => 'nullable|email|max:120'])['to'] ?? null;
        $log = MailService::precheckinLink($reservation, $to);

        return back()->with($log->status === 'sent' ? 'success' : 'error',
            $log->status === 'sent' ? "Online check-in link e-mailed to {$log->to_email}." : "E-mail failed: {$log->error}");
    }

    public function folio(Request $request, Folio $folio)
    {
        $to = $request->validate(['to' => 'nullable|email|max:120'])['to'] ?? null;
        $log = MailService::invoice($folio, $to);

        return back()->with($log->status === 'sent' ? 'success' : 'error',
            $log->status === 'sent' ? ($folio->invoice_no ? 'Invoice' : 'Folio statement')." e-mailed to {$log->to_email}." : "E-mail failed: {$log->error}");
    }

    /** Render a template with the latest matching record, so staff can see what guests receive. */
    public function preview(string $template)
    {
        return match ($template) {
            'reservation_confirmation' => (new ReservationConfirmed(Reservation::with('guest', 'rooms.roomType', 'rooms.room', 'folios.transactions')->latest('id')->firstOrFail(), hotel()))->render(),
            'invoice' => (new GuestInvoice(Folio::with('guest', 'company', 'stay.room')->orderByRaw('invoice_no is null')->latest('id')->firstOrFail(), hotel()))->render(),
            default => abort(404),
        };
    }
}
