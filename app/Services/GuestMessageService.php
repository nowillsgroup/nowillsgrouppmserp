<?php

namespace App\Services;

use App\Models\Guest;
use App\Models\GuestMessage;
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
        if (! in_array($stay->status, ['reserved', 'checked_in'], true)) {
            throw new RuntimeException('Messages can only be taken for in-house or arriving guests.');
        }
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

        $m = DB::transaction(fn () => GuestMessage::create([
            'message_no' => Numbering::next('guest_message'), 'kind' => $kind, 'reservation_room_id' => $stay->id, 'guest_id' => self::stayGuest($stay)?->id,
            'channel' => $channel, 'from_name' => mb_substr($from, 0, 120), 'from_phone' => trim((string) ($d['from_phone'] ?? '')) ?: null,
            'from_company' => trim((string) ($d['from_company'] ?? '')) ?: null, 'body' => $body ?: null,
            'courier' => $kind === 'parcel' ? (trim((string) ($d['courier'] ?? '')) ?: null) : null,
            'tracking_no' => $kind === 'parcel' ? (trim((string) ($d['tracking_no'] ?? '')) ?: null) : null,
            'pieces' => $kind === 'parcel' ? max(1, min(50, (int) ($d['pieces'] ?? 1))) : 1,
            'storage_location' => $kind === 'parcel' ? (trim((string) ($d['storage_location'] ?? '')) ?: 'Front desk') : null,
            'urgent' => ! empty($d['urgent']), 'status' => 'pending', 'taken_by' => auth()->id(),
        ]));
        ActivityLogger::log('messages', 'create', ($kind === 'parcel' ? 'Parcel' : 'Message')." {$m->message_no} for ".self::who($stay).' from '.$m->from_name, $m);

        $auto = FrontDeskOpsService::cfg()['msg_notify'];
        $want = array_key_exists('notify', $d) ? (bool) $d['notify'] : true;   // the desk can untick "notify the guest now"
        if ($want && $stay->status === 'checked_in' && in_array($auto, ['sms', 'whatsapp'], true)) {
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
        $g = $m->guest ?? self::stayGuest($m->stay);
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
        if (! $m->isOpen()) {
            throw new RuntimeException('This '.($m->isParcel() ? 'parcel' : 'message').' is already '.strtolower($m->statusLabel()).'.');
        }
        if (! in_array($via, ['sms', 'whatsapp', 'call'], true)) {
            throw new RuntimeException('Choose how the guest was told.');
        }
        if ($via !== 'call') {
            $phone = ($m->guest ?? self::stayGuest($m->stay))?->phone;
            if (! $phone) {
                if ($quiet) {
                    return $m;
                }
                throw new RuntimeException('The guest has no mobile number on file – phone the room instead.');
            }
            $log = MessageService::send($phone, self::notifyText($m), $via, 'guest_message', $m);
            if ($log->status !== 'sent') {
                if ($quiet) {
                    return $m;
                }
                throw new RuntimeException('Could not send: '.$log->error);
            }
        }
        $m->forceFill(['status' => 'notified', 'notified_at' => now(), 'notified_via' => $via])->save();
        ActivityLogger::log('messages', 'notify', "{$m->message_no}: guest notified by {$via}", $m);

        return $m;
    }

    public static function deliver(GuestMessage $m, ?string $note = null, ?string $collectedBy = null): GuestMessage
    {
        if (! $m->isOpen()) {
            throw new RuntimeException('This '.($m->isParcel() ? 'parcel' : 'message').' is already '.strtolower($m->statusLabel()).'.');
        }
        $collectedBy = trim((string) $collectedBy) ?: null;
        if ($m->isParcel() && ! $collectedBy) {
            throw new RuntimeException('Who collected the parcel?');
        }
        $m->forceFill(['status' => 'delivered', 'delivered_at' => now(), 'delivered_by' => auth()->id(),
            'delivery_note' => trim((string) $note) ? mb_substr(trim($note), 0, 190) : ($m->isParcel() ? 'Collected at the desk' : 'Handed to the guest'),
            'collected_by' => $collectedBy])->save();
        ActivityLogger::log('messages', 'deliver', "{$m->message_no} delivered".($collectedBy ? " – collected by {$collectedBy}" : ''), $m);

        return $m;
    }

    /** returned to sender / forwarded (parcels) or cancelled */
    public static function close(GuestMessage $m, string $status, string $reason): GuestMessage
    {
        if (! $m->isOpen()) {
            throw new RuntimeException('This '.($m->isParcel() ? 'parcel' : 'message').' is already '.strtolower($m->statusLabel()).'.');
        }
        if (! in_array($status, ['returned', 'cancelled'], true)) {
            throw new RuntimeException('Unknown action.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException($status === 'returned' ? 'Where was it returned / forwarded to?' : 'Give a reason.');
        }
        $m->forceFill(['status' => $status, 'close_reason' => mb_substr(trim($reason), 0, 190), 'delivered_at' => now(), 'delivered_by' => auth()->id()])->save();
        ActivityLogger::log('messages', $status, "{$m->message_no} {$status}: {$reason}", $m);

        return $m;
    }

    /** guest opened the portal messages page: messages count as delivered, parcels as notified */
    public static function portalRead(ReservationRoom $stay): void
    {
        GuestMessage::where('reservation_room_id', $stay->id)->whereIn('status', GuestMessage::OPEN)->get()->each(function (GuestMessage $m) {
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
        GuestMessage::where('reservation_room_id', $stay->id)->whereIn('status', GuestMessage::OPEN)->where('kind', 'message')->get()
            ->each(fn ($m) => $m->forceFill(['status' => 'cancelled', 'close_reason' => 'Guest checked out before delivery', 'delivered_at' => now()])->save());
    }

    public static function openFor(ReservationRoom $stay)
    {
        return GuestMessage::where('reservation_room_id', $stay->id)->whereIn('status', GuestMessage::OPEN)->orderBy('id')->get();
    }
}
