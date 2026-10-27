<?php

namespace App\Services;

use App\Models\Folio;
use App\Models\Guest;
use App\Models\GuestRequest;
use App\Models\LaundryItem;
use App\Models\LaundryOrder;
use App\Models\PaymentMode;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Support\Numbering;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v2.2 guest laundry: an order is collected from a room (or handed in by a walk-in customer), itemised against the
 * price list, marked ready and delivered. Delivery posts one "Laundry" charge to the in-house guest's folio (tax by
 * the laundry category, GL 4060 via the category mapping) or takes payment from a walk-in on a closed invoice.
 */
class LaundryService
{
    public const SPEEDS = ['normal' => 'Normal', 'express' => 'Express'];

    public static function expressPct(): float
    {
        return max(0, min(300, (float) setting('laundry_express_pct', '50')));
    }

    public static function hours(string $speed): int
    {
        return max(1, (int) setting($speed === 'express' ? 'laundry_express_hours' : 'laundry_normal_hours', $speed === 'express' ? '6' : '24'));
    }

    /** Laundry needs an in-house stay: the room's current guest. */
    protected static function stayFor(int $stayId): ReservationRoom
    {
        $stay = ReservationRoom::with('guest', 'room')->find($stayId);
        if (! $stay || $stay->status !== 'checked_in' || ! $stay->room_id) {
            throw new RuntimeException('Laundry can only be collected from an in-house room.');
        }

        return $stay;
    }

    public static function create(array $d): LaundryOrder
    {
        $type = ($d['type'] ?? 'guest') === 'walk_in' ? 'walk_in' : 'guest';
        $speed = ($d['speed'] ?? 'normal') === 'express' ? 'express' : 'normal';
        $stay = $type === 'guest' ? self::stayFor((int) ($d['reservation_room_id'] ?? 0)) : null;
        if ($type === 'walk_in' && ! trim((string) ($d['guest_name'] ?? ''))) {
            throw new RuntimeException('Enter the customer\'s name.');
        }
        $req = ! empty($d['guest_request_id']) ? GuestRequest::find($d['guest_request_id']) : null;
        if ($req && ($req->category !== 'laundry' || ! in_array($req->status, GuestRequest::OPEN))) {
            throw new RuntimeException('That guest request is not an open laundry request.');
        }
        if ($req && $stay && (int) $req->reservation_room_id !== (int) $stay->id) {
            throw new RuntimeException('That laundry request belongs to another room.');
        }

        $order = DB::transaction(function () use ($d, $type, $speed, $stay, $req) {
            if ($stay) {
                $reservation = Reservation::whereKey($stay->reservation_id)->lockForUpdate()->firstOrFail();
                $stay = ReservationRoom::whereKey($stay->id)->lockForUpdate()->firstOrFail();
                if ($stay->status !== 'checked_in' || ! $stay->room_id) {
                    throw new RuntimeException('Laundry can only be collected from an in-house room.');
                }
                $stay->setRelation('reservation', $reservation);
                $stay->load(['guest', 'room']);
            }
            $o = LaundryOrder::create([
                'order_no' => Numbering::next('laundry_order'), 'type' => $type, 'speed' => $speed, 'status' => 'collected',
                'reservation_room_id' => $stay?->id, 'room_id' => $stay?->room_id, 'guest_request_id' => $req?->id,
                'guest_name' => $stay ? ($stay->guest?->full_name ?? 'Guest') : trim($d['guest_name']),
                'phone' => $stay ? $stay->guest?->phone : ($d['phone'] ?? null),
                'bag_no' => $d['bag_no'] ?? null, 'bag_pieces' => ! empty($d['bag_pieces']) ? (int) $d['bag_pieces'] : null,
                'collected_at' => now(), 'promised_at' => now()->addHours(self::hours($speed)),
                'notes' => $d['notes'] ?? null, 'created_by' => auth()->id(),
            ]);
            if (! empty($d['lines'])) {
                self::setLines($o, $d['lines'], false);
            }
            if ($req) {
                GuestPortalService::transition($req, 'start', "Laundry collected – order {$o->order_no}, ready by ".$o->promised_at->format('d M H:i'));
                $req->forceFill(['linked_type' => 'LaundryOrder', 'linked_id' => $o->id])->save();
            }
            ActivityLogger::log('laundry', 'create', "Laundry {$o->order_no} collected: ".$o->whoLabel().($speed === 'express' ? ' (express)' : ''), $o);

            return $o;
        });

        return $order->fresh('lines');
    }

    /**
     * Replace the order's items. Each line: laundry_item_id, service (wash|dryclean|press), qty, optional remark.
     * Prices always come from the price list, never from the form.
     */
    public static function setLines(LaundryOrder $o, array $lines, bool $log = true, ?string $speed = null): LaundryOrder
    {
        if ($speed !== null && ! array_key_exists($speed, self::SPEEDS)) {
            throw new RuntimeException('Choose a valid laundry speed.');
        }
        $clean = [];
        foreach ($lines as $l) {
            $qty = (int) ($l['qty'] ?? 0);
            if ($qty <= 0 || empty($l['laundry_item_id'])) {
                continue;
            }
            $item = LaundryItem::where('is_active', true)->find((int) $l['laundry_item_id']);
            $svc = (string) ($l['service'] ?? 'wash');
            if (! $item || ! array_key_exists($svc, LaundryItem::SERVICES)) {
                throw new RuntimeException('Unknown laundry item or service.');
            }
            $rate = $item->rate($svc);
            if ($rate === null) {
                throw new RuntimeException("{$item->name} isn't offered for ".strtolower(LaundryItem::SERVICES[$svc]).'.');
            }
            $qty = min($qty, 99);
            $clean[] = ['laundry_item_id' => $item->id, 'item_name' => $item->name, 'service' => $svc, 'qty' => $qty, 'rate' => $rate,
                'amount' => round($rate * $qty, 2), 'remark' => ! empty($l['remark']) ? mb_substr(trim($l['remark']), 0, 150) : null];
        }

        $updated = DB::transaction(function () use ($o, $clean, $speed) {
            $locked = LaundryOrder::whereKey($o->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw new RuntimeException("Laundry {$locked->order_no} is {$locked->statusLabel()} and can't be changed.");
            }
            if ($speed !== null && $speed !== $locked->speed) {
                $locked->speed = $speed;
                $locked->promised_at = ($locked->collected_at ?? now())->copy()->addHours(self::hours($speed));
            }
            $locked->lines()->delete();
            $locked->lines()->createMany($clean);
            $sub = round(array_sum(array_column($clean, 'amount')), 2);
            $exp = $locked->speed === 'express' ? round($sub * self::expressPct() / 100, 2) : 0.0;
            $nextStatus = $clean ? 'processing' : 'collected';
            $locked->forceFill(['subtotal' => $sub, 'express_charge' => $exp, 'total' => round($sub + $exp, 2), 'pieces' => array_sum(array_column($clean, 'qty')),
                'status' => $nextStatus, 'ready_at' => null])->save();

            return $locked->fresh('lines');
        });
        if ($log) {
            ActivityLogger::log('laundry', 'items', "Laundry {$updated->order_no} itemised: {$updated->pieces} pcs, ".money($updated->total), $updated);
        }

        return $updated;
    }

    public static function markReady(LaundryOrder $o): LaundryOrder
    {
        $ready = DB::transaction(function () use ($o) {
            $locked = LaundryOrder::whereKey($o->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['collected', 'processing'], true)) {
                throw new RuntimeException("Laundry {$locked->order_no} is already {$locked->statusLabel()}.");
            }
            if ($locked->pieces < 1) {
                throw new RuntimeException('Add the items before marking the order ready.');
            }
            $locked->forceFill(['status' => 'ready', 'ready_at' => now()])->save();

            return $locked;
        });
        ActivityLogger::log('laundry', 'ready', "Laundry {$ready->order_no} ready", $ready);

        return $ready;
    }

    /**
     * Hand the laundry back. In-house guest → one charge on the room folio. Walk-in → a paid, closed invoice.
     */
    public static function deliver(LaundryOrder $o, ?int $paymentModeId = null, ?string $reference = null, ?array $cheque = null): LaundryOrder
    {
        if (! in_array($o->status, ['processing', 'ready'])) {
            throw new RuntimeException($o->status === 'collected' ? 'Add the items before delivering.' : "Laundry {$o->order_no} is already {$o->statusLabel()}.");
        }
        if ((float) $o->total <= 0) {
            throw new RuntimeException('The order has no chargeable items.');
        }
        if ($o->type === 'walk_in' && ! $paymentModeId) {
            throw new RuntimeException('Choose how the customer is paying.');
        }
        if ($o->type === 'walk_in' && ! PaymentMode::where('is_active', true)->whereKey($paymentModeId)->whereNotIn('type', ['city_ledger', 'online', 'gift_voucher'])->exists()) {
            throw new RuntimeException('Choose a cash, card, UPI / mobile money or bank payment mode.');
        }

        DB::transaction(function () use ($o, $paymentModeId, $reference, $cheque) {
            $o = LaundryOrder::whereKey($o->id)->lockForUpdate()->firstOrFail();
            if (! in_array($o->status, ['processing', 'ready'], true)) {
                throw new RuntimeException("Laundry {$o->order_no} is already {$o->statusLabel()} and can't be delivered.");
            }
            if ((float) $o->total <= 0) {
                throw new RuntimeException('The order has no chargeable items.');
            }
            if ($o->folio_transaction_id) {
                throw new RuntimeException("Laundry {$o->order_no} has already been charged.");
            }
            $desc = "Laundry {$o->order_no} – {$o->pieces} pc".($o->pieces === 1 ? '' : 's').($o->speed === 'express' ? ' (express)' : '');
            $extra = ['source_type' => 'laundry', 'source_id' => $o->id, 'reference' => $o->order_no];
            if ($o->type === 'guest') {
                $stay = ReservationRoom::find($o->reservation_room_id);
                if (! $stay || $stay->status !== 'checked_in') {
                    throw new RuntimeException('The guest has checked out – post the laundry on their folio from Billing, or cancel this order.');
                }
                $folio = FolioService::openForStay($stay);
                $tx = FolioService::charge($folio, 'laundry', $desc, (float) $o->total, true, $extra);
            } else {
                $name = preg_split('/\s+/', trim($o->guest_name), 2);
                $guest = Guest::create(['first_name' => $name[0], 'last_name' => $name[1] ?? null, 'phone' => $o->phone]);
                $folio = FolioService::openStandalone(['guest_id' => $guest->id, 'type' => 'walk_in']);
                $tx = FolioService::charge($folio, 'laundry', $desc, (float) $o->total, true, $extra);
                FolioService::payment($folio, round((float) $tx->amount + (float) $tx->tax_amount, 2), $paymentModeId, $reference ?: $o->order_no, 'settlement', 'payment', $cheque);
                FolioService::close($folio->fresh());
            }
            $o->forceFill(['status' => 'delivered', 'delivered_at' => now(), 'delivered_by' => auth()->id(), 'folio_id' => $folio->id,
                'folio_transaction_id' => $tx->id, 'payment_mode_id' => $o->type === 'walk_in' ? $paymentModeId : null])->save();
            if ($o->guest_request_id && ($req = GuestRequest::find($o->guest_request_id)) && in_array($req->status, GuestRequest::OPEN)) {
                GuestPortalService::transition($req, 'done', "Laundry delivered – {$o->pieces} pcs");
            }
            ActivityLogger::log('laundry', 'deliver', "Laundry {$o->order_no} delivered – ".money($o->total).($o->type === 'guest' ? " posted to {$folio->folio_no}" : " paid, invoice {$folio->fresh()->invoice_no}"), $o);
        });

        return $o->fresh(['folio', 'folioTransaction']);
    }

    public static function cancel(LaundryOrder $o, string $reason): LaundryOrder
    {
        $cancelled = DB::transaction(function () use ($o, $reason) {
            $locked = LaundryOrder::whereKey($o->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw new RuntimeException("Laundry {$locked->order_no} is already {$locked->statusLabel()}.");
            }
            $locked->forceFill(['status' => 'cancelled', 'cancel_reason' => mb_substr($reason, 0, 190)])->save();
            if ($locked->guest_request_id && ($req = GuestRequest::find($locked->guest_request_id)) && in_array($req->status, GuestRequest::OPEN, true)) {
                GuestPortalService::transition($req, 'decline', 'Laundry order cancelled: '.$reason);
            }
            ActivityLogger::log('laundry', 'cancel', "Laundry {$locked->order_no} cancelled: {$reason}", $locked);

            return $locked;
        });

        return $cancelled;
    }

    /** Orders still with the laundry for a stay – shown as a warning at check-out. */
    public static function pendingForStay(int $stayId)
    {
        return LaundryOrder::where('reservation_room_id', $stayId)->whereIn('status', LaundryOrder::OPEN)->orderBy('id')->get();
    }

    public static function priceList()
    {
        return LaundryItem::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()->groupBy('category');
    }

    /** Default price list for a new property (rates in the hotel's currency units). */
    public static function ensureDefaults(): void
    {
        if (LaundryItem::exists()) {
            return;
        }
        $rows = [
            ['Shirt', 'gents', 60, 150, 30], ['Trousers', 'gents', 70, 160, 35], ['Suit (2 pc)', 'gents', null, 450, 150], ['Jacket / blazer', 'gents', null, 300, 90],
            ['T-shirt', 'gents', 50, null, 25], ['Kurta', 'gents', 70, 170, 35], ['Shorts', 'gents', 45, null, 20], ['Tie', 'gents', null, 80, 25],
            ['Blouse / top', 'ladies', 60, 150, 30], ['Dress', 'ladies', 110, 250, 60], ['Saree', 'ladies', 150, 350, 90], ['Skirt', 'ladies', 70, 160, 35],
            ['Salwar suit (3 pc)', 'ladies', 140, 320, 70], ['Kids garment', 'kids', 35, 90, 20], ['Undergarment', 'household', 30, null, null],
            ['Socks (pair)', 'household', 25, null, null], ['Handkerchief', 'household', 15, null, 10], ['Sweater', 'household', 90, 200, null],
        ];
        foreach ($rows as $i => [$n, $c, $w, $dc, $p]) {
            LaundryItem::create(['name' => $n, 'category' => $c, 'wash_rate' => $w, 'dryclean_rate' => $dc, 'press_rate' => $p, 'sort_order' => $i + 1]);
        }
    }
}
