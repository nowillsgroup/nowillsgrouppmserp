<?php

namespace App\Services;

use App\Mail\GuestInvoice;
use App\Mail\ReservationConfirmed;
use App\Models\EmailLog;
use App\Models\Folio;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends guest e-mails and records every attempt in email_logs. Never throws: a mail
 * failure must not roll back a booking or checkout – it is logged as "failed" instead.
 */
class MailService
{
    public static function send(?string $to, Mailable $mail, string $template, ?Model $related = null): EmailLog
    {
        $hotel = hotel();
        $log = new EmailLog;
        $log->forceFill(['hotel_id' => $hotel->id, 'to_email' => (string) $to, 'template' => $template, 'user_id' => auth()->id(),
            'related_type' => $related ? class_basename($related) : null, 'related_id' => $related?->getKey()]);
        try {
            if (! $to || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('No valid e-mail address.');
            }
            $mail->from(config('mail.from.address'), $hotel->name);
            if ($hotel->email) {
                $mail->replyTo($hotel->email, $hotel->name);
            }
            $log->subject = $mail->envelope()->subject;
            Mail::to($to)->send($mail);
            $log->status = 'sent';
        } catch (Throwable $e) {
            $log->subject ??= $template;
            $log->status = 'failed';
            $log->error = mb_substr($e->getMessage(), 0, 500);
            if (! $e instanceof \InvalidArgumentException) {
                report($e); // transport problems go to the error log; a missing address is just logged here
            }
        }
        $log->save();

        return $log;
    }

    public static function reservationConfirmation(Reservation $r, ?string $to = null): EmailLog
    {
        $r->loadMissing('guest', 'rooms.roomType', 'rooms.room', 'folios.transactions');

        return self::send($to ?? $r->guest?->email, new ReservationConfirmed($r, hotel()), 'reservation_confirmation', $r);
    }

    public static function portalLink(\App\Models\ReservationRoom $stay, ?string $to = null): EmailLog
    {
        $stay->loadMissing('guest', 'room', 'reservation.guest');

        return self::send($to ?? ($stay->guest ?? $stay->reservation?->guest)?->email, new \App\Mail\PortalWelcome($stay, hotel()), 'portal_link', $stay->reservation);
    }

    public static function precheckinLink(Reservation $r, ?string $to = null): EmailLog
    {
        $r->loadMissing('guest', 'rooms.roomType');

        return self::send($to ?? $r->guest?->email, new \App\Mail\PreArrival($r, hotel()), 'precheckin_link', $r);
    }

    public static function invoice(Folio $folio, ?string $to = null): EmailLog
    {
        $folio->loadMissing('guest', 'company', 'stay.room', 'reservation');

        return self::send($to ?? $folio->guest?->email ?? $folio->company?->email, new GuestInvoice($folio, hotel()), 'invoice', $folio);
    }

    /** Auto-confirmation after a booking, if enabled and the guest has an address. */
    public static function autoConfirm(Reservation $r): void
    {
        if (setting('email_auto_confirm', '1') === '1' && $r->guest?->email) {
            self::reservationConfirmation($r);
        }
    }
}
