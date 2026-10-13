<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\FolioTransaction;
use App\Models\Package;
use App\Models\PackageEntitlement;
use App\Models\PackageRedemption;
use App\Models\RatePlan;
use App\Models\ReservationRoom;
use App\Models\StayFixedCharge;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v5.5 Rate packages, meal plans & inclusions.
 *
 * The folio keeps showing the full room tariff (the guest bought "Deluxe B&B at GH₵ 4,650"), and every night the
 * packages included in the rate are allocated out of it into an entitlement: 2 breakfast covers for tomorrow morning,
 * GH₵ 600 of dinner allowance tonight. The restaurant door records what was actually used; the night audit expires the
 * rest as breakage. In the general ledger a daily journal (source "pkg_day") moves the package value out of room
 * revenue into F&B revenue (fixed inclusions) or into an unconsumed-allowance liability that is released on use or
 * on breakage. Add-on packages are sold as fixed charges (they post their own folio line) and follow the same
 * entitlement cycle, so a paid dinner that is never eaten is deferred and released the same way.
 */
class PackageService
{
    public const DEFAULTS = [
        // code, name, category, meal, calc, adult, child, rhythm, service_day, allowance, mode, overage, sellable, description
        ['BRKF', 'Breakfast buffet', 'fnb', 'breakfast', 'per_person', 200, 100, 'every_night', 'next_day', true, 'covers', 250, true, 'Served the morning after each night'],
        ['LNCH', 'Lunch', 'fnb', 'lunch', 'per_person', 250, 125, 'every_night', 'same_day', true, 'covers', 300, true, 'Set lunch or à la carte up to the package value'],
        ['DINR', 'Dinner allowance', 'fnb', 'dinner', 'per_person', 300, 150, 'every_night', 'same_day', true, 'amount', 0, true, 'Spend up to the allowance at dinner – the difference is charged to the room'],
        ['AIRP', 'Airport drop (KIA)', 'transport', null, 'per_room', 450, 0, 'departure', 'next_day', false, 'covers', 0, true, 'Kotoka International Airport drop on departure'],
        ['WIFI', 'Premium Wi-Fi', 'misc', null, 'per_room', 60, 0, 'every_night', 'same_day', false, 'covers', 0, true, 'High-speed Wi-Fi for all devices'],
        ['SPA', 'Spa credit', 'spa', null, 'per_room', 400, 0, 'once', 'same_day', true, 'amount', 0, true, 'Once per stay, redeem at the spa – unused credit is not refunded'],
    ];

    public static function ensureDefaults(): void
    {
        foreach (\App\Http\Controllers\PackageController::SETTINGS as $k => [$v]) {
            \App\Models\Setting::firstOrCreate(['key' => $k], ['value' => (string) $v]);
        }
        \App\Models\Setting::$version++;
        if (Package::exists()) {
            return;
        }
        $rest = \App\Models\Outlet::where('type', 'restaurant')->orderBy('id')->value('id');
        foreach (self::DEFAULTS as $i => [$code, $name, $cat, $meal, $calc, $pa, $pc, $rh, $sd, $allow, $mode, $ov, $sell, $desc]) {
            Package::create(['code' => $code, 'name' => $name, 'category' => $cat, 'meal' => $meal, 'calc' => $calc, 'price_adult' => $pa, 'price_child' => $pc,
                'rhythm' => $rh, 'service_day' => $sd, 'is_allowance' => $allow, 'redeem_mode' => $mode, 'overage_price' => $ov, 'is_sellable' => $sell,
                'apply_tax' => true, 'description' => $desc, 'outlet_id' => $meal ? $rest : null, 'sort' => ($i + 1) * 10, 'is_active' => true]);
        }
    }

    /** Active packages included in a rate plan. */
    public static function includedIn(?int $ratePlanId): Collection
    {
        if (! $ratePlanId) {
            return collect();
        }

        return RatePlan::with(['packages' => fn ($q) => $q->where('is_active', true)])->find($ratePlanId)?->packages ?? collect();
    }

    /** Is the package earned on this stay night? */
    public static function earnedOn(Package $p, ReservationRoom $stay, Carbon $night): bool
    {
        $arr = $stay->arrival_date->toDateString();
        $last = $stay->departure_date->copy()->subDay()->max($stay->arrival_date)->toDateString();
        $n = $night->toDateString();

        return match ($p->rhythm) {
            'arrival' => $n === $arr,
            'departure' => $n === $last,
            'once' => ! PackageEntitlement::where('reservation_room_id', $stay->id)->where('package_id', $p->id)->where('source', 'included')->whereDate('night_date', '!=', $n)->exists(),
            default => true,
        };
    }

    public static function serviceDate(Package $p, Carbon $night): Carbon
    {
        return $p->service_day === 'next_day' ? $night->copy()->addDay() : $night->copy();
    }

    /**
     * Allocate tonight's included packages out of the room tariff. Idempotent per (stay, package, night).
     * When the packages are worth more than the tariff (comp or heavily discounted rate) their values are scaled
     * down pro rata so room revenue never goes negative – the guest keeps the covers.
     */
    public static function allocate(ReservationRoom $stay, Carbon $night, ?FolioTransaction $roomTx = null): int
    {
        $pkgs = self::includedIn($stay->rate_plan_id)->filter(fn ($p) => self::earnedOn($p, $stay, $night));
        if ($pkgs->isEmpty()) {
            return 0;
        }
        $a = (int) $stay->adults;
        $c = (int) $stay->children;
        $quotes = $pkgs->mapWithKeys(fn ($p) => [$p->id => $p->quote($a, $c)]);
        $total = $quotes->sum(fn ($q) => $q[1]);
        $rate = max(0, (float) ($roomTx?->amount ?? $stay->rate));
        $factor = $total > 0 ? min(1, $rate / $total) : 1;
        $n = 0;
        foreach ($pkgs as $p) {
            [$qty, $amount] = $quotes[$p->id];
            $e = PackageEntitlement::firstOrCreate(
                ['reservation_room_id' => $stay->id, 'package_id' => $p->id, 'night_date' => $night->toDateString(), 'source' => 'included'],
                ['folio_transaction_id' => $roomTx?->id, 'service_date' => self::serviceDate($p, $night)->toDateString(), 'adults' => $a, 'children' => $c,
                    'qty' => $qty, 'amount' => round($amount * $factor, 2), 'is_allowance' => $p->is_allowance,
                    'status' => $p->is_allowance ? 'open' : 'consumed', 'consumed_qty' => $p->is_allowance ? 0 : $qty,
                    'consumed_amount' => $p->is_allowance ? 0 : round($amount * $factor, 2), 'consumed_date' => $p->is_allowance ? null : $night->toDateString()]);
            $e->wasRecentlyCreated && $n++;
        }

        return $n;
    }

    /** Entitlements for add-on packages whose fixed charge has posted to the folio (idempotent). */
    public static function allocateAddons(ReservationRoom $stay): int
    {
        $charges = StayFixedCharge::with('package')->where('reservation_room_id', $stay->id)->whereNotNull('package_id')->get();
        if ($charges->isEmpty()) {
            return 0;
        }
        $n = 0;
        $txs = FolioTransaction::where('source_type', 'fixed')->whereIn('source_id', $charges->pluck('id'))->where('is_void', false)->get();
        foreach ($txs as $tx) {
            $fc = $charges->firstWhere('id', $tx->source_id);
            $p = $fc?->package;
            if (! $p) {
                continue;
            }
            $night = Carbon::parse($tx->reference ?: $tx->business_date);
            [$qty] = $p->quote((int) $stay->adults, (int) $stay->children);
            $e = PackageEntitlement::firstOrCreate(
                ['reservation_room_id' => $stay->id, 'package_id' => $p->id, 'night_date' => $night->toDateString(), 'source' => 'addon'],
                ['stay_fixed_charge_id' => $fc->id, 'folio_transaction_id' => $tx->id, 'service_date' => self::serviceDate($p, $night)->toDateString(),
                    'adults' => $stay->adults, 'children' => $stay->children, 'qty' => $qty, 'amount' => (float) $tx->amount, 'is_allowance' => $p->is_allowance,
                    'status' => $p->is_allowance ? 'open' : 'consumed', 'consumed_qty' => $p->is_allowance ? 0 : $qty,
                    'consumed_amount' => $p->is_allowance ? 0 : (float) $tx->amount, 'consumed_date' => $p->is_allowance ? null : $night->toDateString()]);
            $e->wasRecentlyCreated && $n++;
        }

        return $n;
    }

    /** Sell an add-on package to a stay: posted as a fixed charge with the stay's party size. */
    public static function addToStay(ReservationRoom $stay, Package $p, string $start, ?string $end = null): StayFixedCharge
    {
        if (! $p->is_active || ! $p->is_sellable) {
            throw new RuntimeException("{$p->name} is not sold as an add-on.");
        }
        if (! in_array($stay->status, ['reserved', 'checked_in'])) {
            throw new RuntimeException('Packages can only be added to a reserved or in-house stay.');
        }
        $s = Carbon::parse($start);
        $lastNight = $stay->departure_date->copy()->subDay()->max($stay->arrival_date);
        if ($s->lt($stay->arrival_date) || $s->gt($lastNight)) {
            throw new RuntimeException('The package must start on a night of the stay ('.$stay->arrival_date->format('d M').' – '.$lastNight->format('d M').').');
        }
        $once = in_array($p->rhythm, ['once', 'arrival', 'departure']);
        if ($p->rhythm === 'departure') {
            $s = $lastNight->copy();
        }
        $e = $once ? null : ($end ? Carbon::parse($end)->min($lastNight) : null);
        if ($e && $e->lt($s)) {
            throw new RuntimeException('The end date is before the start date.');
        }
        $overlap = StayFixedCharge::where('reservation_room_id', $stay->id)->where('package_id', $p->id)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('end_date')->whereDate('end_date', '>=', $s->toDateString()))
            ->when($e, fn ($q) => $q->whereDate('start_date', '<=', $e->toDateString()))->exists();
        if ($overlap) {
            throw new RuntimeException("{$p->name} is already on this stay for those nights.");
        }
        [$qty, $amount] = $p->quote((int) $stay->adults, (int) $stay->children);
        if ($amount <= 0) {
            throw new RuntimeException("{$p->name} has no price.");
        }
        $fc = StayFixedCharge::create(['reservation_room_id' => $stay->id, 'package_id' => $p->id,
            'description' => $p->name.($p->calc === 'per_room' ? '' : " ({$qty} pax)"), 'category' => $p->category, 'amount' => $amount,
            'apply_tax' => $p->apply_tax, 'frequency' => $once ? 'once' : 'daily', 'start_date' => $s->toDateString(), 'end_date' => $e?->toDateString(),
            'created_by' => auth()->id()]);
        ActivityLogger::log('packages', 'add_on', "Added package {$p->code} to room ".($stay->room?->room_no ?? '#'.$stay->id).' '.money($amount).($once ? ' once' : ' per night'), $fc);

        return $fc;
    }

    // ------------------------------------------------------------------ redemption

    public static function nextNo(): string
    {
        $last = PackageRedemption::orderByDesc('id')->value('redemption_no');

        return 'PKR-'.str_pad((string) ((int) substr((string) $last, 4) + 1), 5, '0', STR_PAD_LEFT);
    }

    /** Entitlements usable today: service date within the grace window, not yet fully used, stay not cancelled. */
    public static function usable(?Carbon $on = null): \Illuminate\Database\Eloquent\Builder
    {
        $on ??= bdate();
        $grace = (int) setting('pkg_grace_days', '0');

        return PackageEntitlement::query()->whereIn('status', ['open', 'partial'])->where('is_allowance', true)
            ->whereBetween('service_date', [$on->copy()->subDays($grace)->toDateString(), $on->toDateString()]);
    }

    /**
     * Record covers served / allowance spent. Anything beyond the entitlement is an extra: charged to the room folio
     * at the package's walk-in price, paid at the outlet, or waived.
     *
     * @param  array{covers?:int, amount?:float, extra_covers?:int, overage_mode?:string, outlet_id?:int, notes?:string, pos_ref?:string}  $in
     */
    public static function redeem(ReservationRoom $stay, Package $p, array $in): PackageRedemption
    {
        return DB::transaction(function () use ($stay, $p, $in) {
            if (! in_array($stay->status, ['checked_in', 'checked_out'])) {
                throw new RuntimeException('Only in-house guests (or guests who left today) can use packages.');
            }
            $today = bdate();
            if ($stay->status === 'checked_out' && $stay->checked_out_at && $stay->checked_out_at->lt($today->copy()->subDay())) {
                throw new RuntimeException('This guest has already left.');
            }
            $ent = self::usable($today)->where('reservation_room_id', $stay->id)->where('package_id', $p->id)
                ->orderBy('service_date')->orderBy('id')->lockForUpdate()->first();
            $mode = $in['overage_mode'] ?? setting('pkg_overage_default', 'folio');
            $covers = $amount = $extraCovers = 0;
            $extraAmount = 0.0;
            if ($p->redeem_mode === 'amount') {
                $spend = round((float) ($in['amount'] ?? 0), 2);
                if ($spend <= 0) {
                    throw new RuntimeException('Enter the amount spent.');
                }
                $free = $ent ? min($spend, $ent->remainingAmount()) : 0;
                $amount = $free;
                $covers = $ent ? 1 : 0;
                $extraAmount = round($spend - $free, 2);
            } else {
                $want = (int) ($in['covers'] ?? 0) + (int) ($in['extra_covers'] ?? 0);
                if ($want <= 0) {
                    throw new RuntimeException('Enter the number of covers served.');
                }
                $covers = $ent ? min($want, $ent->remainingQty()) : 0;
                $extraCovers = $want - $covers;
                $amount = $ent && $covers > 0 ? self::valueOf($ent, $covers) : 0;
                $extraAmount = round($extraCovers * (float) $p->overage_price, 2);
            }
            if ($extraAmount > 0 && $mode === 'folio' && ($stay->folio?->status ?? null) !== 'open') {
                throw new RuntimeException('The folio is closed – extras must be paid at the outlet.');
            }
            if (! $ent && $extraAmount <= 0 && $extraCovers <= 0) {
                throw new RuntimeException("No {$p->name} entitlement for this room today.");
            }
            $r = PackageRedemption::create(['redemption_no' => self::nextNo(), 'package_entitlement_id' => $ent?->id, 'reservation_room_id' => $stay->id,
                'package_id' => $p->id, 'outlet_id' => $in['outlet_id'] ?? $p->outlet_id, 'business_date' => $today->toDateString(), 'covers' => $covers,
                'amount' => $amount, 'extra_covers' => $extraCovers, 'extra_amount' => $extraAmount, 'overage_mode' => $extraAmount > 0 ? $mode : 'folio',
                'pos_ref' => $in['pos_ref'] ?? null, 'notes' => $in['notes'] ?? null, 'recorded_by' => auth()->id()]);
            if ($extraAmount > 0 && $mode === 'folio') {
                $desc = $p->name.' – '.($p->redeem_mode === 'amount' ? 'over allowance' : "{$extraCovers} extra cover".($extraCovers > 1 ? 's' : ''));
                $tx = FolioService::charge($stay->folio, $p->category, $desc, $extraAmount, $p->apply_tax, ['source_type' => 'package', 'source_id' => $r->id, 'reference' => $r->redemption_no]);
                $r->update(['folio_transaction_id' => $tx->id]);
            }
            $ent && self::recompute($ent);
            ActivityLogger::log('packages', 'redeem', "{$r->redemption_no}: room ".($stay->room?->room_no ?? '#'.$stay->id)." {$p->code} "
                .($p->redeem_mode === 'amount' ? money($amount) : "{$covers} cover(s)").($extraAmount > 0 ? ' + extra '.money($extraAmount).' ('.$mode.')' : ''), $r);

            return $r;
        });
    }

    /** Value of $covers covers of an entitlement (the last cover takes the rounding). */
    public static function valueOf(PackageEntitlement $e, int $covers): float
    {
        if ($covers >= $e->remainingQty()) {
            return $e->remainingAmount();
        }

        return round((float) $e->amount * $covers / max(1, (int) $e->qty), 2);
    }

    public static function void(PackageRedemption $r, string $reason): void
    {
        if ($r->voided_at) {
            throw new RuntimeException('Already voided.');
        }
        if ($r->entitlement && $r->entitlement->status === 'expired') {
            throw new RuntimeException('The entitlement has already expired in a night audit – the redemption can no longer be voided.');
        }
        DB::transaction(function () use ($r, $reason) {
            if ($r->folioTransaction && ! $r->folioTransaction->is_void) {
                FolioService::void($r->folioTransaction, 'Package redemption '.$r->redemption_no.' voided: '.$reason);
            }
            $r->update(['voided_at' => now(), 'voided_by' => auth()->id(), 'void_reason' => $reason]);
            $r->entitlement && self::recompute($r->entitlement);
        });
        ActivityLogger::log('packages', 'void', "Voided {$r->redemption_no}: {$reason}", $r);
    }

    public static function recompute(PackageEntitlement $e): PackageEntitlement
    {
        $live = PackageRedemption::where('package_entitlement_id', $e->id)->whereNull('voided_at');
        $qty = (int) (clone $live)->sum('covers');
        $amt = round((float) (clone $live)->sum('amount'), 2);
        $last = (clone $live)->max('business_date');
        $byAmount = $e->package?->redeem_mode === 'amount';
        $full = $byAmount ? ((float) $e->amount > 0 ? $amt >= (float) $e->amount - 0.005 : $qty > 0) : $qty >= max(1, (int) $e->qty);
        $status = in_array($e->status, ['expired', 'cancelled']) ? $e->status : ($full ? 'consumed' : ($qty > 0 || $amt > 0 ? 'partial' : 'open'));
        $e->update(['consumed_qty' => min($qty, $e->qty), 'consumed_amount' => min($amt, (float) $e->amount), 'consumed_date' => $last, 'status' => $status]);

        return $e;
    }

    // ------------------------------------------------------------------ night audit

    /**
     * Closing $date: entitlements whose service date (+ grace) has passed lapse – the unused value is breakage.
     * Entitlements of a voided room night / add-on charge that were never used are cancelled.
     *
     * @return array{expired:int, breakage:float, cancelled:int}
     */
    public static function expireDue(Carbon $date): array
    {
        $out = ['expired' => 0, 'breakage' => 0.0, 'cancelled' => 0];
        $voided = FolioTransaction::where('is_void', true)->whereIn('id', PackageEntitlement::whereIn('status', ['open', 'consumed'])->where('consumed_qty', 0)
            ->whereNotNull('folio_transaction_id')->select('folio_transaction_id'))->pluck('id');
        foreach (PackageEntitlement::whereIn('folio_transaction_id', $voided)->whereIn('status', ['open', 'consumed'])->get() as $e) {
            if ($e->status === 'consumed' && $e->is_allowance) {
                continue;
            }
            $e->update(['status' => 'cancelled', 'consumed_qty' => 0, 'consumed_amount' => 0]);
            $out['cancelled']++;
        }
        $cut = $date->copy()->subDays((int) setting('pkg_grace_days', '0'))->toDateString();
        foreach (PackageEntitlement::whereIn('status', ['open', 'partial'])->where('is_allowance', true)->whereDate('service_date', '<=', $cut)->get() as $e) {
            $b = $e->remainingAmount();
            $e->update(['status' => 'expired', 'expired_date' => $date->toDateString(), 'breakage_amount' => $b]);
            $out['expired']++;
            $out['breakage'] += $b;
        }
        $out['breakage'] = round($out['breakage'], 2);

        return $out;
    }

    /** Called from FolioService::void – a waived night takes its unused packages with it. */
    public static function onFolioVoid(FolioTransaction $tx): void
    {
        if (! in_array($tx->source_type, ['night', 'fixed'])) {
            return;
        }
        PackageEntitlement::where('folio_transaction_id', $tx->id)->where('consumed_qty', 0)->where('consumed_amount', 0)->where('status', 'open')
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
        PackageEntitlement::where('folio_transaction_id', $tx->id)->where('is_allowance', false)->where('status', 'consumed')
            ->update(['status' => 'cancelled', 'consumed_qty' => 0, 'consumed_amount' => 0, 'updated_at' => now()]);
    }

    // ------------------------------------------------------------------ forecast

    /**
     * Expected covers per service date for every allowance package, from reservations on the books and in-house
     * stays (included packages + active add-ons).
     *
     * @return array<string, array<int, array{rooms:int, adults:int, children:int, covers:int, value:float}>>
     */
    public static function forecast(Carbon $from, Carbon $to): array
    {
        $pkgs = Package::where('is_active', true)->where('is_allowance', true)->get()->keyBy('id');
        $out = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $out[$d->toDateString()] = [];
        }
        if ($pkgs->isEmpty()) {
            return $out;
        }
        $stays = ReservationRoom::with(['fixedCharges' => fn ($q) => $q->where('is_active', true)->whereNotNull('package_id')])
            ->whereIn('status', ['reserved', 'checked_in'])
            ->whereHas('reservation', fn ($q) => $q->whereNotIn('status', ['cancelled', 'tentative']))
            ->whereDate('arrival_date', '<=', $to->toDateString())->whereDate('departure_date', '>=', $from->copy()->subDay()->toDateString())->get();
        $plans = [];
        foreach ($stays as $s) {
            $lastNight = $s->departure_date->copy()->subDay()->max($s->arrival_date);
            $lines = [];
            $plans[$s->rate_plan_id ?? 0] ??= self::includedIn($s->rate_plan_id);
            foreach ($plans[$s->rate_plan_id ?? 0] as $p) {
                if ($pkgs->has($p->id)) {
                    $lines[] = [$pkgs[$p->id], $s->arrival_date->copy(), $lastNight->copy(), $p->rhythm];
                }
            }
            foreach ($s->fixedCharges as $fc) {
                if ($pkgs->has($fc->package_id)) {
                    $end = $fc->frequency === 'once' ? $fc->start_date->copy() : ($fc->end_date ? $fc->end_date->copy()->min($lastNight) : $lastNight->copy());
                    $lines[] = [$pkgs[$fc->package_id], $fc->start_date->copy(), $end, 'every_night'];
                }
            }
            foreach ($lines as [$p, $n0, $n1, $rhythm]) {
                if ($rhythm === 'arrival' || $rhythm === 'once') {
                    $n1 = $n0->copy();
                } elseif ($rhythm === 'departure') {
                    $n0 = $n1->copy();
                }
                [$qty, $val] = $p->quote((int) $s->adults, (int) $s->children);
                for ($n = $n0->copy(); $n->lte($n1); $n->addDay()) {
                    $sd = self::serviceDate($p, $n)->toDateString();
                    if (! isset($out[$sd])) {
                        continue;
                    }
                    $cell = $out[$sd][$p->id] ?? ['rooms' => 0, 'adults' => 0, 'children' => 0, 'covers' => 0, 'value' => 0.0];
                    $cell['rooms']++;
                    $cell['adults'] += (int) $s->adults;
                    $cell['children'] += (int) $s->children;
                    $cell['covers'] += $qty;
                    $cell['value'] += $val;
                    $out[$sd][$p->id] = $cell;
                }
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ meal plan & ledger

    /** EP / CP / MAP / AP from the meals included in a rate plan. */
    public static function mealPlanFor(RatePlan $plan): string
    {
        $meals = $plan->packages()->where('is_active', true)->whereNotNull('meal')->pluck('meal')->unique();

        return match (true) {
            $meals->contains('breakfast') && $meals->contains('lunch') && $meals->contains('dinner') => 'AP',
            $meals->contains('breakfast') && ($meals->contains('lunch') || $meals->contains('dinner')) => 'MAP',
            $meals->contains('breakfast') => 'CP',
            $meals->isNotEmpty() => 'MAP',
            default => 'EP',
        };
    }

    public static function breakageAccount(Package $p): int
    {
        return match (setting('pkg_breakage_to', 'breakage')) {
            'room' => LedgerService::categoryAccount('room'),
            'package' => LedgerService::categoryAccount($p->category),
            default => AccountHead::sys('rev_pkg_breakage'),
        };
    }

    /**
     * One journal per business date:
     *   allocation   Dr Room revenue (included) / Dr package revenue (paid add-on allowance)
     *                Cr Package allowances – unconsumed (allowances) or package revenue (fixed inclusions)
     *   consumption  Dr Package allowances / Cr package revenue (F&B, spa …)
     *   breakage     Dr Package allowances / Cr breakage income (setting)
     */
    public static function forGlDay(string $date): ?array
    {
        $liab = AccountHead::sys('package_liability');
        $room = LedgerService::categoryAccount('room');
        $lines = [];
        $alloc = PackageEntitlement::with('package')->where('night_date', $date)->where('status', '!=', 'cancelled')->where('amount', '>', 0)->get();
        foreach ($alloc as $e) {
            if (! $e->package) {
                continue;
            }
            $rev = LedgerService::categoryAccount($e->package->category);
            $a = (float) $e->amount;
            if ($e->source === 'included') {
                $lines[] = [$room, $a, 'Included '.$e->package->name];
                $lines[] = [$e->is_allowance ? $liab : $rev, -$a, $e->package->name];
            } elseif ($e->is_allowance) {
                $lines[] = [$rev, $a, 'Add-on '.$e->package->name.' deferred'];
                $lines[] = [$liab, -$a, $e->package->name];
            }
        }
        $used = PackageRedemption::with('package', 'entitlement')->where('business_date', $date)->whereNull('voided_at')->where('amount', '>', 0)->whereNotNull('package_entitlement_id')->get();
        foreach ($used as $r) {
            if (! $r->package || ($r->entitlement?->status === 'cancelled')) {
                continue;
            }
            $lines[] = [$liab, (float) $r->amount, $r->redemption_no];
            $lines[] = [LedgerService::categoryAccount($r->package->category), -(float) $r->amount, $r->package->name.' used'];
        }
        foreach (PackageEntitlement::with('package')->where('expired_date', $date)->where('status', 'expired')->where('breakage_amount', '>', 0)->get() as $e) {
            if (! $e->package) {
                continue;
            }
            $lines[] = [$liab, (float) $e->breakage_amount, 'Breakage'];
            $lines[] = [self::breakageAccount($e->package), -(float) $e->breakage_amount, $e->package->name.' breakage'];
        }

        return $lines ? ['date' => $date, 'reference' => 'PKG '.$date, 'narration' => 'Package allocation, consumption & breakage', 'lines' => $lines] : null;
    }

    /** Business dates whose package journal may have changed (for LedgerService::sync). */
    public static function glDates(callable $scope): Collection
    {
        $d = collect();
        foreach (['night_date', 'expired_date'] as $col) {
            $d = $d->merge($scope(PackageEntitlement::whereNotNull($col), $col)->distinct()->pluck($col));
        }
        $d = $d->merge($scope(PackageRedemption::query(), 'business_date')->distinct()->pluck('business_date'));

        return $d->filter()->map(fn ($x) => Carbon::parse($x)->toDateString())->unique()->values();
    }

    /** Rooms with an unused entitlement usable today (Meal Desk badge). */
    public static function deskCount(): int
    {
        return (int) self::usable()->distinct('reservation_room_id')->count('reservation_room_id');
    }

    /** Open liability: allowance value allocated but neither used nor expired. */
    public static function openLiability(): float
    {
        return round((float) PackageEntitlement::whereIn('status', ['open', 'partial'])->where('is_allowance', true)
            ->selectRaw('COALESCE(SUM(amount - consumed_amount), 0) v')->value('v'), 2);
    }
}
