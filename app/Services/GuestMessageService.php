<?php

namespace App\Services;

use App\Models\Guest;
use App\Models\GuestMessage;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Support\Numbering;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** v3.5 – guest messages & parcels held at the front desk. */
class GuestMessageService
{
    public static function stayGuest(ReservationRoom $stay): ?Guest
    {
        return $stay->guest ?? $stay->reservation?->guest;
    }

    public static function take(ReservationRoom $stay, array $d): GuestMessage
    {
        $kind = ($d['kind'] ?? 'message') === 'parcel' ? 'parcel' : 'message';
        $from = trim((string) ($d['from_name'] ?? ''));
        $body = trim((string) ($d['body'] ?? ''));
        if ($from === '') {
            throw new RuntimeException($kind === 'parcel' ? 'Who is the parcel from (sender or courier)?' : 'Who is the message from?');
        }
        if ($kind === 'message' && $body === '') {
            throw new RuntimeException('Write the message.');
        }
        $channel = array_key_exists($d['channel'] ?? '', GuestMessage::CHANNELS) ? $d['channel'] : ($kind === 'parcel' ? 'courier' : 'phone');

        [$m, $wasInHouse] = DB::transaction(function () use ($stay, $d, $kind, $from, $body, $channel) {
            // Match check-in and checkout: reservation → stay before creating
            // a desk message, so it cannot appear after a guest has departed.
            $reservation = Reservation::whereKey($stay->reservation_id)->lockForUpdate()->firstOrFail();
            $stay = ReservationRoom::whereKey($stay->id)->lockForUpdate()->firstOrFail();
            if (! in_array($stay->status, ['reserved', 'checked_in'], true)) {
                throw new RuntimeException('Messages can only be taken for in-house or arriving guests.');
            }
            $stay->setRelation('reservation', $reservation);
            $stay->load(['guest', 'room']);
            $m = GuestMessage::create([
                'message_no' => Numbering::next('guest_message'), 'kind' => $kind, 'reservation_room_id' => $stay->id, 'guest_id' => self::stayGuest($stay)?->id,
                'channel' => $channel, 'from_name' => mb_substr($from, 0, 120), 'from_phone' => trim((string) ($d['from_phone'] ?? '')) ?: null,
                'from_company' => trim((string) ($d['from_company'] ?? '')) ?: null, 'body' => $body ?: null,
                'courier' => $kind === 'parcel' ? (trim((string) ($d['courier'] ?? '')) ?: null) : null,
                'tracking_no' => $kind === 'parcel' ? (trim((string) ($d['tracking_no'] ?? '')) ?: null) : null,
                'pieces' => $kind === 'parcel' ? max(1, min(50, (int) ($d['pieces'] ?? 1))) : 1,
                'storage_location' => $kind === 'parcel' ? (trim((string) ($d['storage_location'] ?? '')) ?: 'Front desk') : null,
                'urgent' => ! empty($d['urgent']), 'status' => 'pending', 'taken_by' => auth()->id(),
            ]);
            ActivityLogger::log('messages', 'create', ($kind === 'parcel' ? 'Parcel' : 'Message')." {$m->message_no} for ".self::who($stay).' from '.$m->from_name, $m);

            return [$m, $stay->status === 'checked_in'];
        });

        $auto = FrontDeskOpsService::cfg()['msg_notify'];
        $want = array_key_exists('notify', $d) ? (bool) $d['notify'] : true;
        if ($want && $wasInHouse && in_array($auto, ['sms', 'whatsapp'], true)) {
            self::notify($m, $auto, true);
        }

        return $m->fresh();
    }

    protected static function who(ReservationRoom $stay): string
    {
        return (self::stayGuest($stay)?->full_name ?? 'guest').($stay->room ? ' (room '.$stay->room->room_no.')' : '');
    }

    public static function notifyText(GuestMessage $m): string
    {
        $stay = $m->relationLoaded('stay') ? $m->getRelation('stay') : $m->stay;
        $g = $m->guest ?? ($stay ? self::stayGuest($stay) : null);
        $name = $g?->first_name ?: ($g?->full_name ?? 'Guest');
        $what = $m->isParcel() ? ($m->pieces > 1 ? "{$m->pieces} parcels are" : 'a parcel is') : 'a message is';
        $text = "Dear {$name}, {$what} waiting for you at the front desk of ".hotel()->name.'.';
        if (! $m->isParcel() && FrontDeskOpsService::cfg()['msg_include_text'] === '1' && $m->body) {
            $text .= " From {$m->from_name}: ".mb_strimwidth($m->body, 0, 240, '…');
        }

        return $text.($m->isParcel() ? ' Please collect it at your convenience.' : '');
    }

    /** tell the guest: SMS / WhatsApp through the messaging gateway, or "call" = the desk phoned the room */
    public static function notify(GuestMessage $m, string $via, bool $quiet = false): GuestMessage
    {
        if (! in_array($via, ['sms', 'whatsapp', 'call'], true)) {
            throw new RuntimeException('Choose how the guest was told.');
        }

        $sendError = null;
        $result = DB::transaction(function () use ($m, $via, $quiet, &$sendError) {
            // Stay transitions take reservation → stay locks. Acquire them
            // before the message mutex so a checkout cannot commit while a
            // desk notification is being sent.
            $snapshot = GuestMessage::whereKey($m->id)->firstOrFail();
            $staySnapshot = $snapshot->reservation_room_id ? ReservationRoom::find($snapshot->reservation_room_id) : null;
            $stay = null;
            if ($staySnapshot) {
                Reservation::whereKey($staySnapshot->reservation_id)->lockForUpdate()->first();
                $stay = ReservationRoom::whereKey($staySnapshot->id)->lockForUpdate()->first();
            }
            $locked = GuestMessage::whereKey($m->id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('stay', $stay);
            if (! $locked->isOpen()) {
                if ($quiet) {
                    return $locked;
                }
                throw new RuntimeException('This '.($locked->isParcel() ? 'parcel' : 'message').' is already '.strtolower($locked->statusLabel()).'.');
            }
            if ($locked->kind === 'message' && $stay?->status === 'checked_out') {
                if ($quiet) {
                    return $locked;
                }
                throw new RuntimeException('The guest checked out before this message could be sent.');
            }
            if ($via !== 'call') {
                $guest = $locked->guest ?? ($stay ? self::stayGuest($stay) : null);
                $phone = $guest?->phone;
                if (! $phone) {
                    if ($quiet) {
                        return $locked;
                    }
                    throw new RuntimeException('The guest has no mobile number on file – phone the room instead.');
                }
                $log = MessageService::send($phone, self::notifyText($locked), $via, 'guest_message', $locked);
                if ($log->status !== 'sent') {
                    if (! $quiet) {
                        // Let the failed gateway log commit before surfacing the
                        // error to the desk caller.
                        $sendError = 'Could not send: '.$log->error;
                    }

                    return $locked;
                }
            }
            $locked->forceFill(['status' => 'notified', 'notified_at' => now(), 'notified_via' => $via])->save();
            ActivityLogger::log('messages', 'notify', "{$locked->message_no}: guest notified by {$via}", $locked);

            return $locked;
        });
        if ($sendError !== null) {
            throw new RuntimeException($sendError);
        }

        return $result;
    }

    public static function deliver(GuestMessage $m, ?string $note = null, ?string $collectedBy = null): GuestMessage
    {
        $collectedBy = trim((string) $collectedBy) ?: null;

        return DB::transaction(function () use ($m, $note, $collectedBy) {
            $locked = GuestMessage::whereKey($m->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw new RuntimeException('This '.($locked->isParcel() ? 'parcel' : 'message').' is already '.strtolower($locked->statusLabel()).'.');
            }
            if ($locked->isParcel() && ! $collectedBy) {
                throw new RuntimeException('Who collected the parcel?');
            }
            $locked->forceFill(['status' => 'delivered', 'delivered_at' => now(), 'delivered_by' => auth()->id(),
                'delivery_note' => trim((string) $note) ? mb_substr(trim($note), 0, 190) : ($locked->isParcel() ? 'Collected at the desk' : 'Handed to the guest'),
                'collected_by' => $collectedBy])->save();
            ActivityLogger::log('messages', 'deliver', "{$locked->message_no} delivered".($collectedBy ? " – collected by {$collectedBy}" : ''), $locked);

            return $locked;
        });
    }

    /** returned to sender / forwarded (parcels) or cancelled */
    public static function close(GuestMessage $m, string $status, string $reason): GuestMessage
    {
        if (! in_array($status, ['returned', 'cancelled'], true)) {
            throw new RuntimeException('Unknown action.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException($status === 'returned' ? 'Where was it returned / forwarded to?' : 'Give a reason.');
        }

        return DB::transaction(function () use ($m, $status, $reason) {
            $locked = GuestMessage::whereKey($m->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw new RuntimeException('This '.($locked->isParcel() ? 'parcel' : 'message').' is already '.strtolower($locked->statusLabel()).'.');
            }
            $locked->forceFill(['status' => $status, 'close_reason' => mb_substr(trim($reason), 0, 190), 'delivered_at' => now(), 'delivered_by' => auth()->id()])->save();
            ActivityLogger::log('messages', $status, "{$locked->message_no} {$status}: {$reason}", $locked);

            return $locked;
        });
    }

    /** guest opened the portal messages page: messages count as delivered, parcels as notified */
    public static function portalRead(ReservationRoom $stay): void
    {
        DB::transaction(function () use ($stay) {
            GuestMessage::where('reservation_room_id', $stay->id)->whereIn('status', GuestMessage::OPEN)->orderBy('id')->lockForUpdate()->get()
                ->each(function (GuestMessage $m) {
                    $m->portal_read_at ??= now();
                    if ($m->isParcel()) {
                        if ($m->status === 'pending') {
                            $m->forceFill(['status' => 'notified', 'notified_at' => now(), 'notified_via' => 'portal']);
                        }
                    } else {
                        $m->forceFill(['status' => 'delivered', 'delivered_at' => now(), 'delivery_note' => 'Read on the guest portal', 'notified_via' => $m->notified_via ?? 'portal']);
                    }
                    $m->save();
                });
        });
    }

    /** check-in: messages that were waiting for an arriving guest are announced */
    public static function onCheckIn(ReservationRoom $stay): int
    {
        $pending = GuestMessage::where('reservation_room_id', $stay->id)->where('status', 'pending')->get();
        $via = FrontDeskOpsService::cfg()['msg_notify'];
        if ($pending->isNotEmpty() && in_array($via, ['sms', 'whatsapp'], true)) {
            $pending->each(fn ($m) => self::notify($m, $via, true));
        }

        return $pending->count();
    }

    /** check-out: undelivered messages lapse; parcels stay open (they must be handed over, returned or forwarded) */
    public static function onCheckOut(ReservationRoom $stay): void
    {
        DB::transaction(function () use ($stay) {
            GuestMessage::where('reservation_room_id', $stay->id)->whereIn('status', GuestMessage::OPEN)->where('kind', 'message')
                ->orderBy('id')->lockForUpdate()->get()->each(function (GuestMessage $m) {
                    if ($m->isOpen() && ! $m->isParcel()) {
                        $m->forceFill(['status' => 'cancelled', 'close_reason' => 'Guest checked out before delivery', 'delivered_at' => now()])->save();
                    }
                });
        });
    }

    public static function openFor(ReservationRoom $stay)
    {
        return GuestMessage::where('reservation_room_id', $stay->id)->whereIn('status', GuestMessage::OPEN)->orderBy('id')->get();
    }
}
