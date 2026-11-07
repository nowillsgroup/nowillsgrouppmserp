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
use RuntimeException;

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
        RecreationShiftService::requireOpenForSelling();
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
            $entered = round($unit * $qty, 2);
            $t = ClubBillingService::tax($entered, $type->tax_rate !== null ? (float) $type->tax_rate : null);
            $lines[] = ['type' => $type, 'qty' => $qty, 'unit' => $unit, 'net' => $t['net']] + $t;
            $subtotal += $t['net'];
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
            $rawPayments = $d['payments'] ?? [];
            if (! is_array($rawPayments) || count($rawPayments) > 10) {
                throw ValidationException::withMessages(['payments' => 'A ticket sale accepts one to ten direct payment rows.']);
            }
            foreach (array_values(array_filter($rawPayments)) as $i => $pay) {
                if (! is_array($pay)) {
                    throw ValidationException::withMessages(["payments.{$i}" => ['Each tender must be an object.']]);
                }
                $mode = PaymentMode::where('is_active', true)->whereIn('type', ['cash', 'card', 'bank', 'wallet'])
                    ->find((int) ($pay['payment_mode_id'] ?? 0));
                $amt = round((float) ($pay['amount'] ?? 0), 2);
                if (! $mode || $amt <= 0) {
                    throw ValidationException::withMessages(["payments.{$i}" => ['Choose an active cash, card, bank or mobile-money mode and an amount above zero. Gift vouchers are not accepted at this outlet.']]);
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
                'business_date' => $date,
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
                'department_shift_session_id' => RecreationShiftService::active($u?->id)?->id,
            ]);

            $folio = $stay ? FolioService::openForStay($stay) : null;
            foreach ($payments as $pay) {
                ClubTicketPayment::create(['sale_id' => $sale->id, 'payment_mode_id' => $pay['mode']->id, 'amount' => $pay['amount'],
                    'reference' => $pay['reference'], 'received_by' => $u?->id, 'received_at' => now()]);
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
                        $shareTax = $n === 0 ? $line['tax'] : 0.0; // the line is already split into net + tax
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
                            'tax_rate' => $line['rate'],
                            'tax' => $shareTax,
                            'total' => round($share + $shareTax, 2),
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
        if (($sale->business_date?->toDateString() ?? $sale->sold_at->toDateString()) !== bdate()->toDateString()) {
            throw ValidationException::withMessages(['reason' => 'An outlet sale can only be voided on the business day it was rung up.']);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for the void.']);
        }
        $admitted = $sale->passes()->whereNotNull('redeemed_at')->whereNull('cancelled_at')->first();
        if ($admitted) {
            throw ValidationException::withMessages(['reason' => 'Ticket '.$admitted->pass_no.' has already been admitted at '
                .$admitted->redeemed_at->format('H:i').'. Reverse the admission before voiding this sale.']);
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

    /** Counter takings for the day-close: desk day passes + outlet sales not charged to a room (folio covers those). */
    public static function counterTakings(string $date): array
    {
        $passes = ClubDayPass::whereNull('cancelled_at')->whereNull('reservation_room_id')->whereNull('sale_id')->whereDate('date', $date)->get(['amount', 'tax', 'total']);
        $sales = ClubTicketSale::where('status', 'paid')->whereNull('reservation_room_id')->whereDate('business_date', $date)->get(['subtotal', 'tax', 'total']);

        return [
            'revenue' => round((float) $passes->sum('amount') + (float) $sales->sum('subtotal'), 2),
            'tax' => round((float) $passes->sum('tax') + (float) $sales->sum('tax'), 2),
            'collections' => round((float) $passes->sum('total') + (float) $sales->sum('total'), 2),
        ];
    }

    /**
     * Admit a ticket at the gate: find the pass by its printed code and record the check-in.
     *
     * A pass is only good for the day it was sold, only while it stands (not cancelled by a
     * void), and only once — the second presentation is refused and the first admission is
     * named so the gate can see who let them in.
     */
    public static function redeem(string $code, ?\App\Models\User $u = null, ?int $pax = null, ?string $note = null): ClubDayPass
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            throw new RuntimeException('Enter the ticket code printed on the stub.');
        }
        $pass = ClubDayPass::with(['sale', 'host', 'ticketType'])->whereRaw('UPPER(pass_no) = ?', [$code])->first();
        if (! $pass) {
            throw new RuntimeException('No ticket carries the code '.$code.'.');
        }
        if ($pass->cancelled_at) {
            throw new RuntimeException('Ticket '.$pass->pass_no.' was cancelled'.($pass->cancel_reason ? ' – '.$pass->cancel_reason : '').'.');
        }
        if ($pass->date->toDateString() !== bdate()->toDateString()) {
            throw new RuntimeException('Ticket '.$pass->pass_no.' is for '.dmy($pass->date).', not today.');
        }
        if ($pass->redeemed_at) {
            throw new RuntimeException('Ticket '.$pass->pass_no.' was already used at '.$pass->redeemed_at->format('H:i')
                .($pass->redeemer ? ' by '.$pass->redeemer->name : '').'.');
        }
        $entitled = (int) $pass->adults + (int) $pass->children;
        $pax = $pax === null || $pax < 1 ? max(1, $entitled) : $pax;
        if ($pax > max(1, $entitled)) {
            throw new RuntimeException('Ticket '.$pass->pass_no.' admits '.$entitled.' guest(s); '.$pax.' cannot be checked in on it.');
        }

        return DB::transaction(function () use ($pass, $u, $pax, $note) {
            $locked = ClubDayPass::whereKey($pass->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->redeemed_at) {
                throw new RuntimeException('Ticket '.$locked->pass_no.' was already used.');
            }
            $locked->forceFill([
                'redeemed_at' => now(),
                'redeemed_by' => $u?->id,
                'redeemed_pax' => $pax,
                'redeemed_note' => trim((string) $note) ? mb_substr(trim($note), 0, 120) : null,
            ])->save();
            ActivityLogger::log('club', 'ticket_redeemed', 'Admitted '.$locked->pass_no.' – '.$pax.' guest(s)'
                .($locked->sale ? ' on sale '.$locked->sale->sale_no : ''), $locked);

            return $locked;
        });
    }

    /**
     * Take an admission back off a ticket — the manager's correction when the wrong stub was
     * admitted, a visitor left immediately, or a sale needs to be voided after the gate.
     * Same business day only, with a reason; the ticket becomes valid again for the gate.
     */
    public static function reverseAdmission(ClubDayPass $pass, string $reason, ?\App\Models\User $u = null): ClubDayPass
    {
        if (! $pass->redeemed_at) {
            throw new RuntimeException('Ticket '.$pass->pass_no.' has not been admitted.');
        }
        if ($pass->cancelled_at) {
            throw new RuntimeException('Ticket '.$pass->pass_no.' is cancelled; its admission cannot be reversed.');
        }
        if ($pass->date->toDateString() !== bdate()->toDateString()) {
            throw new RuntimeException('Only an admission made today can be reversed at the gate.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason for reversing the admission.');
        }

        return DB::transaction(function () use ($pass, $reason, $u) {
            $locked = ClubDayPass::whereKey($pass->getKey())->lockForUpdate()->firstOrFail();
            $was = $locked->redeemed_at;
            $locked->forceFill(['redeemed_at' => null, 'redeemed_by' => null, 'redeemed_pax' => null, 'redeemed_note' => null])->save();
            ActivityLogger::log('club', 'ticket_admission_reversed', 'Reversed the admission of '.$locked->pass_no
                .($was ? ' (admitted '.$was->format('H:i').')' : '').' – '.mb_substr(trim($reason), 0, 120), $locked);

            return $locked;
        });
    }

    /** The gate board for a business date: what was sold today and who has come through. */
    public static function gateBoard(?string $date = null): array
    {
        $date ??= bdate()->toDateString();
        $passes = ClubDayPass::with(['sale', 'host', 'redeemer', 'ticketType'])
            ->whereDate('date', $date)->orderByDesc('id')->get();

        $live = $passes->whereNull('cancelled_at');
        $redeemed = $live->whereNotNull('redeemed_at');

        return [
            'date' => $date,
            'passes' => $passes,
            'issued' => $live->count(),
            'admitted' => $redeemed->count(),
            'waiting' => $live->whereNull('redeemed_at')->count(),
            'cancelled' => $passes->whereNotNull('cancelled_at')->count(),
            'pax_issued' => (int) $live->sum(fn ($p) => (int) $p->adults + (int) $p->children),
            'pax_admitted' => (int) $redeemed->sum(fn ($p) => (int) ($p->redeemed_pax ?? ((int) $p->adults + (int) $p->children))),
            'revenue' => round((float) $live->sum('total'), 2),
            'turnstile' => $live->count() ? (int) round($redeemed->count() * 100 / $live->count()) : 0,
        ];
    }

    public static function todayStats(): array
    {
        $today = bdate()->toDateString();
        $sales = ClubTicketSale::where('status', 'paid')->whereDate('sold_at', $today);

        return [
            'sales' => (clone $sales)->count(),
            'total' => (clone $sales)->sum('total'),
            'tickets' => ClubDayPass::whereNull('cancelled_at')->whereDate('date', $today)->whereNotNull('sale_id')->sum(DB::raw('adults + children')),
            'items' => ClubTicketSaleItem::whereHas('sale', fn ($q) => $q->where('status', 'paid')->whereDate('sold_at', $today))->sum('qty'),
        ];
    }
}
