<?php

namespace App\Http\Controllers;

use App\Models\BusinessSource;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\RoomType;
use App\Models\UpsellOffer;
use App\Models\UpsellOrder;
use App\Models\UpsellPresentation;
use App\Services\ActivityLogger;
use App\Services\UpsellService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/** v4.1 – Revenue › Upselling: overview, requests to confirm, orders, offer catalogue, settings; desk selling. */
class UpsellController extends Controller
{
    public const TABS = ['overview' => 'Overview', 'requests' => 'Requests', 'orders' => 'Orders', 'offers' => 'Offer catalogue', 'settings' => 'Settings'];

    public const ICONS = ['layers', 'star', 'login', 'moon', 'coffee', 'cup', 'car', 'plane', 'spa', 'wifi', 'bed', 'house', 'tree', 'clock', 'money'];

    protected function guarded(callable $fn)
    {
        try {
            return $fn();
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function index(Request $request)
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'overview';
        if ($tab === 'settings' && ! can_do('upsell.manage')) {
            $tab = 'overview';
        }
        $bd = bdate()->copy();
        $v = ['tab' => $tab, 'cfg' => UpsellService::cfg(), 'pending' => UpsellService::pendingCount(),
            'mtd' => UpsellService::stats($bd->copy()->startOfMonth(), $bd), 'last30' => UpsellService::stats($bd->copy()->subDays(29), $bd)];
        if (can_do('upsell.manage')) {   // the "New offer" dialog is on every tab
            $v += $this->formData();
        }
        if ($tab === 'overview') {
            $v += ['top' => UpsellOrder::with('offer')->where('status', 'confirmed')->where('created_at', '>=', $bd->copy()->startOfMonth())
                ->groupBy('upsell_offer_id')->selectRaw('upsell_offer_id, count(*) n, sum(amount) amt')->orderByDesc('amt')->limit(6)->get(),
                'today' => UpsellOrder::with('offer', 'stay.room', 'reservation.guest')->where('status', 'confirmed')->whereIn('kind', ['early_checkin', 'late_checkout'])
                    ->whereDate('service_date', $bd->toDateString())->get(),
                'arrivals' => Reservation::with('guest')->where('status', 'confirmed')
                    ->whereDate('arrival_date', '>=', $bd->copy()->addDay()->toDateString())->whereDate('arrival_date', '<=', $bd->copy()->addDays($v['cfg']['days_before'])->toDateString())
                    ->withExists(['upsellPresentations as offered' => fn ($q) => $q->where('channel', 'pre_arrival')->whereNotNull('sent_at')])
                    ->withCount(['upsellOrders as upsells' => fn ($q) => $q->whereIn('status', ['requested', 'confirmed'])])
                    ->orderBy('arrival_date')->limit(25)->get()];
        } elseif ($tab === 'requests') {
            $v += ['requests' => UpsellOrder::with('offer', 'stay.room', 'stay.roomType', 'reservation.guest')->where('status', 'requested')
                ->orderBy('service_date')->orderBy('id')->get()];
        } elseif ($tab === 'orders') {
            $v += ['orders' => UpsellOrder::with('offer', 'reservation.guest', 'stay.room', 'seller', 'charge')
                ->when(array_key_exists((string) $request->query('status'), UpsellOrder::STATUSES), fn ($q) => $q->where('status', $request->query('status')))
                ->when(array_key_exists((string) $request->query('channel'), UpsellOrder::CHANNELS), fn ($q) => $q->where('channel', $request->query('channel')))
                ->when($request->query('offer'), fn ($q, $o) => $q->where('upsell_offer_id', $o))
                ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('order_no', 'like', "%{$s}%")
                    ->orWhereHas('reservation', fn ($r) => $r->where('reservation_no', 'like', "%{$s}%"))))
                ->orderByDesc('id')->paginate(30)->withQueryString(), 'offerNames' => UpsellOffer::orderBy('name')->pluck('name', 'id')];
        } elseif ($tab === 'offers') {
            $v += ['offers' => UpsellOffer::with('toRoomType')->orderBy('sort')->orderBy('name')->get(),
                'offerStats' => UpsellOrder::where('created_at', '>=', $bd->copy()->subDays(29)->startOfDay())->groupBy('upsell_offer_id')
                    ->selectRaw("upsell_offer_id, sum(status = 'confirmed') n, sum(case when status = 'confirmed' then amount else 0 end) amt")->get()->keyBy('upsell_offer_id')];
        }

        return view('upsell.index', $v);
    }

    protected function formData(): array
    {
        return ['roomTypes' => RoomType::where('is_active', true)->orderBy('base_rate')->get(['id', 'code', 'name', 'base_rate']),
            'sources' => BusinessSource::where('is_active', true)->orderBy('name')->pluck('name', 'id')];
    }

    // ───────────────────────────── offer catalogue ─────────────────────────────

    protected function validated(Request $request, ?UpsellOffer $offer = null): array
    {
        $d = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:20', Rule::unique('upsell_offers', 'code')->where('hotel_id', \App\Support\Tenant::id())->ignore($offer?->id)],
            'name' => 'required|string|max:80', 'kind' => ['required', Rule::in(array_keys(UpsellOffer::KINDS))],
            'description' => 'nullable|string|max:1000', 'icon' => ['nullable', Rule::in(self::ICONS)],
            'category' => ['required', Rule::in(array_keys(config('pms.charge_categories')))], 'apply_tax' => 'nullable|boolean',
            'pricing' => ['required', Rule::in(array_keys(UpsellOffer::PRICING))], 'price' => 'nullable|numeric|min:0|max:1000000',
            'diff_pct' => 'nullable|required_if:pricing,rate_difference|numeric|min:1|max:100', 'min_price' => 'nullable|numeric|min:0|max:1000000',
            'to_room_type_id' => 'nullable|required_if:kind,upgrade|integer|owned:room_types',
            'room_type_ids' => 'nullable|array', 'room_type_ids.*' => 'integer|owned:room_types',
            'source_ids' => 'nullable|array', 'source_ids.*' => 'integer|owned:business_sources',
            'channels' => 'required|array|min:1', 'channels.*' => [Rule::in(array_keys(UpsellOffer::CHANNELS))],
            'service_time' => 'nullable|required_if:kind,early_checkin|required_if:kind,late_checkout|date_format:H:i',
            'daily_cap' => 'nullable|integer|min:1|max:999', 'min_nights' => 'required|integer|min:1|max:30', 'lead_hours' => 'required|integer|min:0|max:720',
            'auto_confirm' => 'nullable|boolean', 'incentive_type' => ['required', Rule::in(array_keys(UpsellOffer::INCENTIVES))],
            'incentive_value' => 'nullable|numeric|min:0|max:100000', 'valid_from' => 'nullable|date', 'valid_to' => 'nullable|date|after_or_equal:valid_from',
            'sort' => 'nullable|integer|min:0|max:999', 'is_active' => 'nullable|boolean',
        ], ['to_room_type_id.required_if' => 'Choose the room type the guest is upgraded to.', 'service_time.required_if' => 'Set the time (early check-in from / late check-out until).',
            'diff_pct.required_if' => 'Set the % of the rate difference to charge.', 'channels.required' => 'Choose at least one channel where the offer is shown.']);
        $k = $d['kind'];
        if ($k === 'upgrade' && ! in_array($d['pricing'], ['per_night', 'rate_difference'], true)) {
            throw new RuntimeException('Upgrades are priced per night – a fixed supplement or a % of the rate difference.');
        }
        if ($k !== 'upgrade' && $d['pricing'] === 'rate_difference') {
            throw new RuntimeException('"% of the rate difference" only applies to room upgrades.');
        }
        if (in_array($k, ['early_checkin', 'late_checkout'], true) && ! in_array($d['pricing'], ['per_stay', 'per_person'], true)) {
            throw new RuntimeException('Early check-in and late check-out are priced per stay (or per person).');
        }
        if ($d['pricing'] !== 'rate_difference' && (float) ($d['price'] ?? 0) <= 0) {
            throw new RuntimeException('Enter a price above zero.');
        }
        if (($d['incentive_type'] ?? 'none') === 'pct' && (float) ($d['incentive_value'] ?? 0) > 50) {
            throw new RuntimeException('A percentage incentive above 50% of the sale is not allowed.');
        }
        if ($k === 'upgrade' && ! empty($d['room_type_ids']) && in_array((int) $d['to_room_type_id'], array_map('intval', $d['room_type_ids']), true)) {
            throw new RuntimeException('The target room type cannot also be an eligible booked type.');
        }

        return ['code' => strtoupper($d['code']), 'name' => $d['name'], 'kind' => $k, 'description' => $d['description'] ?? null,
            'icon' => $d['icon'] ?? UpsellOffer::KINDS[$k][1], 'category' => $d['category'], 'apply_tax' => $request->boolean('apply_tax'),
            'pricing' => $d['pricing'], 'price' => $d['pricing'] === 'rate_difference' ? 0 : (float) $d['price'],
            'diff_pct' => $d['pricing'] === 'rate_difference' ? $d['diff_pct'] : null, 'min_price' => $d['pricing'] === 'rate_difference' ? ($d['min_price'] ?? null) : null,
            'to_room_type_id' => $k === 'upgrade' ? $d['to_room_type_id'] : null,
            'room_type_ids' => ! empty($d['room_type_ids']) ? array_values(array_map('intval', $d['room_type_ids'])) : null,
            'source_ids' => ! empty($d['source_ids']) ? array_values(array_map('intval', $d['source_ids'])) : null,
            'channels' => array_values(array_unique($d['channels'])), 'service_time' => in_array($k, ['early_checkin', 'late_checkout'], true) ? $d['service_time'] : null,
            'daily_cap' => $d['daily_cap'] ?? null, 'min_nights' => (int) $d['min_nights'], 'lead_hours' => (int) $d['lead_hours'],
            'auto_confirm' => $request->boolean('auto_confirm'), 'incentive_type' => $d['incentive_type'],
            'incentive_value' => $d['incentive_type'] === 'none' ? 0 : (float) ($d['incentive_value'] ?? 0),
            'valid_from' => $d['valid_from'] ?? null, 'valid_to' => $d['valid_to'] ?? null, 'sort' => (int) ($d['sort'] ?? 0),
            'is_active' => $request->boolean('is_active', true)];
    }

    public function storeOffer(Request $request)
    {
        return $this->guarded(function () use ($request) {
            $o = UpsellOffer::create($this->validated($request));
            ActivityLogger::log('upsell', 'offer_create', "Created upsell offer {$o->code} – {$o->name}", $o);

            return redirect()->route('upsell.index', ['tab' => 'offers'])->with('success', "Offer \"{$o->name}\" created.");
        });
    }

    public function updateOffer(Request $request, UpsellOffer $uoffer)
    {
        return $this->guarded(function () use ($request, $uoffer) {
            $uoffer->update($this->validated($request, $uoffer));
            ActivityLogger::log('upsell', 'offer_update', "Updated upsell offer {$uoffer->code} – {$uoffer->name}", $uoffer);

            return redirect()->route('upsell.index', ['tab' => 'offers'])->with('success', "Offer \"{$uoffer->name}\" saved.");
        });
    }

    public function toggleOffer(UpsellOffer $uoffer)
    {
        $uoffer->update(['is_active' => ! $uoffer->is_active]);
        ActivityLogger::log('upsell', 'offer_toggle', ($uoffer->is_active ? 'Activated' : 'Deactivated')." upsell offer {$uoffer->code}", $uoffer);

        return back()->with('success', "\"{$uoffer->name}\" ".($uoffer->is_active ? 'is on sale again.' : 'is no longer offered.'));
    }

    public function destroyOffer(UpsellOffer $uoffer)
    {
        if ($uoffer->orders()->exists()) {
            return back()->with('error', "\"{$uoffer->name}\" has been sold before – deactivate it instead, so the sales history stays intact.");
        }
        $uoffer->delete();
        ActivityLogger::log('upsell', 'offer_delete', "Deleted upsell offer {$uoffer->code} – {$uoffer->name}");

        return back()->with('success', "Offer \"{$uoffer->name}\" deleted.");
    }

    // ───────────────────────────── orders ─────────────────────────────

    public function sell(Request $request, ReservationRoom $stay)
    {
        $d = $request->validate(['offer_id' => 'required|integer|owned:upsell_offers', 'note' => 'nullable|string|max:255']);

        return $this->guarded(function () use ($d, $stay) {
            $o = UpsellService::place($stay, UpsellOffer::findOrFail($d['offer_id']), 'desk', $d['note'] ?? null);

            return back()->with('success', "{$o->offer->name} added – ".money($o->amount).' will be charged with the room ('.$o->order_no.').');
        });
    }

    public function confirm(Request $request, UpsellOrder $uorder)
    {
        $note = $request->validate(['note' => 'nullable|string|max:255'])['note'] ?? null;

        return $this->guarded(function () use ($uorder, $note) {
            UpsellService::confirm($uorder, $note);

            return back()->with('success', "{$uorder->order_no} confirmed – {$uorder->offer->name} for ".($uorder->reservation->guest?->full_name ?? $uorder->reservation->reservation_no).'.');
        });
    }

    public function decline(Request $request, UpsellOrder $uorder)
    {
        $reason = $request->validate(['reason' => 'required|string|min:3|max:255'])['reason'];

        return $this->guarded(function () use ($uorder, $reason) {
            UpsellService::decline($uorder, $reason);

            return back()->with('success', "{$uorder->order_no} declined.");
        });
    }

    public function cancel(Request $request, UpsellOrder $uorder)
    {
        $reason = $request->validate(['reason' => 'required|string|min:3|max:255'])['reason'];

        return $this->guarded(function () use ($uorder, $reason) {
            UpsellService::cancel($uorder, $reason);

            return back()->with('success', "{$uorder->order_no} cancelled".($uorder->kind === 'upgrade' ? ' – the stay is back in its original room type.' : '.'));
        });
    }

    public function sendOffers(Request $request, Reservation $reservation)
    {
        $to = $request->validate(['to' => 'nullable|email|max:120'])['to'] ?? null;

        return $this->guarded(function () use ($reservation, $to) {
            $log = UpsellService::sendOffers($reservation, $to);

            return back()->with($log->status === 'sent' ? 'success' : 'error',
                $log->status === 'sent' ? "Offers e-mailed to {$log->to_email}." : "E-mail failed: {$log->error}");
        });
    }

    public function run()
    {
        $r = UpsellService::run();

        return back()->with($r['failed'] ? 'warn' : 'success', "Offers e-mailed to {$r['sent']} arriving guest(s)".($r['failed'] ? ", {$r['failed']} failed" : '')
            .($r['expired'] ? "; {$r['expired']} stale request(s) declined" : '').'.');
    }

    public function settings(Request $request)
    {
        $d = $request->validate(['enabled' => 'nullable|boolean', 'days_before' => 'required|integer|min:1|max:30', 'auto_email' => 'nullable|boolean',
            'guest_cancel_hours' => 'required|integer|min:0|max:240', 'keep_free' => 'required|integer|min:0|max:50']);
        UpsellService::saveSettings($d);

        return redirect()->route('upsell.index', ['tab' => 'settings'])->with('success', 'Upselling settings saved.');
    }
}
