<?php

namespace App\Observers;

use App\Models\FolioTransaction;
use App\Models\NightAudit;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Services\ApiService;
use App\Services\WebhookService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * v4.0 – turns model changes into webhook events. WHICH event is decided when the row is saved (wasChanged() is only
 * reliable then); the payload is built and queued after the surrounding DB transaction commits, so a rolled-back booking
 * never notifies anyone and payloads see the finished state (e.g. a reservation's room lines).
 * dispatch() is a single indexed query when the hotel has no endpoints, and never breaks the business operation.
 */
class WebhookObserver
{
    public function created(Model $m): void
    {
        if ($m instanceof Reservation) {
            $this->later(fn () => WebhookService::dispatch('reservation.created', ApiService::reservation($m->fresh()), $m->hotel_id));
        } elseif ($m instanceof FolioTransaction && $m->type === 'payment' && ! $m->is_void) {
            $this->later(function () use ($m) {
                $f = $m->folio;
                WebhookService::dispatch('payment.received', ['folio_no' => $f?->folio_no, 'reservation_no' => $f?->reservation?->reservation_no,
                    'room_no' => $f?->stay?->room?->room_no, 'date' => $m->business_date?->toDateString(), 'amount' => (float) $m->amount,
                    'mode' => $m->paymentMode?->name, 'category' => $m->category, 'reference' => $m->reference], $m->hotel_id);
            });
        } elseif ($m instanceof NightAudit) {
            $this->later(fn () => WebhookService::dispatch('night_audit.completed', ApiService::audit($m) + ['new_business_date' => $m->business_date->copy()->addDay()->toDateString()], $m->hotel_id));
        }
    }

    public function updated(Model $m): void
    {
        if ($m instanceof Reservation && $m->wasChanged('status') && in_array($m->status, ['cancelled', 'no_show'], true)) {
            $event = $m->status === 'cancelled' ? 'reservation.cancelled' : 'reservation.no_show';
            $this->later(fn () => WebhookService::dispatch($event, ApiService::reservation($m->fresh()), $m->hotel_id));
        } elseif ($m instanceof ReservationRoom && $m->wasChanged('status') && in_array($m->status, ['checked_in', 'checked_out'], true)) {
            $event = $m->status === 'checked_in' ? 'guest.checked_in' : 'guest.checked_out';
            $this->later(fn () => WebhookService::dispatch($event, ApiService::stay($m->fresh(['reservation', 'room', 'roomType', 'guest']), true), $m->hotel_id));
        } elseif ($m instanceof Room && ($m->wasChanged('hk_status') || $m->wasChanged('status'))) {
            $prev = ['status' => $m->getPrevious()['status'] ?? $m->status, 'housekeeping' => $m->getPrevious()['hk_status'] ?? $m->hk_status];
            $this->later(fn () => WebhookService::dispatch('room.status_changed', ApiService::room($m->fresh(['roomType', 'floor'])) + ['previous' => $prev], $m->hotel_id));
        }
    }

    /** run after the commit (at once when no transaction is open); failures are reported, never thrown */
    protected function later(callable $fn): void
    {
        DB::afterCommit(fn () => $this->safely($fn));
    }

    protected function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            report($e);   // a notification problem must never undo a booking / check-in
        }
    }
}
