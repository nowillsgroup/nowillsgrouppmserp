<?php

namespace App\Services;

use App\Models\ClubDayPass;
use App\Models\ClubMember;
use App\Models\ClubTicketPayment;
use App\Models\ClubTicketSale;
use App\Models\ClubTicketSaleItem;
use App\Models\ClubTicketType;
use App\Models\PaymentMode;
use App\Models\ReservationRoom;
use App\Support\Numbering;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * v7.38 Recreation ticketing — a POS-style outlet for Wellness & Recreation.
 *
 * Walk-ins buy tickets / rentals / services over the counter with split-tender
 * payment (cash, card, MoMo, room charge…). Access tickets issue day-pass
 * credentials on the existing club_day_passes table, so the check-in desk,
 * visits and membership flows stay exactly as they were: members scan in on
 * their membership, walk-ins scan a ticket.
 */
class RecreationService
{
    /** Default outlet catalog — created per hotel the first time the outlet opens. */
    public const DEFAULT_TYPES = [
        ['code' => 'ADULT', 'name' => 'Adult day pass', 'category' => 'access', 'pax_type' => 'adult', 'price' => 'club_daypass_adult', 'issues_pass' => true, 'sort' => 10],
        ['code' => 'CHILD', 'name' => 'Child day pass (under 12)', 'category' => 'access', 'pax_type' => 'child', 'price' => 'club_daypass_child', 'issues_pass' => true, 'sort' => 20],
        ['code' => 'GUEST', 'name' => "Member's guest pass", 'category' => 'access', 'pax_type' => 'adult', 'price' => 'club_guestpass', 'issues_pass' => true, 'sort' => 30],
        ['code' => 'GYM', 'name' => 'Gym day ticket', 'category' => 'access', 'pax_type' => 'adult', 'price' => 100, 'issues_pass' => true, 'sort' => 40],
        ['code' => 'COURT', 'name' => 'Court hire (per hour)', 'category' => 'rental', 'pax_type' => 'adult', 'price' => 120, 'issues_pass' => false, 'sort' => 50],
        ['code' => 'TOWEL', 'name' => 'Towel rental', 'category' => 'rental', 'pax_type' => 'adult', 'price' => 20, 'issues_pass' => false, 'sort' => 60],
        ['code' => 'LOCKER', 'name' => 'Locker rental', 'category' => 'rental', 'pax_type' => 'adult', 'price' => 15, 'issues_pass' => false, 'sort' => 70],
        ['code' => 'KIT', 'name' => 'Equipment hire', 'category' => 'rental', 'pax_type' => 'adult', 'price' => 50, 'issues_pass' => false, 'sort' => 80],
        ['code' => 'CLASS', 'name' => 'Class drop-in', 'category' => 'service', 'pax_type' => 'adult', 'price' => 60, 'issues_pass' => false, 'sort' => 90],
        ['code' => 'PT', 'name' => 'Personal training session', 'category' => 'service', 'pax_type' => 'adult', 'price' => 150, 'issues_pass' => false, 'sort' => 100],
    ];

    public static function ensureTypes(?int $hotelId = null): int
    {
        $hotelId ??= Tenant::id();
        if (ClubTicketType::where('hotel_id', $hotelId)->exists()) {
            return 0;
        }
        $made = 0;
        foreach (self::DEFAULT_TYPES as $t) {
            $price = is_string($t['price']) ? (float) ClubService::cfg($t['price']) : $t['price'];
            $t['price'] = $price;
            $type = new ClubTicketType(array_merge(['tax_rate' => null, 'is_active' => true], $t));
            $type->hotel_id = $hotelId; // hotel_id is guarded from mass assignment
            $type->save();
            $made++;
        }

        return $made;
    }

    /**
     * Ring up an outlet sale: lines → taxes → day-pass credentials → tenders.
     *
     * $d: guest_name, phone, host_member_id, items[{type_id,qty,unit_price?}],
     *     settle (pay|room), payments[{payment_mode_id,amount,reference?}], room_no,
     *     reference, notes
     */
    public static function sell(array $d, ?\App\Models\User $u = null): ClubTicketSale
    {
        $items = array_values(array_filter($d['items'] ?? []));
        if (! $items) {
            throw ValidationException::withMessages(['items' => 'Add at least one ticket or item to the cart.']);
        }
        if (count($items) > 40) {
            throw ValidationException::withMessages(['items' => 'A sale can carry at most 40 lines.']);
        }
        $settle = ($d['settle'] ?? 'pay') === 'room' ? 'room' : 'pay';
        $types = ClubTicketType::where('hotel_id', Tenant::id())->where('is_active', true)->get()->keyBy('id');

        $lines = [];
        $subtotal = 0.0;
        $taxSum = 0.0;
        foreach ($items as $i => $line) {
            $type = $types->get((int) ($line['type_id'] ?? 0));
            if (! $type) {
                throw ValidationException::withMessages(['items' => 'Line '.($i + 1).': that ticket type is not on sale.']);
            }
            $qty = (int) ($line['qty'] ?? 0);
            if ($qty < 1 || $qty > 99) {
                throw ValidationException::withMessages(['items' => 'Line '.($i + 1).': quantity must be 1 to 99.']);
            }
            $unit = isset($line['unit_price']) && $line['unit_price'] !== '' ? round((float) $line['unit_price'], 2) : (float) $type->price;
            if ($unit < 0) {
                throw ValidationException::withMessages(['items' => 'Line '.($i + 1).': price cannot be negative.']);
            }
            $net = round($unit * $qty, 2);
            $t = ClubBillingService::tax($net, $type->tax_rate !== null ? (float) $type->tax_rate : null);
            $lines[] = ['type' => $type, 'qty' => $qty, 'unit' => $unit, 'net' => $net] + $t;
            $subtotal += $net;
            $taxSum += $t['tax'];
        }
        $total = round($subtotal + $taxSum, 2);

        // ── tenders ──
        $stay = null;
        $payments = [];
        if ($settle === 'room') {
            $roomNo = trim((string) ($d['room_no'] ?? ''));
            $stay = $roomNo !== '' ? ClubService::residentStay($roomNo) : null;
            if (! $stay) {
                throw ValidationException::withMessages(['room_no' => 'Room '.($roomNo ?: '—').' has no in-house guest to charge.']);
            }
        } else {
            foreach (array_values(array_filter($d['payments'] ?? [])) as $i => $pay) {
                $mode = PaymentMode::find((int) ($pay['payment_mode_id'] ?? 0));
                $amt = round((float) ($pay['amount'] ?? 0), 2);
                if (! $mode || $amt <= 0) {
                    throw ValidationException::withMessages(['payments' => 'Payment '.($i + 1).': choose a mode and an amount above zero.']);
                }
                $payments[] = ['mode' => $mode, 'amount' => $amt, 'reference' => trim((string) ($pay['reference'] ?? '')) ?: null];
            }
            if (! $payments) {
                throw ValidationException::withMessages(['payments' => 'Take payment or charge the sale to a room.']);
            }
            $paid = round(array_sum(array_column($payments, 'amount')), 2);
            if ($paid < $total) {
                throw ValidationException::withMessages(['payments' => 'Tendered '.money($paid).' does not cover '.money($total).'.']);
            }
        }

        $hostId = ! empty($d['host_member_id']) ? (int) $d['host_member_id'] : null;
        if ($hostId && ! ClubMember::whereKey($hostId)->exists()) {
            throw ValidationException::withMessages(['host_member_id' => 'Host member not found.']);
        }
        $date = bdate()->toDateString();
        $paidTotal = $settle === 'room' ? 0.0 : round(array_sum(array_column($payments, 'amount')), 2);

        return DB::transaction(function () use ($d, $u, $lines, $subtotal, $taxSum, $total, $payments, $paidTotal, $stay, $hostId, $date, $settle) {
            $sale = ClubTicketSale::create([
                'sale_no' => Numbering::next('club_tsale'),
                'sold_at' => Carbon::now(),
                'guest_name' => mb_substr(trim((string) ($d['guest_name'] ?? '')), 0, 120) ?: null,
                'phone' => trim((string) ($d['phone'] ?? '')) ?: null,
                'host_member_id' => $hostId,
                'subtotal' => $subtotal,
                'tax' => $taxSum,
                'total' => $total,
                'paid' => $paidTotal,
                'change_due' => max(0, round($paidTotal - $total, 2)),
                'status' => 'paid',
                'reservation_room_id' => $stay?->id,
                'reference' => trim((string) ($d['reference'] ?? '')) ?: null,
                'notes' => trim((string) ($d['notes'] ?? '')) ?: null,
                'user_id' => $u?->id,
            ]);

            $folio = $stay ? FolioService::openForStay($stay) : null;
            foreach ($payments as $pay) {
                ClubTicketPayment::create(['sale_id' => $sale->id, 'payment_mode_id' => $pay['mode']->id, 'amount' => $pay['amount'], 'reference' => $pay['reference']]);
            }

            foreach ($lines as $line) {
                /** @var ClubTicketType $type */
                $type = $line['type'];
                $item = ClubTicketSaleItem::create([
                    'sale_id' => $sale->id,
                    'ticket_type_id' => $type->id,
                    'description' => $type->name,
                    'qty' => $line['qty'],
                    'unit_price' => $line['unit'],
                    'line_total' => $line['net'],
                    'tax_rate' => $line['rate'],
                    'tax' => $line['tax'],
                    'passes' => 0,
                ]);

                $tx = null;
                if ($folio) {
                    $tx = FolioService::charge($folio, 'club', "Recreation outlet – {$type->name} x{$line['qty']} ({$sale->sale_no})", $line['net'], null,
                        ['tax_rate' => $line['rate'], 'source_type' => 'club_ticket_sale', 'source_id' => $sale->id]);
                    if (! $sale->folio_transaction_id) {
                        $sale->update(['folio_transaction_id' => $tx->id]);
                    }
                }

                if ($type->issues_pass && $line['qty'] > 0) {
                    // One credential per access line, split into parties of ≤10 (day-pass cap).
                    $chunks = array_chunk(range(1, $line['qty']), 10);
                    foreach ($chunks as $n => $chunk) {
                        $pax = count($chunk);
                        $share = $n === 0 ? $line['net'] : 0.0; // price stays on the first pass; the rest are pax-only
                        $pt = ClubBillingService::tax($share, $type->tax_rate !== null ? (float) $type->tax_rate : null);
                        $pass = ClubDayPass::create([
                            'pass_no' => Numbering::next('club_daypass'),
                            'date' => $date,
                            'kind' => $hostId ? 'guest' : 'day',
                            'name' => $sale->guest_name ?: $type->name,
                            'phone' => $sale->phone,
                            'adults' => $type->pax_type === 'child' ? 0 : $pax,
                            'children' => $type->pax_type === 'child' ? $pax : 0,
                            'host_member_id' => $hostId,
                            'amount' => $share,
                            'tax_rate' => $pt['rate'],
                            'tax' => $pt['tax'],
                            'total' => $pt['total'],
                            'payment_mode_id' => $stay ? null : ($payments[0]['mode']->id ?? null),
                            'reservation_room_id' => $stay?->id,
                            'folio_transaction_id' => $tx?->id,
                            'reference' => $sale->sale_no,
                            'user_id' => $u?->id,
                            'sale_id' => $sale->id,
                            'ticket_type_id' => $type->id,
                        ]);
                        $item->increment('passes');
                    }
                }
            }

            ActivityLogger::log('club', 'ticket_sale', "Ticket outlet {$sale->sale_no} – ".money($sale->total)
                .($stay ? ' to room '.$stay->room?->room_no : ' ('.count($payments).' tender(s))')
                .' · '.$sale->items()->sum('qty').' item(s), '.$sale->passes()->count().' ticket(s)', $sale);

            return $sale->load(['items.type', 'payments.paymentMode', 'passes']);
        });
    }

    /** Void a sale the day it was sold: reverse room charges, cancel its tickets. */
    public static function void(ClubTicketSale $sale, string $reason, ?\App\Models\User $u = null): void
    {
        if ($sale->isVoid()) {
            throw ValidationException::withMessages(['reason' => 'This sale is already void.']);
        }
        if ($sale->sold_at->toDateString() !== bdate()->toDateString()) {
            throw ValidationException::withMessages(['reason' => 'An outlet sale can only be voided on the day it was rung up.']);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for the void.']);
        }
        DB::transaction(function () use ($sale, $reason, $u) {
            \App\Models\FolioTransaction::where('source_type', 'club_ticket_sale')->where('source_id', $sale->id)
                ->where('is_void', false)->each(fn ($tx) => FolioService::void($tx, 'Outlet sale voided – '.$reason));
            foreach ($sale->passes as $pass) {
                if ($pass->folio_transaction_id && ($tx = \App\Models\FolioTransaction::find($pass->folio_transaction_id)) && ! $tx->is_void) {
                    FolioService::void($tx, 'Outlet sale voided – '.$reason);
                }
                $pass->update(['cancelled_at' => now(), 'cancelled_by' => $u?->id, 'cancel_reason' => mb_substr($reason, 0, 255)]);
                \App\Models\ClubVisit::where('club_day_pass_id', $pass->id)->whereNull('checked_out_at')->update(['checked_out_at' => now()]);
            }
            $sale->update(['status' => 'void', 'cancelled_at' => now(), 'cancelled_by' => $u?->id, 'cancel_reason' => mb_substr($reason, 0, 255)]);
        });
        ActivityLogger::log('club', 'ticket_sale_voided', "Ticket outlet {$sale->sale_no} voided – {$reason}", $sale);
    }

    public static function todayStats(): array
    {
        $today = bdate()->toDateString();
        $sales = ClubTicketSale::where('status', 'paid')->whereDate('sold_at', $today);

        return [
            'sales' => (clone $sales)->count(),
            'total' => (clone $sales)->sum('total'),
            'tickets' => ClubDayPass::whereNull('cancelled_at')->where('date', $today)->whereNotNull('sale_id')->sum(DB::raw('adults + children')),
            'items' => ClubTicketSaleItem::whereHas('sale', fn ($q) => $q->where('status', 'paid')->whereDate('sold_at', $today))->sum('qty'),
        ];
    }
}
