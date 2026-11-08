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
use Illuminate\Validation\ValidationException;
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
        return max(1, (int) ($speed === 'express'
            ? setting('laundry_express_hours', '6')
            : setting('laundry_normal_hours', '24')));
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
     * Describe the editable grid without discarding old or unusual lines. The
     * fingerprint covers both the order and the catalogue used to render it.
     */
    public static function itemEditState(LaundryOrder $o, $catalogue = null): array
    {
        $o->loadMissing('lines');
        $catalogue ??= LaundryItem::orderBy('id')->get();
        $items = $catalogue->keyBy('id');
        $groups = $o->lines->groupBy(fn ($line) => $line->laundry_item_id === null ? 'missing-'.$line->id : (string) $line->laundry_item_id);
        $current = $remarks = $rates = $names = $blocked = [];
        $preserved = collect();
        foreach ($groups as $lines) {
            $itemId = $lines->first()->laundry_item_id;
            $item = $itemId === null ? null : $items->get($itemId);
            // The grid has one remark per garment and one cell per service. Do
            // not flatten legacy duplicates or conflicting line remarks.
            $frozen = ! $item || ! $item->is_active
                || $lines->pluck('service')->unique()->count() !== $lines->count()
                || $lines->map(fn ($line) => (string) ($line->remark ?? ''))->unique()->count() > 1
                || $lines->pluck('item_name')->unique()->count() > 1
                || $lines->contains(fn ($line) => (int) $line->qty < 1 || (int) $line->qty > 99 || (float) $line->rate < 0 || abs((float) $line->amount - round((float) $line->rate * (int) $line->qty, 2)) > 0.005
                    || ($line->remark !== null && $line->remark !== trim($line->remark))
                    || ! array_key_exists($line->service, LaundryItem::SERVICES) || $item->rate($line->service) === null);
            if ($frozen) {
                if ($itemId !== null) $blocked[$itemId] = true;
                foreach ($lines as $line) $preserved->push($line);
                continue;
            }
            foreach ($lines as $line) {
                $current[$itemId][$line->service] = (int) $line->qty;
                $names[$itemId] = $line->item_name;
                $rates[$itemId][$line->service] = (float) $line->rate;
                if ($line->remark !== null && $line->remark !== '') $remarks[$itemId] = $line->remark;
            }
        }
        $snapshot = [
            'status' => $o->status, 'speed' => $o->speed, 'subtotal' => (string) $o->subtotal,
            'express_charge' => (string) $o->express_charge,
            'lines' => $o->lines->map(fn ($l) => [$l->id, $l->laundry_item_id, $l->item_name, $l->service, $l->qty, (string) $l->rate, (string) $l->amount, $l->remark])->all(),
            'catalogue' => $catalogue->map(fn ($i) => [$i->id, $i->name, $i->category, $i->is_active, (string) $i->wash_rate, (string) $i->dryclean_rate, (string) $i->press_rate])->all(),
        ];

        return ['current' => $current, 'remarks' => $remarks, 'savedRates' => $rates, 'savedNames' => $names, 'blockedItems' => $blocked,
            'preservedLines' => $preserved, 'revision' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))];
    }

    /** Replace editable cells, keeping recorded rates and explicitly retained legacy lines. */
    public static function setLines(LaundryOrder $o, array $lines, bool $log = true, ?string $speed = null, ?string $revision = null, array $removePreserved = []): LaundryOrder
    {
        if ($speed !== null && ! array_key_exists($speed, self::SPEEDS)) {
            throw new RuntimeException('Choose a valid laundry speed.');
        }
        $updated = DB::transaction(function () use ($o, $lines, $speed, $revision, $removePreserved) {
            $locked = LaundryOrder::whereKey($o->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw new RuntimeException("Laundry {$locked->order_no} is {$locked->statusLabel()} and can't be changed.");
            }
            $locked->setRelation('lines', $locked->lines()->lockForUpdate()->get());
            $catalogue = LaundryItem::orderBy('id')->lockForUpdate()->get();
            $state = self::itemEditState($locked, $catalogue);
            if (($revision === null && $locked->lines->isNotEmpty()) || ($revision !== null && ! hash_equals($state['revision'], $revision))) {
                throw new RuntimeException('The order or price list changed since this page was opened. Reload it before saving items.');
            }
            $removeIds = array_map('intval', $removePreserved);
            $preservedIds = $state['preservedLines']->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (count($removeIds) !== count(array_unique($removeIds)) || array_diff($removeIds, $preservedIds)) {
                throw new RuntimeException('Invalid preserved laundry line selection. Reload the order and try again.');
            }
            $clean = [];
            foreach ($state['preservedLines'] as $line) {
                if (! in_array((int) $line->id, $removeIds, true)) {
                    $clean[] = $line->only(['laundry_item_id', 'item_name', 'service', 'qty', 'rate', 'amount', 'remark']);
                }
            }
            $items = $catalogue->keyBy('id');
            $existing = $locked->lines->keyBy(fn ($line) => $line->laundry_item_id.':'.$line->service);
            $seen = [];
            foreach ($lines as $l) {
                $qty = (int) ($l['qty'] ?? 0);
                if ($qty <= 0 || empty($l['laundry_item_id'])) continue;
                $id = (int) $l['laundry_item_id'];
                $svc = (string) ($l['service'] ?? 'wash');
                $key = $id.':'.$svc;
                $item = $items->get($id);
                if (isset($seen[$key]) || isset($state['blockedItems'][$id]) || ! $item || ! $item->is_active || ! array_key_exists($svc, LaundryItem::SERVICES) || $item->rate($svc) === null) {
                    throw new RuntimeException('Unknown, duplicate or unavailable laundry item or service. Reload the order and try again.');
                }
                $seen[$key] = true;
                $old = $existing->get($key);
                $rate = $old ? (float) $old->rate : $item->rate($svc);
                $qty = min($qty, 99);
                $remark = trim((string) ($l['remark'] ?? ''));
                $clean[] = ['laundry_item_id' => $id, 'item_name' => $old?->item_name ?? ($state['savedNames'][$id] ?? $item->name), 'service' => $svc, 'qty' => $qty, 'rate' => $rate,
                    'amount' => round($rate * $qty, 2), 'remark' => $remark !== '' ? mb_substr($remark, 0, 150) : null];
            }
            $oldSpeed = $locked->speed;
            $oldSubtotal = (float) $locked->subtotal;
            $oldExpress = (float) $locked->express_charge;
            if ($speed !== null && $speed !== $locked->speed) {
                $locked->speed = $speed;
                $locked->promised_at = ($locked->collected_at ?? now())->copy()->addHours(self::hours($speed));
            }
            $locked->lines()->delete();
            $locked->lines()->createMany($clean);
            $sub = round(array_sum(array_column($clean, 'amount')), 2);
            // Existing express orders have no stored percentage. Preserve their
            // exact charge if unchanged; otherwise use the recorded effective
            // percentage (which may be approximate because of past rounding).
            $exp = 0.0;
            if ($locked->speed === 'express') {
                $exp = $oldSpeed === 'express' && $oldSubtotal > 0
                    ? ($sub === $oldSubtotal ? $oldExpress : round($sub * $oldExpress / $oldSubtotal, 2))
                    : round($sub * self::expressPct() / 100, 2);
            }
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
     * What the order comes to: the listed items plus express, and the tax the bill will carry.
     *
     * Prices are entered either exclusive (tax added on top) or inclusive (tax extracted), following
     * the same tax-prices setting the outlets use — so laundry prices always attract the VAT, NHIL,
     * GETFund and tourism-levy components configured for the hotel's service category.
     *
     * @return array{listed: float, net: float, tax: float, total: float, rate: float, inclusive: bool, components: array<string, float>}
     */
    public static function quote(LaundryOrder $o): array
    {
        $listed = round((float) $o->total, 2);
        $split = TaxService::splitPrice($listed, null, 'laundry');

        return ['listed' => $listed, 'net' => (float) $split['net'], 'tax' => (float) $split['tax'], 'total' => (float) $split['total'],
            'rate' => (float) $split['rate'], 'inclusive' => TaxService::pricesInclusive(),
            'components' => TaxService::componentRates('laundry', $listed)];
    }

    /** The components a laundry line carries, e.g. "VAT 15% + NHIL 2.5% + GETFund levy 2.5% + Tourism levy 1%". */
    public static function taxLabel(?float $amount = null): string
    {
        $parts = [];
        foreach (TaxService::componentRates('laundry', $amount) as $code => $rate) {
            if ($rate <= 0) {
                continue;
            }
            $parts[] = (TaxService::COMPONENTS[$code][0] ?? $code).' '.rtrim(rtrim(number_format((float) $rate, 2), '0'), '.').'%';
        }

        return $parts ? implode(' + ', $parts) : 'no tax';
    }

    /** The laundry tax before an amount is known (itemising screen): rate, its components and the mode. */
    public static function taxInfo(): array
    {
        return ['rate' => round(array_sum(TaxService::componentRates('laundry')), 4), 'label' => self::taxLabel(), 'inclusive' => TaxService::pricesInclusive()];
    }

    /** Cash, card, mobile money or bank — the modes a guest can settle with at the counter. */
    protected static function isDirectMode(int $modeId): bool
    {
        return PaymentMode::where('is_active', true)->whereKey($modeId)->whereNotIn('type', ['city_ledger', 'online', 'gift_voucher'])->exists();
    }

    /**
     * Hand the laundry back.
     *
     *   in-house guest, settle = room → one charge on the room folio (the default)
     *   in-house guest, settle = pay  → the guest settles now and gets a paid invoice
     *   walk-in customer              → always paid on delivery
     *
     * The net of tax is what gets posted, so the bill carries the tax exactly once whether the
     * hotel's prices are entered inclusive or exclusive.
     */
    public static function deliver(LaundryOrder $o, ?int $paymentModeId = null, ?string $reference = null, ?array $cheque = null, array $payments = [], ?string $settle = null): LaundryOrder
    {
        if (! in_array($o->status, ['processing', 'ready'])) {
            throw new RuntimeException($o->status === 'collected' ? 'Add the items before delivering.' : "Laundry {$o->order_no} is already {$o->statusLabel()}.");
        }
        if ((float) $o->total <= 0) {
            throw new RuntimeException('The order has no chargeable items.');
        }
        $direct = $o->type === 'walk_in' || $settle === 'pay';
        $payments = array_values($payments);
        if ($payments && ! $direct) {
            throw ValidationException::withMessages(['payments' => 'Split tender is available only when the laundry is being paid for now.']);
        }
        if ($payments && ($paymentModeId || count($payments) < 2 || count($payments) > 10)) {
            throw ValidationException::withMessages(['payments' => $paymentModeId ? 'Use either one payment mode or split tender rows, not both.' : 'Use between two and ten payment tenders.']);
        }
        if ($direct && ! $payments && ! $paymentModeId) {
            throw ValidationException::withMessages(['payment_mode_id' => 'Choose how the laundry is being paid for.']);
        }
        if ($direct) {
            $modes = $payments ? array_column($payments, 'payment_mode_id') : [$paymentModeId];
            foreach ($modes as $i => $modeId) {
                if (! self::isDirectMode((int) $modeId)) {
                    throw ValidationException::withMessages([$payments ? "payments.{$i}.payment_mode_id" : 'payment_mode_id' => 'Choose a cash, card, UPI / mobile money or bank payment mode.']);
                }
            }
        }

        DB::transaction(function () use ($o, $paymentModeId, $reference, $cheque, $payments, $direct) {
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
            $quote = self::quote($o);
            $desc = "Laundry {$o->order_no} – {$o->pieces} pc".($o->pieces === 1 ? '' : 's').($o->speed === 'express' ? ' (express)' : '');
            $extra = ['source_type' => 'laundry', 'source_id' => $o->id, 'reference' => $o->order_no];
            $stay = $o->reservation_room_id ? ReservationRoom::with('reservation')->find($o->reservation_room_id) : null;
            if ($o->type === 'guest' && ! $direct) {
                if (! $stay || $stay->status !== 'checked_in') {
                    throw new RuntimeException('The guest has checked out – take payment for the laundry now, or cancel the order.');
                }
                $folio = FolioService::openForStay($stay);
            } else {
                // Paid across the counter: the guest keeps their own guest record, the room folio stays clean.
                $guestId = $stay?->guest_id ?? $stay?->reservation?->guest_id;
                if (! $guestId) {
                    $name = preg_split('/\s+/', trim($o->guest_name), 2);
                    $guestId = Guest::create(['first_name' => $name[0], 'last_name' => $name[1] ?? null, 'phone' => $o->phone])->id;
                }
                $folio = FolioService::openStandalone(['guest_id' => $guestId, 'type' => 'walk_in', 'reservation_id' => $stay?->reservation_id]);
            }
            $tx = FolioService::charge($folio, 'laundry', $desc, $quote['net'], true, $extra);
            if ($direct) {
                $amountDue = round((float) $tx->amount + (float) $tx->tax_amount, 2);
                if ($payments) {
                    $allocated = round(array_sum(array_map(fn ($line) => round((float) $line['amount'], 2), $payments)), 2);
                    if (abs($allocated - $amountDue) > 0.005) {
                        throw ValidationException::withMessages(['payments' => 'Payment tenders must total '.money($amountDue).' to settle the laundry order.']);
                    }
                    foreach ($payments as $i => $line) {
                        if (! self::isDirectMode((int) ($line['payment_mode_id'] ?? 0))) {
                            throw ValidationException::withMessages(["payments.{$i}.payment_mode_id" => 'Choose an active direct payment mode.']);
                        }
                        $lineReference = trim((string) ($line['reference'] ?? '')) ?: $o->order_no;
                        if (filled($line['momo_network'] ?? null) || filled($line['momo_phone'] ?? null)) {
                            $lineReference = mb_substr(trim(implode(' · ', array_filter([
                                $line['momo_network'] ?? null, $line['momo_phone'] ?? null, $lineReference,
                            ]))), 0, 80);
                        }
                        FolioService::payment($folio, round((float) $line['amount'], 2), (int) $line['payment_mode_id'], $lineReference, 'settlement', 'payment', [
                            'bank_name' => $line['cheque_bank_name'] ?? null, 'number' => $line['cheque_number'] ?? null,
                            'bank_branch' => $line['cheque_bank_branch'] ?? null,
                        ]);
                    }
                } else {
                    FolioService::payment($folio, $amountDue, (int) $paymentModeId, $reference ?: $o->order_no, 'settlement', 'payment', $cheque);
                }
                FolioService::close($folio->fresh());
            }
            $o->forceFill(['status' => 'delivered', 'delivered_at' => now(), 'delivered_by' => auth()->id(), 'folio_id' => $folio->id,
                'folio_transaction_id' => $tx->id, 'payment_mode_id' => $direct && ! $payments ? $paymentModeId : null])->save();
            if ($o->guest_request_id && ($req = GuestRequest::find($o->guest_request_id)) && in_array($req->status, GuestRequest::OPEN)) {
                GuestPortalService::transition($req, 'done', "Laundry delivered – {$o->pieces} pcs");
            }
            ActivityLogger::log('laundry', 'deliver', "Laundry {$o->order_no} delivered – ".money($quote['total'])
                .($direct ? " paid, invoice {$folio->fresh()->invoice_no}" : " posted to {$folio->folio_no}"), $o);
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

    /** Orders still with the laundry for a stay – these block check-out. */
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
